<?php
require_once __DIR__.'/inc/bootstrap.php';
require_once __DIR__.'/inc/totalcoin.php';
require_once __DIR__.'/inc/db.php';

$title = 'Checkout TotalCoin (Tickex)';
$errors = array();
$paymentUrl = null;
$freeSuccess = false;
$revendedorId = 0;
$revendedorName = '';
$eventOwnerAdminId = 0;
$step = 'select';
$csrfTok = function_exists('tickex_csrf_token') ? (string)tickex_csrf_token() : '';
$isPrivDebug = false;
$gatewayDebug = '';
$lastDebugId = '';
$redirectFallback = array('auto' => false, 'reason' => '', 'hs_file' => '', 'hs_line' => 0);
$preview = array(
  'selected' => array(),
  'total' => 0,
  'dni' => '',
  'first_name' => '',
  'last_name' => '',
  'email' => '',
  'ref' => '',
);

// Captura revendedor (afiliado): GET ?aff=ID o cookie tickex_aff
$affCandidate = '';
if (isset($_GET['aff']) && $_GET['aff'] !== '') {
  $affCandidate = (string)$_GET['aff'];
} elseif (isset($_COOKIE['tickex_aff']) && $_COOKIE['tickex_aff'] !== '') {
  $affCandidate = (string)$_COOKIE['tickex_aff'];
}
if ($affCandidate !== '' && preg_match('/^\d+$/', $affCandidate)) {
  $revendedorId = (int)$affCandidate;
}
$defaults = array(
  'amount'      => $_GET['amount'] ?? '',
  'concept'     => $_GET['concept'] ?? ($_GET['event'] ?? ''),
  'dni'         => $_GET['dni'] ?? '',
  'ref'         => $_GET['ref'] ?? (isset($_GET['event']) ? ('str-' . preg_replace('/[^a-zA-Z0-9_-]/','', (string)$_GET['event']) . '-' . time()) : ''),
  'last_name'   => $_GET['last_name'] ?? '',
  'first_name'  => $_GET['first_name'] ?? '',
  'email'       => $_GET['email'] ?? '',
);
$eventId = isset($_GET['event']) ? (int)$_GET['event'] : 0;

// Datos del usuario logueado (si los hubiera)
$cu = current_user();

try {
  if (function_exists('is_admin') && is_admin()) {
    $isPrivDebug = true;
  } else {
    $tg = isset($cu['tipo_global']) ? (string)$cu['tipo_global'] : (isset($_SESSION['tipo_global']) ? (string)$_SESSION['tipo_global'] : '');
    $rol = isset($cu['rol']) ? (string)$cu['rol'] : (isset($_SESSION['rol']) ? (string)$_SESSION['rol'] : '');
    if (in_array($tg, array('admin_evento','super_admin','superadmin'), true) || $rol === 'admin') {
      $isPrivDebug = true;
    }
  }
} catch (Exception $e) {
  $isPrivDebug = false;
}

if (!function_exists('_tickex_pick_first_col')) {
  function _tickex_pick_first_col($colMap, $candidates) {
    foreach ($candidates as $c) {
      if (isset($colMap[$c])) return $c;
    }
    return null;
  }
}

if (!function_exists('_tickex_validate_revendedor_for_event')) {
  // Devuelve array(id, name) o array(0,'') si inválido.
  function _tickex_validate_revendedor_for_event($pdo, $revendedorId, $eventOwnerAdminId) {
    $rid = (int)$revendedorId;
    if ($rid <= 0) return array(0, '');
    try {
      $st = $pdo->prepare('SELECT id, nombre, activo, owner_admin_id, usuario_admin_id FROM revendedores WHERE id = :id LIMIT 1');
      $st->execute(array(':id' => $rid));
      $rv = $st->fetch(PDO::FETCH_ASSOC);
      if (!$rv || (isset($rv['activo']) && (int)$rv['activo'] !== 1)) {
        return array(0, '');
      }

      $name = isset($rv['nombre']) ? (string)$rv['nombre'] : '';
      $owner = isset($rv['owner_admin_id']) ? (int)$rv['owner_admin_id'] : 0;
      $legacyOwner = isset($rv['usuario_admin_id']) ? (int)$rv['usuario_admin_id'] : 0;
      $eoid = (int)$eventOwnerAdminId;

      if ($eoid > 0) {
        if ($owner > 0) {
          if ($owner !== $eoid) return array(0, '');
        } else {
          // compat: si no tiene owner_admin_id pero tiene usuario_admin_id igual, lo aceptamos y backfilleamos
          if ($legacyOwner !== $eoid) return array(0, '');
          try {
            $stUp = $pdo->prepare('UPDATE revendedores SET owner_admin_id = :oid WHERE id = :id AND (owner_admin_id IS NULL OR owner_admin_id = 0)');
            $stUp->execute(array(':oid' => $eoid, ':id' => $rid));
          } catch (Exception $_e) {
            // ignore
          }
        }
      }

      return array((int)$rv['id'], $name);
    } catch (Exception $e) {
      return array(0, '');
    }
  }
}

// Validar revendedor en DB (si existe) y re-setear cookie (last-click)
if ($revendedorId > 0) {
  try {
    $pdoAff = db();
    $stRev = $pdoAff->prepare('SELECT id, nombre, activo FROM revendedores WHERE id = :id LIMIT 1');
    $stRev->execute(array(':id' => $revendedorId));
    $rv = $stRev->fetch(PDO::FETCH_ASSOC);
    if (!$rv || (isset($rv['activo']) && (int)$rv['activo'] !== 1)) {
      $revendedorId = 0;
    } else {
      $revendedorId = (int)$rv['id'];
      $revendedorName = isset($rv['nombre']) ? (string)$rv['nombre'] : '';
    }
  } catch (Exception $e) {
    // no bloquear el checkout si falla validación
  }
}

$sessionEmail = $_SESSION['usuario_email'] ?? ($_SESSION['email'] ?? ($cu['email'] ?? ($_SESSION['usuario'] ?? '')));
$sessionFirst = $_SESSION['first_name'] ?? '';
$sessionLast  = $_SESSION['last_name'] ?? '';
$sessionDni   = $_SESSION['dni'] ?? '';

// Si el usuario está logueado como cliente pero no tenemos datos en sesión,
// intentar cargarlos desde registro_pendientes para no caer en loop de login.
try {
  $uidCliente = isset($_SESSION['usuario_id']) ? (int)$_SESSION['usuario_id'] : 0;
  if ($uidCliente > 0 && ($sessionEmail === '' || $sessionFirst === '' || $sessionLast === '' || $sessionDni === '')) {
    $pdoU = db();
    $stU = $pdoU->prepare('SELECT nombre, apellido, email, dni FROM registro_pendientes WHERE id = :id LIMIT 1');
    $stU->execute(array(':id' => $uidCliente));
    $ru = $stU->fetch(PDO::FETCH_ASSOC);
    if ($ru) {
      if ($sessionEmail === '' && !empty($ru['email'])) $sessionEmail = (string)$ru['email'];
      if ($sessionFirst === '' && !empty($ru['nombre'])) $sessionFirst = (string)$ru['nombre'];
      if ($sessionLast === '' && !empty($ru['apellido'])) $sessionLast = (string)$ru['apellido'];
      if ($sessionDni === '' && !empty($ru['dni'])) $sessionDni = (string)$ru['dni'];
      // mantener espejo básico en sesión para próximas pantallas
      if (!isset($_SESSION['usuario_email']) && $sessionEmail !== '') $_SESSION['usuario_email'] = $sessionEmail;
      if (!isset($_SESSION['first_name']) && $sessionFirst !== '') $_SESSION['first_name'] = $sessionFirst;
      if (!isset($_SESSION['last_name']) && $sessionLast !== '') $_SESSION['last_name'] = $sessionLast;
      if (!isset($_SESSION['dni']) && $sessionDni !== '') $_SESSION['dni'] = $sessionDni;
    }
  }
} catch (Exception $e) {
  // no bloquear el checkout
}

// Fallback: si no hay first/last, intentar a partir del nombre de sesión
if ($sessionFirst === '' || $sessionLast === '') {
  $full = '';
  if (isset($_SESSION['usuario_nombre']) && trim((string)$_SESSION['usuario_nombre']) !== '') {
    $full = trim((string)$_SESSION['usuario_nombre']);
  } elseif (isset($_SESSION['nombre']) && trim((string)$_SESSION['nombre']) !== '') {
    $full = trim((string)$_SESSION['nombre']);
  } elseif (isset($cu['display_name']) && trim((string)$cu['display_name']) !== '') {
    $full = trim((string)$cu['display_name']);
  }
  if ($full !== '') {
    $parts = preg_split('/\s+/', $full);
    if ($sessionFirst === '' && !empty($parts[0])) $sessionFirst = (string)$parts[0];
    if ($sessionLast === '' && count($parts) > 1) {
      array_shift($parts);
      $sessionLast = trim(implode(' ', $parts));
    }
  }
}

// Tomar email/nombre de sesión si no vienen en GET
if ($defaults['email'] === '' && $sessionEmail !== '') $defaults['email'] = $sessionEmail;
if ($defaults['first_name'] === '' && $sessionFirst !== '') $defaults['first_name'] = $sessionFirst;
if ($defaults['last_name'] === '' && $sessionLast !== '') $defaults['last_name'] = $sessionLast;
if ($defaults['dni'] === '' && $sessionDni !== '') $defaults['dni'] = $sessionDni;

// Cargar evento y entradas desde Tickex (SQLite). Enriquecer con SenForms si estuviera accesible.
$event = null; $ticketTypes = array();
$flyerUrl = null;
$eventName = 'Evento'; $eventDate = ''; $eventLoc  = '';
if ($eventId > 0) {
  // Tickex local
  try {
    $pdoLocal = db();

    // Determinar columna de creador (si existe)
    $creatorCol = null;
    try {
      $evColsInfo = $pdoLocal->query('PRAGMA table_info(eventos)')->fetchAll(PDO::FETCH_ASSOC);
      $evColMap = array();
      foreach ($evColsInfo as $ci) {
        if (isset($ci['name'])) $evColMap[$ci['name']] = true;
      }
      $creatorCol = _tickex_pick_first_col($evColMap, array('creado_por_admin_id','creador_id','admin_id','usuario_admin_id'));
    } catch (Exception $_e) {
      $creatorCol = null;
    }

    $stEv = $pdoLocal->prepare('SELECT * FROM eventos WHERE id = :id LIMIT 1');
    $stEv->execute(array(':id' => $eventId));
    $evRow = $stEv->fetch(PDO::FETCH_ASSOC);
    if ($evRow) {
      if ($creatorCol && isset($evRow[$creatorCol]) && (int)$evRow[$creatorCol] > 0) {
        $eventOwnerAdminId = (int)$evRow[$creatorCol];
      }
      $eventName = $evRow['nombre'] ?? $eventName;
      $eventDate = $evRow['fecha_desde'] ?? $eventDate;
      $eventLoc  = $evRow['lugar'] ?? ($evRow['ubicacion'] ?? $eventLoc);

      if (!empty($evRow['flyer_filename'])) {
        $ff = $evRow['flyer_filename'];
        $pff = __DIR__ . '/' . $ff;
        if (file_exists($pff)) {
          $flyerUrl = $ff;
        }
      }
      if (!$flyerUrl && !empty($evRow['flyer'])) {
        $flyerUrl = $evRow['flyer']; // URL remota (fallback)
      }
    }

    // Tipos desde tipos_entrada (solo públicos/activos si la columna existe)
    $ticketTypes = array();
    $hasTipos = false; $colsTe = array();
    try {
      $test = $pdoLocal->query("SELECT name FROM sqlite_master WHERE type='table' AND name='tipos_entrada' LIMIT 1");
      if ($test && $test->fetch(PDO::FETCH_ASSOC)) {
        $hasTipos = true;
        $colsInfo = $pdoLocal->query("PRAGMA table_info(tipos_entrada)")->fetchAll(PDO::FETCH_ASSOC);
        foreach ($colsInfo as $ci) { $colsTe[$ci['name']] = true; }
      }
    } catch (Exception $e) {}

    if ($hasTipos) {
      $colActivo = isset($colsTe['activo']) ? 'activo' : null;
      $colPublic = null; $colVentaHasta = null;
      foreach (array('publico','visible_publico','venta_publico') as $c) {
        if (isset($colsTe[$c])) { $colPublic = $c; break; }
      }
      if (isset($colsTe['venta_hasta'])) { $colVentaHasta = 'venta_hasta'; }
      $sqlTp = "SELECT id, nombre, tipo, precio, cantidad_total, cantidad_disponible";
      if ($colActivo) $sqlTp .= ", $colActivo AS activo";
      if ($colPublic) $sqlTp .= ", $colPublic AS publico";
      if ($colVentaHasta) $sqlTp .= ", $colVentaHasta AS venta_hasta";
      $sqlTp .= " FROM tipos_entrada WHERE evento_id = :id";
      $conds = array();
      if ($colActivo) $conds[] = "$colActivo = 1";
      if ($colPublic) $conds[] = "$colPublic = 1";
      if ($conds) $sqlTp .= " AND " . implode(' AND ', $conds);
      $sqlTp .= " ORDER BY id ASC";
      $stTp = $pdoLocal->prepare($sqlTp);
      $stTp->execute(array(':id' => $eventId));
      $today = date('Y-m-d');
      while ($r = $stTp->fetch(PDO::FETCH_ASSOC)) {
        if ($colVentaHasta && !empty($r['venta_hasta'])) {
          $limit = substr($r['venta_hasta'],0,10);
          if ($limit !== '' && $today > $limit) {
            continue; // vencida
          }
        }
        if ($colPublic && isset($r[$colPublic]) && (int)$r[$colPublic] !== 1) {
          continue; // ocultas para el público
        }
        $ticketTypes[] = array(
          'Id'    => $r['id'],
          'Name'  => $r['nombre'] ?? 'Entrada',
          'Price' => isset($r['precio']) ? (float)$r['precio'] : 0,
          'Available' => isset($r['cantidad_disponible']) ? (int)$r['cantidad_disponible'] : (isset($r['cantidad_total']) ? (int)$r['cantidad_total'] : null),
        );

        // Ocultar agotadas para que no aparezcan en el checkout
        $lastIdx = count($ticketTypes) - 1;
        if ($lastIdx >= 0) {
          $availVal = $ticketTypes[$lastIdx]['Available'];
          if ($availVal !== null && $availVal <= 0) {
            array_pop($ticketTypes);
          }
        }
      }
    }

    // Si no hubo tipos_entrada, inferir de entradas cargadas
    if (empty($ticketTypes)) {
      $stTp = $pdoLocal->prepare("SELECT COALESCE(tipo,'General') AS tipo, MAX(COALESCE(monto_pagado,0)) AS precio FROM entradas WHERE evento_id = :id GROUP BY tipo ORDER BY tipo ASC");
      $stTp->execute(array(':id' => $eventId));
      while ($r = $stTp->fetch(PDO::FETCH_ASSOC)) {
        $ticketTypes[] = array('Id' => $r['tipo'], 'Name' => $r['tipo'], 'Price' => (float)$r['precio']);
      }
    }
  } catch (Exception $e) {
    // ignorar fallas locales
  }
}

// Enforce: si el evento tiene dueño (admin), el revendedor debe pertenecer a ese admin.
if ($revendedorId > 0) {
  try {
    $pdoAff = db();
    $val = _tickex_validate_revendedor_for_event($pdoAff, $revendedorId, $eventOwnerAdminId);
    $revendedorId = (int)$val[0];
    $revendedorName = (string)$val[1];
    if ($revendedorId > 0) {
      tickex_set_cookie('tickex_aff', (string)$revendedorId, 30, '/');
    } else {
      // limpiar cookie si no corresponde a este evento
      tickex_set_cookie('tickex_aff', '', 1, '/');
    }
  } catch (Exception $e) {
    // ignore
  }
}

$nextUrl = $_SERVER['REQUEST_URI'];

// Login en el mismo host (soporta localhost/dev). Evita hardcodear el dominio productivo.
$scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$host = isset($_SERVER['HTTP_HOST']) && $_SERVER['HTTP_HOST'] !== '' ? $_SERVER['HTTP_HOST'] : 'localhost';
$basePath = rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? '/'), '/\\');
if ($basePath === '' || $basePath === '.') { $basePath = ''; }
$loginBase = $scheme . '://' . $host . $basePath . '/login.php';

// Opciones de entradas: si no hay, usar fallback
$entryOptions = array();
foreach ($ticketTypes as $tt) {
  $price = isset($tt['Price']) ? (float)$tt['Price'] : 0;
  $entryOptions[] = array(
    'id'    => $tt['Id'],
    'name'  => $tt['Name'],
    'price' => $price,
    'avail' => isset($tt['Available']) ? $tt['Available'] : null,
  );
}
if (empty($entryOptions)) {
  $fallbackPrice = $defaults['amount'] !== '' ? (float)$defaults['amount'] : 0;
  $entryOptions[] = array(
    'id'    => 'general',
    'name'  => $defaults['concept'] !== '' ? $defaults['concept'] : 'Entrada general',
    'price' => $fallbackPrice > 0 ? $fallbackPrice : 0,
    'avail' => null,
  );
}

// Mapa rápido para validar selección en POST
$optionMap = array();
foreach ($entryOptions as $opt) {
  $optionMap[(string)$opt['id']] = $opt;
}

if (!function_exists('_tickex_parse_selection')) {
  // Normaliza selección del form a array de líneas: id,name,qty,price
  function _tickex_parse_selection($optionMap, $ids, $qtys, &$errors)
  {
    $selectedTickets = array();
    $total = 0;
    if (!is_array($ids) || !is_array($qtys)) {
      return array($selectedTickets, $total);
    }
    foreach ($ids as $i => $tid) {
      $tidStr = (string)$tid;
      $qty = isset($qtys[$i]) ? (int)$qtys[$i] : 0;
      if ($qty <= 0) continue;
      if (!isset($optionMap[$tidStr])) continue;
      $opt = $optionMap[$tidStr];
      $maxAvail = isset($opt['avail']) && $opt['avail'] !== null ? (int)$opt['avail'] : 999999;
      if ($qty > $maxAvail) {
        $errors[] = 'Cantidad excede disponibilidad para ' . e($opt['name']);
        continue;
      }
      $lineTotal = $opt['price'] * $qty;
      $total += $lineTotal;
      $selectedTickets[] = array('id' => $tidStr, 'name' => $opt['name'], 'qty' => $qty, 'price' => $opt['price']);
    }
    return array($selectedTickets, $total);
  }
}

if (!function_exists('_tickex_validate_buyer_fields')) {
  function _tickex_validate_buyer_fields($dni, $last, $first, $email, &$errors)
  {
    if ($dni === '') $errors[] = 'DNI requerido';
    if ($last === '') $errors[] = 'Apellido requerido';
    if ($first === '') $errors[] = 'Nombre requerido';
    if ($email === '' || strpos($email, '@') === false) $errors[] = 'Email inválido';
  }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $action = isset($_POST['action']) ? (string)$_POST['action'] : 'preview';

  // Para poder trazar cualquier fallo del paso de pago
  if ($action === 'pay') {
    $tmpRefForId = isset($_POST['ref']) ? (string)$_POST['ref'] : (string)($defaults['ref'] ?? '');
    if ($tmpRefForId === '') $tmpRefForId = 'str-' . $eventId;
    $lastDebugId = 'TC-' . date('Ymd-His') . '-' . substr(sha1($tmpRefForId . '|' . (string)$eventId . '|' . microtime(true)), 0, 8);
    try {
      if (function_exists('tc_debug_log')) {
        tc_debug_log($lastDebugId, 'pay_attempt', array('event_id' => (int)$eventId, 'ref' => $tmpRefForId));
      }
    } catch (Exception $_e) {
      // ignore
    }
  }

  // Validar CSRF solo al momento de pagar (acción que cambia estado / llama gateway)
  if ($action === 'pay') {
    $providedCsrf = isset($_POST['csrf']) ? (string)$_POST['csrf'] : '';
    if (function_exists('tickex_csrf_verify') && !tickex_csrf_verify($providedCsrf)) {
      $errors[] = 'CSRF inválido. Actualizá la página e intentá de nuevo.' . ($lastDebugId !== '' ? (' (ID: ' . $lastDebugId . ')') : '');
      try {
        if ($lastDebugId !== '' && function_exists('tc_debug_log')) {
          tc_debug_log($lastDebugId, 'csrf_invalid', array('event_id' => (int)$eventId));
        }
      } catch (Exception $_e) {
      }
    }
  }

  $concept = $eventName;
  $ref = trim($_POST['ref'] ?? ($defaults['ref'] !== '' ? $defaults['ref'] : ('str-' . $eventId . '-' . time())));
  if ($ref === '') $errors[] = 'Referencia requerida';

  $affPost = isset($_POST['aff']) ? (int)$_POST['aff'] : 0;
  if ($revendedorId <= 0 && $affPost > 0) {
    $revendedorId = $affPost;
  }
  // Revalidar revendedor en POST (anti-tamper + ownership por evento)
  if ($revendedorId > 0) {
    try {
      $pdoAff = db();
      $val = _tickex_validate_revendedor_for_event($pdoAff, $revendedorId, $eventOwnerAdminId);
      $revendedorId = (int)$val[0];
      $revendedorName = (string)$val[1];
    } catch (Exception $e) {
      $revendedorId = 0;
      $revendedorName = '';
    }
  }

  // Selección (paso 1 o paso 2)
  $selIds = isset($_POST['selected_id']) ? $_POST['selected_id'] : (isset($_POST['ticket_id']) ? $_POST['ticket_id'] : array());
  $selQty = isset($_POST['selected_qty']) ? $_POST['selected_qty'] : (isset($_POST['qty']) ? $_POST['qty'] : array());
  list($selectedTickets, $total) = _tickex_parse_selection($optionMap, $selIds, $selQty, $errors);

  if ($total <= 0 && empty($selectedTickets)) {
    $errors[] = 'Seleccioná al menos una entrada.';
  }

  if ($action === 'preview') {
    // Pasar al paso de confirmación (form visible)
    $step = 'confirm';
    $preview['selected'] = $selectedTickets;
    $preview['total'] = $total;
    $preview['ref'] = $ref;
    $preview['dni'] = trim((string)($defaults['dni'] ?? ''));
    $preview['first_name'] = trim((string)($defaults['first_name'] ?? ''));
    $preview['last_name'] = trim((string)($defaults['last_name'] ?? ''));
    $preview['email'] = trim((string)($defaults['email'] ?? ''));
  } elseif ($action === 'pay') {
    $step = 'confirm';
    $dni = trim((string)($_POST['dni'] ?? $defaults['dni']));
    $last = trim((string)($_POST['last_name'] ?? $defaults['last_name']));
    $first = trim((string)($_POST['first_name'] ?? $defaults['first_name']));
    $email = trim((string)($_POST['email'] ?? $defaults['email']));

    $preview['selected'] = $selectedTickets;
    $preview['total'] = $total;
    $preview['ref'] = $ref;
    $preview['dni'] = $dni;
    $preview['first_name'] = $first;
    $preview['last_name'] = $last;
    $preview['email'] = $email;

    _tickex_validate_buyer_fields($dni, $last, $first, $email, $errors);

    if (!empty($errors) && $lastDebugId !== '') {
      // Asegurar correlación si falla antes del gateway
      $hasIdAlready = false;
      foreach ($errors as $erTxt) {
        if (strpos((string)$erTxt, $lastDebugId) !== false) { $hasIdAlready = true; break; }
      }
      if (!$hasIdAlready) {
        $errors[] = 'Detalle: validación falló. (ID: ' . $lastDebugId . ')';
      }
      try {
        if (function_exists('tc_debug_log')) {
          tc_debug_log($lastDebugId, 'validation_failed', array('event_id' => (int)$eventId));
        }
      } catch (Exception $_e) {
      }
    }

    if (empty($errors)) {
      if ($total > 0) {
        try {
          try {
            if ($lastDebugId !== '' && function_exists('tc_debug_log')) {
              tc_debug_log($lastDebugId, 'gateway_start', array('event_id' => (int)$eventId, 'ref' => (string)$ref, 'amount' => (float)$total));
            }
          } catch (Throwable $_t) {
            // ignore
          }

          $paymentUrl = tc_checkout($total, $concept, $dni, $ref, $last, $first, $email);

          try {
            if ($lastDebugId !== '' && function_exists('tc_debug_log')) {
              tc_debug_log($lastDebugId, 'gateway_ok', array('event_id' => (int)$eventId, 'ref' => (string)$ref));
            }
          } catch (Throwable $_t) {
            // ignore
          }

        // Persistir orden (requestId) para auditoría/atribución (revendedor)
        if ($paymentUrl) {
          $requestId = '';
          try {
            $u = @parse_url($paymentUrl);
            if (is_array($u) && isset($u['query'])) {
              $q = array();
              @parse_str($u['query'], $q);
              if (isset($q['requestId'])) {
                $requestId = (string)$q['requestId'];
              }
            }
            if ($requestId === '') {
              $pos = strpos($paymentUrl, 'requestId=');
              if ($pos !== false) {
                $requestId = substr($paymentUrl, $pos + 10);
              }
            }
          } catch (Exception $_e) {}

          if ($requestId !== '') {
            try {
              $pdoSave = db();
              $ticketsJson = '';
              try { $ticketsJson = json_encode($selectedTickets); } catch (Exception $_e) { $ticketsJson = ''; }
              $ip = isset($_SERVER['REMOTE_ADDR']) ? (string)$_SERVER['REMOTE_ADDR'] : '';
              $ua = isset($_SERVER['HTTP_USER_AGENT']) ? (string)$_SERVER['HTTP_USER_AGENT'] : '';
              $stIns = $pdoSave->prepare("INSERT OR IGNORE INTO tc_orders (request_id, state, evento_id, ref, concept, amount, buyer_dni, buyer_last, buyer_first, buyer_email, revendedor_id, selected_tickets_json, payment_url, ip, user_agent, updated_at)
                VALUES (:rid, :st, :eid, :ref, :c, :am, :dni, :bl, :bf, :be, :rev, :tj, :pu, :ip, :ua, datetime('now'))");
              $stIns->execute(array(
                ':rid' => $requestId,
                ':st'  => 'created',
                ':eid' => $eventId,
                ':ref' => $ref,
                ':c'   => $concept,
                ':am'  => $total,
                ':dni' => $dni,
                ':bl'  => $last,
                ':bf'  => $first,
                ':be'  => $email,
                ':rev' => ($revendedorId > 0 ? $revendedorId : null),
                ':tj'  => $ticketsJson,
                ':pu'  => $paymentUrl,
                ':ip'  => $ip,
                ':ua'  => $ua,
              ));

              // si ya existía, actualizar los campos de atribución
              $stUp = $pdoSave->prepare("UPDATE tc_orders SET evento_id=:eid, ref=:ref, concept=:c, amount=:am, buyer_dni=:dni, buyer_last=:bl, buyer_first=:bf, buyer_email=:be, revendedor_id=:rev, selected_tickets_json=:tj, payment_url=:pu, updated_at=datetime('now') WHERE request_id=:rid");
              $stUp->execute(array(
                ':rid' => $requestId,
                ':eid' => $eventId,
                ':ref' => $ref,
                ':c'   => $concept,
                ':am'  => $total,
                ':dni' => $dni,
                ':bl'  => $last,
                ':bf'  => $first,
                ':be'  => $email,
                ':rev' => ($revendedorId > 0 ? $revendedorId : null),
                ':tj'  => $ticketsJson,
                ':pu'  => $paymentUrl,
              ));
            } catch (Exception $e) {
              try { error_log('[TotalCoin] failed to persist order: ' . $e->getMessage()); } catch (Exception $_e) {}
            }
          }
        }

          // UX: redirigir automáticamente al checkout de TotalCoin
          if ($paymentUrl) {
            $hsFile = ''; $hsLine = 0;
            $hs = headers_sent($hsFile, $hsLine);

            try {
              if ($lastDebugId !== '' && function_exists('tc_debug_log')) {
                tc_debug_log($lastDebugId, 'redirect_attempt', array(
                  'event_id' => (int)$eventId,
                  'ref' => (string)$ref,
                  'headers_sent' => (bool)$hs,
                  'hs_file' => (string)$hsFile,
                  'hs_line' => (int)$hsLine,
                ));
              }
            } catch (Throwable $_t) {
              // ignore
            }

            if (!$hs) {
              header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
              header('Pragma: no-cache');
              header('Location: ' . $paymentUrl, true, 303);
              // Fallback extra: si por algún motivo el cliente no sigue el Location,
              // dejamos un HTML mínimo con link + redirección JS.
              $safeUrl = htmlspecialchars((string)$paymentUrl, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
              echo "<!doctype html><html><head><meta charset=\"utf-8\"><meta name=\"referrer\" content=\"no-referrer\">";
              echo "<meta http-equiv=\"refresh\" content=\"0;url=" . $safeUrl . "\">";
              echo "<title>Redirigiendo…</title></head><body style=\"font-family:system-ui,-apple-system,Segoe UI,Roboto,Arial,sans-serif;padding:20px;\">";
              echo "<h3 style=\"margin:0 0 8px;\">Redirigiendo al pago…</h3>";
              echo "<p style=\"margin:0 0 12px;\">Si no te redirige automáticamente, abrí el link:</p>";
              echo "<p><a href=\"" . $safeUrl . "\" rel=\"noopener noreferrer\">Continuar al pago</a></p>";
              echo "<script>try{window.location.replace(" . json_encode((string)$paymentUrl, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . ");}catch(e){}</script>";
              echo "</body></html>";
              exit;
            }

            // Fallback: si ya se enviaron headers (BOM/echo/warning), no hacemos exit:
            // mostramos el link y además intentamos redirigir por JS.
            $redirectFallback = array('auto' => true, 'reason' => 'headers_sent', 'hs_file' => (string)$hsFile, 'hs_line' => (int)$hsLine);
          }
        } catch (Throwable $e) {
          $debugId = $lastDebugId !== '' ? $lastDebugId : ('TC-' . date('Ymd-His') . '-' . substr(sha1((string)$ref . '|' . (string)$eventId . '|' . microtime(true)), 0, 8));
          // No exponer detalles internos del gateway al público
          try {
            error_log('[TotalCoin] ' . $debugId . ' checkout error: ' . $e->getMessage());
          } catch (Exception $_e) {
          }
          try {
            if (function_exists('tc_debug_log')) {
              $cfg = function_exists('tc_config') ? tc_config() : array();
              tc_debug_log($debugId, get_class($e) . ': ' . $e->getMessage(), array(
                'event_id' => (int)$eventId,
                'ref' => (string)$ref,
                'use_prod' => isset($cfg['use_prod']) ? (bool)$cfg['use_prod'] : null,
                'login_url' => isset($cfg['login_url']) ? (string)$cfg['login_url'] : null,
                'checkout_url' => isset($cfg['checkout_url']) ? (string)$cfg['checkout_url'] : null,
                'callback_base' => isset($cfg['callback_base']) ? (string)$cfg['callback_base'] : null,
              ));
            }
          } catch (Throwable $_e) {
            // ignore
          }
          $errors[] = 'No pudimos iniciar el pago en este momento. Probá de nuevo en unos minutos. (ID: ' . $debugId . ')';
          if ($isPrivDebug) {
            $gatewayDebug = $e->getMessage();
          }
        }
      } else {
        // Orden 100% gratuita: se marca como éxito sin pasar por TotalCoin
        $freeSuccess = true;
      }
    }
  }
}

include __DIR__.'/inc/layout_top.php';
?>
<style>
  .checkout-hero { display:grid; grid-template-columns: minmax(260px, 1fr) 1.4fr; gap:16px; align-items:start; }
  .flyer-box { position:relative; border-radius:12px; overflow:hidden; border:1px solid var(--line); background:var(--panel-2); min-height:260px; }
  .flyer-box img { width:100%; height:100%; object-fit:cover; display:block; }
  .flyer-empty { width:100%; height:100%; display:flex; align-items:center; justify-content:center; color:var(--muted); font-size:14px; }
  .meta-grid { display:grid; gap:6px; font-size:14px; color:var(--muted); }
  .meta-chip { display:inline-flex; align-items:center; gap:6px; padding:6px 10px; border:1px solid var(--line); border-radius:999px; font-size:13px; color:var(--muted); }
  .card-soft { background:var(--panel-2); border:1px solid var(--line); border-radius:12px; padding:14px 16px; }
  @media (max-width: 780px) { .checkout-hero { grid-template-columns:1fr; } }
</style>

<div class="card card-soft" style="margin-bottom:12px;">
  <div class="checkout-hero">
    <div class="flyer-box">
      <?php if ($flyerUrl): ?>
        <img src="<?php echo e($flyerUrl); ?>" alt="Flyer de <?php echo e($eventName); ?>">
      <?php else: ?>
        <div class="flyer-empty">Sin flyer</div>
      <?php endif; ?>
    </div>
    <div style="display:flex;flex-direction:column;gap:10px;">
      <div>
        <div style="font-size:14px;color:var(--muted);margin-bottom:2px;">Checkout</div>
        <h2 style="margin:0 0 6px;line-height:1.2;"><?php echo e($eventName); ?></h2>
        <div class="meta-grid">
          <?php if ($eventDate): ?><div class="meta-chip">📅 <span><?php echo e($eventDate); ?></span></div><?php endif; ?>
          <?php if ($eventLoc):  ?><div class="meta-chip">📍 <span><?php echo e($eventLoc); ?></span></div><?php endif; ?>
        </div>
      </div>
      <div style="font-size:14px;color:var(--muted);">Seleccioná las entradas y la cantidad que quieras comprar. El total se calcula automáticamente antes de ir a pagar.</div>
    </div>
  </div>
</div>

  <?php $prefillMissing = ($defaults['email']==='' || $defaults['dni']==='' || $defaults['first_name']==='' || $defaults['last_name']===''); ?>
  <?php if ($prefillMissing): ?>
    <div class="flash" style="background:var(--panel-2);border:1px solid var(--line);color:var(--muted);">
      <div style="display:flex;flex-wrap:wrap;gap:10px;align-items:center;justify-content:space-between;">
        <div>
          <strong>Tip:</strong> si completás tus datos (email/DNI/nombre/apellido) el checkout es más rápido.
        </div>
        <a class="btn secondary" href="<?php echo $loginBase.'?next='.urlencode($nextUrl); ?>">Iniciar sesión</a>
      </div>
    </div>
  <?php endif; ?>

  <?php if (!empty($errors)): ?>
    <div class="flash err">
      <ul style="margin:0 0 0 16px;">
        <?php foreach ($errors as $er): ?>
          <li><?php echo e($er); ?></li>
        <?php endforeach; ?>
      </ul>
    </div>
  <?php endif; ?>

  <?php if ($gatewayDebug !== '' && $isPrivDebug): ?>
    <div class="flash" style="background:var(--panel-2);border:1px solid var(--line);color:var(--muted);">
      <div style="font-weight:700;color:var(--text);margin-bottom:6px;">Detalle técnico (solo admin)</div>
      <div style="font-family:ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, 'Liberation Mono', 'Courier New', monospace;font-size:12px;white-space:pre-wrap;word-break:break-word;">
        <?php echo e($gatewayDebug); ?>
      </div>
    </div>
  <?php endif; ?>

  <?php if ($paymentUrl): ?>
    <div class="flash ok">
      <strong>Pago generado.</strong>
      <div>URL de pago: <a class="link" href="<?php echo e($paymentUrl); ?>" target="_blank" rel="noopener noreferrer"><?php echo e($paymentUrl); ?></a></div>
      <div style="margin-top:10px;"><a class="btn secondary" href="<?php echo e($paymentUrl); ?>" target="_blank" rel="noopener noreferrer">Ir al pago</a></div>
    </div>
    <?php if (!empty($redirectFallback['auto']) && $redirectFallback['auto'] && $paymentUrl): ?>
      <script>
        // Fallback: si no pudimos redirigir por header(Location), intentamos navegar desde el browser.
        // Esto también permite ver el link si el navegador bloquea popups.
        (function(){
          try {
            window.location.href = <?php echo json_encode($paymentUrl, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE); ?>;
          } catch (e) {}
        })();
      </script>
    <?php endif; ?>
  <?php endif; ?>
  <?php if ($freeSuccess && !$paymentUrl): ?>
    <div class="flash ok">
      <strong>Entradas gratuitas generadas.</strong>
      <div>No se requirió pago. Se registró tu selección.</div>
    </div>
  <?php endif; ?>

  <?php if ($step === 'confirm'): ?>
    <?php $csrfTokConfirm = function_exists('tickex_csrf_token') ? (string)tickex_csrf_token() : $csrfTok; ?>
    <div class="card" style="max-width:920px;margin:0 auto 12px auto;background:var(--panel-2);border-color:var(--line);">
      <h3 style="margin:0 0 8px;">Confirmación del checkout</h3>
      <div style="display:grid;gap:10px;">
        <div class="card" style="margin:0;background:transparent;border:1px solid var(--line);">
          <div class="muted" style="font-size:12px;">Entradas</div>
          <?php if (!empty($preview['selected'])): ?>
            <ul style="margin:8px 0 0 18px;">
              <?php foreach ($preview['selected'] as $ln): ?>
                <li>
                  <strong><?php echo e((string)$ln['name']); ?></strong>
                  · x<?php echo (int)$ln['qty']; ?>
                  · $<?php echo e(number_format((float)$ln['price'], 0, ',', '.')); ?>
                </li>
              <?php endforeach; ?>
            </ul>
            <div style="margin-top:10px;font-size:16px;font-weight:700;">Total: $<?php echo e(number_format((float)$preview['total'], 0, ',', '.')); ?></div>
          <?php else: ?>
            <div class="muted" style="margin-top:6px;">No hay entradas seleccionadas.</div>
          <?php endif; ?>
        </div>

        <form method="post" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:10px;align-items:end;">
          <input type="hidden" name="csrf" value="<?php echo e($csrfTokConfirm); ?>">
          <input type="hidden" name="action" value="pay">
          <input type="hidden" name="ref" value="<?php echo e($preview['ref'] !== '' ? $preview['ref'] : ($defaults['ref'] !== '' ? $defaults['ref'] : ('str-' . $eventId . '-' . time()))); ?>">
          <input type="hidden" name="aff" value="<?php echo (int)$revendedorId; ?>">

          <?php foreach (($preview['selected'] ?? array()) as $ln): ?>
            <input type="hidden" name="selected_id[]" value="<?php echo e((string)$ln['id']); ?>">
            <input type="hidden" name="selected_qty[]" value="<?php echo (int)$ln['qty']; ?>">
          <?php endforeach; ?>

          <label>
            DNI
            <input type="text" name="dni" value="<?php echo e($preview['dni']); ?>" required>
          </label>
          <label>
            Nombre
            <input type="text" name="first_name" value="<?php echo e($preview['first_name']); ?>" required>
          </label>
          <label>
            Apellido
            <input type="text" name="last_name" value="<?php echo e($preview['last_name']); ?>" required>
          </label>
          <label style="grid-column:1 / -1;">
            Email (acá te llegan las entradas)
            <input type="email" name="email" value="<?php echo e($preview['email']); ?>" required>
          </label>

          <div style="grid-column:1 / -1;display:flex;gap:8px;flex-wrap:wrap;align-items:center;">
            <button class="btn" type="submit">Confirmar y pagar</button>
            <a class="btn secondary" href="<?php echo e($_SERVER['REQUEST_URI']); ?>">Volver</a>
          </div>
        </form>
      </div>
    </div>
  <?php else: ?>

  <form method="post" id="checkoutForm" style="display:grid;gap:12px;">
    <input type="hidden" name="csrf" value="<?php echo e($csrfTok); ?>">
    <input type="hidden" name="action" value="preview">
    <div class="card" style="background:var(--panel-2);border-color:var(--line);">
      <h3 style="margin:0 0 8px;">Seleccioná tus entradas</h3>
      <?php foreach ($entryOptions as $idx => $opt): 
        $avail = $opt['avail'];
        if ($avail === null) {
          $maxQty = 10;
        } elseif ($avail > 0) {
          $maxQty = $avail;
        } else {
          $maxQty = 0;
        }
        if ($maxQty > 20) $maxQty = 20; // limitar selector para no alargar
        $isSoldOut = ($avail !== null && $avail <= 0);
      ?>
        <div style="display:flex;align-items:center;gap:12px;padding:10px 0;border-bottom:1px solid var(--line);flex-wrap:wrap;">
          <label style="display:flex;align-items:center;gap:10px;min-width:220px;">
            <input type="checkbox" name="ticket_id[<?php echo $idx; ?>]" value="<?php echo e($opt['id']); ?>" data-price="<?php echo e((string)$opt['price']); ?>" data-idx="<?php echo $idx; ?>" <?php echo $isSoldOut ? 'disabled' : ''; ?>>
            <div>
              <div style="font-weight:700;"><?php echo e($opt['name']); ?></div>
              <div style="color:var(--muted);">$<?php echo e(number_format($opt['price'],0,',','.')); ?><?php if($avail !== null): ?> · Disponibles: <?php echo (int)$avail; ?><?php endif; ?><?php if($isSoldOut): ?> · Agotado<?php endif; ?></div>
            </div>
          </label>
          <div style="display:flex;align-items:center;gap:6px;">
            <span style="color:var(--muted);">Cantidad</span>
            <select name="qty[<?php echo $idx; ?>]" data-idx="<?php echo $idx; ?>" style="min-width:70px;" <?php echo $isSoldOut ? 'disabled' : ''; ?>>
              <?php for($i=0;$i<=$maxQty;$i++): ?>
                <option value="<?php echo $i; ?>" <?php echo $i===0?'selected':''; ?>><?php echo $i; ?></option>
              <?php endfor; ?>
            </select>
          </div>
        </div>
      <?php endforeach; ?>
    </div>

    <div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:10px;">
      <div style="font-size:18px;font-weight:700;">Total: <span id="totalDisplay">$0</span></div>
      <button class="btn" type="submit">Comprar</button>
    </div>

    <!-- Campos ocultos mínimos -->
    <input type="hidden" name="ref" value="<?php echo e($defaults['ref'] !== '' ? $defaults['ref'] : ('str-' . $eventId . '-' . time())); ?>">
    <input type="hidden" name="aff" value="<?php echo (int)$revendedorId; ?>">
  </form>

  <?php endif; ?>
</div>

<script>
  (function(){
    const checkboxes = document.querySelectorAll('input[type="checkbox"][name^="ticket_id"]');
    const qtySelects = document.querySelectorAll('select[name^="qty"]');
    const totalDisplay = document.getElementById('totalDisplay');

    if (!totalDisplay || !checkboxes.length) {
      return;
    }

    function recalc() {
      let total = 0;
      checkboxes.forEach(cb => {
        const idx = cb.getAttribute('data-idx');
        const price = parseFloat(cb.getAttribute('data-price')) || 0;
        const qtyEl = document.querySelector('select[name="qty['+idx+']"]');
        const qty = qtyEl ? parseInt(qtyEl.value || '0', 10) : 0;
        if (cb.checked && qty > 0) {
          total += price * qty;
        }
      });
      totalDisplay.textContent = '$' + total.toLocaleString('es-AR');
    }

    checkboxes.forEach(cb => {
      cb.addEventListener('change', function(){
        const idx = this.getAttribute('data-idx');
        const qtyEl = document.querySelector('select[name="qty['+idx+']"]');
        if (this.checked && qtyEl && parseInt(qtyEl.value||'0',10) === 0) {
          qtyEl.value = '1';
        }
        if (!this.checked && qtyEl) {
          qtyEl.value = '0';
        }
        recalc();
      });
    });
    qtySelects.forEach(sel => {
      sel.addEventListener('change', function(){
        const idx = this.getAttribute('data-idx');
        const cb = document.querySelector('input[type="checkbox"][name="ticket_id['+idx+']"]');
        if (parseInt(this.value||'0',10) > 0) {
          if (cb) cb.checked = true;
        } else {
          if (cb) cb.checked = false;
        }
        recalc();
      });
    });

    recalc();
  })();
</script>
<?php include __DIR__.'/inc/layout_bottom.php'; ?>
