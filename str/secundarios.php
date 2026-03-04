<?php
require_once __DIR__.'/inc/bootstrap.php';
require_once __DIR__ . '/inc/mail.php';
require_once __DIR__ . '/inc/staff_roles.php';
$title = "Mi staff – Administrador";

// ===== AUTH: solo admin_evento o super_admin =====
require_login();

$cu = current_user();
$tipoGlobal = isset($_SESSION['tipo_global']) ? $_SESSION['tipo_global'] : (isset($cu['rol'])?$cu['rol']:'');
if (!in_array($tipoGlobal, array('admin_evento','super_admin','superadmin'), true)) {
    header("Location: login.php");
    exit;
}

$adminId = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : (isset($cu['id'])?(int)$cu['id']:0);
if ($adminId <= 0) {
    http_response_code(400);
    include __DIR__.'/inc/layout_top.php';
    echo "<div class='card error'><h2>Sesión inválida</h2><p>Sin user_id.</p></div>";
    include __DIR__.'/inc/layout_bottom.php';
    exit;
}

$pdo = db();
tickex_staff_roles_ensure_table($pdo);
tickex_staff_roles_seed_defaults($pdo, $adminId);
$staffRoles = tickex_staff_roles_get_all($pdo, $adminId);
$staffRolesMap = array();
foreach ($staffRoles as $sr) {
  if (!empty($sr['code'])) {
    $staffRolesMap[(string)$sr['code']] = $sr;
  }
}

$prefEventoId = isset($_GET['evento_id']) ? (int)$_GET['evento_id'] : 0;

// Utilidad: obtener asignaciones de staff
function get_staff_event_ids($pdo, $staffId) {
  $stmt = $pdo->prepare("SELECT evento_id FROM staff_eventos WHERE staff_id = :id");
  $stmt->execute(array(':id'=>$staffId));
  $rows = $stmt->fetchAll(PDO::FETCH_COLUMN);
  return array_map('intval', $rows ?: array());
}

// Asegurar columna de costo de servicio para staff
function ensure_staff_cost_column($pdo) {
  try {
    $cols = array();
    $st = $pdo->query("PRAGMA table_info(usuarios_admin)");
    if ($st) {
      foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $ci) {
        $cols[$ci['name']] = true;
      }
    }
    if (!isset($cols['costo_servicio'])) {
      $pdo->exec("ALTER TABLE usuarios_admin ADD COLUMN costo_servicio REAL DEFAULT 0");
    }
  } catch (Exception $e) {
    // continuar sin bloquear
  }
}
ensure_staff_cost_column($pdo);

function _tickex_base_url()
{
  $host = isset($_SERVER['HTTP_HOST']) ? (string)$_SERVER['HTTP_HOST'] : '';
  if ($host === '') return '';
  $scheme = (function_exists('tickex_is_https') && tickex_is_https()) ? 'https' : 'http';
  return $scheme . '://' . $host;
}

function _tickex_random_token($bytesLen = 16)
{
  $n = (int)$bytesLen;
  if ($n < 8) $n = 8;
  if (function_exists('random_bytes')) {
    return bin2hex(random_bytes($n));
  }
  return sha1(uniqid(mt_rand(), true));
}

function _tickex_is_super($tipoGlobal)
{
  return in_array($tipoGlobal, array('super_admin','superadmin'), true);
}

/* =========================================================
   INVITAR STAFF POR EMAIL (nuevo modelo) (POST)
   ========================================================= */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'staff_invite') {
  $provided = isset($_POST['csrf']) ? (string)$_POST['csrf'] : '';
  if (function_exists('tickex_csrf_verify') && !tickex_csrf_verify($provided)) {
    flash('err', 'CSRF inválido. Actualizá la página e intentá de nuevo.');
  } else {
    $email = isset($_POST['email']) ? trim((string)$_POST['email']) : '';
    $tickexId = isset($_POST['tickex_id']) ? trim((string)$_POST['tickex_id']) : '';
    $mensaje = null;
    $rolStaff = 'puerta';

    $clienteIdByTickex = 0;
    if ($tickexId !== '') {
      try {
        if (preg_match('/^#?(\d+)$/', $tickexId, $m)) {
          $cid = (int)$m[1];
          if ($cid > 0) {
            $stCli = $pdo->prepare('SELECT id, email FROM registro_pendientes WHERE id = :id LIMIT 1');
            $stCli->execute(array(':id' => $cid));
            $cli = $stCli->fetch(PDO::FETCH_ASSOC);
            if ($cli) {
              $clienteIdByTickex = (int)$cli['id'];
              if ($email === '' && !empty($cli['email'])) $email = (string)$cli['email'];
            }
          }
        } else {
          $stCli = $pdo->prepare('SELECT id, email FROM registro_pendientes WHERE lower(apodo) = lower(:ap) LIMIT 1');
          $stCli->execute(array(':ap' => $tickexId));
          $cli = $stCli->fetch(PDO::FETCH_ASSOC);
          if ($cli) {
            $clienteIdByTickex = (int)$cli['id'];
            if ($email === '' && !empty($cli['email'])) $email = (string)$cli['email'];
          }
        }
      } catch (Exception $e) {
        // ignore
      }
    }

    if ($email === '' && $tickexId === '') {
      flash('warn', 'Ingresá email o Tickex ID.');
    } elseif ($tickexId !== '' && $clienteIdByTickex <= 0) {
      flash('warn', 'No encontramos un usuario con ese Tickex ID.');
    } elseif ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
      flash('warn', 'Ingresá un email válido o un Tickex ID existente.');
    } else {
      try {
        $stDup = $pdo->prepare("SELECT 1 FROM staff_admin_invitaciones WHERE owner_admin_id = :oid AND lower(email) = lower(:e) AND estado = 'pending' LIMIT 1");
        $stDup->execute(array(':oid' => $adminId, ':e' => $email));
        if ($stDup->fetchColumn()) {
          flash('warn', 'Ya existe una invitación pendiente para ese email.');
        } else {
          $inviteToken = _tickex_random_token(16);
          $base = _tickex_base_url();
          $perfilUrl = ($base !== '' ? $base : '') . '/panel_usuario_mi_perfil.php?staff_invite=' . urlencode($inviteToken);

          // Preparar registro (si no está completado)
          $stRp = $pdo->prepare('SELECT id, completado_en, password_hash, nombre, apellido FROM registro_pendientes WHERE lower(email) = lower(:e) ORDER BY id DESC LIMIT 1');
          $stRp->execute(array(':e' => $email));
          $rp = $stRp->fetch(PDO::FETCH_ASSOC);

          $clienteId = $clienteIdByTickex;
          $needsReg = true;
          $regToken = '';
          if ($rp && $clienteId <= 0) {
            $clienteId = (int)$rp['id'];
          }

          if ($rp) {
            $ph = isset($rp['password_hash']) ? (string)$rp['password_hash'] : '';
            $ce = isset($rp['completado_en']) ? (string)$rp['completado_en'] : '';
            if ($ph !== '' || $ce !== '') {
              $needsReg = false;
            }
          }

          $nombre = ($rp && isset($rp['nombre'])) ? (string)$rp['nombre'] : '';
          $apellido = ($rp && isset($rp['apellido'])) ? (string)$rp['apellido'] : '';

          if ($needsReg) {
            $regToken = _tickex_random_token(16);
            $ahora = date('Y-m-d H:i:s');
            $nextForReg = 'panel_usuario_mi_perfil.php?staff_invite=' . urlencode($inviteToken);
            if ($rp) {
              $stmtUp = $pdo->prepare("UPDATE registro_pendientes
                SET token = :t,
                    completado_en = NULL,
                    next_url = :n,
                    creado_en = :c,
                    nombre = :fn,
                    apellido = :ln
                WHERE id = :id");
              $stmtUp->execute(array(
                ':t' => $regToken,
                ':n' => $nextForReg,
                ':c' => $ahora,
                ':fn' => $nombre,
                ':ln' => $apellido,
                ':id' => (int)$rp['id'],
              ));
              $clienteId = (int)$rp['id'];
            } else {
              $stmtIns = $pdo->prepare('INSERT INTO registro_pendientes (email, token, nombre, apellido, next_url, creado_en) VALUES (:e, :t, :fn, :ln, :n, :c)');
              $stmtIns->execute(array(
                ':e' => $email,
                ':t' => $regToken,
                ':fn' => $nombre,
                ':ln' => $apellido,
                ':n' => $nextForReg,
                ':c' => $ahora,
              ));
              $clienteId = (int)$pdo->lastInsertId();
            }
          }

          $stInv = $pdo->prepare("INSERT INTO staff_admin_invitaciones (owner_admin_id, email, token, mensaje, rol_staff, estado, cliente_id, created_at)
            VALUES (:oid, :e, :t, :m, :r, 'pending', :cid, datetime('now'))");
          $stInv->execute(array(
            ':oid' => $adminId,
            ':e' => $email,
            ':t' => $inviteToken,
            ':m' => ($mensaje !== '' ? $mensaje : null),
            ':r' => $rolStaff,
            ':cid' => ($clienteId > 0 ? $clienteId : null),
          ));
          $invId = (int)$pdo->lastInsertId();

          if ($clienteId > 0) {
            require_once __DIR__ . '/inc/notificaciones.php';
            add_notification(
              $clienteId,
              'Te invitaron a ser parte del staff de un evento. Revisá tu perfil para aceptar.',
              'staff_invite',
              array('admin_id' => $adminId, 'inv_id' => $invId)
            );
          }

          $registerUrl = '';
          if ($needsReg && $regToken !== '') {
            $registerUrl = ($base !== '' ? $base : '') . '/completar_registro.php?token=' . urlencode($regToken);
          }

          $okMail = tickex_send_mail_template(
            $email,
            'staff_invite',
            array(
              'email' => $email,
              'perfil_url' => $perfilUrl,
              'register_url' => $registerUrl,
                'mensaje' => '',
            ),
            array(
              'context' => 'staff_invite',
              'related_table' => 'staff_admin_invitaciones',
              'related_id' => $invId,
            ),
            array(
              'subject' => 'Invitación a staff - Tickex',
                'body' => "Hola,\n\nTe invitaron a ser parte del staff.\n\nSi todavía no tenés cuenta, completá el registro desde este link:\n{{register_url}}\n\nSi ya tenés cuenta, ingresá y aceptá la invitación desde tu perfil:\n{{perfil_url}}\n\nTickex\n",
              'from_email' => 'no-reply@tickex.com.ar',
              'from_name' => 'Tickex',
              'reply_to' => 'no-reply@tickex.com.ar',
              'extra_params' => '-f no-reply@tickex.com.ar',
              'is_html' => 0,
            )
          );

          flash($okMail ? 'ok' : 'warn', $okMail ? 'Invitación enviada (requiere aceptación).' : 'Invitación registrada internamente (mail no disponible en este entorno).');
        }
      } catch (Exception $e) {
        flash('err', 'No se pudo crear/enviar la invitación.');
      }
    }
  }
}

// ===== Detectar columnas opcionales =====
$colsEv = $pdo->query("PRAGMA table_info(eventos)")->fetchAll(PDO::FETCH_ASSOC);
$hasCreadoPorEv = false;
foreach($colsEv as $c){
    if (isset($c['name']) && $c['name']==='creado_por_admin_id') { $hasCreadoPorEv=true; break; }
}

$colsU = $pdo->query("PRAGMA table_info(usuarios_admin)")->fetchAll(PDO::FETCH_ASSOC);
$hasCreadoPorU = false;
foreach($colsU as $c){
    if (isset($c['name']) && $c['name']==='creado_por_admin_id') { $hasCreadoPorU=true; break; }
}

/* =========================================================
   QUITAR STAFF (nuevo modelo) (POST)
   ========================================================= */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'remove_staff_link') {
  $provided = isset($_POST['csrf']) ? (string)$_POST['csrf'] : '';
  if (function_exists('tickex_csrf_verify') && !tickex_csrf_verify($provided)) {
    flash('err', 'CSRF inválido.');
  } else {
    $clienteId = isset($_POST['cliente_id']) ? (int)$_POST['cliente_id'] : 0;
    if ($clienteId <= 0) {
      flash('warn', 'Cliente inválido.');
    } else {
      try {
        if (_tickex_is_super($tipoGlobal)) {
          $stDel = $pdo->prepare('UPDATE staff_admins SET activo = 0 WHERE cliente_id = :cid');
          $stDel->execute(array(':cid' => $clienteId));
        } else {
          $stDel = $pdo->prepare('UPDATE staff_admins SET activo = 0 WHERE owner_admin_id = :aid AND cliente_id = :cid');
          $stDel->execute(array(':aid' => $adminId, ':cid' => $clienteId));
        }
        flash('ok', 'Staff removido correctamente.');
      } catch (Exception $e) {
        flash('err', 'No se pudo remover el staff.');
      }
    }
  }
}

/* =========================================================
   ACTUALIZAR ROL STAFF (nuevo modelo) (POST)
   ========================================================= */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update_staff_role') {
  $provided = isset($_POST['csrf']) ? (string)$_POST['csrf'] : '';
  if (function_exists('tickex_csrf_verify') && !tickex_csrf_verify($provided)) {
    flash('err', 'CSRF inválido.');
  } else {
    $clienteId = isset($_POST['cliente_id']) ? (int)$_POST['cliente_id'] : 0;
    $rolStaff = isset($_POST['rol_staff']) ? trim((string)$_POST['rol_staff']) : 'puerta';
    if (!isset($staffRolesMap[$rolStaff])) $rolStaff = 'puerta';

    if ($clienteId <= 0) {
      flash('warn', 'Cliente inválido.');
    } else {
      try {
        if (_tickex_is_super($tipoGlobal)) {
          $stUp = $pdo->prepare('UPDATE staff_admins SET rol_staff = :r WHERE cliente_id = :cid');
          $stUp->execute(array(':r' => $rolStaff, ':cid' => $clienteId));
        } else {
          $stUp = $pdo->prepare('UPDATE staff_admins SET rol_staff = :r WHERE owner_admin_id = :aid AND cliente_id = :cid');
          $stUp->execute(array(':r' => $rolStaff, ':aid' => $adminId, ':cid' => $clienteId));
        }
        flash('ok', 'Rol de staff actualizado.');
      } catch (Exception $e) {
        flash('err', 'No se pudo actualizar el rol.');
      }
    }
  }
}

/* =========================================================
   ELIMINAR STAFF (POST)
   ========================================================= */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete_staff') {
    $staffId = isset($_POST['staff_id']) ? (int)$_POST['staff_id'] : 0;

    if ($staffId <= 0) {
        flash('warn', 'ID de staff inválido.');
    } else {
        // Traer staff
        $stQ = $pdo->prepare("SELECT * FROM usuarios_admin WHERE id=? LIMIT 1");
        $stQ->execute(array($staffId));
        $staff = $stQ->fetch(PDO::FETCH_ASSOC);

        if (!$staff || $staff['tipo_global'] !== 'staff_evento') {
            flash('err', 'Staff no encontrado.');
        } else {
            $permitido = false;

            if ($tipoGlobal === 'super_admin' || $tipoGlobal === 'superadmin') {
                $permitido = true;
            } else {
                // admin_evento: validar creador si existe columna
                if ($hasCreadoPorU) {
                    $creadorStaff = isset($staff['creado_por_admin_id']) ? (int)$staff['creado_por_admin_id'] : 0;
                    if ($creadorStaff === $adminId) $permitido = true;
                } else {
                    // fallback legacy: permitir si pertenece a un evento visible del admin
                    $adminEventoId = isset($_SESSION['evento_id']) ? (int)$_SESSION['evento_id'] : 0;
                    $staffEventoId = isset($staff['evento_id']) ? (int)$staff['evento_id'] : 0;

                    if ($adminEventoId > 0 && $staffEventoId === $adminEventoId) {
                        $permitido = true;
                    } elseif ($hasCreadoPorEv) {
                        $chkEv = $pdo->prepare("SELECT COUNT(*) FROM eventos WHERE id=? AND creado_por_admin_id=?");
                        $chkEv->execute(array($staffEventoId, $adminId));
                        if ((int)$chkEv->fetchColumn() > 0) $permitido = true;
                    }
                }
            }

            if (!$permitido) {
                flash('err','No tenés permiso para eliminar este staff.');
            } else {
                try {
                    $del = $pdo->prepare("DELETE FROM usuarios_admin WHERE id=? AND tipo_global='staff_evento'");
                    $del->execute(array($staffId));
                    flash('ok','Staff eliminado correctamente.');
                } catch (Exception $e) {
                    flash('err','Error al eliminar staff: '.$e->getMessage());
                }
            }
        }
    }
}

/* =========================================================
   ACTUALIZAR COSTO SERVICIO (POST)
   ========================================================= */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update_costo') {
  $staffId = isset($_POST['staff_id']) ? (int)$_POST['staff_id'] : 0;
  $costoServ = isset($_POST['costo_servicio']) ? (float)$_POST['costo_servicio'] : 0;

  if ($staffId <= 0) {
    flash('warn', 'ID de staff inválido.');
  } else {
    $stQ = $pdo->prepare("SELECT * FROM usuarios_admin WHERE id=? LIMIT 1");
    $stQ->execute(array($staffId));
    $staff = $stQ->fetch(PDO::FETCH_ASSOC);

    if (!$staff || $staff['tipo_global'] !== 'staff_evento') {
      flash('err', 'Staff no encontrado.');
    } else {
      $permitido = false;
      if ($tipoGlobal === 'super_admin' || $tipoGlobal === 'superadmin') {
        $permitido = true;
      } elseif ($hasCreadoPorU) {
        $creadorStaff = isset($staff['creado_por_admin_id']) ? (int)$staff['creado_por_admin_id'] : 0;
        if ($creadorStaff === $adminId) $permitido = true;
      }

      if (!$permitido) {
        flash('err','No tenés permiso para modificar este staff.');
      } else {
        try {
          $up = $pdo->prepare("UPDATE usuarios_admin SET costo_servicio = :c WHERE id = :id");
          $up->execute(array(':c' => $costoServ, ':id' => $staffId));
          flash('ok','Costo actualizado.');
        } catch (Exception $e) {
          flash('err','Error al actualizar: '.$e->getMessage());
        }
      }
    }
  }
}

  /* =========================================================
     ASIGNAR STAFF EXISTENTE A UN EVENTO (POST)
     ========================================================= */
  if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'assign_staff_event') {
    $staffIdAsign = isset($_POST['staff_id']) ? (int)$_POST['staff_id'] : 0;
    $clienteIdAsign = isset($_POST['cliente_id']) ? (int)$_POST['cliente_id'] : 0;
    if ($staffIdAsign <= 0 && $clienteIdAsign > 0) {
      $staffIdAsign = $clienteIdAsign;
    }
    $rolEventoSet = trim(isset($_POST['rol_evento']) ? $_POST['rol_evento'] : 'puerta');
    if (!isset($staffRolesMap[$rolEventoSet])) $rolEventoSet = 'puerta';
    $allEvents    = isset($_POST['all_events']) && (int)$_POST['all_events'] === 1;

    // evento_id puede venir como array (multi-select)
    $eventosAsign = array();
    if (isset($_POST['evento_id'])) {
      if (is_array($_POST['evento_id'])) {
        foreach ($_POST['evento_id'] as $e) {
          $eid = (int)$e;
          if ($eid > 0) $eventosAsign[$eid] = true;
        }
      } else {
        $eid = (int)$_POST['evento_id'];
        if ($eid > 0) $eventosAsign[$eid] = true;
      }
    }

    if ($staffIdAsign <= 0 || (!$allEvents && empty($eventosAsign))) {
      flash('warn', 'Elegí staff y al menos un evento (o Todos).');
    } else {
      $stRel = $pdo->prepare('SELECT id, owner_admin_id, cliente_id FROM staff_admins WHERE cliente_id = :cid AND activo = 1 LIMIT 1');
      $stRel->execute(array(':cid' => $staffIdAsign));
      $staffRel = $stRel->fetch(PDO::FETCH_ASSOC);

      if ($staffRel) {
        $permitido = _tickex_is_super($tipoGlobal) || ((int)$staffRel['owner_admin_id'] === $adminId);
        if (!$permitido) {
          flash('err', 'No tenés permiso para asignar este staff.');
        } else {
          try {
            $selectedIds = array();
            if ($allEvents) {
              if (_tickex_is_super($tipoGlobal)) {
                $stmtEvAll = $pdo->query('SELECT id FROM eventos');
                $all = $stmtEvAll ? $stmtEvAll->fetchAll(PDO::FETCH_COLUMN) : array();
              } else {
                if ($hasCreadoPorEv) {
                  $stmtEvAll = $pdo->prepare('SELECT id FROM eventos WHERE creado_por_admin_id = :aid');
                  $stmtEvAll->execute(array(':aid' => $adminId));
                  $all = $stmtEvAll->fetchAll(PDO::FETCH_COLUMN);
                } else {
                  $stmtEvAll = $pdo->query('SELECT id FROM eventos');
                  $all = $stmtEvAll ? $stmtEvAll->fetchAll(PDO::FETCH_COLUMN) : array();
                }
              }
              foreach ($all as $eid) { $selectedIds[(int)$eid] = true; }
            }
            foreach (array_keys($eventosAsign) as $eid) { $selectedIds[$eid] = true; }

            $del = $pdo->prepare('DELETE FROM staff_eventos WHERE staff_id = :id');
            $del->execute(array(':id' => $staffIdAsign));

            if (!empty($selectedIds)) {
              $ins = $pdo->prepare('INSERT OR IGNORE INTO staff_eventos (staff_id, evento_id) VALUES (:sid, :eid)');
              foreach ($selectedIds as $eid => $v) {
                $ins->execute(array(':sid' => $staffIdAsign, ':eid' => $eid));
              }
            }

            $upRel = $pdo->prepare('UPDATE staff_admins SET rol_staff = COALESCE(NULLIF(:r,\'\'), rol_staff), activo = 1 WHERE id = :id');
            $upRel->execute(array(':r' => $rolEventoSet, ':id' => (int)$staffRel['id']));

            flash('ok', 'Staff asignado a ' . count($selectedIds) . ' evento(s).');
          } catch (Exception $e) {
            flash('err', 'Error al asignar: ' . $e->getMessage());
          }
        }
      } else {
        $stQ = $pdo->prepare("SELECT * FROM usuarios_admin WHERE id=? LIMIT 1");
        $stQ->execute(array($staffIdAsign));
        $staff = $stQ->fetch(PDO::FETCH_ASSOC);

        if (!$staff || $staff['tipo_global'] !== 'staff_evento') {
          flash('err', 'Staff no encontrado.');
        } else {
          $permitido = false;
          if ($tipoGlobal === 'super_admin' || $tipoGlobal === 'superadmin') {
            $permitido = true;
          } elseif ($hasCreadoPorU) {
            $creadorStaff = isset($staff['creado_por_admin_id']) ? (int)$staff['creado_por_admin_id'] : 0;
            if ($creadorStaff === $adminId) $permitido = true;
          } else {
            $adminEventoId = isset($_SESSION['evento_id']) ? (int)$_SESSION['evento_id'] : 0;
            if ($adminEventoId > 0 && isset($staff['evento_id']) && (int)$staff['evento_id'] === $adminEventoId) {
              $permitido = true;
            }
          }

          if (!$permitido) {
            flash('err', 'No tenés permiso para asignar este staff.');
          } else {
            try {
              // limpiar asignaciones previas
              $del = $pdo->prepare("DELETE FROM staff_eventos WHERE staff_id = :id");
              $del->execute(array(':id'=>$staffIdAsign));

              $selectedIds = array();
              if ($allEvents) {
                $stmtEvAll = $pdo->query("SELECT id FROM eventos");
                $all = $stmtEvAll ? $stmtEvAll->fetchAll(PDO::FETCH_COLUMN) : array();
                foreach ($all as $eid) { $selectedIds[(int)$eid] = true; }
              }
              foreach (array_keys($eventosAsign) as $eid) { $selectedIds[$eid] = true; }

              if (!empty($selectedIds)) {
                $ins = $pdo->prepare("INSERT OR IGNORE INTO staff_eventos (staff_id, evento_id) VALUES (:sid, :eid)");
                foreach ($selectedIds as $eid => $v) {
                  $ins->execute(array(':sid'=>$staffIdAsign, ':eid'=>$eid));
                }
                $first = array_key_first($selectedIds);
                $upd = $pdo->prepare("UPDATE usuarios_admin SET evento_id = :eid, rol_evento = :rol, activo = 1 WHERE id = :id");
                $upd->execute(array(
                  ':eid' => $first,
                  ':rol' => $rolEventoSet !== '' ? $rolEventoSet : 'puerta',
                  ':id'  => $staffIdAsign,
                ));
              }

              flash('ok', 'Staff asignado a '.count($selectedIds).' evento(s).');
            } catch (Exception $e) {
              flash('err', 'Error al asignar: '.$e->getMessage());
            }
          }
        }
      }
    }
  }

/* =========================================================
   CREAR STAFF (POST)
   ========================================================= */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'create_staff') {
  $provided = isset($_POST['csrf']) ? (string)$_POST['csrf'] : '';
  if (function_exists('tickex_csrf_verify') && !tickex_csrf_verify($provided)) {
    flash('err', 'CSRF inválido. Actualizá la página e intentá de nuevo.');
  } else {
  // Si venimos desde asignación (prefEventoId>0) no mostramos alta aquí, se gestiona en mi staff sin evento seleccionado
  if ($prefEventoId > 0) {
    // ignorar creación en modo asignación
  } else {
    $username  = trim(isset($_POST['username']) ? $_POST['username'] : '');
    $password  = (string)(isset($_POST['password']) ? $_POST['password'] : '');
    $plantilla = trim(isset($_POST['plantilla']) ? $_POST['plantilla'] : 'puerta');
    $eventoId  = (int)(isset($_POST['evento_id']) ? $_POST['evento_id'] : 0);
    $costoServ = isset($_POST['costo_servicio']) ? (float)$_POST['costo_servicio'] : 0;

    if ($username === '' || $password === '') {
        flash('warn', "Usuario y contraseña son obligatorios.");
    } elseif ($eventoId <= 0) {
        flash('warn', "Tenés que asignar un evento.");
    } else {
        $rolEvento  = 'puerta';
        $tipoGlobalNuevo = 'staff_evento';

        try {
            if ($hasCreadoPorU) {
                $stmt = $pdo->prepare("
                    INSERT INTO usuarios_admin
                        (username, password, rol, tipo_global, rol_evento, evento_id, activo, creado_por_admin_id, costo_servicio)
                    VALUES
                        (:u, :p, :rol, :tg, :re, :eid, 1, :creador, :costo)
                ");
                $stmt->execute(array(
                    ':u'       => $username,
                    ':p'       => $password,
                    ':rol'     => $rolEvento,
                    ':tg'      => $tipoGlobalNuevo,
                    ':re'      => $rolEvento,
                    ':eid'     => $eventoId,
                      ':creador' => $adminId,
                      ':costo'   => $costoServ,
                ));
            } else {
                $stmt = $pdo->prepare("
                    INSERT INTO usuarios_admin
                        (username, password, rol, tipo_global, rol_evento, evento_id, activo, costo_servicio)
                    VALUES
                        (:u, :p, :rol, :tg, :re, :eid, 1, :costo)
                ");
                $stmt->execute(array(
                    ':u'   => $username,
                    ':p'   => $password,
                    ':rol' => $rolEvento,
                    ':tg'  => $tipoGlobalNuevo,
                    ':re'  => $rolEvento,
                      ':eid' => $eventoId,
                      ':costo' => $costoServ,
                ));
            }

            flash('ok', "Staff creado: {$username} (evento #{$eventoId}).");
        } catch (Exception $ex) {
            flash('err', "Error al crear staff: " . $ex->getMessage());
        }
    }
  }
}
    }

// ===== Eventos del admin actual =====
if ($tipoGlobal === 'super_admin' || $tipoGlobal === 'superadmin') {
    $stmtEv = $pdo->query("SELECT id, nombre, slug FROM eventos ORDER BY id DESC");
    $eventos = $stmtEv->fetchAll(PDO::FETCH_ASSOC);
} else {
    if ($hasCreadoPorEv) {
        $stmtEv = $pdo->prepare("
            SELECT id, nombre, slug
            FROM eventos
            WHERE creado_por_admin_id = :aid
            ORDER BY id DESC
        ");
        $stmtEv->execute(array(':aid'=>$adminId));
        $eventos = $stmtEv->fetchAll(PDO::FETCH_ASSOC);
    } else {
        $stmtEv = $pdo->query("SELECT id, nombre, slug FROM eventos ORDER BY id DESC");
        $eventos = $stmtEv->fetchAll(PDO::FETCH_ASSOC);
    }
}

// ===== Staff visible (listado principal) =====
if ($tipoGlobal === 'super_admin' || $tipoGlobal === 'superadmin') {
  $sqlStaff = "
    SELECT u.id, u.username, u.rol_evento, u.evento_id, u.activo, u.costo_servicio,
      e.nombre AS evento_nombre, e.slug AS evento_slug
    FROM usuarios_admin u
    LEFT JOIN eventos e ON e.id = u.evento_id
    WHERE u.tipo_global='staff_evento'";
  $paramsStaff = array();
  if ($prefEventoId > 0) {
    $sqlStaff .= " AND u.evento_id = :filtro_evento";
    $paramsStaff[':filtro_evento'] = $prefEventoId;
  }
  $sqlStaff .= " ORDER BY u.id DESC";
  $stmtStaff = $pdo->prepare($sqlStaff);
  $stmtStaff->execute($paramsStaff);
  $staffRows = $stmtStaff->fetchAll(PDO::FETCH_ASSOC);
} else {
  if ($hasCreadoPorU) {
    $sqlStaff = "
      SELECT u.id, u.username, u.rol_evento, u.evento_id, u.activo, u.costo_servicio,
           e.nombre AS evento_nombre, e.slug AS evento_slug
      FROM usuarios_admin u
      LEFT JOIN eventos e ON e.id = u.evento_id
      WHERE u.tipo_global='staff_evento'
        AND u.creado_por_admin_id = :aid";
    $paramsStaff = array(':aid'=>$adminId);
    if ($prefEventoId > 0) {
      $sqlStaff .= " AND u.evento_id = :filtro_evento";
      $paramsStaff[':filtro_evento'] = $prefEventoId;
    }
    $sqlStaff .= " ORDER BY u.id DESC";
    $stmtStaff = $pdo->prepare($sqlStaff);
    $stmtStaff->execute($paramsStaff);
    $staffRows = $stmtStaff->fetchAll(PDO::FETCH_ASSOC);
  } else {
    $adminEventoId = isset($_SESSION['evento_id']) ? (int)$_SESSION['evento_id'] : 0;
    if ($prefEventoId > 0) {
      if ($hasCreadoPorEv) {
        $chkEv = $pdo->prepare("SELECT COUNT(*) FROM eventos WHERE id=? AND creado_por_admin_id=?");
        $chkEv->execute(array($prefEventoId, $adminId));
        if ((int)$chkEv->fetchColumn() > 0) {
          $adminEventoId = $prefEventoId;
        }
      } else {
        $adminEventoId = $prefEventoId;
      }
    }
    if ($adminEventoId > 0) {
      $stmtStaff = $pdo->prepare("
        SELECT u.id, u.username, u.rol_evento, u.evento_id, u.activo, u.costo_servicio,
             e.nombre AS evento_nombre, e.slug AS evento_slug
        FROM usuarios_admin u
        LEFT JOIN eventos e ON e.id = u.evento_id
        WHERE u.tipo_global='staff_evento'
          AND u.evento_id = :eid
        ORDER BY u.id DESC
      ");
      $stmtStaff->execute(array(':eid'=>$adminEventoId));
      $staffRows = $stmtStaff->fetchAll(PDO::FETCH_ASSOC);
    } else {
      $staffRows = array();
    }
  }
}

// ===== Pool completo de staff (para asignar) =====
if ($tipoGlobal === 'super_admin' || $tipoGlobal === 'superadmin') {
  $stmtAll = $pdo->query("
    SELECT u.id, u.username, u.rol_evento, u.evento_id, u.activo,
           e.nombre AS evento_nombre, e.slug AS evento_slug
    FROM usuarios_admin u
    LEFT JOIN eventos e ON e.id = u.evento_id
    WHERE u.tipo_global='staff_evento'
    ORDER BY u.username ASC, u.id DESC
  ");
  $staffAll = $stmtAll->fetchAll(PDO::FETCH_ASSOC);
} elseif ($hasCreadoPorU) {
  $stmtAll = $pdo->prepare("
    SELECT u.id, u.username, u.rol_evento, u.evento_id, u.activo,
           e.nombre AS evento_nombre, e.slug AS evento_slug
    FROM usuarios_admin u
    LEFT JOIN eventos e ON e.id = u.evento_id
    WHERE u.tipo_global='staff_evento'
      AND u.creado_por_admin_id = :aid
    ORDER BY u.username ASC, u.id DESC
  ");
  $stmtAll->execute(array(':aid'=>$adminId));
  $staffAll = $stmtAll->fetchAll(PDO::FETCH_ASSOC);
} else {
  $adminEventoId = isset($_SESSION['evento_id']) ? (int)$_SESSION['evento_id'] : 0;
  if ($prefEventoId > 0) {
    if ($hasCreadoPorEv) {
      $chkEv = $pdo->prepare("SELECT COUNT(*) FROM eventos WHERE id=? AND creado_por_admin_id=?");
      $chkEv->execute(array($prefEventoId, $adminId));
      if ((int)$chkEv->fetchColumn() > 0) {
        $adminEventoId = $prefEventoId;
      }
    } else {
      $adminEventoId = $prefEventoId;
    }
  }
  if ($adminEventoId > 0) {
    $stmtAll = $pdo->prepare("
      SELECT u.id, u.username, u.rol_evento, u.evento_id, u.activo,
             e.nombre AS evento_nombre, e.slug AS evento_slug
      FROM usuarios_admin u
      LEFT JOIN eventos e ON e.id = u.evento_id
      WHERE u.tipo_global='staff_evento'
        AND u.evento_id = :eid
      ORDER BY u.username ASC, u.id DESC
    ");
    $stmtAll->execute(array(':eid'=>$adminEventoId));
    $staffAll = $stmtAll->fetchAll(PDO::FETCH_ASSOC);
  } else {
    $staffAll = array();
  }
}

include __DIR__.'/inc/layout_top.php';
?>

<div class="card" style="display:flex;gap:8px;flex-wrap:wrap;align-items:center;">
  <a class="btn secondary" href="panel_admin.php">⬅ Volver al panel</a>
  <?php if ($prefEventoId > 0): ?>
    <a class="btn" href="secundarios.php" style="background:var(--ok);color:#04150a;">+ Agregar staff</a>
  <?php endif; ?>
  <?php if ($prefEventoId <= 0): ?>
    <a class="btn secondary" href="/roles_staff.php">Roles staff</a>
  <?php endif; ?>
  <span style="flex:1 1 auto;"></span>
  <a class="btn danger" href="login.php?logout=1">Salir</a>
</div>

<div class="card">
  <h2>Mi staff</h2>
  <div style="color:var(--muted);font-size:14px;">
    Staff ahora se maneja por invitación (con aceptación obligatoria) sobre cuentas de cliente.
  </div>
</div>

<?php if ($prefEventoId <= 0): ?>
<div class="card" style="max-width:700px;">
  <h3>Invitar staff</h3>
  <div class="muted" style="margin:6px 0 10px 0;">Podés invitar por email o por Tickex ID (apodo). Si no tiene cuenta, recibe invitación para registrarse y luego aceptar.</div>

  <form method="post" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:10px;align-items:end;">
    <input type="hidden" name="csrf" value="<?php echo e(tickex_csrf_token()); ?>">
    <input type="hidden" name="action" value="staff_invite">

    <label>Email (opcional)
      <input type="email" name="email" placeholder="persona@email.com">
    </label>
    <label>Tickex ID (opcional)
      <input type="text" name="tickex_id" placeholder="Ej: Senchi o #123">
    </label>
    <div style="display:flex;gap:8px;align-items:center;">
      <button class="btn" type="submit">Enviar invitación</button>
    </div>
  </form>
</div>
<?php endif; ?>

<?php
// Listado nuevo: staff activo y pendientes por invitación (por admin)
$staffNuevo = array();
$staffPendientes = array();
try {
  $stN = $pdo->prepare("SELECT sa.id, sa.cliente_id, sa.rol_staff, sa.created_at,
      rp.apodo, rp.nombre, rp.apellido, rp.email
    FROM staff_admins sa
    LEFT JOIN registro_pendientes rp ON rp.id = sa.cliente_id
    WHERE sa.owner_admin_id = :aid AND sa.activo = 1
    ORDER BY sa.id DESC
    LIMIT 100");
  $stN->execute(array(':aid' => $adminId));
  $staffNuevo = $stN->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
  $staffNuevo = array();
}

try {
  $stP = $pdo->prepare("SELECT id, email, mensaje, created_at
    FROM staff_admin_invitaciones
    WHERE owner_admin_id = :aid AND estado = 'pending'
    ORDER BY id DESC
    LIMIT 100");
  $stP->execute(array(':aid' => $adminId));
  $staffPendientes = $stP->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
  $staffPendientes = array();
}

$staffAsignadoEvento = array();
if ($prefEventoId > 0) {
  try {
    $stAE = $pdo->prepare("SELECT DISTINCT se.staff_id AS cliente_id,
        rp.apodo, rp.email, rp.nombre, rp.apellido,
        sa.rol_staff,
        se.evento_id
      FROM staff_eventos se
      LEFT JOIN registro_pendientes rp ON rp.id = se.staff_id
      LEFT JOIN staff_admins sa ON sa.cliente_id = se.staff_id AND sa.activo = 1
      WHERE se.evento_id = :eid
      ORDER BY se.staff_id DESC");
    $stAE->execute(array(':eid' => $prefEventoId));
    $staffAsignadoEvento = $stAE->fetchAll(PDO::FETCH_ASSOC);
  } catch (Exception $e) {
    $staffAsignadoEvento = array();
  }
}
?>

<div class="card" style="max-width:900px;">
  <h3>Staff</h3>
  <?php if (empty($staffNuevo)): ?>
    <div class="muted">No hay staff activo aún.</div>
  <?php else: ?>
    <div style="overflow:auto;">
      <table class="table" style="width:100%;min-width:780px;">
        <thead>
          <tr>
            <th style="width:90px;">Cliente</th>
            <th style="width:220px;">Tickex ID</th>
            <th>Email</th>
            <th style="width:160px;">Rol</th>
            <th style="width:180px;">Desde</th>
            <th style="width:90px;"></th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($staffNuevo as $s): ?>
            <tr>
              <td>#<?php echo (int)$s['cliente_id']; ?></td>
              <td><?php echo e(($s['apodo'] && $s['apodo'] !== '') ? (string)$s['apodo'] : ('#' . (int)$s['cliente_id'])); ?></td>
              <td><?php echo e((string)($s['email'] ?? '')); ?></td>
              <td><?php echo e(tickex_staff_role_label($pdo, $adminId, (string)($s['rol_staff'] ?? 'puerta'))); ?></td>
              <td><?php echo e((string)($s['created_at'] ?? '')); ?></td>
              <td style="text-align:right;">
                <form method="post" style="margin:0;" onsubmit="return confirm('¿Quitar este usuario del staff?');">
                  <input type="hidden" name="csrf" value="<?php echo e(tickex_csrf_token()); ?>">
                  <input type="hidden" name="action" value="remove_staff_link">
                  <input type="hidden" name="cliente_id" value="<?php echo (int)$s['cliente_id']; ?>">
                  <button class="btn danger" type="submit" title="Quitar staff" style="padding:6px 10px;font-size:14px;">🗑️</button>
                </form>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
</div>

<?php if ($prefEventoId <= 0): ?>
<div class="card" style="max-width:900px;">
  <h3>Invitaciones pendientes</h3>
  <?php if (empty($staffPendientes)): ?>
    <div class="muted">No hay invitaciones pendientes.</div>
  <?php else: ?>
    <div style="overflow:auto;">
      <table class="table" style="width:100%;min-width:780px;">
        <thead>
          <tr>
            <th style="width:80px;">ID</th>
            <th style="width:240px;">Email</th>
            <th>Mensaje</th>
            <th style="width:180px;">Creada</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($staffPendientes as $p): ?>
            <tr>
              <td><?php echo (int)$p['id']; ?></td>
              <td><?php echo e((string)($p['email'] ?? '')); ?></td>
              <td><?php echo e((string)($p['mensaje'] ?? '')); ?></td>
              <td><?php echo e((string)($p['created_at'] ?? '')); ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
</div>
<?php endif; ?>

<div class="card" style="max-width:900px;">
  <h3>Asignar staff existente a un evento</h3>

  <form method="post" style="display:grid;grid-template-columns:1.2fr 1fr 0.8fr auto;gap:10px;align-items:end;">
    <input type="hidden" name="csrf" value="<?php echo e(tickex_csrf_token()); ?>">
    <input type="hidden" name="action" value="assign_staff_event">

    <div>
      <label>Elegí staff</label>
      <div style="display:flex;gap:6px;flex-direction:column;">
        <input type="text" id="filterStaff" placeholder="Buscar por Tickex ID o email" oninput="filterStaffOptions()" style="width:100%;">
        <select name="cliente_id" id="staffSelect" required style="width:100%;">
          <option value="">Seleccioná staff...</option>
          <?php foreach ($staffNuevo as $s): ?>
            <?php
              $labelTickex = ($s['apodo'] && $s['apodo'] !== '') ? (string)$s['apodo'] : ('#' . (int)$s['cliente_id']);
              $labelEmail = (string)($s['email'] ?? '');
              $searchKey = strtolower($labelTickex . ' ' . $labelEmail);
            ?>
            <option value="<?php echo (int)$s['cliente_id']; ?>" data-name="<?php echo e($searchKey); ?>">
              <?php echo e($labelTickex); ?><?php if ($labelEmail !== ''): ?> — <?php echo e($labelEmail); ?><?php endif; ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>
    </div>

    <div>
      <label>Asignar a evento</label>
      <select name="evento_id" required>
        <option value="">Elegí un evento...</option>
        <?php foreach($eventos as $ev): ?>
          <option value="<?php echo (int)$ev['id']; ?>" <?php echo ($prefEventoId === (int)$ev['id']) ? 'selected' : ''; ?>>
            #<?php echo (int)$ev['id']; ?> — <?php echo e($ev['nombre']); ?> (<?php echo e($ev['slug']); ?>)
          </option>
        <?php endforeach; ?>
      </select>
      <label style="font-size:12px;display:flex;gap:4px;align-items:center;margin-top:6px;">
        <input type="checkbox" name="all_events" value="1"> Asignar a todos los eventos
      </label>
    </div>

    <div>
      <label>Rol en evento</label>
      <select name="rol_evento">
        <?php foreach ($staffRoles as $sr): ?>
          <option value="<?php echo e((string)$sr['code']); ?>" <?php echo ((string)$sr['code'] === 'puerta') ? 'selected' : ''; ?>>
            <?php echo e((string)$sr['name']); ?>
          </option>
        <?php endforeach; ?>
      </select>
    </div>

    <div>
      <button class="btn" type="submit" style="margin-top:2px;">Asignar</button>
    </div>
  </form>

  <script>
    function filterStaffOptions(){
      var q = document.getElementById('filterStaff').value.toLowerCase();
      var sel = document.getElementById('staffSelect');
      for (var i=0; i<sel.options.length; i++) {
        var opt = sel.options[i];
        var name = opt.getAttribute('data-name') || '';
        opt.hidden = (q !== '' && name.indexOf(q) === -1);
      }
      // Si la opción seleccionada quedó oculta, reseteamos
      if (sel.selectedIndex > 0 && sel.options[sel.selectedIndex].hidden) {
        sel.selectedIndex = 0;
      }
    }
  </script>
</div>

<?php if ($prefEventoId <= 0): ?>
<div class="card" style="max-width:900px;">
  <h3>Roles del staff</h3>
  <div class="muted" style="margin-bottom:8px;">Gestioná roles y permisos desde la pantalla dedicada.</div>
  <a class="btn" href="/roles_staff.php">Abrir Roles de Staff</a>
</div>
<?php endif; ?>

<?php if ($prefEventoId > 0): ?>
<div class="card">
  <h3>Staff asignado al evento</h3>

  <?php if ($prefEventoId > 0): ?>
    <div class="muted" style="margin-bottom:8px;">Mostrando staff del evento #<?php echo (int)$prefEventoId; ?>.</div>
  <?php endif; ?>

  <?php if(empty($staffAsignadoEvento)): ?>
    <div style="color:var(--muted);font-size:14px;">No hay staff asignado todavía.</div>
  <?php else: ?>
    <div style="overflow:auto;margin-top:8px;">
      <table class="table">
        <thead>
          <tr>
            <th>Cliente ID</th>
            <th>Tickex ID</th>
            <th>Email</th>
            <th>Rol</th>
          </tr>
        </thead>
        <tbody>
        <?php foreach($staffAsignadoEvento as $s): ?>
          <tr>
            <td>#<?php echo (int)$s['cliente_id']; ?></td>
            <td><?php echo e((!empty($s['apodo']) ? (string)$s['apodo'] : ('#' . (int)$s['cliente_id']))); ?></td>
            <td><?php echo e((string)($s['email'] ?? '')); ?></td>
            <td><?php echo e(tickex_staff_role_label($pdo, $adminId, (string)($s['rol_staff'] ?: 'puerta'))); ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
</div>
<?php endif; ?>

<?php include __DIR__.'/inc/layout_bottom.php'; ?>
