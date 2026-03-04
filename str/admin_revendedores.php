<?php
require_once __DIR__ . '/inc/bootstrap.php';
require_once __DIR__ . '/inc/mail.php';

require_login();
$cu = current_user();
$tipoGlobal = isset($cu['tipo_global']) ? $cu['tipo_global'] : (isset($_SESSION['tipo_global']) ? $_SESSION['tipo_global'] : '');
$esAdmin = is_admin();

if (!$esAdmin || !in_array($tipoGlobal, array('admin_evento', 'super_admin', 'superadmin'), true)) {
  http_response_code(403);
  include __DIR__ . '/inc/layout_top.php';
  echo '<div class="card" style="max-width:640px;margin:32px auto;"><h2>Acceso restringido</h2><p>Solo para administradores de evento.</p></div>';
  include __DIR__ . '/inc/layout_bottom.php';
  exit;
}

$pdo = db();
$title = 'Mis revendedores';
$csrf = function_exists('tickex_csrf_token') ? tickex_csrf_token() : '';

$adminId = 0;
foreach (array('user_id','admin_id') as $k) {
  if (isset($_SESSION[$k]) && (int)$_SESSION[$k] > 0) { $adminId = (int)$_SESSION[$k]; break; }
}
if ($adminId <= 0 && isset($cu['id'])) $adminId = (int)$cu['id'];

if ($tipoGlobal === 'admin_evento' && $adminId <= 0) {
  http_response_code(500);
  include __DIR__ . '/inc/layout_top.php';
  echo '<div class="card" style="max-width:640px;margin:32px auto;"><h2>Error</h2><p>No se pudo determinar tu ID de admin.</p></div>';
  include __DIR__ . '/inc/layout_bottom.php';
  exit;
}

function _rev_codigo_is_valid($codigo) {
  if ($codigo === '') return true;
  if (strlen($codigo) > 64) return false;
  return (bool)preg_match('/^[a-zA-Z0-9_-]+$/', $codigo);
}

function _tickex_resolve_cliente_id($pdo, $raw, &$err)
{
  $err = '';
  $s = trim((string)$raw);
  if ($s === '') { $err = 'Ingresá un Tickex ID de cliente (ej: Senchi o #123).'; return 0; }

  // #123 o 123
  $s2 = $s;
  if ($s2 !== '' && $s2[0] === '#') $s2 = substr($s2, 1);
  if ($s2 !== '' && ctype_digit($s2)) {
    $id = (int)$s2;
    if ($id <= 0) { $err = 'Cliente inválido.'; return 0; }
    try {
      $st = $pdo->prepare('SELECT 1 FROM registro_pendientes WHERE id = :id LIMIT 1');
      $st->execute(array(':id' => $id));
      if ($st->fetchColumn()) return $id;
    } catch (Exception $e) {}
    $err = 'No encontramos un cliente con ese ID.';
    return 0;
  }

  // apodo (Tickex ID)
  if (strlen($s) > 64 || !preg_match('/^[a-zA-Z0-9_-]+$/', $s)) {
    $err = 'El Tickex ID solo puede tener letras, números, _ y - (sin espacios).';
    return 0;
  }
  try {
    $st = $pdo->prepare('SELECT id FROM registro_pendientes WHERE lower(apodo) = lower(:ap) LIMIT 1');
    $st->execute(array(':ap' => $s));
    $id = (int)$st->fetchColumn();
    if ($id > 0) return $id;
  } catch (Exception $e) {}
  $err = 'No encontramos un cliente con ese Tickex ID.';
  return 0;
}

function _tickex_cliente_tickex_id($pdo, $clienteId)
{
  $cid = (int)$clienteId;
  if ($cid <= 0) return '';
  try {
    $st = $pdo->prepare('SELECT apodo FROM registro_pendientes WHERE id = :id LIMIT 1');
    $st->execute(array(':id' => $cid));
    $ap = (string)$st->fetchColumn();
    return trim($ap);
  } catch (Exception $e) {}
  return '';
}

$flashErr = '';
$flashOk = '';

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

// --- POST actions ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $provided = isset($_POST['csrf']) ? (string)$_POST['csrf'] : '';
  if (function_exists('tickex_csrf_verify') && !tickex_csrf_verify($provided)) {
    http_response_code(403);
    include __DIR__ . '/inc/layout_top.php';
    echo '<div class="card" style="max-width:640px;margin:32px auto;"><h2>CSRF inválido</h2><p>Actualizá la página e intentá de nuevo.</p></div>';
    include __DIR__ . '/inc/layout_bottom.php';
    exit;
  }

  $action = isset($_POST['action']) ? (string)$_POST['action'] : '';

  if ($action === 'staff_invite') {
    $email = isset($_POST['email']) ? trim((string)$_POST['email']) : '';
    $nombre = isset($_POST['nombre']) ? trim((string)$_POST['nombre']) : '';
    $apellido = isset($_POST['apellido']) ? trim((string)$_POST['apellido']) : '';
    $mensaje = isset($_POST['mensaje']) ? trim((string)$_POST['mensaje']) : '';
    $rolStaff = 'staff_evento';

    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
      $flashErr = 'Ingresá un email válido.';
    } else {
      try {
        if (strlen($mensaje) > 500) {
          $flashErr = 'El mensaje es demasiado largo (máx 500).';
        } else {
          // Evitar duplicados pendientes
          $stDup = $pdo->prepare("SELECT 1 FROM staff_admin_invitaciones WHERE owner_admin_id = :oid AND lower(email) = lower(:e) AND estado = 'pending' LIMIT 1");
          $stDup->execute(array(':oid' => $adminId, ':e' => $email));
          if ($stDup->fetchColumn()) {
            $flashErr = 'Ya existe una invitación de staff pendiente para ese email.';
          } else {
            $inviteToken = _tickex_random_token(16);
            $base = _tickex_base_url();
            $perfilUrl = ($base !== '' ? $base : '') . '/panel_usuario_mi_perfil.php?staff_invite=' . urlencode($inviteToken);

            // Si el email no tiene cuenta de cliente (o no está completada), generamos/actualizamos registro_pendientes
            $stRp = $pdo->prepare('SELECT id, completado_en, password_hash FROM registro_pendientes WHERE lower(email) = lower(:e) ORDER BY id DESC LIMIT 1');
            $stRp->execute(array(':e' => $email));
            $rp = $stRp->fetch(PDO::FETCH_ASSOC);

            $clienteId = 0;
            $needsReg = true;
            $regToken = '';
            if ($rp) {
              $clienteId = (int)$rp['id'];
              $ph = isset($rp['password_hash']) ? (string)$rp['password_hash'] : '';
              $ce = isset($rp['completado_en']) ? (string)$rp['completado_en'] : '';
              if ($ph !== '' || $ce !== '') {
                $needsReg = false;
              }
            }

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
              ':m' => $mensaje,
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
                'body' => "Hola,\n\nTe invitaron a ser parte del staff.\n\nSi todavía no tenés cuenta, completá el registro desde este link:\n{{register_url}}\n\nSi ya tenés cuenta, ingresá y aceptá la invitación desde tu perfil:\n{{perfil_url}}\n\nMensaje:\n{{mensaje}}\n\nTickex\n",
                'from_email' => 'no-reply@tickex.com.ar',
                'from_name' => 'Tickex',
                'reply_to' => 'no-reply@tickex.com.ar',
                'extra_params' => '-f no-reply@tickex.com.ar',
                'is_html' => 0,
              )
            );

            $flashOk = $okMail ? 'Invitación de staff enviada por email (requiere aceptación).' : 'Invitación registrada, pero no se pudo enviar el email (revisar mail logs).';
          }
        }
      } catch (Exception $e) {
        $flashErr = 'No se pudo crear/enviar la invitación.';
      }
    }
  }

  if ($action === 'withdraw_decision') {
    $wid = isset($_POST['withdraw_id']) ? (int)$_POST['withdraw_id'] : 0;
    $decision = isset($_POST['decision']) ? (string)$_POST['decision'] : '';
    if ($wid > 0 && ($decision === 'paid' || $decision === 'reject')) {
      try {
        $stW = $pdo->prepare("SELECT * FROM revendedor_retiros WHERE id = :id AND estado = 'pending' LIMIT 1");
        $stW->execute(array(':id' => $wid));
        $wrow = $stW->fetch(PDO::FETCH_ASSOC);

        if (!$wrow) {
          $flashErr = 'Retiro inexistente.';
        } elseif ($tipoGlobal === 'admin_evento' && isset($wrow['owner_admin_id']) && (int)$wrow['owner_admin_id'] !== $adminId) {
          $flashErr = 'No autorizado.';
        } else {
          $newState = ($decision === 'paid') ? 'paid' : 'rejected';
          $stU = $pdo->prepare("UPDATE revendedor_retiros SET estado = :st, updated_at = datetime('now') WHERE id = :id");
          $stU->execute(array(':st' => $newState, ':id' => $wid));
          header('Location: admin_revendedores.php');
          exit;
        }
      } catch (Exception $e) {
        $flashErr = 'No se pudo actualizar el retiro.';
      }
    }
  }

  if ($action === 'delete') {
    $id = isset($_POST['id']) ? (int)$_POST['id'] : 0;
    if ($id > 0) {
      try {
        // seguridad: un admin_evento solo borra sus revendedores
        $where = 'id = :id';
        $params = array(':id' => $id);
        if ($tipoGlobal === 'admin_evento') {
          $where .= ' AND owner_admin_id = :oid';
          $params[':oid'] = $adminId;
        }

        // No permitir borrar si tiene órdenes asociadas
        $stChk = $pdo->prepare('SELECT 1 FROM tc_orders WHERE revendedor_id = :rid LIMIT 1');
        $stChk->execute(array(':rid' => $id));
        if ($stChk->fetchColumn()) {
          $flashErr = 'No se puede eliminar: tiene ventas asociadas. Desactivá el revendedor.';
        } else {
          $stDel = $pdo->prepare("DELETE FROM revendedores WHERE $where");
          $stDel->execute($params);
          header('Location: admin_revendedores.php');
          exit;
        }
      } catch (Exception $e) {
        $flashErr = 'No se pudo eliminar.';
      }
    }
  }

  if ($action === 'invite') {
    $raw = isset($_POST['cliente_tickex_id']) ? (string)$_POST['cliente_tickex_id'] : '';
    $mensaje = isset($_POST['mensaje']) ? trim((string)$_POST['mensaje']) : '';
    $tmpErr = '';
    $clienteId = _tickex_resolve_cliente_id($pdo, $raw, $tmpErr);
    if ($clienteId <= 0) {
      $flashErr = ($tmpErr !== '' ? $tmpErr : 'Cliente inválido.');
    } elseif (strlen($mensaje) > 500) {
      $flashErr = 'El mensaje es demasiado largo (máx 500).';
    } else {
      try {
        // Evitar duplicados pendientes
        $stDup = $pdo->prepare("SELECT 1 FROM revendedor_solicitudes WHERE cliente_id = :cid AND owner_admin_id = :oid AND estado = 'pending' AND direction = 'admin_to_client' LIMIT 1");
        $stDup->execute(array(':cid' => $clienteId, ':oid' => $adminId));
        if ($stDup->fetchColumn()) {
          $flashErr = 'Ya existe una invitación pendiente para ese cliente.';
        } else {
          $stEmail = $pdo->prepare('SELECT email FROM registro_pendientes WHERE id = :id LIMIT 1');
          $stEmail->execute(array(':id' => $clienteId));
          $clienteEmail = $stEmail->fetchColumn();

          $stIns = $pdo->prepare("INSERT INTO revendedor_solicitudes (cliente_id, cliente_email, evento_id, owner_admin_id, mensaje, estado, direction) VALUES (:cid,:ce,NULL,:oid,:m,'pending','admin_to_client')");
          $stIns->execute(array(
            ':cid' => $clienteId,
            ':ce'  => ($clienteEmail ? (string)$clienteEmail : null),
            ':oid' => $adminId,
            ':m'   => ($mensaje !== '' ? $mensaje : null),
          ));
          $flashOk = 'Invitación enviada. El cliente la verá en su perfil y podrá aceptar/rechazar.';
        }
      } catch (Exception $e) {
        $flashErr = 'No se pudo enviar la invitación.';
      }
    }
  }

  if ($action === 'save') {
    $id = isset($_POST['id']) ? (int)$_POST['id'] : 0;
    $codigo = isset($_POST['codigo']) ? trim((string)$_POST['codigo']) : '';
    $nombre = isset($_POST['nombre']) ? trim((string)$_POST['nombre']) : '';
    $comision = isset($_POST['comision_percent']) ? (float)$_POST['comision_percent'] : 0.0;
    $activo = isset($_POST['activo']) && (string)$_POST['activo'] === '1' ? 1 : 0;
    // Asignación a un cliente: solo por invitación y aceptación (no por este formulario)
    $clienteId = null;

    if ($nombre === '') {
      $flashErr = 'Nombre requerido.';
    } elseif ($comision < 0 || $comision > 100) {
      $flashErr = 'La comisión debe estar entre 0 y 100.';
    } elseif (!_rev_codigo_is_valid($codigo)) {
      $flashErr = 'El código solo puede tener letras/números/_/- (máx 64).';
    } else {
      if ($id <= 0) {
        $flashErr = 'No se permite crear revendedores manualmente. Usá “Invitar revendedor” y que el cliente acepte.';
      }

      if ($flashErr === '') {
        try {
          if ($id > 0) {
          // Mantener cliente_id actual (si existe) y no permitir cambios por POST
          $existingClienteId = null;
          try {
            $w2 = 'id = :id';
            $p2 = array(':id' => $id);
            if ($tipoGlobal === 'admin_evento') {
              $w2 .= ' AND owner_admin_id = :oid';
              $p2[':oid'] = $adminId;
            }
            $stEx = $pdo->prepare("SELECT cliente_id FROM revendedores WHERE $w2 LIMIT 1");
            $stEx->execute($p2);
            $existingClienteId = $stEx->fetchColumn();
          } catch (Exception $e) {
            $existingClienteId = null;
          }
          $clienteId = ($existingClienteId !== null && $existingClienteId !== '' ? (int)$existingClienteId : null);

          // seguridad: un admin_evento solo edita sus revendedores
          $where = 'id = :id';
          $params = array(
            ':id' => $id,
            ':c' => ($codigo !== '' ? $codigo : null),
            ':n' => $nombre,
            ':p' => $comision,
            ':a' => $activo,
            ':cid' => $clienteId,
          );
          if ($tipoGlobal === 'admin_evento') {
            $where .= ' AND owner_admin_id = :oid';
            $params[':oid'] = $adminId;
          }
          $st = $pdo->prepare("UPDATE revendedores SET codigo=:c, nombre=:n, comision_percent=:p, activo=:a, cliente_id=:cid WHERE $where");
          $st->execute($params);
          $flashOk = ($st->rowCount() > 0) ? 'Revendedor actualizado.' : 'No autorizado o revendedor inexistente.';
          } else {
          if ($tipoGlobal === 'admin_evento') {
            $ownerId = $adminId;
          } else {
            // superadmin puede forzar owner_admin_id (opcional)
            $ownerId = isset($_POST['owner_admin_id']) ? (int)$_POST['owner_admin_id'] : 0;
            if ($ownerId <= 0) $ownerId = null;
          }

          $st = $pdo->prepare('INSERT INTO revendedores (owner_admin_id, cliente_id, codigo, nombre, comision_percent, activo) VALUES (:oid,:cid,:c,:n,:p,:a)');
          $st->execute(array(
            ':oid' => $ownerId,
            ':cid' => null,
            ':c' => ($codigo !== '' ? $codigo : null),
            ':n' => $nombre,
            ':p' => $comision,
            ':a' => $activo,
          ));
          $flashOk = 'Revendedor creado.';
          }

          header('Location: admin_revendedores.php');
          exit;
        } catch (Exception $e) {
          $flashErr = 'No se pudo guardar (¿código duplicado?).';
        }
      }
    }
  }

  if ($action === 'toggle') {
    $id = isset($_POST['id']) ? (int)$_POST['id'] : 0;
    $activo = isset($_POST['activo']) && (string)$_POST['activo'] === '1' ? 1 : 0;
    if ($id > 0) {
      try {
        $where = 'id = :id';
        $params = array(':a' => $activo, ':id' => $id);
        if ($tipoGlobal === 'admin_evento') {
          $where .= ' AND owner_admin_id = :oid';
          $params[':oid'] = $adminId;
        }
        $st = $pdo->prepare("UPDATE revendedores SET activo = :a WHERE $where");
        $st->execute($params);
        header('Location: admin_revendedores.php');
        exit;
      } catch (Exception $e) {
        $flashErr = 'No se pudo actualizar el estado.';
      }
    }
  }

  if ($action === 'req_decision') {
    $reqId = isset($_POST['req_id']) ? (int)$_POST['req_id'] : 0;
    $decision = isset($_POST['decision']) ? (string)$_POST['decision'] : '';
    if ($reqId > 0 && ($decision === 'approve' || $decision === 'reject')) {
      try {
        $stR = $pdo->prepare("SELECT * FROM revendedor_solicitudes WHERE id = :id AND estado = 'pending' AND (direction IS NULL OR direction = '' OR direction = 'client_to_admin') LIMIT 1");
        $stR->execute(array(':id' => $reqId));
        $req = $stR->fetch(PDO::FETCH_ASSOC);

        if (!$req) {
          $flashErr = 'Solicitud inexistente.';
        } elseif ($tipoGlobal === 'admin_evento' && isset($req['owner_admin_id']) && (int)$req['owner_admin_id'] !== $adminId) {
          $flashErr = 'No autorizado.';
        } else {
          if ($decision === 'reject') {
            $stU = $pdo->prepare("UPDATE revendedor_solicitudes SET estado='rejected', updated_at=datetime('now') WHERE id=:id");
            $stU->execute(array(':id' => $reqId));
            header('Location: admin_revendedores.php');
            exit;
          }

          // Approve: crear revendedor y linkear
          $clienteId = isset($req['cliente_id']) ? (int)$req['cliente_id'] : 0;
          $nombre = '';
          try {
            $stC = $pdo->prepare('SELECT nombre, apellido, apodo, email FROM registro_pendientes WHERE id = :id LIMIT 1');
            $stC->execute(array(':id' => $clienteId));
            $c = $stC->fetch(PDO::FETCH_ASSOC);
            if ($c) {
              $nombre = trim((string)($c['apodo'] ?? ''));
              if ($nombre === '') {
                $nombre = trim((string)($c['nombre'] ?? '') . ' ' . (string)($c['apellido'] ?? ''));
              }
              if ($nombre === '') $nombre = (string)($c['email'] ?? 'Revendedor');
            }
          } catch (Exception $e) {}
          if ($nombre === '') $nombre = 'Revendedor';

          $stIns = $pdo->prepare('INSERT INTO revendedores (owner_admin_id, cliente_id, nombre, comision_percent, activo) VALUES (:oid,:cid,:n,0,1)');
          $stIns->execute(array(
            ':oid' => (isset($req['owner_admin_id']) && (int)$req['owner_admin_id'] > 0) ? (int)$req['owner_admin_id'] : ($tipoGlobal === 'admin_evento' ? $adminId : null),
            ':cid' => ($clienteId > 0 ? $clienteId : null),
            ':n' => $nombre,
          ));
          $newRid = (int)$pdo->lastInsertId();

          $stUp = $pdo->prepare("UPDATE revendedor_solicitudes SET estado='approved', revendedor_id=:rid, updated_at=datetime('now') WHERE id=:id");
          $stUp->execute(array(':rid' => $newRid, ':id' => $reqId));

          header('Location: admin_revendedores.php');
          exit;
        }
      } catch (Exception $e) {
        $flashErr = 'No se pudo procesar la solicitud.';
      }
    }
  }
}

// --- GET: edit, search, list ---
$q = isset($_GET['q']) ? trim((string)$_GET['q']) : '';
$editId = isset($_GET['edit']) ? (int)$_GET['edit'] : 0;

$edit = null;
if ($editId > 0) {
  try {
    $where = 'id = :id';
    $params = array(':id' => $editId);
    if ($tipoGlobal === 'admin_evento') {
      $where .= ' AND owner_admin_id = :oid';
      $params[':oid'] = $adminId;
    }
    $st = $pdo->prepare("SELECT * FROM revendedores WHERE $where LIMIT 1");
    $st->execute($params);
    $edit = $st->fetch(PDO::FETCH_ASSOC);
  } catch (Exception $e) {
    $edit = null;
  }
}

$rows = array();
try {
  $where = array();
  $params = array();
  if ($tipoGlobal === 'admin_evento') {
    $where[] = 'owner_admin_id = :oid';
    $params[':oid'] = $adminId;
  }
  if ($q !== '') {
    $where[] = '(nombre LIKE :q OR codigo LIKE :q OR CAST(id AS TEXT) = :qid)';
    $params[':q'] = '%' . $q . '%';
    $params[':qid'] = $q;
  }
  $sql = 'SELECT * FROM revendedores';
  if (!empty($where)) $sql .= ' WHERE ' . implode(' AND ', $where);
  $sql .= ' ORDER BY id DESC LIMIT 500';
  $st = $pdo->prepare($sql);
  $st->execute($params);
  $rows = $st->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
  $rows = array();
}

$solicitudes = array();
try {
  $w = array("estado = 'pending'", "(direction IS NULL OR direction = '' OR direction = 'client_to_admin')");
  $p = array();
  if ($tipoGlobal === 'admin_evento') {
    $w[] = 'owner_admin_id = :oid';
    $p[':oid'] = $adminId;
  }
  $sql = 'SELECT * FROM revendedor_solicitudes';
  if (!empty($w)) $sql .= ' WHERE ' . implode(' AND ', $w);
  $sql .= ' ORDER BY id DESC LIMIT 200';
  $st = $pdo->prepare($sql);
  $st->execute($p);
  $solicitudes = $st->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
  $solicitudes = array();
}

$retiros = array();
try {
  $w = array("estado = 'pending'");
  $p = array();
  if ($tipoGlobal === 'admin_evento') {
    $w[] = 'owner_admin_id = :oid';
    $p[':oid'] = $adminId;
  }
  $sql = 'SELECT * FROM revendedor_retiros';
  if (!empty($w)) $sql .= ' WHERE ' . implode(' AND ', $w);
  $sql .= ' ORDER BY id DESC LIMIT 200';
  $st = $pdo->prepare($sql);
  $st->execute($p);
  $retiros = $st->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
  $retiros = array();
}

include __DIR__ . '/inc/layout_top.php';
?>

<div class="card" style="max-width:1100px;margin:16px auto;">
  <div style="display:flex;justify-content:space-between;gap:12px;flex-wrap:wrap;align-items:center;">
    <div>
      <h1 style="margin:0;">Revendedores</h1>
      <div class="muted" style="margin-top:4px;">Compartí links con <strong>?aff=ID</strong> para atribuir ventas por cookie.</div>
    </div>
    <div style="display:flex;gap:8px;flex-wrap:wrap;">
      <a class="btn secondary" href="panel_admin.php">⬅ Volver</a>
    </div>
  </div>

  <?php if ($flashErr !== ''): ?>
    <div class="flash err" style="margin-top:12px;"><?php echo e($flashErr); ?></div>
  <?php endif; ?>
  <?php if ($flashOk !== ''): ?>
    <div class="flash ok" style="margin-top:12px;"><?php echo e($flashOk); ?></div>
  <?php endif; ?>
</div>

<div class="card" style="max-width:1100px;margin:16px auto;">
  <h2 style="margin-top:0;">Invitar revendedor</h2>
  <div class="muted" style="margin:6px 0 10px 0;">Envía una invitación al cliente. El cliente la verá en su perfil y podrá aceptar o rechazar.</div>
  <form method="post" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:10px;align-items:end;">
    <input type="hidden" name="csrf" value="<?php echo e($csrf); ?>">
    <input type="hidden" name="action" value="invite">
    <label>
      Tickex ID del cliente
      <input type="text" name="cliente_tickex_id" placeholder="Senchi o #123" required>
    </label>
    <label>
      Mensaje (opcional)
      <input type="text" name="mensaje" maxlength="500" placeholder="Ej: Te invitamos a ser revendedor de STR">
    </label>
    <div style="display:flex;gap:8px;align-items:center;">
      <button class="btn" type="submit">Enviar invitación</button>
    </div>
  </form>
</div>

<div class="card" style="max-width:1100px;margin:16px auto;">
  <h2 style="margin-top:0;">Invitar staff por email</h2>
  <div class="muted" style="margin:6px 0 10px 0;">Envía una invitación por email. La persona debe registrarse (si no tiene cuenta) y aceptar la invitación desde su perfil.</div>
  <form method="post" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:10px;align-items:end;">
    <input type="hidden" name="csrf" value="<?php echo e($csrf); ?>">
    <input type="hidden" name="action" value="staff_invite">
    <label>
      Email
      <input type="email" name="email" placeholder="persona@email.com" required>
    </label>
    <label>
      Nombre (opcional)
      <input type="text" name="nombre" placeholder="Nombre">
    </label>
    <label>
      Apellido (opcional)
      <input type="text" name="apellido" placeholder="Apellido">
    </label>
    <label>
      Mensaje (opcional)
      <input type="text" name="mensaje" maxlength="500" placeholder="Ej: Te invitamos a ser staff del evento">
    </label>
    <div style="display:flex;gap:8px;align-items:center;">
      <button class="btn" type="submit">Enviar invitación</button>
    </div>
  </form>
</div>

<div class="card" style="max-width:1100px;margin:16px auto;">
  <h2 style="margin-top:0;">Solicitudes pendientes</h2>
  <?php if (empty($solicitudes)): ?>
    <div class="muted">No hay solicitudes pendientes.</div>
  <?php else: ?>
    <div style="overflow:auto;">
      <table class="table" style="width:100%;min-width:860px;">
        <thead>
          <tr>
            <th style="width:60px;">ID</th>
            <th style="width:220px;">Cliente</th>
            <th>Mensaje</th>
            <th style="width:200px;">Acciones</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($solicitudes as $s): ?>
            <tr>
              <td><?php echo (int)$s['id']; ?></td>
              <td>
                <div style="font-weight:700;">#<?php echo (int)$s['cliente_id']; ?></div>
                <div class="muted" style="font-size:12px;"><?php echo e((string)($s['cliente_email'] ?? '')); ?></div>
              </td>
              <td><?php echo e((string)($s['mensaje'] ?? '')); ?></td>
              <td>
                <form method="post" style="display:inline;">
                  <input type="hidden" name="csrf" value="<?php echo e($csrf); ?>">
                  <input type="hidden" name="action" value="req_decision">
                  <input type="hidden" name="req_id" value="<?php echo (int)$s['id']; ?>">
                  <input type="hidden" name="decision" value="approve">
                  <button class="btn" type="submit">Aprobar</button>
                </form>
                <form method="post" style="display:inline;">
                  <input type="hidden" name="csrf" value="<?php echo e($csrf); ?>">
                  <input type="hidden" name="action" value="req_decision">
                  <input type="hidden" name="req_id" value="<?php echo (int)$s['id']; ?>">
                  <input type="hidden" name="decision" value="reject">
                  <button class="btn danger" type="submit">Rechazar</button>
                </form>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
</div>

<div class="card" style="max-width:1100px;margin:16px auto;">
  <h2 style="margin-top:0;">Retiros pendientes</h2>
  <?php if (empty($retiros)): ?>
    <div class="muted">No hay retiros pendientes.</div>
  <?php else: ?>
    <div style="overflow:auto;">
      <table class="table" style="width:100%;min-width:860px;">
        <thead>
          <tr>
            <th style="width:60px;">ID</th>
            <th style="width:120px;">Revendedor</th>
            <th style="width:120px;">Cliente</th>
            <th style="width:140px;">Monto</th>
            <th>CBU</th>
            <th style="width:220px;">Acciones</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($retiros as $r): ?>
            <tr>
              <td><?php echo (int)$r['id']; ?></td>
              <td>#<?php echo (int)$r['revendedor_id']; ?></td>
              <td><?php echo !empty($r['cliente_id']) ? ('#' . (int)$r['cliente_id']) : '<span class="muted">—</span>'; ?></td>
              <td>$<?php echo number_format((float)$r['amount'], 2, ',', '.'); ?></td>
              <td><?php echo e((string)($r['cbu'] ?? '')); ?></td>
              <td>
                <form method="post" style="display:inline;">
                  <input type="hidden" name="csrf" value="<?php echo e($csrf); ?>">
                  <input type="hidden" name="action" value="withdraw_decision">
                  <input type="hidden" name="withdraw_id" value="<?php echo (int)$r['id']; ?>">
                  <input type="hidden" name="decision" value="paid">
                  <button class="btn" type="submit">Marcar pagado</button>
                </form>
                <form method="post" style="display:inline;">
                  <input type="hidden" name="csrf" value="<?php echo e($csrf); ?>">
                  <input type="hidden" name="action" value="withdraw_decision">
                  <input type="hidden" name="withdraw_id" value="<?php echo (int)$r['id']; ?>">
                  <input type="hidden" name="decision" value="reject">
                  <button class="btn danger" type="submit">Rechazar</button>
                </form>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
</div>

<?php if ($edit): ?>
  <div class="card" style="max-width:1100px;margin:16px auto;">
    <h2 style="margin-top:0;">Editar revendedor</h2>
    <form method="post" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:10px;align-items:end;">
      <input type="hidden" name="csrf" value="<?php echo e($csrf); ?>">
      <input type="hidden" name="action" value="save">
      <input type="hidden" name="id" value="<?php echo (int)$edit['id']; ?>">

      <label>
        Nombre
        <input type="text" name="nombre" value="<?php echo e((string)$edit['nombre']); ?>" required>
      </label>

      <label>
        Código (opcional)
        <input type="text" name="codigo" value="<?php echo e((string)($edit['codigo'] ?? '')); ?>" placeholder="ej: juan_afiliado">
      </label>

      <label>
        Comisión (%)
        <input type="number" step="0.01" min="0" max="100" name="comision_percent" value="<?php echo e((string)($edit['comision_percent'] ?? '0')); ?>">
      </label>

      <?php if (!empty($edit['cliente_id'])): ?>
        <div style="grid-column:1 / -1;">
          <div class="muted" style="font-size:12px;">Cliente asignado: <strong>#<?php echo (int)$edit['cliente_id']; ?></strong></div>
        </div>
      <?php endif; ?>

      <label>
        Activo
        <select name="activo">
          <option value="1" <?php echo ((int)$edit['activo'] === 1) ? 'selected' : ''; ?>>Sí</option>
          <option value="0" <?php echo ((int)$edit['activo'] === 0) ? 'selected' : ''; ?>>No</option>
        </select>
      </label>

      <div style="display:flex;gap:8px;align-items:center;">
        <button class="btn" type="submit">Guardar cambios</button>
        <a class="btn secondary" href="admin_revendedores.php">Cancelar</a>
      </div>
    </form>
  </div>
<?php endif; ?>

<div class="card" style="max-width:1100px;margin:16px auto;">
  <div style="display:flex;justify-content:space-between;gap:12px;flex-wrap:wrap;align-items:center;">
    <h2 style="margin:0;">Listado</h2>
    <form method="get" style="display:flex;gap:8px;flex-wrap:wrap;align-items:center;">
      <input type="text" name="q" value="<?php echo e($q); ?>" placeholder="Buscar por nombre/código/ID" style="min-width:240px;">
      <button class="btn secondary" type="submit">Buscar</button>
      <?php if ($q !== ''): ?>
        <a class="btn secondary" href="admin_revendedores.php">Limpiar</a>
      <?php endif; ?>
    </form>
  </div>

  <div style="overflow:auto;margin-top:12px;">
    <table class="table" style="width:100%;min-width:900px;">
      <thead>
        <tr>
          <th style="width:60px;">ID</th>
          <th>Nombre</th>
          <th style="width:180px;">Código</th>
          <th style="width:110px;">Comisión</th>
          <th style="width:110px;">Cliente</th>
          <th style="width:90px;">Activo</th>
          <th style="width:260px;">Acciones</th>
        </tr>
      </thead>
      <tbody>
        <?php if (empty($rows)): ?>
          <tr><td colspan="7" class="muted">No hay revendedores.</td></tr>
        <?php else: ?>
          <?php foreach ($rows as $r): ?>
            <tr>
              <td><?php echo (int)$r['id']; ?></td>
              <td>
                <div style="font-weight:700;"><?php echo e((string)$r['nombre']); ?></div>
                <div class="muted" style="font-size:12px;">Link: <strong>?aff=<?php echo (int)$r['id']; ?></strong></div>
              </td>
              <td><?php echo !empty($r['codigo']) ? e((string)$r['codigo']) : '<span class="muted">—</span>'; ?></td>
              <td><?php echo e((string)$r['comision_percent']); ?>%</td>
              <td><?php echo !empty($r['cliente_id']) ? (int)$r['cliente_id'] : '<span class="muted">—</span>'; ?></td>
              <td><?php echo ((int)$r['activo'] === 1) ? 'Sí' : 'No'; ?></td>
              <td>
                <a class="btn secondary" href="admin_revendedores.php?edit=<?php echo (int)$r['id']; ?>">Editar</a>
                <form method="post" style="display:inline;">
                  <input type="hidden" name="csrf" value="<?php echo e($csrf); ?>">
                  <input type="hidden" name="action" value="toggle">
                  <input type="hidden" name="id" value="<?php echo (int)$r['id']; ?>">
                  <input type="hidden" name="activo" value="<?php echo ((int)$r['activo'] === 1) ? '0' : '1'; ?>">
                  <button class="btn <?php echo ((int)$r['activo'] === 1) ? 'danger' : 'secondary'; ?>" type="submit"><?php echo ((int)$r['activo'] === 1) ? 'Desactivar' : 'Activar'; ?></button>
                </form>
                <form method="post" style="display:inline;" onsubmit="return confirm('¿Eliminar revendedor? Si tiene ventas no se podrá borrar.');">
                  <input type="hidden" name="csrf" value="<?php echo e($csrf); ?>">
                  <input type="hidden" name="action" value="delete">
                  <input type="hidden" name="id" value="<?php echo (int)$r['id']; ?>">
                  <button class="btn danger" type="submit">Eliminar</button>
                </form>
              </td>
            </tr>
          <?php endforeach; ?>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<?php include __DIR__ . '/inc/layout_bottom.php'; ?>
