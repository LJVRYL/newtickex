<?php
require_once __DIR__.'/inc/bootstrap.php';
require_once __DIR__ . '/inc/mail.php';
require_once __DIR__ . '/inc/staff_roles.php';
require_once __DIR__ . '/inc/staff_operations.php';
$title = "Mi staff – Administrador";

$cu = current_user();
$tipoGlobal = isset($cu['tipo_global']) ? (string)$cu['tipo_global'] : (isset($cu['rol']) ? (string)$cu['rol'] : '');
$adminContext = isset($_SESSION['auth_context']) && $_SESSION['auth_context'] === 'admin';
if (!$adminContext || !in_array($tipoGlobal, array('admin_evento','super_admin','superadmin'), true)) {
    $next = isset($_SERVER['REQUEST_URI']) ? (string)$_SERVER['REQUEST_URI'] : '/secundarios.php';
    header('Location: /login_admin.php?next=' . urlencode($next), true, 302);
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
tickex_staff_operations_ensure_schema($pdo);
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

function ensure_staff_event_cost_column($pdo) {
  try {
    $cols = array();
    $st = $pdo->query("PRAGMA table_info(staff_eventos)");
    if ($st) {
      foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $ci) {
        if (isset($ci['name'])) $cols[$ci['name']] = true;
      }
    }
    if (!isset($cols['costo_servicio'])) {
      $pdo->exec("ALTER TABLE staff_eventos ADD COLUMN costo_servicio REAL DEFAULT 0");
    }
  } catch (Exception $e) {
    // continuar
  }
}
ensure_staff_event_cost_column($pdo);

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

$blockedPostAction = false;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $requestedAction = isset($_POST['action']) ? (string)$_POST['action'] : '';
  if (in_array($requestedAction, array('delete_staff', 'update_costo', 'assign_staff_event', 'remove_staff_event'), true)) {
    $provided = isset($_POST['csrf']) ? (string)$_POST['csrf'] : '';
    if (function_exists('tickex_csrf_verify') && !tickex_csrf_verify($provided)) {
      flash('err', 'CSRF inválido. Actualizá la página e intentá de nuevo.');
      $blockedPostAction = true;
    }
  }
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
    $mensaje = isset($_POST['mensaje']) ? trim((string)$_POST['mensaje']) : '';
    $rolStaff = isset($_POST['rol_staff']) ? trim((string)$_POST['rol_staff']) : 'puerta';
    if (!isset($staffRolesMap[$rolStaff])) $rolStaff = 'puerta';

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
                'mensaje' => $mensaje,
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
        if ($stUp->rowCount()) tickex_staff_audit($pdo,$adminId,$adminId,'global_role_changed',$clienteId,null,array('role'=>$rolStaff));
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
if (!$blockedPostAction && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete_staff') {
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
if (!$blockedPostAction && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update_costo') {
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

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update_staff_event_cost') {
  $provided = isset($_POST['csrf']) ? (string)$_POST['csrf'] : '';
  if (function_exists('tickex_csrf_verify') && !tickex_csrf_verify($provided)) {
    flash('err', 'CSRF inválido.');
  } else {
    $clienteId = isset($_POST['cliente_id']) ? (int)$_POST['cliente_id'] : 0;
    $eventoIdCost = isset($_POST['evento_id']) ? (int)$_POST['evento_id'] : 0;
    $costo = isset($_POST['costo_servicio']) ? (float)$_POST['costo_servicio'] : 0;
    if ($clienteId <= 0 || $eventoIdCost <= 0) {
      flash('warn', 'Datos inválidos para actualizar costo.');
    } else {
      try {
        $permitido = false;
        if (_tickex_is_super($tipoGlobal)) {
          $permitido = true;
        } else {
          $stOwn = $pdo->prepare('SELECT 1 FROM staff_admins WHERE owner_admin_id = :aid AND cliente_id = :cid AND activo = 1 LIMIT 1');
          $stOwn->execute(array(':aid' => $adminId, ':cid' => $clienteId));
          $permitido = (bool)$stOwn->fetchColumn();
        }

        if (!$permitido) {
          flash('err', 'No tenés permiso para modificar este costo.');
        } else {
          $up = $pdo->prepare('UPDATE staff_eventos SET costo_servicio = :c WHERE staff_id = :sid AND evento_id = :eid');
          $up->execute(array(':c' => $costo, ':sid' => $clienteId, ':eid' => $eventoIdCost));
          flash('ok', 'Costo de staff actualizado para este evento.');
        }
      } catch (Exception $e) {
        flash('err', 'No se pudo actualizar el costo de staff.');
      }
    }
  }
}

  /* =========================================================
     ASIGNAR STAFF EXISTENTE A UN EVENTO (POST)
     ========================================================= */
  if (!$blockedPostAction && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'assign_staff_event') {
    $staffIdAsign = isset($_POST['staff_id']) ? (int)$_POST['staff_id'] : 0;
    $clienteIdAsign = isset($_POST['cliente_id']) ? (int)$_POST['cliente_id'] : 0;
    if ($staffIdAsign <= 0 && $clienteIdAsign > 0) {
      $staffIdAsign = $clienteIdAsign;
    }
    $rolEventoSet = trim(isset($_POST['rol_evento']) ? $_POST['rol_evento'] : 'puerta');
    if (!isset($staffRolesMap[$rolEventoSet])) $rolEventoSet = 'puerta';
    $costoAsign = isset($_POST['costo_servicio']) ? (float)$_POST['costo_servicio'] : 0;
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

            if (!empty($selectedIds)) {
              foreach ($selectedIds as $eid => $v) {
                tickex_staff_assign_event($pdo,$adminId,$adminId,$staffIdAsign,$eid,$rolEventoSet,$costoAsign);
              }
            }

            $upRel = $pdo->prepare('UPDATE staff_admins SET rol_staff = COALESCE(NULLIF(:r,\'\'), rol_staff), activo = 1 WHERE id = :id');
            $upRel->execute(array(':r' => $rolEventoSet, ':id' => (int)$staffRel['id']));

            flash('ok', 'Asignación agregada o actualizada en ' . count($selectedIds) . ' evento(s). Las demás se conservaron.');
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
              $selectedIds = array();
              if ($allEvents) {
                $stmtEvAll = $pdo->query("SELECT id FROM eventos");
                $all = $stmtEvAll ? $stmtEvAll->fetchAll(PDO::FETCH_COLUMN) : array();
                foreach ($all as $eid) { $selectedIds[(int)$eid] = true; }
              }
              foreach (array_keys($eventosAsign) as $eid) { $selectedIds[$eid] = true; }

              if (!empty($selectedIds)) {
                $ins = $pdo->prepare("INSERT OR REPLACE INTO staff_eventos (staff_id, evento_id, costo_servicio, rol_staff) VALUES (:sid, :eid, :c, :rol)");
                foreach ($selectedIds as $eid => $v) {
                  $ins->execute(array(':sid'=>$staffIdAsign, ':eid'=>$eid, ':c'=>$costoAsign, ':rol'=>$rolEventoSet));
                }
                $first = array_key_first($selectedIds);
                $upd = $pdo->prepare("UPDATE usuarios_admin SET evento_id = :eid, rol_evento = :rol, activo = 1 WHERE id = :id");
                $upd->execute(array(
                  ':eid' => $first,
                  ':rol' => $rolEventoSet !== '' ? $rolEventoSet : 'puerta',
                  ':id'  => $staffIdAsign,
                ));
              }

              flash('ok', 'Asignación agregada o actualizada en '.count($selectedIds).' evento(s).');
            } catch (Exception $e) {
              flash('err', 'Error al asignar: '.$e->getMessage());
            }
          }
        }
      }
    }
  }

if (!$blockedPostAction && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'remove_staff_event') {
  $provided = isset($_POST['csrf']) ? (string)$_POST['csrf'] : '';
  $staffRemove = isset($_POST['cliente_id']) ? (int)$_POST['cliente_id'] : 0;
  $eventRemove = isset($_POST['evento_id']) ? (int)$_POST['evento_id'] : 0;
  if (!tickex_csrf_verify($provided)) {
    flash('err','La sesión venció. Recargá la página.');
  } elseif ($staffRemove <= 0 || $eventRemove <= 0) {
    flash('warn','Asignación inválida.');
  } else {
    try {
      if (tickex_staff_remove_event($pdo,$adminId,$adminId,$staffRemove,$eventRemove)) flash('ok','Asignación quitada. Las demás se conservaron.');
      else flash('warn','La asignación ya no existía.');
    } catch (Exception $e) { flash('err','No se pudo quitar la asignación.'); }
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

// ===== Equipo aceptado, invitaciones y asignaciones =====
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
  $stP = $pdo->prepare("SELECT id, email, mensaje, rol_staff, created_at
    FROM staff_admin_invitaciones
    WHERE owner_admin_id = :aid AND estado = 'pending'
    ORDER BY id DESC
    LIMIT 100");
  $stP->execute(array(':aid' => $adminId));
  $staffPendientes = $stP->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
  $staffPendientes = array();
}

$eventosMap = array();
foreach ($eventos as $eventRow) {
  $eventosMap[(int)$eventRow['id']] = $eventRow;
}
if ($prefEventoId > 0 && !isset($eventosMap[$prefEventoId])) {
  http_response_code(404);
  if (function_exists('abort_404')) abort_404('Evento no encontrado o sin permiso.');
  echo 'Evento no encontrado o sin permiso.';
  exit;
}
$staffAssignments = array();
$totalAssignments = 0;
$rolesInUse = array();
foreach ($staffNuevo as $staffMember) {
  $staffId = (int)$staffMember['cliente_id'];
  $staffAssignments[$staffId] = array();
  if (!empty($staffMember['rol_staff'])) $rolesInUse[(string)$staffMember['rol_staff']] = true;
}
if (!empty($staffAssignments)) {
  try {
    $staffIds = array_keys($staffAssignments);
    $placeholders = implode(',', array_fill(0, count($staffIds), '?'));
    $stAssignments = $pdo->prepare('SELECT staff_id, evento_id, COALESCE(costo_servicio,0) AS costo_servicio, rol_staff FROM staff_eventos WHERE staff_id IN (' . $placeholders . ') ORDER BY id DESC');
    $stAssignments->execute($staffIds);
    foreach ($stAssignments->fetchAll(PDO::FETCH_ASSOC) as $assignment) {
      $staffId = (int)$assignment['staff_id'];
      $eventId = (int)$assignment['evento_id'];
      if (!isset($staffAssignments[$staffId]) || !isset($eventosMap[$eventId])) continue;
      $assignment['evento_nombre'] = $eventosMap[$eventId]['nombre'];
      $assignment['evento_slug'] = $eventosMap[$eventId]['slug'];
      $staffAssignments[$staffId][] = $assignment;
      $totalAssignments++;
    }
  } catch (Exception $e) {
    $staffAssignments = array();
  }
}

$prefEventoNombre = '';
if ($prefEventoId > 0 && isset($eventosMap[$prefEventoId])) {
  $prefEventoNombre = (string)$eventosMap[$prefEventoId]['nombre'];
}

include __DIR__.'/inc/layout_top.php';
?>

<style>
  .staff-hero{position:relative;overflow:hidden;padding:28px;background:radial-gradient(circle at 88% 18%,rgba(43,213,164,.17),transparent 29%),linear-gradient(135deg,rgba(24,77,82,.78),rgba(30,27,77,.95))}
  .staff-hero:after{content:"";position:absolute;width:300px;height:300px;right:-105px;bottom:-205px;border:1px solid rgba(88,226,192,.22);border-radius:50%}
  .staff-eyebrow{color:#54e3c1;font-size:11px;font-weight:800;letter-spacing:.13em;text-transform:uppercase}.staff-hero h1{margin:7px 0 6px;font-size:clamp(28px,4vw,44px)}.staff-hero p{margin:0;color:var(--muted);max-width:700px}
  .staff-toolbar{position:relative;z-index:1;display:flex;justify-content:space-between;align-items:flex-end;gap:18px;flex-wrap:wrap}.staff-actions{display:flex;gap:8px;flex-wrap:wrap}
  .staff-stats{position:relative;z-index:1;display:grid;grid-template-columns:repeat(4,minmax(110px,1fr));gap:10px;margin-top:24px}.staff-stat{padding:13px 15px;border:1px solid rgba(255,255,255,.08);border-radius:14px;background:rgba(6,10,22,.38)}.staff-stat span{display:block;color:var(--muted);font-size:11px;font-weight:750;text-transform:uppercase;letter-spacing:.06em}.staff-stat strong{display:block;margin-top:3px;font-size:22px}
  .staff-section{padding:0}.staff-section>summary{list-style:none;cursor:pointer;display:flex;justify-content:space-between;align-items:center;gap:16px;padding:20px 22px}.staff-section>summary::-webkit-details-marker{display:none}.staff-section>summary:after{content:"+";display:grid;place-items:center;width:32px;height:32px;flex:0 0 auto;border:1px solid var(--line);border-radius:10px;color:#62dfc2;font-size:22px}.staff-section[open]>summary:after{content:"−"}.staff-section-title{font-size:18px;font-weight:800}.staff-section-subtitle{margin-top:3px;color:var(--muted);font-size:13px}.staff-section-body{border-top:1px solid var(--line);padding:22px}
  .staff-form-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:13px}.staff-form-field.full{grid-column:1/-1}.staff-form-field label,.staff-assign-field label{display:block;margin-bottom:6px;font-size:12px;font-weight:750}.staff-form-field input,.staff-form-field select,.staff-form-field textarea,.staff-assign-field input,.staff-assign-field select{width:100%}.staff-form-hint{margin-top:5px;color:var(--muted);font-size:11px;line-height:1.45}.staff-form-actions{display:flex;justify-content:flex-end;gap:8px;grid-column:1/-1;padding-top:5px}
  .staff-list-head{display:flex;justify-content:space-between;align-items:flex-end;gap:14px;flex-wrap:wrap;margin-bottom:14px}.staff-list-head h2{margin:0}.staff-list-head p{margin:4px 0 0;color:var(--muted);font-size:13px}.staff-member-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(285px,1fr));gap:12px}
  .staff-member{padding:17px;border:1px solid var(--line);border-radius:17px;background:linear-gradient(145deg,rgba(18,26,43,.82),rgba(10,15,29,.78));min-width:0}.staff-member-head{display:flex;gap:12px;align-items:center}.staff-avatar{width:48px;height:48px;flex:0 0 auto;display:grid;place-items:center;border-radius:14px;background:linear-gradient(135deg,#6f4cf4,#238f8b);color:#fff;font-size:17px;font-weight:850}.staff-member-name{font-weight:800;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}.staff-member-email{margin-top:2px;color:var(--muted);font-size:12px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
  .staff-badges{display:flex;gap:6px;flex-wrap:wrap;margin-top:13px}.staff-badge{display:inline-flex;align-items:center;gap:5px;padding:5px 8px;border:1px solid var(--line);border-radius:999px;background:var(--panel-2);font-size:11px;font-weight:750}.staff-badge.active{color:var(--ok)}.staff-badge.pending{color:var(--warn)}.staff-events{margin-top:13px;padding-top:13px;border-top:1px solid var(--line)}.staff-events-label{margin-bottom:7px;color:var(--muted);font-size:10px;font-weight:800;letter-spacing:.08em;text-transform:uppercase}.staff-event-chips{display:flex;gap:6px;flex-wrap:wrap}.staff-event-chip{appearance:none;padding:6px 9px;border:1px solid rgba(145,126,255,.28);border-radius:8px;background:rgba(90,72,168,.22);color:#e9e5ff;font:inherit;font-size:11px;font-weight:750;line-height:1.25;cursor:pointer}.staff-event-chip:hover{border-color:rgba(145,126,255,.55);background:rgba(104,83,194,.32);color:#fff}.staff-event-empty{font-size:12px;color:var(--muted)}
  .staff-member-controls{display:grid;grid-template-columns:1fr auto;gap:7px;align-items:end;margin-top:14px}.staff-member-controls label{display:block;margin-bottom:5px;color:var(--muted);font-size:10px;font-weight:800;text-transform:uppercase;letter-spacing:.06em}.staff-member-controls select{width:100%}.staff-member-controls form{margin:0}.staff-pending-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(245px,1fr));gap:10px}.staff-pending-card{padding:14px;border:1px solid var(--line);border-radius:14px;background:rgba(10,16,30,.48)}.staff-pending-card strong{display:block;overflow:hidden;text-overflow:ellipsis}.staff-pending-meta{display:flex;justify-content:space-between;gap:10px;margin-top:8px;color:var(--muted);font-size:11px}.staff-pending-message{margin-top:8px;font-size:12px;color:var(--muted)}
  .staff-assignment-grid{display:grid;grid-template-columns:1.2fr 1fr .8fr .8fr;gap:12px;align-items:end}.staff-check{display:flex!important;gap:7px;align-items:center;margin-top:8px!important;font-weight:600!important}.staff-check input{width:auto}.staff-model{display:flex;justify-content:space-between;align-items:center;gap:18px;flex-wrap:wrap}.staff-model h2{margin:0 0 5px}.staff-model p{margin:0;color:var(--muted);font-size:13px;max-width:720px}
  @media(max-width:900px){.staff-assignment-grid{grid-template-columns:repeat(2,minmax(0,1fr))}}@media(max-width:720px){.staff-hero{padding:22px}.staff-stats{grid-template-columns:repeat(2,minmax(0,1fr))}.staff-actions,.staff-actions .btn,.staff-form-actions .btn{width:100%}.staff-actions .btn,.staff-form-actions .btn{text-align:center}.staff-form-grid,.staff-assignment-grid{grid-template-columns:1fr}.staff-form-field.full,.staff-form-actions{grid-column:auto}.staff-member-controls{grid-template-columns:1fr}.staff-member-controls .btn{width:100%}}
</style>

<div class="card staff-hero">
  <div class="staff-toolbar"><div><div class="staff-eyebrow"><?php echo $prefEventoId > 0 ? 'Equipo del evento' : 'Centro de equipo'; ?></div><h1><?php echo $prefEventoNombre !== '' ? 'Staff de ' . e($prefEventoNombre) : 'Mi staff'; ?></h1><p>Invitá personas, definí qué pueden hacer y organizá su participación en cada evento.</p></div><div class="staff-actions"><a class="btn secondary" href="panel_admin.php">Volver al panel</a><?php if($prefEventoId>0): ?><a class="btn secondary" href="staff_operaciones.php?evento_id=<?php echo (int)$prefEventoId; ?>">Operaciones</a><a class="btn secondary" href="secundarios.php">Ver todo el equipo</a><?php endif; ?><a class="btn" href="#invitar-staff">Invitar persona</a></div></div>
  <div class="staff-stats"><div class="staff-stat"><span>Equipo activo</span><strong><?php echo count($staffNuevo); ?></strong></div><div class="staff-stat"><span>Invitaciones</span><strong><?php echo count($staffPendientes); ?></strong></div><div class="staff-stat"><span>Asignaciones</span><strong><?php echo (int)$totalAssignments; ?></strong></div><div class="staff-stat"><span>Roles en uso</span><strong><?php echo count($rolesInUse); ?></strong></div></div>
</div>

<details class="card staff-section" id="invitar-staff"<?php echo empty($staffNuevo)?' open':''; ?>><summary><div><div class="staff-section-title">Invitar al equipo</div><div class="staff-section-subtitle">La persona debe aceptar la invitación antes de acceder.</div></div></summary><div class="staff-section-body">
  <form method="post" class="staff-form-grid"><input type="hidden" name="csrf" value="<?php echo e(tickex_csrf_token()); ?>"><input type="hidden" name="action" value="staff_invite">
    <div class="staff-form-field"><label for="staff_email">Email</label><input type="email" id="staff_email" name="email" placeholder="persona@email.com"><div class="staff-form-hint">Usalo si todavía no tiene Tickex ID.</div></div>
    <div class="staff-form-field"><label for="staff_tickex_id">Tickex ID</label><input type="text" id="staff_tickex_id" name="tickex_id" placeholder="Ej: Senchi o #123"><div class="staff-form-hint">Con uno de los dos datos alcanza.</div></div>
    <div class="staff-form-field"><label for="invite_role">Rol inicial</label><select id="invite_role" name="rol_staff"><?php foreach($staffRoles as $role): ?><option value="<?php echo e((string)$role['code']); ?>"<?php echo (string)$role['code']==='puerta'?' selected':''; ?>><?php echo e((string)$role['name']); ?></option><?php endforeach; ?></select></div>
    <div class="staff-form-field"><label for="invite_message">Mensaje</label><input type="text" id="invite_message" name="mensaje" maxlength="300" placeholder="Ej: Te sumamos al equipo de puerta"></div>
    <div class="staff-form-actions"><button class="btn" type="submit">Enviar invitación</button></div>
  </form>
</div></details>

<?php
$staffAsignadoEvento = array();
if ($prefEventoId > 0) {
  try {
    $stAE = $pdo->prepare("SELECT DISTINCT se.staff_id AS cliente_id,
        rp.apodo, rp.email, rp.nombre, rp.apellido,
        COALESCE(NULLIF(se.rol_staff,''),sa.rol_staff) AS rol_staff,
        se.evento_id,
        COALESCE(se.costo_servicio, 0) AS costo_servicio
      FROM staff_eventos se
      LEFT JOIN registro_pendientes rp ON rp.id = se.staff_id
      LEFT JOIN staff_admins sa ON sa.cliente_id = se.staff_id AND sa.owner_admin_id = :aid AND sa.activo = 1
      WHERE se.evento_id = :eid AND sa.id IS NOT NULL
      ORDER BY se.staff_id DESC");
    $stAE->execute(array(':eid' => $prefEventoId, ':aid' => $adminId));
    $staffAsignadoEvento = $stAE->fetchAll(PDO::FETCH_ASSOC);
  } catch (Exception $e) {
    $staffAsignadoEvento = array();
  }
}
?>

<div class="card">
  <div class="staff-list-head"><div><h2><?php echo $prefEventoId>0?'Equipo disponible':'Personas del equipo'; ?></h2><p>Revisá roles, eventos asignados y antigüedad de cada integrante.</p></div><span class="staff-badge active"><?php echo count($staffNuevo); ?> activos</span></div>
  <?php if(empty($staffNuevo)): ?><div class="muted">Todavía no hay personas activas. Empezá enviando una invitación.</div><?php else: ?>
  <div class="staff-member-grid">
    <?php foreach($staffNuevo as $member): ?>
      <?php $memberId=(int)$member['cliente_id'];$memberName=!empty($member['apodo'])?(string)$member['apodo']:trim((string)$member['nombre'].' '.(string)$member['apellido']);if($memberName==='')$memberName=!empty($member['email'])?(string)$member['email']:('#'.$memberId);$initial=function_exists('mb_substr')?mb_substr($memberName,0,1,'UTF-8'):substr($memberName,0,1);$memberEvents=isset($staffAssignments[$memberId])?$staffAssignments[$memberId]:array();$memberRole=!empty($member['rol_staff'])?(string)$member['rol_staff']:'puerta'; ?>
      <article class="staff-member">
        <div class="staff-member-head"><div class="staff-avatar"><?php echo e(strtoupper($initial)); ?></div><div style="min-width:0;flex:1;"><div class="staff-member-name"><?php echo e($memberName); ?></div><div class="staff-member-email"><?php echo e((string)$member['email']); ?></div></div></div>
        <div class="staff-badges"><span class="staff-badge active">Activo</span><span class="staff-badge"><?php echo e(tickex_staff_role_label($pdo,$adminId,$memberRole)); ?></span><span class="staff-badge"><?php echo count($memberEvents); ?> evento<?php echo count($memberEvents)===1?'':'s'; ?></span></div>
        <div class="staff-events"><div class="staff-events-label">Eventos asignados</div><div class="staff-event-chips"><?php if(empty($memberEvents)): ?><span class="staff-event-empty">Sin eventos asignados</span><?php else: ?><?php foreach($memberEvents as $memberEvent): ?><form method="post" style="display:inline-flex;margin:0;" onsubmit="return confirm('¿Quitar esta asignación?');"><input type="hidden" name="csrf" value="<?php echo e(tickex_csrf_token()); ?>"><input type="hidden" name="action" value="remove_staff_event"><input type="hidden" name="cliente_id" value="<?php echo $memberId; ?>"><input type="hidden" name="evento_id" value="<?php echo (int)$memberEvent['evento_id']; ?>"><button class="staff-event-chip" type="submit" title="Quitar asignación"><?php echo e((string)$memberEvent['evento_nombre']); ?> · <?php echo e(tickex_staff_role_label($pdo,$adminId,(string)($memberEvent['rol_staff']?:$memberRole))); ?> ×</button></form><?php endforeach; ?><?php endif; ?></div></div>
        <div class="staff-member-controls">
          <form method="post" style="display:flex;gap:7px;align-items:end;"><input type="hidden" name="csrf" value="<?php echo e(tickex_csrf_token()); ?>"><input type="hidden" name="action" value="update_staff_role"><input type="hidden" name="cliente_id" value="<?php echo $memberId; ?>"><div style="flex:1;"><label>Rol general</label><select name="rol_staff"><?php foreach($staffRoles as $role): ?><option value="<?php echo e((string)$role['code']); ?>"<?php echo (string)$role['code']===$memberRole?' selected':''; ?>><?php echo e((string)$role['name']); ?></option><?php endforeach; ?></select></div><button class="btn secondary" type="submit">Actualizar</button></form>
          <form method="post" onsubmit="return confirm('¿Quitar este usuario del staff?');"><input type="hidden" name="csrf" value="<?php echo e(tickex_csrf_token()); ?>"><input type="hidden" name="action" value="remove_staff_link"><input type="hidden" name="cliente_id" value="<?php echo $memberId; ?>"><button class="btn danger" type="submit">Quitar</button></form>
        </div>
        <div class="staff-form-hint" style="margin-top:10px;">En el equipo desde <?php echo e((string)$member['created_at']); ?></div>
      </article>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>
</div>

<?php if ($prefEventoId <= 0): ?>
<details class="card staff-section"<?php echo !empty($staffPendientes)?' open':''; ?>><summary><div><div class="staff-section-title">Invitaciones pendientes</div><div class="staff-section-subtitle"><?php echo count($staffPendientes); ?> personas todavía deben aceptar.</div></div></summary><div class="staff-section-body">
  <?php if(empty($staffPendientes)): ?><div class="muted">No hay invitaciones pendientes.</div><?php else: ?><div class="staff-pending-grid">
    <?php foreach($staffPendientes as $pending): ?><div class="staff-pending-card"><strong><?php echo e((string)$pending['email']); ?></strong><div class="staff-pending-meta"><span><?php echo e(tickex_staff_role_label($pdo,$adminId,(string)($pending['rol_staff'] ?: 'puerta'))); ?></span><span><?php echo e((string)$pending['created_at']); ?></span></div><?php if(!empty($pending['mensaje'])): ?><div class="staff-pending-message"><?php echo e((string)$pending['mensaje']); ?></div><?php endif; ?></div><?php endforeach; ?>
  </div><?php endif; ?>
</div></details>
<?php endif; ?>

<details class="card staff-section" id="asignar-staff"<?php echo $prefEventoId>0?' open':''; ?>><summary><div><div class="staff-section-title">Asignar eventos y costos</div><div class="staff-section-subtitle">Elegí dónde trabaja cada persona y registrá el costo operativo.</div></div></summary><div class="staff-section-body">
  <form method="post" class="staff-assignment-grid">
    <input type="hidden" name="csrf" value="<?php echo e(tickex_csrf_token()); ?>">
    <input type="hidden" name="action" value="assign_staff_event">

    <div class="staff-assign-field">
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

    <div class="staff-assign-field">
      <label>Asignar a evento</label>
      <select name="evento_id" required>
        <option value="">Elegí un evento...</option>
        <?php foreach($eventos as $ev): ?>
          <option value="<?php echo (int)$ev['id']; ?>" <?php echo ($prefEventoId === (int)$ev['id']) ? 'selected' : ''; ?>>
            #<?php echo (int)$ev['id']; ?> — <?php echo e($ev['nombre']); ?> (<?php echo e($ev['slug']); ?>)
          </option>
        <?php endforeach; ?>
      </select>
      <label class="staff-check">
        <input type="checkbox" name="all_events" value="1"> Todos mis eventos
      </label>
    </div>

    <div class="staff-assign-field">
      <label>Rol en evento</label>
      <select name="rol_evento">
        <?php foreach ($staffRoles as $sr): ?>
          <option value="<?php echo e((string)$sr['code']); ?>" <?php echo ((string)$sr['code'] === 'puerta') ? 'selected' : ''; ?>>
            <?php echo e((string)$sr['name']); ?>
          </option>
        <?php endforeach; ?>
      </select>
    </div>

    <div class="staff-assign-field">
      <label>Costo staff ($)</label>
      <input type="number" name="costo_servicio" min="0" step="0.01" value="0" placeholder="0.00">
    </div>

    <div class="staff-form-hint" style="grid-column:1/-1;">La asignación se agrega o actualiza. Los demás eventos de esta persona se conservan.</div>
    <div class="staff-form-actions">
      <button class="btn" type="submit"<?php echo empty($staffNuevo)||empty($eventos)?' disabled':''; ?>>Guardar asignación</button>
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
</div></details>

<?php if ($prefEventoId <= 0): ?>
<div class="card staff-model">
  <div><div class="staff-eyebrow">Organización</div><h2>Roles y revendedores</h2><p>Los roles definen permisos generales. Los eventos y costos se asignan por separado. Los revendedores forman parte del equipo comercial, pero conservan su circuito de ventas y comisiones.</p></div>
  <div class="staff-actions"><a class="btn" href="roles_staff.php">Configurar roles</a><a class="btn secondary" href="<?php echo _tickex_is_super($tipoGlobal)?'superadmin_revendedores.php':'admin_revendedores.php'; ?>">Ver revendedores</a></div>
</div>
<?php endif; ?>

<?php if ($prefEventoId > 0): ?>
<div class="card">
  <div class="staff-list-head"><div><h2>Equipo asignado</h2><p>Personas con acceso a <?php echo e($prefEventoNombre); ?> y costo registrado para este evento.</p></div><span class="staff-badge active"><?php echo count($staffAsignadoEvento); ?> asignados</span></div>
  <?php if(empty($staffAsignadoEvento)): ?><div class="muted">Todavía no asignaste staff a este evento.</div><?php else: ?><div class="staff-pending-grid">
    <?php foreach($staffAsignadoEvento as $assigned): ?>
      <?php $assignedName=!empty($assigned['apodo'])?(string)$assigned['apodo']:trim((string)$assigned['nombre'].' '.(string)$assigned['apellido']);if($assignedName==='')$assignedName=(string)$assigned['email']; ?>
      <div class="staff-pending-card"><strong><?php echo e($assignedName); ?></strong><div class="staff-pending-message"><?php echo e(tickex_staff_role_label($pdo,$adminId,(string)($assigned['rol_staff'] ?: 'puerta'))); ?> · <?php echo e((string)$assigned['email']); ?></div>
        <form method="post" style="display:flex;gap:7px;align-items:end;margin-top:12px;"><input type="hidden" name="csrf" value="<?php echo e(tickex_csrf_token()); ?>"><input type="hidden" name="action" value="update_staff_event_cost"><input type="hidden" name="cliente_id" value="<?php echo (int)$assigned['cliente_id']; ?>"><input type="hidden" name="evento_id" value="<?php echo (int)$prefEventoId; ?>"><label style="flex:1;font-size:11px;color:var(--muted);">Costo en este evento<input type="number" name="costo_servicio" min="0" step="0.01" value="<?php echo e(number_format((float)$assigned['costo_servicio'],2,'.','')); ?>" style="width:100%;margin-top:5px;"></label><button class="btn secondary" type="submit">Guardar</button></form>
        <form method="post" style="margin-top:8px;" onsubmit="return confirm('¿Quitar a esta persona del evento?');"><input type="hidden" name="csrf" value="<?php echo e(tickex_csrf_token()); ?>"><input type="hidden" name="action" value="remove_staff_event"><input type="hidden" name="cliente_id" value="<?php echo (int)$assigned['cliente_id']; ?>"><input type="hidden" name="evento_id" value="<?php echo (int)$prefEventoId; ?>"><button class="btn danger" type="submit">Quitar del evento</button></form>
      </div>
    <?php endforeach; ?>
  </div><?php endif; ?>
</div>
<?php endif; ?>

<?php include __DIR__.'/inc/layout_bottom.php'; ?>
