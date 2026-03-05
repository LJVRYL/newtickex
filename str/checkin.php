<?php
require_once __DIR__.'/inc/bootstrap.php';
require_once __DIR__.'/inc/unified_tickets.php';
$title = "Check-in – TICKEX";

date_default_timezone_set('America/Argentina/Buenos_Aires');

$pdo = db();

// Obtener asignaciones de staff (multi-evento)
function get_staff_event_ids($pdo, $staffId) {
  $stmt = $pdo->prepare("SELECT evento_id FROM staff_eventos WHERE staff_id = :sid");
  $stmt->execute(array(':sid'=>$staffId));
  $rows = $stmt->fetchAll(PDO::FETCH_COLUMN);
  return $rows ? array_map('intval', $rows) : array();
}

$codigo = isset($_GET['c']) ? trim($_GET['c']) : '';
$eventoIdGet = isset($_GET['evento_id']) ? (int)$_GET['evento_id'] : 0;

// Si está logueado, saco rol
$isLogged = !empty($_SESSION['usuario']);
$tipoGlobal = $isLogged ? (isset($_SESSION['tipo_global']) ? $_SESSION['tipo_global'] : '') : '';
$rolEvento  = $isLogged ? (isset($_SESSION['rol_evento']) ? $_SESSION['rol_evento'] : '') : '';
$eventoIdSes = $isLogged ? (isset($_SESSION['evento_id']) ? (int)$_SESSION['evento_id'] : 0) : 0;

function tickex_chk_candidates($rawCode) {
  $raw = trim((string)$rawCode);
  if ($raw === '') return array();
  $cands = array($raw, urldecode($raw));
  if (preg_match('/^https?:\/\//i', $raw)) {
    $parts = @parse_url($raw);
    if (is_array($parts)) {
      if (!empty($parts['query'])) {
        parse_str($parts['query'], $qs);
        foreach (array('c','codigo','code','ticket_ref','order_id','ticket') as $k) {
          if (!empty($qs[$k])) $cands[] = (string)$qs[$k];
        }
      }
      if (!empty($parts['path'])) {
        $last = basename((string)$parts['path']);
        if ($last !== '' && $last !== '/') $cands[] = $last;
      }
    }
  }
  $out = array();
  foreach ($cands as $c) {
    $c = trim((string)$c);
    if ($c !== '' && !in_array($c, $out, true)) $out[] = $c;
  }
  return $out;
}

function tickex_chk_bridge_source($pdo) {
  $hasView = false; $hasTable = false;
  try { $hasView = (bool)$pdo->query("SELECT 1 FROM sqlite_master WHERE type='view' AND name='v_senforms_bridge_status' LIMIT 1")->fetchColumn(); } catch (Exception $e) {}
  try { $hasTable = (bool)$pdo->query("SELECT 1 FROM sqlite_master WHERE type='table' AND name='senforms_bridge_tickets' LIMIT 1")->fetchColumn(); } catch (Exception $e) {}
  if (!$hasView && !$hasTable) return array(null, array());
  $colsView = $hasView ? detect_table_columns($pdo, 'v_senforms_bridge_status') : array();
  $colsTable = $hasTable ? detect_table_columns($pdo, 'senforms_bridge_tickets') : array();
  $useTable = false;
  if ($hasTable && (isset($colsTable['selected_type_name']) || isset($colsTable['selected_type']) || !$hasView)) $useTable = true;
  $source = ($useTable || !$hasView) ? 'senforms_bridge_tickets' : 'v_senforms_bridge_status';
  return array($source, $useTable ? $colsTable : $colsView);
}

function tickex_chk_bridge_paid($row) {
  if (isset($row['is_paid'])) return ((int)$row['is_paid'] === 1);
  foreach (array('payment_state','payment_status','pago_status','pn_estado','status') as $f) {
    if (isset($row[$f])) {
      $state = strtoupper(trim((string)$row[$f]));
      if (in_array($state, array('SUCCESS','APROBADO','APPROVED','COMPLETED','PAID'), true)) return true;
    }
  }
  return false;
}

function tickex_chk_bridge_multiplier($row) {
  $mult = 1;
  foreach (array('quantity','cantidad','qty','num_entries') as $qf) {
    if (isset($row[$qf]) && is_numeric($row[$qf])) {
      $v = (int)$row[$qf];
      if ($v > 1) { $mult = $v; break; }
    }
  }
  $tipo = '';
  foreach (array('selected_type_name','selected_type','ticket_type','ticket_name','product_name','entry_type','event_name','ticket_class','category','nombre','name','tipo') as $tf) {
    if (isset($row[$tf]) && trim((string)$row[$tf]) !== '') { $tipo = (string)$row[$tf]; break; }
  }
  if ($tipo !== '') {
    $norm = preg_replace('/\s+/', '', strtolower($tipo));
    if (strpos($norm, '2x1') !== false && $mult < 2) $mult = 2;
  }
  return $mult;
}

$ticket = null;
$eventoNombre = '';
$eventoSlug = '';
$scanCandidates = tickex_chk_candidates($codigo);

if (!empty($scanCandidates)) {
  $ph = array(); $params = array();
  foreach ($scanCandidates as $i => $c) { $k = ':c'.$i; $ph[] = $k; $params[$k] = $c; }
  $stmt = $pdo->prepare("SELECT id, nombre, email, codigo, tipo, evento_id, checked_in, checked_in_at FROM entradas WHERE codigo IN (" . implode(',', $ph) . ") LIMIT 1");
  $stmt->execute($params);
  $row = $stmt->fetch(PDO::FETCH_ASSOC);
  if ($row) {
    $ticket = array(
      'source' => 'STR',
      'id' => (int)$row['id'],
      'nombre' => (string)$row['nombre'],
      'email' => (string)$row['email'],
      'codigo' => (string)$row['codigo'],
      'tipo' => (string)$row['tipo'],
      'evento_id' => (int)$row['evento_id'],
      'checked_in' => (int)$row['checked_in'],
      'checked_in_at' => $row['checked_in_at'],
      'multiplier' => 1,
      'event_slug' => '',
      'bridge_ref' => '',
    );
  }
}

if (!$ticket && !empty($scanCandidates)) {
  list($bridgeSource, $bridgeCols) = tickex_chk_bridge_source($pdo);
  if ($bridgeSource) {
    $refCols = array('ticket_ref','order_id','codigo','code','qr_code','token');
    $usableRefCols = array();
    foreach ($refCols as $rc) if (isset($bridgeCols[$rc])) $usableRefCols[] = $rc;
    if (!empty($usableRefCols)) {
      $where = array(); $params = array(); $n = 0;
      foreach ($usableRefCols as $col) {
        foreach ($scanCandidates as $cand) {
          $k = ':b' . $n++;
          $where[] = "$col = $k";
          $params[$k] = $cand;
        }
      }
      try {
        $sql = "SELECT * FROM $bridgeSource WHERE (" . implode(' OR ', $where) . ") LIMIT 1";
        $stB = $pdo->prepare($sql);
        $stB->execute($params);
        $rowB = $stB->fetch(PDO::FETCH_ASSOC);
        if ($rowB && tickex_chk_bridge_paid($rowB)) {
          $nombre = '';
          if (isset($rowB['first_name']) || isset($rowB['last_name'])) {
            $nombre = trim((string)($rowB['first_name'] ?? '') . ' ' . (string)($rowB['last_name'] ?? ''));
          }
          if ($nombre === '') $nombre = (string)($rowB['buyer_name'] ?? ($rowB['nombre'] ?? 'Entrada SenForms'));
          $email = (string)($rowB['buyer_email'] ?? ($rowB['email'] ?? ''));
          $tipo = (string)($rowB['selected_type_name'] ?? ($rowB['selected_type'] ?? ($rowB['ticket_type'] ?? 'Tickex / SenForms')));
          $bridgeRef = (string)($rowB['ticket_ref'] ?? ($rowB['order_id'] ?? ($rowB['codigo'] ?? '')));
          $isChecked = isset($rowB['is_checked_in']) ? (int)$rowB['is_checked_in'] : (isset($rowB['checked_in']) ? (int)$rowB['checked_in'] : 0);
          $ticket = array(
            'source' => 'TICKEX',
            'id' => (int)($rowB['id'] ?? ($rowB['legacy_ticket_id'] ?? 0)),
            'nombre' => $nombre,
            'email' => $email,
            'codigo' => $bridgeRef !== '' ? $bridgeRef : (string)$codigo,
            'tipo' => $tipo,
            'evento_id' => 0,
            'checked_in' => $isChecked,
            'checked_in_at' => ($rowB['checked_in_at'] ?? null),
            'multiplier' => tickex_chk_bridge_multiplier($rowB),
            'event_slug' => (string)($rowB['event_slug'] ?? ''),
            'bridge_ref' => $bridgeRef,
          );
        }
      } catch (Exception $e) {
        // ignore
      }
    }
  }
}

if ($ticket) {
  $eid = (int)$ticket['evento_id'];
  if ($eid > 0) {
    $stmtEv = $pdo->prepare("SELECT nombre, slug FROM eventos WHERE id = :id LIMIT 1");
    $stmtEv->execute(array(':id' => $eid));
    $ev = $stmtEv->fetch(PDO::FETCH_ASSOC);
    if ($ev) {
      $eventoNombre = (string)$ev['nombre'];
      $eventoSlug = (string)$ev['slug'];
    }
  } elseif (!empty($ticket['event_slug'])) {
    $eventoSlug = (string)$ticket['event_slug'];
    try {
      $stEv = $pdo->prepare("SELECT id, nombre FROM eventos WHERE slug = :s LIMIT 1");
      $stEv->execute(array(':s' => $eventoSlug));
      $ev = $stEv->fetch(PDO::FETCH_ASSOC);
      if ($ev) {
        $ticket['evento_id'] = (int)$ev['id'];
        $eventoNombre = (string)$ev['nombre'];
      }
    } catch (Exception $e) {}
  }
}

$puedeCheckin = false;
if ($isLogged) {
  if ($tipoGlobal === 'staff_evento' && $rolEvento === 'puerta') $puedeCheckin = true;
  if ($tipoGlobal === 'admin_evento' || $tipoGlobal === 'super_admin' || $tipoGlobal === 'superadmin') $puedeCheckin = true;
}

$eventoOk = false;
if ($ticket) {
  $eidEntrada = (int)($ticket['evento_id'] ?? 0);
  if ($eventoId > 0 && $eidEntrada > 0 && $eidEntrada === $eventoId) {
    $eventoOk = true;
  } elseif ($eventoId > 0 && $ticket['source'] === 'TICKEX' && !empty($ticket['event_slug'])) {
    $mappedSlugs = get_mapped_bridge_slugs($pdo, $eventoId);
    if (empty($mappedSlugs)) {
      try {
        $sstmt = $pdo->prepare("SELECT slug FROM eventos WHERE id = :eid LIMIT 1");
        $sstmt->execute(array(':eid' => $eventoId));
        $srow = $sstmt->fetch(PDO::FETCH_ASSOC);
        if ($srow && !empty($srow['slug'])) $mappedSlugs = array($srow['slug']);
      } catch (Exception $e) {}
    }
    if (!empty($mappedSlugs) && in_array((string)$ticket['event_slug'], $mappedSlugs, true)) {
      $eventoOk = true;
      if ($ticket['evento_id'] <= 0) $ticket['evento_id'] = $eventoId;
    }
  } elseif ($isLogged && $tipoGlobal === 'staff_evento') {
    $staffSessionId = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : (isset($_SESSION['usuario_id']) ? (int)$_SESSION['usuario_id'] : 0);
    $staffIds = get_staff_event_ids($pdo, $staffSessionId);
    if ($eidEntrada > 0 && in_array($eidEntrada, $staffIds, true)) {
      $eventoOk = true;
      if ($eventoId <= 0) {
        $eventoId = $eidEntrada;
        $_SESSION['evento_id'] = $eventoId;
      }
    } elseif ($ticket['source'] === 'TICKEX' && !empty($ticket['event_slug'])) {
      foreach ($staffIds as $sidEv) {
        $slugs = get_mapped_bridge_slugs($pdo, (int)$sidEv);
        if (in_array((string)$ticket['event_slug'], $slugs, true)) {
          $eventoOk = true;
          if ($eventoId <= 0) {
            $eventoId = (int)$sidEv;
            $_SESSION['evento_id'] = $eventoId;
          }
          if ($ticket['evento_id'] <= 0) $ticket['evento_id'] = (int)$sidEv;
          break;
        }
      }
    }
  }
}

$hizoCheckinAhora = false;
$mensaje = '';

if ($ticket && $ticket['source'] === 'TICKEX') {
  $uses = get_bridge_checkin_used_counts($pdo, array((int)$ticket['id']));
  $used = isset($uses[(int)$ticket['id']]) ? (int)$uses[(int)$ticket['id']] : ((int)$ticket['checked_in'] === 1 ? (int)$ticket['multiplier'] : 0);
  if ($used < 0) $used = 0;
  if ($used > (int)$ticket['multiplier']) $used = (int)$ticket['multiplier'];
  $ticket['used_count'] = $used;
  $ticket['checked_in'] = ($used >= (int)$ticket['multiplier']) ? 1 : 0;
}

if ($ticket && $puedeCheckin && $eventoOk) {
  $canConsume = true;
  if ($ticket['source'] === 'TICKEX') {
    $usedNow = isset($ticket['used_count']) ? (int)$ticket['used_count'] : 0;
    $canConsume = ($usedNow < (int)$ticket['multiplier']);
  } else {
    $canConsume = ((int)$ticket['checked_in'] === 0);
  }

  if ($canConsume) {
    $ahora = date('Y-m-d H:i:s');
    if ($ticket['source'] === 'TICKEX') {
      try {
        $stTbl = $pdo->query("SELECT 1 FROM sqlite_master WHERE type='table' AND name='senforms_bridge_tickets' LIMIT 1");
        if ($stTbl && $stTbl->fetch(PDO::FETCH_ASSOC)) {
          $use = increment_bridge_checkin_use($pdo, (int)$ticket['id'], (int)$ticket['multiplier']);
          if (!empty($use['ok']) && !empty($use['changed'])) {
            $ticket['used_count'] = (int)$use['used'];
            $ticket['checked_in'] = !empty($use['full']) ? 1 : 0;
            if (!empty($use['full'])) {
              $upd = $pdo->prepare("UPDATE senforms_bridge_tickets SET is_checked_in=1, checked_in_at=:f WHERE id=:id");
              $upd->execute(array(':f' => $ahora, ':id' => (int)$ticket['id']));
            }
            $ticket['checked_in_at'] = $ahora;
            $hizoCheckinAhora = true;
            $mensaje = "Check-in SenForms/Tickex realizado (" . (int)$use['used'] . "/" . (int)$use['max'] . ").";
          } else {
            $mensaje = "Esta entrada SenForms/Tickex ya agotó sus ingresos.";
          }
        } else {
          $mensaje = "No se pudo actualizar check-in de SenForms en este entorno.";
        }
      } catch (Exception $e) {
        $mensaje = "No se pudo realizar check-in SenForms/Tickex.";
      }
    } else {
      $upd = $pdo->prepare("UPDATE entradas SET checked_in=1, checked_in_at=:f WHERE id=:id");
      $upd->execute(array(':f'=>$ahora, ':id'=>(int)$ticket['id']));
      $ticket['checked_in'] = 1;
      $ticket['checked_in_at'] = $ahora;
      $hizoCheckinAhora = true;
      $mensaje = "Check-in realizado correctamente.";
    }
  } else {
    if ($ticket['source'] === 'TICKEX') {
      $mensaje = "Esta entrada SenForms/Tickex ya agotó sus ingresos.";
    } else {
      $mensaje = "Esta entrada ya estaba checkeada.";
    }
  }
} elseif ($ticket && $puedeCheckin && !$eventoOk) {
  $mensaje = "Este ticket no pertenece a tu evento.";
} elseif ($ticket && !$puedeCheckin) {
  $mensaje = "Entrada válida. Para hacer check-in, iniciá sesión en Puerta.";
}

$baseUrl    = 'https://str.tickex.com.ar';
$checkinUrl = $baseUrl . '/checkin.php?c=' . urlencode($codigo);
$qrUrl      = 'https://api.qrserver.com/v1/create-qr-code/?size=260x260&data=' . urlencode($checkinUrl);
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8" />
  <title><?php echo e($title); ?></title>
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <link rel="stylesheet" href="assets/str.css">
  <style>
    body{display:flex;align-items:center;justify-content:center;min-height:100vh;}
    .card{max-width:520px;width:100%;}
    .status{
      display:inline-block;padding:4px 10px;border-radius:999px;
      font-size:12px;letter-spacing:.06em;text-transform:uppercase;font-weight:800;
      margin:6px 0 10px;
    }
    .status.ok{background:#123b20;color:#a6f3b7;border:1px solid var(--ok);}
    .status.err{background:#3b1616;color:#ffb3b3;border:1px solid var(--err);}
    .qr img{max-width:260px;border-radius:10px;background:#fff;padding:6px;}
    .muted{color:var(--muted);font-size:13px;}
  </style>
</head>
<body>
  <div class="wrap">
    <div class="card">
      <h2>Check-in – Entrada</h2>

      <?php if(!$ticket): ?>
        <div class="status err">Código no válido</div>
        <p>No encontramos una entrada para este código.</p>

      <?php else: ?>
        <?php if($eventoNombre || $eventoSlug): ?>
          <div class="muted">
            Evento: <strong><?php echo e($eventoNombre); ?></strong>
            <?php if($eventoSlug): ?> (<?php echo e($eventoSlug); ?>)<?php endif; ?>
          </div>
        <?php endif; ?>

        <?php if((int)$ticket['checked_in']===1): ?>
          <div class="status ok">Check-in OK</div>
        <?php else: ?>
          <div class="status err">Pendiente</div>
        <?php endif; ?>

        <?php if(isset($ticket['source']) && $ticket['source'] === 'TICKEX'): ?>
          <div class="muted" style="margin-bottom:8px;">Origen: SenForms / Tickex bridge</div>
        <?php endif; ?>

        <?php if(isset($ticket['multiplier']) && (int)$ticket['multiplier'] > 1): ?>
          <div class="flash warn">Reconocido como 2x1 (<?php echo (int)$ticket['multiplier']; ?> ingresos).</div>
          <div class="muted" style="margin-bottom:8px;">Usos registrados: <?php echo (int)($ticket['used_count'] ?? 0); ?>/<?php echo (int)$ticket['multiplier']; ?></div>
        <?php endif; ?>

        <?php if($mensaje): ?>
          <div class="flash <?php echo ($eventoOk && $puedeCheckin) ? 'ok' : 'warn'; ?>">
            <?php echo e($mensaje); ?>
          </div>
        <?php endif; ?>

        <p>
          #<?php echo (int)$ticket['id']; ?> —
          <strong><?php echo e($ticket['nombre']); ?></strong>
          <div class="muted"><?php echo e($ticket['tipo']); ?></div>
        </p>

        <?php if(!empty($ticket['email'])): ?>
          <div class="muted"><?php echo e($ticket['email']); ?></div>
        <?php endif; ?>

        <div class="qr" style="margin-top:14px;">
          <img src="<?php echo e($qrUrl); ?>" alt="QR de entrada">
        </div>

        <div class="muted" style="margin-top:8px;word-break:break-all;">
          <?php echo e($checkinUrl); ?>
        </div>

        <?php if(!empty($ticket['checked_in_at'])): ?>
          <div class="muted" style="margin-top:8px;">
            Checkeada el: <?php echo e($ticket['checked_in_at']); ?>
          </div>
        <?php endif; ?>

        <?php if($isLogged && $eventoId>0): ?>
          <div style="margin-top:14px;">
            <a class="btn secondary" href="puerta.php?evento_id=<?php echo (int)$eventoId; ?>">Volver a Puerta</a>
          </div>
        <?php endif; ?>
      <?php endif; ?>

    </div>
  </div>
</body>
</html>
