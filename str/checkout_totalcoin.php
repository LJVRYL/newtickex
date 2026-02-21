<?php
require_once __DIR__.'/inc/bootstrap.php';
require_once __DIR__.'/inc/totalcoin.php';
require_once __DIR__.'/inc/db.php';
require_once __DIR__.'/inc/mail.php';

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
$tcGo = isset($_GET['tc_go']) ? (int)$_GET['tc_go'] : 0;
$tcRid = isset($_GET['rid']) ? trim((string)$_GET['rid']) : '';
$preview = array(
  'selected' => array(),
  'total' => 0,
  'dni' => '',
  'first_name' => '',
  'last_name' => '',
  'email' => '',
  'ref' => '',
  'create_account' => 0,
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

// Si venimos de un redirect interno (POST-Redirect-GET), cargar el payment_url desde DB
// y dejar que el browser navegue a TotalCoin con JS.
if ($tcGo === 1 && $tcRid !== '' && $paymentUrl === null) {
  try {
    $pdoGo = db();
    $stGo = $pdoGo->prepare('SELECT payment_url, evento_id, ref FROM tc_orders WHERE request_id = :rid LIMIT 1');
    $stGo->execute(array(':rid' => (string)$tcRid));
    $rowGo = $stGo->fetch(PDO::FETCH_ASSOC);
    if ($rowGo && !empty($rowGo['payment_url'])) {
      $paymentUrl = (string)$rowGo['payment_url'];
      $redirectFallback = array('auto' => true, 'reason' => 'prg_get', 'hs_file' => '', 'hs_line' => 0);
      // Ajustar eventId si no estaba en GET
      if ($eventId <= 0 && isset($rowGo['evento_id'])) {
        $eventId = (int)$rowGo['evento_id'];
      }
      // No forzar step: solo mostrar flash + link + auto redirect.
    } else {
      $errors[] = 'No se encontró la orden para continuar al pago. (RID: ' . e($tcRid) . ')';
    }
  } catch (Throwable $_t) {
    $errors[] = 'No se pudo cargar la orden para continuar al pago.';
  }
}

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

// Base URL del sitio para links en emails (evita hardcodear dominio productivo)
$siteBaseUrl = rtrim($scheme . '://' . $host . $basePath, '/');

if (!function_exists('ensure_registro_pendientes_checkout')) {
  function ensure_registro_pendientes_checkout($pdo)
  {
    $pdo->exec("CREATE TABLE IF NOT EXISTS registro_pendientes (
      id INTEGER PRIMARY KEY AUTOINCREMENT,
      email TEXT NOT NULL,
      token TEXT NOT NULL,
      nombre TEXT,
      apellido TEXT,
      apodo TEXT,
      dni TEXT,
      genero TEXT,
      foto_path TEXT,
      next_url TEXT,
      creado_en TEXT,
      completado_en TEXT,
      password_hash TEXT
    )");
    $pdo->exec("CREATE UNIQUE INDEX IF NOT EXISTS idx_regpend_token ON registro_pendientes(token)");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_regpend_email ON registro_pendientes(email)");

    // Backfill para instancias existentes
    try {
      $cols = $pdo->query('PRAGMA table_info(registro_pendientes)')->fetchAll(PDO::FETCH_ASSOC);
      $hasPass = false;
      foreach ($cols as $c) {
        if (isset($c['name']) && $c['name'] === 'password_hash') { $hasPass = true; break; }
      }
      if (!$hasPass) {
        $pdo->exec('ALTER TABLE registro_pendientes ADD COLUMN password_hash TEXT');
      }
    } catch (Exception $e) {
      // ignore
    }
  }
}

if (!function_exists('tickex_send_registro_step1_from_checkout')) {
  function tickex_send_registro_step1_from_checkout($toEmail, $token, $registroId, $siteBaseUrl)
  {
    $fromEmail = 'servicio@tickex.com.ar';
    $fromName  = 'Tickex';

    $link = rtrim((string)$siteBaseUrl, '/') . '/completar_registro.php?token=' . urlencode((string)$token);
    $subject = 'Confirmá tu email en Tickex';

    $body  = "Hola,\n\n";
    $body .= "Para continuar tu registro en Tickex, hacé clic en este enlace:\n";
    $body .= $link . "\n\n";
    $body .= "Si no fuiste vos, podés ignorar este mensaje.\n\n";
    $body .= "Tickex\n";

    $extraParams = '-f ' . $fromEmail;

    return tickex_send_mail_template($toEmail, 'registro_step1', array(
      'link' => $link,
    ), array(
      'context'       => 'registro_step1',
      'related_table' => 'registro_pendientes',
      'related_id'    => $registroId,
    ), array(
      'subject'      => $subject,
      'body'         => $body,
      'from_email'   => $fromEmail,
      'from_name'    => $fromName,
      'reply_to'     => $fromEmail,
      'extra_params' => $extraParams,
      'is_html'      => 0,
    ));
  }
}

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

      // Límite UX/anti-abuso: no más de 10 por tipo en una compra.
      if ($qty > 10) {
        $errors[] = 'Máximo 10 entradas por tipo en una compra.';
        continue;
      }
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
    $createAccount = 0;
    if (isset($_POST['create_account'])) {
      $v = (string)$_POST['create_account'];
      if ($v === '1' || $v === 'on' || $v === 'true') $createAccount = 1;
    }

    $preview['selected'] = $selectedTickets;
    $preview['total'] = $total;
    $preview['ref'] = $ref;
    $preview['dni'] = $dni;
    $preview['first_name'] = $first;
    $preview['last_name'] = $last;
    $preview['email'] = $email;
    $preview['create_account'] = $createAccount;

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
      // Registro opcional (best effort): crea/actualiza registro_pendientes y envía mail de confirmación
      if ($createAccount === 1) {
        try {
          $pdoReg = db();
          $pdoReg->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
          ensure_registro_pendientes_checkout($pdoReg);

          $stEx = $pdoReg->prepare('SELECT id, completado_en, password_hash FROM registro_pendientes WHERE email = :e ORDER BY id DESC LIMIT 1');
          $stEx->execute(array(':e' => $email));
          $ex = $stEx->fetch(PDO::FETCH_ASSOC);

          $alreadyHasPassword = false;
          if ($ex) {
            $ph = isset($ex['password_hash']) ? (string)$ex['password_hash'] : '';
            $ce = isset($ex['completado_en']) ? (string)$ex['completado_en'] : '';
            if ($ph !== '' || $ce !== '') {
              $alreadyHasPassword = true;
            }
          }

          if (!$alreadyHasPassword) {
            $token = function_exists('random_bytes') ? bin2hex(random_bytes(16)) : sha1(uniqid(mt_rand(), true));
            $ahora = date('Y-m-d H:i:s');
            $nextForReg = 'checkout_totalcoin.php?event=' . (int)$eventId;

            if ($ex) {
              $stmtUp = $pdoReg->prepare("UPDATE registro_pendientes
                SET token = :t,
                    completado_en = NULL,
                    next_url = :n,
                    creado_en = :c,
                    nombre = :fn,
                    apellido = :ln,
                    dni = :dni
                WHERE id = :id");
              $stmtUp->execute(array(
                ':t' => $token,
                ':n' => $nextForReg,
                ':c' => $ahora,
                ':fn' => $first,
                ':ln' => $last,
                ':dni' => $dni,
                ':id' => (int)$ex['id'],
              ));
              $regId = (int)$ex['id'];
            } else {
              $stmtIns = $pdoReg->prepare('INSERT INTO registro_pendientes (email, token, nombre, apellido, dni, next_url, creado_en) VALUES (:e, :t, :fn, :ln, :dni, :n, :c)');
              $stmtIns->execute(array(
                ':e' => $email,
                ':t' => $token,
                ':fn' => $first,
                ':ln' => $last,
                ':dni' => $dni,
                ':n' => $nextForReg,
                ':c' => $ahora,
              ));
              $regId = (int)$pdoReg->lastInsertId();
            }

            $mailOk = tickex_send_registro_step1_from_checkout($email, $token, $regId, $siteBaseUrl);
            try {
              if ($lastDebugId !== '' && function_exists('tc_debug_log')) {
                tc_debug_log($lastDebugId, 'register_mail', array(
                  'event_id' => (int)$eventId,
                  'email' => (string)$email,
                  'reg_id' => (int)$regId,
                  'mail_ok' => (bool)$mailOk,
                ));
              }
            } catch (Throwable $_t) {
              // ignore
            }
          } else {
            try {
              if ($lastDebugId !== '' && function_exists('tc_debug_log')) {
                tc_debug_log($lastDebugId, 'register_skip_existing', array(
                  'event_id' => (int)$eventId,
                  'email' => (string)$email,
                  'reg_id' => (int)$ex['id'],
                ));
              }
            } catch (Throwable $_t) {
              // ignore
            }
          }
        } catch (Throwable $_t) {
          try {
            if ($lastDebugId !== '' && function_exists('tc_debug_log')) {
              tc_debug_log($lastDebugId, 'register_error', array(
                'event_id' => (int)$eventId,
                'msg' => (string)$_t->getMessage(),
              ));
            }
          } catch (Throwable $_t2) {
            // ignore
          }
          // No bloquear pago por error de registro/email
        }
      }

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

          // UX: Post-Redirect-Get interno (evita depender de redirects externos).
          if ($paymentUrl) {
            // Intentar extraer requestId para construir una URL interna de continuación.
            $ridForGo = '';
            try {
              $uGo = @parse_url($paymentUrl);
              if (is_array($uGo) && isset($uGo['query'])) {
                $qGo = array();
                @parse_str($uGo['query'], $qGo);
                if (isset($qGo['requestId'])) $ridForGo = (string)$qGo['requestId'];
              }
              if ($ridForGo === '') {
                $posGo = strpos($paymentUrl, 'requestId=');
                if ($posGo !== false) {
                  $ridForGo = substr($paymentUrl, $posGo + 10);
                }
              }
            } catch (Throwable $_t) {
              $ridForGo = '';
            }

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
                  'rid' => (string)$ridForGo,
                ));
              }
            } catch (Throwable $_t) {
              // ignore
            }

            // Redirigir a una URL interna (GET) para evitar problemas con redirects externos.
            if (!$hs && $ridForGo !== '') {
              header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
              header('Pragma: no-cache');
              header('Location: checkout_totalcoin.php?event=' . (int)$eventId . '&tc_go=1&rid=' . urlencode((string)$ridForGo), true, 303);
              exit;
            }

            // Fallback: si no pudimos hacer PRG (headers enviados o no pudimos extraer rid),
            // quedarnos en esta misma respuesta y dejar que el browser navegue con JS.
            $redirectFallback = array('auto' => true, 'reason' => ($ridForGo !== '' ? 'prg_unavailable' : 'no_rid'), 'hs_file' => (string)$hsFile, 'hs_line' => (int)$hsLine);
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
  .tickex-safe-bottom { padding-bottom:calc(24px + constant(safe-area-inset-bottom)); padding-bottom:calc(24px + env(safe-area-inset-bottom)); }
  .tickex-hidden { display:none !important; }
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
    <div class="card" style="margin:0 0 12px 0;background:var(--panel-2);border-color:var(--line);">
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

          <?php $isLoggedCliente = isset($_SESSION['usuario_id']) && (int)$_SESSION['usuario_id'] > 0; ?>
          <?php if (!$isLoggedCliente): ?>
            <label style="grid-column:1 / -1;display:flex;gap:10px;align-items:flex-start;">
              <input type="checkbox" name="create_account" value="1" style="margin-top:4px;" <?php echo !empty($preview['create_account']) ? 'checked' : ''; ?>>
              <span>
                Crear cuenta con estos datos (te enviamos un email para confirmar y definir tu contraseña)
              </span>
            </label>
          <?php endif; ?>

          <div style="grid-column:1 / -1;display:flex;gap:8px;flex-wrap:wrap;align-items:center;">
            <button class="btn" type="submit">Confirmar y pagar</button>
            <a class="btn secondary" href="<?php echo e($_SERVER['REQUEST_URI']); ?>">Volver</a>
          </div>
        </form>
      </div>
    </div>
  <?php else: ?>

  <form method="post" id="checkoutFlow" class="tickex-safe-bottom" style="display:grid;gap:12px;">
    <input type="hidden" name="csrf" value="<?php echo e($csrfTok); ?>">
    <input type="hidden" name="action" value="pay">
    <input type="hidden" name="ref" value="<?php echo e($defaults['ref'] !== '' ? $defaults['ref'] : ('str-' . $eventId . '-' . time())); ?>">
    <input type="hidden" name="aff" value="<?php echo (int)$revendedorId; ?>">

    <div class="card" style="background:var(--panel-2);border-color:var(--line);margin:0 0 12px 0;">
      <h3 style="margin:0 0 8px;">Seleccioná tus entradas</h3>
      <div style="font-size:14px;color:var(--muted);margin-bottom:10px;">Elegí la cantidad (máx 10 por tipo). Si dejás en 0, no se agrega.</div>

      <div id="stepSelect">
        <?php foreach ($entryOptions as $idx => $opt): 
          $avail = $opt['avail'];
          if ($avail === null) {
            $maxQty = 10;
          } elseif ($avail > 0) {
            $maxQty = $avail;
          } else {
            $maxQty = 0;
          }
          if ($maxQty > 10) $maxQty = 10;
          $isSoldOut = ($avail !== null && $avail <= 0);
        ?>
          <div style="display:flex;align-items:center;gap:12px;padding:10px 0;border-bottom:1px solid var(--line);flex-wrap:wrap;">
            <input type="hidden" name="ticket_id[<?php echo $idx; ?>]" value="<?php echo e($opt['id']); ?>">
            <div style="min-width:220px;flex:1;">
              <div style="font-weight:700;line-height:1.2;" data-ticket-name="<?php echo e($opt['name']); ?>"><?php echo e($opt['name']); ?></div>
              <div style="color:var(--muted);">
                $<?php echo e(number_format($opt['price'],0,',','.')); ?>
                <?php if($avail !== null): ?> · Disponibles: <?php echo (int)$avail; ?><?php endif; ?>
                <?php if($isSoldOut): ?> · Agotado<?php endif; ?>
              </div>
            </div>
            <div style="display:flex;align-items:center;gap:6px;">
              <span style="color:var(--muted);">Cantidad</span>
              <select
                name="qty[<?php echo $idx; ?>]"
                data-idx="<?php echo $idx; ?>"
                data-price="<?php echo e((string)$opt['price']); ?>"
                data-name="<?php echo e((string)$opt['name']); ?>"
                style="min-width:70px;"
                <?php echo $isSoldOut ? 'disabled' : ''; ?>
              >
                <?php for($i=0;$i<=$maxQty;$i++): ?>
                  <option value="<?php echo $i; ?>" <?php echo $i===0?'selected':''; ?>><?php echo $i; ?></option>
                <?php endfor; ?>
              </select>
            </div>
          </div>
        <?php endforeach; ?>

        <div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:10px;margin-top:12px;">
          <div style="font-size:18px;font-weight:700;">Total: <span id="totalDisplay">$0</span></div>
          <button class="btn" type="button" id="btnContinue">Continuar</button>
        </div>
      </div>

      <div id="stepConfirm" class="tickex-hidden" style="margin-top:12px;">
        <div class="card" style="margin:0;background:transparent;border:1px solid var(--line);">
          <div class="muted" style="font-size:12px;">Confirmación</div>
          <ul id="confirmLines" style="margin:8px 0 0 18px;"></ul>
          <div style="margin-top:10px;font-size:16px;font-weight:700;">Total: <span id="totalConfirm">$0</span></div>
        </div>

        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:10px;align-items:end;margin-top:10px;">
          <label>
            DNI
            <input type="text" name="dni" value="<?php echo e($defaults['dni']); ?>" id="inpDni" disabled>
          </label>
          <label>
            Nombre
            <input type="text" name="first_name" value="<?php echo e($defaults['first_name']); ?>" id="inpFirst" disabled>
          </label>
          <label>
            Apellido
            <input type="text" name="last_name" value="<?php echo e($defaults['last_name']); ?>" id="inpLast" disabled>
          </label>
          <label style="grid-column:1 / -1;">
            Email (acá te llegan las entradas)
            <input type="email" name="email" value="<?php echo e($defaults['email']); ?>" id="inpEmail" disabled>
          </label>

          <?php $isLoggedCliente = isset($_SESSION['usuario_id']) && (int)$_SESSION['usuario_id'] > 0; ?>
          <?php if (!$isLoggedCliente): ?>
            <label style="grid-column:1 / -1;display:flex;gap:10px;align-items:flex-start;">
              <input type="checkbox" name="create_account" value="1" style="margin-top:4px;" id="chkCreate" disabled>
              <span>
                Crear cuenta con estos datos (te enviamos un email para confirmar y definir tu contraseña)
              </span>
            </label>
          <?php endif; ?>

          <div style="grid-column:1 / -1;display:flex;gap:8px;flex-wrap:wrap;align-items:center;">
            <button class="btn" type="submit" id="btnPay">Confirmar y pagar</button>
            <button class="btn secondary" type="button" id="btnBack">Volver</button>
          </div>
        </div>
      </div>

    </div>
  </form>

  <?php endif; ?>
</div>

<script>
  (function(){
    const form = document.getElementById('checkoutFlow');
    const qtySelects = document.querySelectorAll('#checkoutFlow select[name^="qty"]');
    const totalDisplay = document.getElementById('totalDisplay');
    const stepSelect = document.getElementById('stepSelect');
    const stepConfirm = document.getElementById('stepConfirm');
    const confirmLines = document.getElementById('confirmLines');
    const totalConfirm = document.getElementById('totalConfirm');
    const btnContinue = document.getElementById('btnContinue');
    const btnBack = document.getElementById('btnBack');

    const inpDni = document.getElementById('inpDni');
    const inpFirst = document.getElementById('inpFirst');
    const inpLast = document.getElementById('inpLast');
    const inpEmail = document.getElementById('inpEmail');
    const chkCreate = document.getElementById('chkCreate');

    if (!form || !totalDisplay || !qtySelects.length) {
      return;
    }

    function readLines() {
      const lines = [];
      qtySelects.forEach(sel => {
        const qty = parseInt(sel.value || '0', 10) || 0;
        const price = parseFloat(sel.getAttribute('data-price') || '0') || 0;
        const name = sel.getAttribute('data-name') || '';
        if (qty > 0) {
          lines.push({ name, qty, price, total: price * qty });
        }
      });
      return lines;
    }

    function recalc() {
      const lines = readLines();
      let total = 0;
      lines.forEach(ln => { total += (ln.total || 0); });
      totalDisplay.textContent = '$' + total.toLocaleString('es-AR');
      return { lines, total };
    }

    function setConfirmEnabled(enabled) {
      const on = !!enabled;
      if (inpDni) { inpDni.disabled = !on; inpDni.required = on; }
      if (inpFirst) { inpFirst.disabled = !on; inpFirst.required = on; }
      if (inpLast) { inpLast.disabled = !on; inpLast.required = on; }
      if (inpEmail) { inpEmail.disabled = !on; inpEmail.required = on; }
      if (chkCreate) { chkCreate.disabled = !on; }
    }

    function goConfirm() {
      const r = recalc();
      if (!r.lines.length || r.total <= 0) {
        alert('Elegí al menos 1 entrada.');
        return;
      }
      if (confirmLines) {
        confirmLines.innerHTML = '';
        r.lines.forEach(ln => {
          const li = document.createElement('li');
          li.innerHTML = '<strong>' + (ln.name || '') + '</strong>' +
            ' · x' + (ln.qty || 0) +
            ' · $' + (Number(ln.price || 0)).toLocaleString('es-AR');
          confirmLines.appendChild(li);
        });
      }
      if (totalConfirm) {
        totalConfirm.textContent = '$' + r.total.toLocaleString('es-AR');
      }

      if (stepSelect) stepSelect.classList.add('tickex-hidden');
      if (stepConfirm) stepConfirm.classList.remove('tickex-hidden');
      setConfirmEnabled(true);

      try {
        stepConfirm.scrollIntoView({ behavior: 'smooth', block: 'start' });
      } catch (e) {}
    }

    function goBack() {
      if (stepConfirm) stepConfirm.classList.add('tickex-hidden');
      if (stepSelect) stepSelect.classList.remove('tickex-hidden');
      setConfirmEnabled(false);
      try {
        stepSelect.scrollIntoView({ behavior: 'smooth', block: 'start' });
      } catch (e) {}
    }

    qtySelects.forEach(sel => {
      sel.addEventListener('change', function(){
        recalc();
      });
    });

    if (btnContinue) btnContinue.addEventListener('click', goConfirm);
    if (btnBack) btnBack.addEventListener('click', goBack);

    // Confirm inputs disabled until stepConfirm
    setConfirmEnabled(false);

    recalc();
  })();
</script>
<?php include __DIR__.'/inc/layout_bottom.php'; ?>
