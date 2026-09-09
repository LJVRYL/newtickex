<?php
require_once __DIR__ . '/inc/security.php';
require_once __DIR__ . '/inc/routes.php';
tickex_send_security_headers();
tickex_session_start();
require_once __DIR__ . '/inc/db.php';
require_once __DIR__ . '/inc/staff_roles.php';
require_once __DIR__ . '/inc/staff_operations.php';
require_once __DIR__ . '/inc/notificaciones.php';
require_once __DIR__ . '/inc/unified_tickets.php';

if (!isset($_SESSION['usuario_id']) || (int)$_SESSION['usuario_id'] <= 0) {
  header('Location: ' . tickex_route('login', array()) . '?next=' . urlencode(tickex_route('staff_home', array())));
  exit;
}

$usuarioId = (int)$_SESSION['usuario_id'];
$pdo = db();
tickex_staff_operations_ensure_schema($pdo);
$title = 'Panel staff';

$staffUser = array('nombre' => '', 'email' => (string)($_SESSION['usuario_email'] ?? ''), 'apodo' => '');
try {
  $stU = $pdo->prepare('SELECT nombre, email, apodo FROM registro_pendientes WHERE id = :id LIMIT 1');
  $stU->execute(array(':id' => $usuarioId));
  $rowU = $stU->fetch(PDO::FETCH_ASSOC);
  if ($rowU) {
    $staffUser = array_merge($staffUser, $rowU);
  }
} catch (Exception $e) {
  // ignore
}

// Traer asignaciones staff activas para este cliente
$asignaciones = array();
try {
  $st = $pdo->prepare("SELECT sa.id, sa.owner_admin_id, sa.rol_staff, sa.created_at,
      ua.apodo AS admin_apodo, ua.username AS admin_username, ua.email AS admin_email
    FROM staff_admins sa
    LEFT JOIN usuarios_admin ua ON ua.id = sa.owner_admin_id
    WHERE sa.cliente_id = :cid AND sa.activo = 1
    ORDER BY sa.id DESC
    LIMIT 50");
  $st->execute(array(':cid' => $usuarioId));
  $asignaciones = $st->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
  $asignaciones = array();
}

$permCatalog = tickex_staff_roles_permissions_catalog();

function _tickex_staff_perm_labels($catalog, $keys)
{
  $out = array();
  if (!is_array($keys)) return $out;
  foreach ($keys as $k) {
    $kk = (string)$k;
    if (isset($catalog[$kk])) $out[] = (string)$catalog[$kk];
  }
  return $out;
}

function _staff_norm_ticket_ref($ref)
{
  $v = trim((string)$ref);
  if ($v === '') return '';
  return preg_replace('/-\d+$/', '', $v);
}

function _staff_bridge_ticket_id_by_ref($pdo, $ticketRef)
{
  $ref = trim((string)$ticketRef);
  if ($ref === '') return 0;
  try {
    $stTbl = $pdo->query("SELECT 1 FROM sqlite_master WHERE type='table' AND name='senforms_bridge_tickets' LIMIT 1");
    if (!$stTbl || !$stTbl->fetch(PDO::FETCH_ASSOC)) return 0;
    $cols = detect_table_columns($pdo, 'senforms_bridge_tickets');
    $conds = array();
    foreach (array('ticket_ref', 'order_id', 'codigo') as $c) {
      if (isset($cols[$c])) $conds[] = $c . ' = :r';
    }
    if (empty($conds)) return 0;
    $sql = 'SELECT id FROM senforms_bridge_tickets WHERE (' . implode(' OR ', $conds) . ') ORDER BY id DESC LIMIT 1';
    $st = $pdo->prepare($sql);
    $st->execute(array(':r' => $ref));
    return (int)$st->fetchColumn();
  } catch (Exception $e) {
    return 0;
  }
}

function _staff_log_checkin($pdo, $usuarioId, $eventoId, $source, $sourceTicketId, $ticketRef, $attendeeName, $result, $detail)
{
  log_checkin_audit($pdo, array(
    'actor_user_id' => (int)$usuarioId,
    'evento_id' => (int)$eventoId,
    'source' => strtoupper((string)$source),
    'source_ticket_id' => (int)$sourceTicketId,
    'ticket_ref' => (string)$ticketRef,
    'attendee_name' => (string)$attendeeName,
    'action' => 'manual_swipe',
    'result' => (string)$result,
    'detail' => (string)$detail,
  ));
}

$eventosStaff = array();
try {
  $stE = $pdo->prepare("SELECT e.id, e.nombre, e.slug,sa.owner_admin_id,
      COALESCE(NULLIF(se.rol_staff,''),sa.rol_staff,'puerta') AS event_role
    FROM staff_eventos se
    JOIN eventos e ON e.id = se.evento_id
    JOIN staff_admins sa ON sa.cliente_id=se.staff_id AND sa.owner_admin_id=e.creado_por_admin_id AND sa.activo=1
    WHERE se.staff_id = :sid
    ORDER BY e.id DESC");
  $stE->execute(array(':sid' => $usuarioId));
  $eventosStaff = $stE->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
  $eventosStaff = array();
}

if (!empty($eventosStaff)) {
  foreach ($eventosStaff as &$ev) {
    $stats = get_unified_stats($pdo, (int)$ev['id']);
    $ev['total'] = isset($stats['total']) ? (int)$stats['total'] : 0;
    $ev['checkins'] = isset($stats['checkins']) ? (int)$stats['checkins'] : 0;
  }
  unset($ev);
}

$activeEventId = isset($_GET['evento_id']) ? (int)$_GET['evento_id'] : 0;
if ($activeEventId <= 0 && !empty($eventosStaff)) {
  $activeEventId = (int)$eventosStaff[0]['id'];
}

$activeEvent = null;
$staffEventIds = array();
foreach ($eventosStaff as $ev) {
  $staffEventIds[] = (int)$ev['id'];
  if ((int)$ev['id'] === $activeEventId) {
    $activeEvent = $ev;
    break;
  }
}
if (!$activeEvent && !empty($eventosStaff)) {
  $activeEvent = $eventosStaff[0];
  $activeEventId = (int)$activeEvent['id'];
}
$activePerms = $activeEvent ? tickex_staff_role_permissions($pdo,(int)$activeEvent['owner_admin_id'],(string)$activeEvent['event_role']) : array();
$canScan = in_array('checkin_scan',$activePerms,true);
$canSell = in_array('sales_view',$activePerms,true);
$canReports = in_array('reports_view',$activePerms,true);
$canValidate = in_array('tickets_validate',$activePerms,true);
if ($activeEvent && !in_array('dashboard_view',$activePerms,true)) {
  http_response_code(403);
  exit('Tu rol no permite abrir el panel operativo de este evento.');
}

$q = isset($_GET['q']) ? trim((string)$_GET['q']) : '';
$flashOk = '';
$flashErr = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'staff_task_toggle') {
  $provided = isset($_POST['csrf']) ? (string)$_POST['csrf'] : '';
  $taskId = isset($_POST['task_id']) ? (int)$_POST['task_id'] : 0;
  $eventPost = isset($_POST['evento_id']) ? (int)$_POST['evento_id'] : 0;
  if (!tickex_csrf_verify($provided)) {
    $flashErr = 'CSRF inválido.';
  } elseif ($eventPost !== $activeEventId || $taskId <= 0 || !$activeEvent) {
    $flashErr = 'Tarea inválida.';
  } else {
    $stTask = $pdo->prepare("UPDATE staff_tasks SET status=CASE status WHEN 'done' THEN 'pending' ELSE 'done' END,completed_at=CASE status WHEN 'done' THEN NULL ELSE CURRENT_TIMESTAMP END,updated_at=CURRENT_TIMESTAMP WHERE id=:id AND owner_admin_id=:owner AND evento_id=:event AND (assigned_staff_id=:staff OR (assigned_staff_id IS NULL AND role_code=:role) OR (assigned_staff_id IS NULL AND COALESCE(role_code,'')=''))");
    $stTask->execute(array(':id'=>$taskId,':owner'=>(int)$activeEvent['owner_admin_id'],':event'=>$activeEventId,':staff'=>$usuarioId,':role'=>(string)$activeEvent['event_role']));
    $flashOk = $stTask->rowCount() ? 'Tarea actualizada.' : '';
    if (!$stTask->rowCount()) $flashErr = 'No tenés acceso a esa tarea.';
  }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'staff_checkin') {
  $provided = isset($_POST['csrf']) ? (string)$_POST['csrf'] : '';
  if (function_exists('tickex_csrf_verify') && !tickex_csrf_verify($provided)) {
    $flashErr = 'CSRF inválido.';
    _staff_log_checkin($pdo, $usuarioId, (int)($_POST['evento_id'] ?? 0), 'UNKNOWN', 0, '', '', 'error', 'csrf_invalido');
  } else {
    $entryId = isset($_POST['entry_id']) ? (int)$_POST['entry_id'] : 0;
    $entrySource = isset($_POST['entry_source']) ? strtoupper(trim((string)$_POST['entry_source'])) : 'STR';
    $eidPost = isset($_POST['evento_id']) ? (int)$_POST['evento_id'] : 0;
    $entryRefPost = isset($_POST['entry_ref']) ? trim((string)$_POST['entry_ref']) : '';
    $entryNamePost = isset($_POST['entry_name']) ? trim((string)$_POST['entry_name']) : '';
    if ($eidPost <= 0 || ($entryId <= 0 && !($entrySource === 'TICKEX' && $entryRefPost !== ''))) {
      $flashErr = 'Entrada inválida.';
      _staff_log_checkin($pdo, $usuarioId, $eidPost, $entrySource, $entryId, $entryRefPost, $entryNamePost, 'error', 'entrada_invalida');
    } elseif (!in_array($eidPost, $staffEventIds, true) || !$canScan) {
      $flashErr = 'No tenés permiso para operar ese evento.';
      _staff_log_checkin($pdo, $usuarioId, $eidPost, $entrySource, $entryId, $entryRefPost, $entryNamePost, 'error', 'sin_permiso_evento');
    } else {
      try {
        if ($entrySource === 'TICKEX') {
          $allowedTickex = false;
          $entryMultiplier = 0;
          $resolvedTicketId = $entryId;
          $refNormPost = _staff_norm_ticket_ref($entryRefPost);
          $entriesEvent = get_unified_entries($pdo, $eidPost);
          foreach ($entriesEvent as $ue) {
            $srcU = isset($ue['source']) ? strtoupper((string)$ue['source']) : 'STR';
            $idU = isset($ue['ticket_id']) ? (int)$ue['ticket_id'] : 0;
            $refU = isset($ue['ticket_ref']) ? _staff_norm_ticket_ref((string)$ue['ticket_ref']) : '';
            $matchId = ($entryId > 0 && $idU === $entryId);
            $matchRef = ($refNormPost !== '' && $refU !== '' && $refNormPost === $refU);
            if ($srcU === 'TICKEX' && ($matchId || $matchRef)) {
              $allowedTickex = true;
              $entryMultiplier++;
              if ($resolvedTicketId <= 0 && $idU > 0) $resolvedTicketId = $idU;
              if ($entryNamePost === '' && isset($ue['nombre'])) $entryNamePost = (string)$ue['nombre'];
              if ($entryRefPost === '' && isset($ue['ticket_ref'])) $entryRefPost = (string)$ue['ticket_ref'];
            }
          }
          if (!$allowedTickex) {
            $flashErr = 'La entrada no corresponde al evento seleccionado.';
            _staff_log_checkin($pdo, $usuarioId, $eidPost, 'TICKEX', $entryId, $entryRefPost, $entryNamePost, 'error', 'ticket_fuera_de_evento');
          } else {
          if ($resolvedTicketId <= 0 && $entryRefPost !== '') {
            $resolvedTicketId = _staff_bridge_ticket_id_by_ref($pdo, _staff_norm_ticket_ref($entryRefPost));
          }
          $stCheckTbl = $pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name='senforms_bridge_tickets' LIMIT 1");
          if ($stCheckTbl && $stCheckTbl->fetch(PDO::FETCH_ASSOC)) {
            $maxUses = max(1, $entryMultiplier);
            if ($resolvedTicketId <= 0) {
              $flashErr = 'No se pudo identificar el ticket para check-in.';
              _staff_log_checkin($pdo, $usuarioId, $eidPost, 'TICKEX', 0, $entryRefPost, $entryNamePost, 'error', 'ticket_no_resuelto');
            } else {
            $use = increment_bridge_checkin_use($pdo, $resolvedTicketId, $maxUses);
            if (!empty($use['ok']) && !empty($use['changed'])) {
              if (!empty($use['full'])) {
                $st = $pdo->prepare('UPDATE senforms_bridge_tickets SET is_checked_in = 1, checked_in_at = datetime(\'now\') WHERE id = :id');
                $st->execute(array(':id' => $resolvedTicketId));
              }
              $flashOk = 'Check-in realizado (' . (int)$use['used'] . '/' . (int)$use['max'] . ').';
              _staff_log_checkin($pdo, $usuarioId, $eidPost, 'TICKEX', $resolvedTicketId, $entryRefPost, $entryNamePost, 'ok', 'checkin_' . (int)$use['used'] . '_de_' . (int)$use['max']);
            } else {
              $flashErr = 'Esta entrada ya agotó sus ingresos.';
              _staff_log_checkin($pdo, $usuarioId, $eidPost, 'TICKEX', $resolvedTicketId, $entryRefPost, $entryNamePost, 'duplicate', 'intento_repetido_tickex');
            }
            }
          } else {
            $flashErr = 'No se puede checkear esta entrada en este entorno.';
            _staff_log_checkin($pdo, $usuarioId, $eidPost, 'TICKEX', $resolvedTicketId, $entryRefPost, $entryNamePost, 'error', 'tabla_bridge_inexistente');
          }
          }
        } else {
          $colCheck = get_checkin_column($pdo);
          $st = $pdo->prepare("UPDATE entradas SET $colCheck = 1, checked_in_at = datetime('now'), oculto = 0 WHERE id = :id AND evento_id = :eid AND COALESCE($colCheck,0)=0");
          $st->execute(array(':id' => $entryId, ':eid' => $eidPost));
          if ($st->rowCount() > 0) {
            $flashOk = 'Check-in realizado.';
            _staff_log_checkin($pdo, $usuarioId, $eidPost, 'STR', $entryId, $entryRefPost, $entryNamePost, 'ok', 'checkin_realizado');
          } else {
            $flashErr = 'La entrada ya estaba checkeada o no corresponde al evento.';
            _staff_log_checkin($pdo, $usuarioId, $eidPost, 'STR', $entryId, $entryRefPost, $entryNamePost, 'duplicate', 'intento_repetido_o_fuera_evento');
          }
        }
      } catch (Exception $e) {
        $flashErr = 'No se pudo realizar el check-in.';
        _staff_log_checkin($pdo, $usuarioId, $eidPost, $entrySource, $entryId, $entryRefPost, $entryNamePost, 'error', 'excepcion_checkin');
      }
    }
  }
}

$rowsEntradas = array();
if ($activeEventId > 0 && $canValidate) {
  try {
    $filters = array();
    $rowsEntradas = get_unified_entries($pdo, $activeEventId, $filters);

    if ($q !== '') {
      $qNeedle = function_exists('mb_strtolower') ? mb_strtolower($q, 'UTF-8') : strtolower($q);
      $rowsEntradas = array_values(array_filter($rowsEntradas, function ($row) use ($qNeedle) {
        $haystack = array(
          isset($row['nombre']) ? (string)$row['nombre'] : '',
          isset($row['ticket_ref']) ? (string)$row['ticket_ref'] : '',
          isset($row['email']) ? (string)$row['email'] : '',
          isset($row['tipo']) ? (string)$row['tipo'] : '',
        );
        $txt = implode(' ', $haystack);
        $txt = function_exists('mb_strtolower') ? mb_strtolower($txt, 'UTF-8') : strtolower($txt);
        return strpos($txt, $qNeedle) !== false;
      }));
    }

    if (count($rowsEntradas) > 300) {
      $rowsEntradas = array_slice($rowsEntradas, 0, 300);
    }
  } catch (Exception $e) {
    $rowsEntradas = array();
  }
}
$myTasks = array();
$myShifts = array();
if ($activeEvent) {
  try {
    $stTasks=$pdo->prepare("SELECT * FROM staff_tasks WHERE owner_admin_id=:owner AND evento_id=:event AND (assigned_staff_id=:staff OR (assigned_staff_id IS NULL AND role_code=:role) OR (assigned_staff_id IS NULL AND COALESCE(role_code,'')='')) ORDER BY CASE status WHEN 'done' THEN 1 ELSE 0 END,due_at,id DESC");
    $stTasks->execute(array(':owner'=>(int)$activeEvent['owner_admin_id'],':event'=>$activeEventId,':staff'=>$usuarioId,':role'=>(string)$activeEvent['event_role']));
    $myTasks=$stTasks->fetchAll(PDO::FETCH_ASSOC);
    $stShifts=$pdo->prepare('SELECT * FROM staff_shifts WHERE owner_admin_id=:owner AND evento_id=:event AND staff_id=:staff ORDER BY starts_at');
    $stShifts->execute(array(':owner'=>(int)$activeEvent['owner_admin_id'],':event'=>$activeEventId,':staff'=>$usuarioId));
    $myShifts=$stShifts->fetchAll(PDO::FETCH_ASSOC);
  } catch(Exception $e) {}
}

$helloName = trim((string)($staffUser['nombre'] ?? ''));
if ($helloName === '') {
  $helloName = trim((string)($staffUser['apodo'] ?? ''));
}
if ($helloName === '') {
  $helloName = trim((string)($staffUser['email'] ?? 'Staff'));
}

$notifs = get_user_notifications($usuarioId, $pdo);
$unreadCount = 0;
foreach ($notifs as $n) {
  if (empty($n['leida']) || (int)$n['leida'] === 0) $unreadCount++;
}

include __DIR__ . '/inc/layout_top.php';
?>

<style>
  .topbar{display:none !important}
  .nav,.nav-overlay{display:none !important}
  body{padding-left:0 !important}
  .wrap{max-width:980px;margin:0 auto}
  .staff-app{max-width:860px;margin:0 auto;padding-bottom:96px}
  .footer{display:none !important}
  .staff-head{display:flex;justify-content:space-between;align-items:center;gap:8px;margin-bottom:10px}
  .staff-brand{font-weight:800;letter-spacing:.06em}
  .staff-hello{font-size:14px;color:var(--muted)}
  .staff-notif{display:inline-flex;align-items:center;justify-content:center;width:40px;height:40px;border-radius:50%;border:1px solid var(--line);background:var(--panel-2);text-decoration:none;color:var(--ink);position:relative}
  .staff-notif-badge{position:absolute;top:-5px;right:-5px;min-width:16px;height:16px;border-radius:999px;background:#d22;color:#fff;font-size:10px;line-height:16px;text-align:center;padding:0 4px}
  .staff-hero{padding:14px;border:1px solid var(--line);border-radius:14px;background:var(--panel)}
  .staff-stats{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:8px;margin-top:10px}
  .staff-stat{background:var(--panel-2);border:1px solid var(--line);border-radius:12px;padding:10px}
  .staff-stat .k{color:var(--muted);font-size:12px}
  .staff-stat .v{font-size:20px;font-weight:800;margin-top:3px}
  .staff-events{display:flex;gap:8px;overflow:auto;padding-bottom:2px;margin:12px 0}
  .staff-events a{white-space:nowrap;text-decoration:none;padding:7px 10px;border-radius:999px;border:1px solid var(--line);background:var(--panel-2);color:var(--ink);font-size:12px}
  .staff-events a.active{border-color:var(--acc);box-shadow:0 0 0 1px var(--acc) inset}
  .staff-bottom-nav{position:fixed;left:0;right:0;bottom:0;z-index:140;background:rgba(11,16,32,.98);border-top:1px solid var(--line);padding:8px 8px calc(8px + env(safe-area-inset-bottom));display:grid;grid-template-columns:1fr 1fr auto 1fr 1fr;gap:6px;align-items:end}
  .staff-bottom-nav a,.staff-bottom-nav button{background:none;border:none;color:var(--muted);text-decoration:none;display:flex;flex-direction:column;align-items:center;justify-content:center;gap:3px;font-size:11px;cursor:pointer}
  .staff-bottom-nav .i{font-size:18px;line-height:1}
  .staff-bottom-nav .center{width:58px;height:58px;border-radius:50%;margin-top:-26px;background:linear-gradient(135deg,#ffdd33,#ff8a00);color:#111;border:2px solid rgba(255,255,255,.18);box-shadow:0 8px 20px rgba(0,0,0,.35)}
  .staff-bottom-nav .center .i{font-size:22px}
  .qr-icon{display:inline-flex;align-items:center;justify-content:center;width:22px;height:22px}
  .qr-icon svg{width:22px;height:22px;display:block;fill:#000}
  .staff-sheet{display:none;position:fixed;inset:0;z-index:180;background:rgba(0,0,0,.45)}
  .staff-sheet.open{display:block}
  .staff-sheet-box{position:absolute;left:0;right:0;bottom:0;background:var(--panel);border-top:1px solid var(--line);border-radius:16px 16px 0 0;padding:14px 14px calc(20px + env(safe-area-inset-bottom))}
  .staff-sheet-links{display:grid;gap:8px}
  .staff-sheet-links a{display:block;text-decoration:none;color:var(--ink);padding:10px 12px;border-radius:10px;border:1px solid var(--line);background:var(--panel-2)}
  .staff-notifs{display:none;position:fixed;top:60px;right:12px;z-index:185;width:min(360px,94vw);max-height:62vh;overflow:auto;background:var(--panel);border:1px solid var(--line);border-radius:12px;box-shadow:0 12px 22px rgba(0,0,0,.35)}
  .staff-notifs.open{display:block}
  .staff-notifs-head{padding:10px 12px;border-bottom:1px solid var(--line);font-weight:700}
  .staff-notif-item{padding:10px 12px;border-bottom:1px solid rgba(255,255,255,.06)}
  .staff-notif-item.unread{background:rgba(255,255,255,.04)}
  .staff-list{margin-top:12px;border:1px solid var(--line);border-radius:14px;background:var(--panel)}
  .staff-list-head{padding:12px;border-bottom:1px solid var(--line)}
  .staff-search{display:grid;grid-template-columns:1fr auto;gap:8px;align-items:end}
  .entry-list{display:flex;flex-direction:column}
  .entry-item{padding:12px;border-bottom:1px solid rgba(255,255,255,.07);display:grid;grid-template-columns:1fr auto;gap:10px;align-items:center}
  .entry-main strong{display:block}
  .entry-meta{font-size:12px;color:var(--muted)}
  .entry-state{font-size:11px;padding:3px 8px;border-radius:999px;border:1px solid var(--line)}
  .entry-state.ok{color:#7df3a3;border-color:rgba(34,197,94,.45)}
  .entry-state.pending{color:#f8d27c;border-color:rgba(245,158,11,.45)}
  .swipe-wrap{width:112px;height:36px;border-radius:999px;background:#151f31;border:1px solid var(--line);position:relative;overflow:hidden}
  .swipe-fill{position:absolute;inset:0;width:0;background:linear-gradient(90deg,#0f6,#2dd4bf);opacity:.35;transition:width .12s}
  .swipe-knob{position:absolute;left:2px;top:2px;width:30px;height:30px;border-radius:50%;background:#fff;color:#111;display:flex;align-items:center;justify-content:center;font-weight:800;touch-action:none;user-select:none;cursor:grab}
  .roles-mini{margin-top:10px;padding:10px 12px;border:1px solid var(--line);border-radius:12px;background:var(--panel-2)}
  .roles-mini details{margin:0}
  .roles-mini summary{cursor:pointer;font-size:13px;color:var(--muted)}
  .roles-mini-list{margin-top:8px;display:grid;gap:8px}
  .roles-mini-item{font-size:12px;color:var(--muted)}
  .staff-work{display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-top:12px}.staff-work-card{padding:12px;border:1px solid var(--line);border-radius:14px;background:var(--panel)}.staff-work-card h3{margin:0 0 9px}.staff-work-row{display:flex;justify-content:space-between;gap:9px;align-items:center;padding:9px 0;border-top:1px solid var(--line)}.staff-work-row:first-of-type{border-top:0}.staff-work-row.done{opacity:.55;text-decoration:line-through}@media(max-width:680px){.staff-work{grid-template-columns:1fr}}
  @media (min-width:1024px){.staff-bottom-nav{max-width:860px;left:50%;right:auto;transform:translateX(-50%);width:100%}}
  .staff-app{max-width:1120px;padding:24px 18px 108px}
  .staff-head{margin-bottom:24px;padding-bottom:18px;border-bottom:1px solid rgba(255,255,255,.09)}
  .staff-identity{display:flex;align-items:center;gap:13px;min-width:0}
  .staff-brand-mark{display:flex;width:48px;height:48px;flex:0 0 48px;align-items:center;justify-content:center}
  .staff-brand-mark img{display:block;width:100%;height:100%;object-fit:contain}
  .staff-kicker{color:#36d6f0;font-size:10px;font-weight:900;letter-spacing:.14em;text-transform:uppercase}
  .staff-hello{margin-top:4px;color:#f5f7ff;font-size:18px;line-height:1.2}
  .staff-hello span{color:var(--muted);font-size:13px;font-weight:500}
  .staff-event-label{margin:18px 0 8px;color:var(--muted);font-size:10px;font-weight:900;letter-spacing:.12em;text-transform:uppercase}
  .staff-events{margin:0 0 14px;padding:0 0 4px}
  .staff-events a{padding:9px 13px;border-radius:10px;font-weight:700}
  .staff-events a.active{color:#fff;border-color:rgba(125,92,255,.8);background:linear-gradient(135deg,rgba(102,67,255,.42),rgba(41,51,111,.58));box-shadow:none}
  .staff-hero{position:relative;overflow:hidden;padding:28px;border-color:rgba(132,112,255,.35);border-radius:22px;background:linear-gradient(135deg,#171934 0%,#24205d 58%,#29366c 100%);box-shadow:0 24px 60px rgba(0,0,0,.25)}
  .staff-hero::after{content:"";position:absolute;width:360px;height:360px;right:-190px;bottom:-260px;border:1px solid rgba(255,255,255,.10);border-radius:50%;box-shadow:0 0 0 55px rgba(255,255,255,.018),0 0 0 110px rgba(255,255,255,.012)}
  .staff-hero-main,.staff-stats,.roles-mini{position:relative;z-index:1}
  .staff-role-chip{display:inline-flex;padding:6px 10px;border:1px solid rgba(62,220,242,.28);border-radius:999px;background:rgba(34,211,238,.08);color:#66e8f8;font-size:10px;font-weight:900;letter-spacing:.1em;text-transform:uppercase}
  .staff-hero h1{max-width:720px;margin:16px 0 6px;color:#fff;font-size:clamp(30px,5vw,54px);line-height:1;letter-spacing:-.05em}
  .staff-hero-copy{max-width:660px;margin:0;color:rgba(255,255,255,.66);font-size:14px}
  .staff-stats{grid-template-columns:repeat(3,minmax(0,1fr));gap:12px;margin-top:24px}
  .staff-stat{min-height:104px;padding:17px;border-color:rgba(255,255,255,.12);background:rgba(7,10,26,.35);backdrop-filter:blur(8px)}
  .staff-stat .k{color:rgba(255,255,255,.6);font-size:10px;font-weight:800;letter-spacing:.1em;text-transform:uppercase}
  .staff-stat .v{margin-top:9px;color:#fff;font-family:Manrope,Inter,sans-serif;font-size:30px}
  .staff-progress{height:4px;margin-top:12px;overflow:hidden;border-radius:999px;background:rgba(255,255,255,.10)}
  .staff-progress span{display:block;height:100%;border-radius:inherit;background:linear-gradient(90deg,#37d9f2,#7b5cff)}
  .roles-mini{margin-top:14px;border-color:rgba(255,255,255,.12);background:rgba(7,10,26,.25)}
  .roles-mini summary{color:rgba(255,255,255,.7);font-weight:700}
  .staff-section{margin-top:22px}
  .staff-section-head{display:flex;justify-content:space-between;gap:16px;align-items:end;margin-bottom:10px}
  .staff-section-head h2{margin:4px 0 0;font-size:22px;letter-spacing:-.025em}
  .staff-section-head p{max-width:520px;margin:0;color:var(--muted);font-size:13px}
  .staff-section-kicker{color:#36d6f0;font-size:9px;font-weight:900;letter-spacing:.13em;text-transform:uppercase}
  .staff-quick-actions{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:10px}
  .staff-action{display:flex;min-height:100px;padding:17px;flex-direction:column;justify-content:space-between;text-decoration:none;color:#f7f8ff;border:1px solid var(--line);border-radius:16px;background:var(--panel);transition:.16s ease}
  .staff-action:hover{transform:translateY(-2px);border-color:rgba(124,92,255,.6);background:linear-gradient(145deg,rgba(35,38,73,.98),rgba(19,24,43,.98))}
  .staff-action strong{font-size:16px}.staff-action span{color:var(--muted);font-size:12px}
  .staff-action.primary{border-color:rgba(100,80,255,.5);background:linear-gradient(135deg,rgba(90,58,245,.65),rgba(38,49,102,.9))}
  .staff-work{gap:12px;margin-top:0}
  .staff-work-card{padding:18px;border-radius:16px}.staff-work-card h3{font-size:17px}
  .staff-list{margin-top:0;border-radius:18px;overflow:hidden}
  .staff-list-head{padding:18px}.staff-list-head h3{font-size:20px;letter-spacing:-.02em}
  .entry-item{padding:15px 18px}.entry-main strong{font-size:14px}.entry-meta{margin-top:4px}
  .staff-bottom-nav{max-width:620px!important;left:50%!important;right:auto!important;transform:translateX(-50%);width:calc(100% - 24px)!important;bottom:12px;border:1px solid rgba(255,255,255,.12);border-radius:18px;padding:9px 10px calc(9px + env(safe-area-inset-bottom));box-shadow:0 18px 48px rgba(0,0,0,.5)}
  @media(max-width:760px){.staff-app{padding:16px 10px 104px}.staff-hero{padding:20px 16px}.staff-quick-actions{grid-template-columns:1fr}.staff-section-head{display:block}.staff-section-head p{margin-top:5px}.staff-head{margin-bottom:16px}.staff-stats{gap:7px}.staff-stat{min-height:88px;padding:12px}.staff-stat .v{font-size:24px}.staff-hello{font-size:15px}.staff-brand-mark{width:42px;height:42px;flex-basis:42px}}
</style>

<div class="staff-app">
  <header class="staff-head">
    <div class="staff-identity">
      <a class="staff-brand-mark" href="<?php echo e(tickex_route('customer_home', array())); ?>" aria-label="Volver a mi cuenta"><img src="/tickex-isotipo.svg" alt="Tickex"></a>
      <div>
        <div class="staff-kicker">Espacio operativo</div>
        <div class="staff-hello">Hola, <strong><?php echo e($helloName); ?></strong> <span>· Equipo Tickex</span></div>
      </div>
    </div>
    <button class="staff-notif" id="btnNotifStaff" type="button" title="Notificaciones">🔔
      <?php if ($unreadCount > 0): ?><span class="staff-notif-badge"><?php echo (int)$unreadCount; ?></span><?php endif; ?>
    </button>
  </header>

  <div id="notifPanelStaff" class="staff-notifs" aria-hidden="true">
    <div class="staff-notifs-head">Notificaciones</div>
    <?php if (empty($notifs)): ?>
      <div class="staff-notif-item">Sin notificaciones por ahora.</div>
    <?php else: ?>
      <?php foreach ($notifs as $n): ?>
        <div class="staff-notif-item <?php echo (empty($n['leida']) ? 'unread' : ''); ?>">
          <div><?php echo e((string)$n['mensaje']); ?></div>
          <div class="entry-meta" style="margin-top:4px;"><?php echo e(date('d/m H:i', strtotime((string)$n['created_at']))); ?></div>
        </div>
      <?php endforeach; ?>
    <?php endif; ?>
  </div>

  <?php if (empty($asignaciones)): ?>
    <div class="flash err">No tenés asignaciones de staff activas.</div>
  <?php else: ?>
    <?php if (!empty($eventosStaff)): ?>
      <div class="staff-event-label">Elegí el evento que vas a operar</div>
      <div class="staff-events">
        <?php foreach ($eventosStaff as $ev): ?>
          <?php $isAct = ((int)$ev['id'] === (int)$activeEventId); ?>
          <a href="<?php echo e(tickex_route('staff_home', array())); ?>?evento_id=<?php echo (int)$ev['id']; ?>" class="<?php echo $isAct ? 'active' : ''; ?>">
            <?php echo e($ev['nombre']); ?>
          </a>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>

    <div class="staff-hero">
      <div class="staff-hero-main">
        <span class="staff-role-chip"><?php echo $activeEvent ? e(tickex_staff_role_label($pdo,(int)$activeEvent['owner_admin_id'],(string)$activeEvent['event_role'])) : 'Staff'; ?></span>
        <h1><?php echo $activeEvent ? e($activeEvent['nombre']) : 'Sin evento seleccionado'; ?></h1>
        <p class="staff-hero-copy"><?php echo $activeEvent ? 'Todo lo necesario para operar tu turno y seguir los ingresos en tiempo real.' : 'Cuando te asignen un evento, vas a encontrar acá tus herramientas de trabajo.'; ?></p>
      </div>

      <?php if ($activeEvent): ?>
      <?php $staffProgress = (int)$activeEvent['total'] > 0 ? min(100, round(((int)$activeEvent['checkins'] / (int)$activeEvent['total']) * 100)) : 0; ?>
      <div class="staff-stats">
        <div class="staff-stat"><div class="k">Entradas emitidas</div><div class="v"><?php echo (int)$activeEvent['total']; ?></div></div>
        <div class="staff-stat"><div class="k">Ingresaron</div><div class="v"><?php echo (int)$activeEvent['checkins']; ?></div><div class="staff-progress"><span style="width:<?php echo (int)$staffProgress; ?>%"></span></div></div>
        <div class="staff-stat"><div class="k">Faltan ingresar</div><div class="v"><?php echo max(0, (int)$activeEvent['total'] - (int)$activeEvent['checkins']); ?></div></div>
      </div>
      <?php endif; ?>

      <div class="roles-mini">
        <details>
          <summary>Mis roles y permisos</summary>
          <div class="roles-mini-list">
            <?php foreach ($asignaciones as $a): ?>
              <?php
                $adminLabel = '';
                if (isset($a['admin_apodo']) && trim((string)$a['admin_apodo']) !== '') $adminLabel = (string)$a['admin_apodo'];
                elseif (isset($a['admin_username']) && trim((string)$a['admin_username']) !== '') $adminLabel = (string)$a['admin_username'];
                else $adminLabel = '#' . (int)$a['owner_admin_id'];
                $roleCode = isset($a['rol_staff']) ? (string)$a['rol_staff'] : 'puerta';
                $roleName = tickex_staff_role_label($pdo, (int)$a['owner_admin_id'], $roleCode);
                $permKeys = tickex_staff_role_permissions($pdo, (int)$a['owner_admin_id'], $roleCode);
                $permLabels = _tickex_staff_perm_labels($permCatalog, $permKeys);
              ?>
              <div class="roles-mini-item"><strong><?php echo e($adminLabel); ?></strong> — <?php echo e($roleName); ?><?php if (!empty($permLabels)): ?>: <?php echo e(implode(', ', $permLabels)); ?><?php endif; ?></div>
            <?php endforeach; ?>
          </div>
        </details>
      </div>
    </div>

    <?php if($activeEvent): ?>
    <section class="staff-section">
      <div class="staff-section-head"><div><div class="staff-section-kicker">Acciones principales</div><h2>¿Qué necesitás hacer?</h2></div><p>Solo aparecen las herramientas habilitadas para tu rol en este evento.</p></div>
      <div class="staff-quick-actions">
        <?php if($canScan): ?><a class="staff-action primary" href="<?php echo e(tickex_route('staff_scan', array())); ?>?evento_id=<?php echo (int)$activeEventId; ?>"><strong>Escanear un QR</strong><span>Abrir cámara y validar accesos</span></a><?php endif; ?>
        <?php if($canSell): ?><a class="staff-action" href="<?php echo e(tickex_route('staff_sales', array())); ?>?evento_id=<?php echo (int)$activeEventId; ?>"><strong>Registrar venta</strong><span>Cargar una operación de puerta</span></a><?php endif; ?>
        <?php if($canReports): ?><a class="staff-action" href="<?php echo e(tickex_route('staff_activity', array())); ?>?evento_id=<?php echo (int)$activeEventId; ?>"><strong>Ver actividad</strong><span>Revisar los últimos check-ins</span></a><?php endif; ?>
      </div>
    </section>

    <section class="staff-section">
      <div class="staff-section-head"><div><div class="staff-section-kicker">Tu jornada</div><h2>Turnos y tareas</h2></div><p>Tu horario, sector y pendientes asignados por el organizador.</p></div>
      <div class="staff-work">
      <section class="staff-work-card"><h3>Mis turnos</h3><?php if(!$myShifts): ?><div class="muted">No tenés turnos cargados.</div><?php endif; ?><?php foreach($myShifts as $shift): ?><div class="staff-work-row"><div><strong><?php echo e($shift['area']?:'Turno'); ?></strong><div class="muted"><?php echo e($shift['starts_at']); ?><?php echo $shift['ends_at']?' → '.e($shift['ends_at']):''; ?></div></div></div><?php endforeach; ?></section>
      <section class="staff-work-card"><h3>Mis tareas</h3><?php if(!$myTasks): ?><div class="muted">No tenés tareas pendientes.</div><?php endif; ?><?php foreach($myTasks as $task): ?><form method="post" class="staff-work-row <?php echo $task['status']==='done'?'done':''; ?>"><input type="hidden" name="csrf" value="<?php echo e(tickex_csrf_token()); ?>"><input type="hidden" name="action" value="staff_task_toggle"><input type="hidden" name="task_id" value="<?php echo (int)$task['id']; ?>"><input type="hidden" name="evento_id" value="<?php echo (int)$activeEventId; ?>"><div><strong><?php echo e($task['title']); ?></strong><?php if($task['due_at']): ?><div class="muted">Hasta <?php echo e($task['due_at']); ?></div><?php endif; ?></div><button class="btn secondary" type="submit"><?php echo $task['status']==='done'?'Reabrir':'Listo'; ?></button></form><?php endforeach; ?></section>
      </div>
    </section>
    <?php endif; ?>

    <section class="staff-section">
      <div class="staff-section-head"><div><div class="staff-section-kicker">Control de acceso</div><h2>Personas e ingresos</h2></div><p>Buscá por nombre o código y confirmá el ingreso sin perder el contexto del evento.</p></div>
    <div class="staff-list">
      <div class="staff-list-head">
        <h3 style="margin:0 0 10px 0;">Lista de ingresos</h3>
        <form method="get" class="staff-search">
          <input type="hidden" name="evento_id" value="<?php echo (int)$activeEventId; ?>">
          <div>
            <label>Buscar por nombre o código</label>
            <input type="text" name="q" value="<?php echo e($q); ?>" placeholder="Ej: Juan Pérez">
          </div>
          <button class="btn secondary" type="submit">Buscar</button>
        </form>
      </div>

      <?php if ($flashOk !== ''): ?><div class="flash ok" style="margin:10px 12px;"><?php echo e($flashOk); ?></div><?php endif; ?>
      <?php if ($flashErr !== ''): ?><div class="flash err" style="margin:10px 12px;"><?php echo e($flashErr); ?></div><?php endif; ?>

      <div class="entry-list">
        <?php if (empty($rowsEntradas)): ?>
          <div class="entry-item"><div class="entry-main">No hay ingresos para mostrar.</div></div>
        <?php else: ?>
          <?php foreach ($rowsEntradas as $r): ?>
            <?php
              $src = isset($r['source']) ? (string)$r['source'] : 'STR';
              $checked = !empty($r['is_checked_in']);
              $entryId = isset($r['ticket_id']) ? (int)$r['ticket_id'] : 0;
              $codigo = isset($r['ticket_ref']) ? (string)$r['ticket_ref'] : '';
            ?>
            <div class="entry-item">
              <div class="entry-main">
                <strong><?php echo e((string)($r['nombre'] ?? 'Sin nombre')); ?></strong>
                <div class="entry-meta"><?php echo e((string)($r['tipo'] ?? 'Entrada')); ?> · Código: <?php echo e($codigo); ?> · <?php echo e($src); ?></div>
              </div>
              <?php if ($checked): ?>
                <span class="entry-state ok">Check-in OK</span>
              <?php else: ?>
                <form method="post" class="swipe-form" style="margin:0;">
                  <input type="hidden" name="csrf" value="<?php echo e(tickex_csrf_token()); ?>">
                  <input type="hidden" name="action" value="staff_checkin">
                  <input type="hidden" name="entry_id" value="<?php echo (int)$entryId; ?>">
                  <input type="hidden" name="entry_source" value="<?php echo e($src); ?>">
                  <input type="hidden" name="entry_ref" value="<?php echo e($codigo); ?>">
                  <input type="hidden" name="entry_name" value="<?php echo e((string)($r['nombre'] ?? '')); ?>">
                  <input type="hidden" name="evento_id" value="<?php echo (int)$activeEventId; ?>">
                  <input type="hidden" name="q" value="<?php echo e($q); ?>">
                  <div class="swipe-wrap" data-swipe>
                    <div class="swipe-fill"></div>
                    <div class="swipe-knob">→</div>
                  </div>
                </form>
              <?php endif; ?>
            </div>
          <?php endforeach; ?>
        <?php endif; ?>
      </div>
    </div>
    </section>
  <?php endif; ?>
</div>

<nav class="staff-bottom-nav" aria-label="Navegación staff">
  <a href="<?php echo e(tickex_route('customer_home', array())); ?>"><span class="i">🏠</span><span>Inicio</span></a>
  <a href="<?php echo e(tickex_route('staff_home', array())); ?><?php echo $activeEventId > 0 ? ('?evento_id=' . (int)$activeEventId) : ''; ?>"><span class="i">📋</span><span>Gestión</span></a>
  <?php if($canScan): ?><a href="<?php echo e(tickex_route('staff_scan', array())); ?><?php echo $activeEventId > 0 ? ('?evento_id=' . (int)$activeEventId) : ''; ?>" id="btnOpenScan" class="center" aria-label="QR" title="QR">
    <span class="qr-icon" aria-hidden="true">
      <svg viewBox="0 0 24 24" role="img" focusable="false" aria-hidden="true">
        <path d="M3 3h7v7H3V3zm2 2v3h3V5H5zm9-2h7v7h-7V3zm2 2v3h3V5h-3zM3 14h7v7H3v-7zm2 2v3h3v-3H5zm11-2h2v2h-2v-2zm-2 2h2v2h-2v-2zm4 0h2v2h-2v-2zm-4 4h2v2h-2v-2zm2-2h2v2h-2v-2zm4 0h2v2h-2v-2z"></path>
      </svg>
    </span>
    <span>QR</span>
  </a><?php else: ?><span class="center"><span>QR</span></span><?php endif; ?>
  <?php if($canSell): ?><a href="<?php echo e(tickex_route('staff_sales', array())); ?><?php echo $activeEventId > 0 ? ('?evento_id=' . (int)$activeEventId) : ''; ?>"><span class="i">💸</span><span>Venta</span></a><?php else: ?><span><span>Sin venta</span></span><?php endif; ?>
  <button type="button" id="btnMore"><span class="i">☰</span><span>Más</span></button>
</nav>

<div id="staffSheet" class="staff-sheet" aria-hidden="true">
  <div class="staff-sheet-box">
    <div class="staff-sheet-links">
      <?php if($canReports): ?><a href="<?php echo e(tickex_route('staff_activity', array())); ?><?php echo $activeEventId > 0 ? ('?evento_id=' . (int)$activeEventId) : ''; ?>">Actividad check-ins</a><?php endif; ?>
      <a href="<?php echo e(tickex_route('customer_profile', array())); ?>">Mi perfil</a>
      <a href="<?php echo e(tickex_route('customer_home', array())); ?>">Dashboard usuario</a>
      <a href="<?php echo e(tickex_route('logout', array())); ?>">Cerrar sesión</a>
    </div>
  </div>
</div>

<script>
  (function () {
    var sheet = document.getElementById('staffSheet');
    var btnMore = document.getElementById('btnMore');
    var btnNotif = document.getElementById('btnNotifStaff');
    var notifPanel = document.getElementById('notifPanelStaff');
    var swipeAreas = document.querySelectorAll('[data-swipe]');

    function openSheet() { if (sheet) sheet.classList.add('open'); }
    function closeSheet() { if (sheet) sheet.classList.remove('open'); }
    function toggleNotif() { if (notifPanel) notifPanel.classList.toggle('open'); }

    if (btnMore) btnMore.addEventListener('click', function (e) { e.preventDefault(); openSheet(); });
    if (sheet) sheet.addEventListener('click', function (e) { if (e.target === sheet) closeSheet(); });

    if (btnNotif) {
      btnNotif.addEventListener('click', function (e) {
        e.preventDefault();
        e.stopPropagation();
        toggleNotif();
      });
    }
    document.addEventListener('click', function () {
      if (notifPanel) notifPanel.classList.remove('open');
    });
    if (notifPanel) notifPanel.addEventListener('click', function (e) { e.stopPropagation(); });

    function bindSwipe(el) {
      var knob = el.querySelector('.swipe-knob');
      var fill = el.querySelector('.swipe-fill');
      if (!knob || !fill) return;

      var dragging = false;
      var startX = 0;
      var current = 2;
      var max = el.clientWidth - knob.clientWidth - 2;

      function setPos(x) {
        current = Math.max(2, Math.min(max, x));
        knob.style.left = current + 'px';
        fill.style.width = (current + knob.clientWidth/2) + 'px';
      }

      function reset() { setPos(2); }

      knob.addEventListener('pointerdown', function (e) {
        dragging = true;
        startX = e.clientX - current;
        knob.setPointerCapture(e.pointerId);
      });

      knob.addEventListener('pointermove', function (e) {
        if (!dragging) return;
        setPos(e.clientX - startX);
      });

      function endDrag() {
        if (!dragging) return;
        dragging = false;
        if (current >= max * 0.78) {
          var form = el.closest('form');
          if (form) form.submit();
        } else {
          reset();
        }
      }

      knob.addEventListener('pointerup', endDrag);
      knob.addEventListener('pointercancel', endDrag);
      reset();
    }

    swipeAreas.forEach(bindSwipe);
  })();
</script>

<?php include __DIR__ . '/inc/layout_bottom.php'; ?>
