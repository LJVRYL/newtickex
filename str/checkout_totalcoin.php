<?php
require_once __DIR__.'/inc/bootstrap.php';
require_once __DIR__.'/inc/totalcoin.php';
require_once __DIR__.'/inc/db.php';
require_once __DIR__.'/inc/mail.php';
require_once __DIR__ . '/inc/tc_debug.php';
require_once __DIR__ . '/inc/order_events.php';
require_once __DIR__ . '/inc/free_checkout.php';
require_once __DIR__ . '/inc/totalcoin_callback_auth.php';
require_once __DIR__ . '/inc/totalcoin_checkout_claim.php';
require_once __DIR__ . '/inc/ticket_packages.php';
require_once __DIR__ . '/inc/communication_tracking.php';
require_once __DIR__ . '/inc/mercadopago_marketplace.php';

require_once __DIR__.'/inc/turnstile.php';

$title = 'Checkout Tickex';
$errors = array();
$paymentUrl = null;
$freeSuccess = false;
$revendedorId = 0;
$revendedorName = '';
$eventOwnerAdminId = 0;
$paymentProvider = 'totalcoin';
$paymentProviderPreferenceId = '';
$paymentProviderFee = null;
$checkoutServicePercent = 0;
$checkoutMpCostPercent = 0;
$checkoutBreakdown = null;
$marketplaceSalesEnabled = true;
$gatewayRequestId = '';
$step = 'select';
$csrfTok = function_exists('tickex_csrf_token') ? (string)tickex_csrf_token() : '';
$isPrivDebug = false;
$gatewayDebug = '';
$lastDebugId = '';
$redirectFallback = array('auto' => false, 'reason' => '', 'hs_file' => '', 'hs_line' => 0);
$tcGo = isset($_GET['tc_go']) ? (int)$_GET['tc_go'] : 0;
$tcRid = isset($_GET['rid']) ? trim((string)$_GET['rid']) : '';
$communicationTrackingToken = isset($_POST['ct']) ? (string)$_POST['ct'] : (isset($_GET['ct']) ? (string)$_GET['ct'] : '');
$communicationTrackingToken = preg_replace('/[^a-f0-9]/i', '', $communicationTrackingToken);
if (strlen($communicationTrackingToken) < 32 || strlen($communicationTrackingToken) > 96) $communicationTrackingToken = '';
$preview = array(
  'selected' => array(),
  'subtotal' => 0,
  'service_fee' => 0,
  'service_percent' => 0,
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
  'amount'      => isset($_GET['amount']) ? (string)$_GET['amount'] : '',
  'concept'     => isset($_GET['concept']) ? (string)$_GET['concept'] : (isset($_GET['event']) ? (string)$_GET['event'] : ''),
  'dni'         => isset($_GET['dni']) ? (string)$_GET['dni'] : '',
  'ref'         => isset($_GET['ref']) ? (string)$_GET['ref'] : (isset($_GET['event']) ? tickex_totalcoin_new_reference((string)$_GET['event']) : ''),
  'last_name'   => isset($_GET['last_name']) ? (string)$_GET['last_name'] : '',
  'first_name'  => isset($_GET['first_name']) ? (string)$_GET['first_name'] : '',
  'email'       => isset($_GET['email']) ? (string)$_GET['email'] : '',
);
$eventId = isset($_GET['event']) ? (int)$_GET['event'] : 0;
$freeCheckoutTypeId = 0;

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
  } catch (Exception $_t) {
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

if (!function_exists('_tickex_checkout_is_hidden_free_ticket')) {
  function _tickex_checkout_is_hidden_free_ticket($ticketRow, $freeCheckoutTypeId)
  {
    $ticketId = isset($ticketRow['Id']) ? $ticketRow['Id'] : (isset($ticketRow['id']) ? $ticketRow['id'] : null);
    if ($freeCheckoutTypeId > 0 && $ticketId !== null && ctype_digit((string)$ticketId) && (int)$ticketId === (int)$freeCheckoutTypeId) {
      return true;
    }

    $ticketPrice = isset($ticketRow['Price']) ? (float)$ticketRow['Price'] : (isset($ticketRow['price']) ? (float)$ticketRow['price'] : 0);
    if ($ticketPrice <= 0) {
      return true;
    }

    $ticketTipo = '';
    if (isset($ticketRow['tipo'])) {
      $ticketTipo = (string)$ticketRow['tipo'];
    } elseif (isset($ticketRow['Name'])) {
      $ticketTipo = (string)$ticketRow['Name'];
    } elseif (isset($ticketRow['name'])) {
      $ticketTipo = (string)$ticketRow['name'];
    }

    $ticketTipo = strtoupper(trim($ticketTipo));
    $ticketTipo = str_replace(array('Á','É','Í','Ó','Ú'), array('A','E','I','O','U'), $ticketTipo);
    if ($ticketTipo === 'FREE' || $ticketTipo === 'GRATIS' || $ticketTipo === 'CORTESIA') {
      return true;
    }

    return false;
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

$sessionEmail = isset($_SESSION['usuario_email']) ? (string)$_SESSION['usuario_email'] : (isset($_SESSION['email']) ? (string)$_SESSION['email'] : (isset($cu['email']) ? (string)$cu['email'] : (isset($_SESSION['usuario']) ? (string)$_SESSION['usuario'] : '')));
$sessionFirst = isset($_SESSION['first_name']) ? (string)$_SESSION['first_name'] : '';
$sessionLast  = isset($_SESSION['last_name']) ? (string)$_SESSION['last_name'] : '';
$sessionDni   = isset($_SESSION['dni']) ? (string)$_SESSION['dni'] : '';

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

    $eventWhere = 'id = :id';
    if (isset($evColMap['borrado_en'])) $eventWhere .= ' AND borrado_en IS NULL';
    $stEv = $pdoLocal->prepare('SELECT * FROM eventos WHERE ' . $eventWhere . ' LIMIT 1');
    $stEv->execute(array(':id' => $eventId));
    $evRow = $stEv->fetch(PDO::FETCH_ASSOC);
    if ($evRow) {
      if ($creatorCol && isset($evRow[$creatorCol]) && (int)$evRow[$creatorCol] > 0) {
        $eventOwnerAdminId = (int)$evRow[$creatorCol];
      }
      try {
        $eventPaymentConfig = tickex_mp_event_config($pdoLocal, $eventId);
        $paymentProvider = isset($eventPaymentConfig['provider']) && $eventPaymentConfig['provider'] === 'mercadopago' ? 'mercadopago' : 'totalcoin';
        if ($paymentProvider === 'mercadopago') {
          $checkoutServicePercent = isset($eventPaymentConfig['service_charge_percent']) ? (float)$eventPaymentConfig['service_charge_percent'] : 0;
          $checkoutMpCostPercent = isset($eventPaymentConfig['mp_cost_estimate_percent']) ? (float)$eventPaymentConfig['mp_cost_estimate_percent'] : 0;
          $marketplaceSalesEnabled = !empty($eventPaymentConfig['enforcement_enabled']);
        }
      } catch (Exception $_mpConfigError) {
        // Fallar cerrado: un problema de configuracion nunca debe mandar una
        // venta de un cliente a la cuenta TotalCoin de STR.
        $paymentProvider = 'unavailable';
        $marketplaceSalesEnabled = false;
      }
      if (isset($evRow['nombre']) && $evRow['nombre'] !== null) $eventName = $evRow['nombre'];
      if (isset($evRow['fecha_desde']) && $evRow['fecha_desde'] !== null) $eventDate = $evRow['fecha_desde'];
      if (isset($evRow['lugar']) && $evRow['lugar'] !== null) {
        $eventLoc = $evRow['lugar'];
      } elseif (isset($evRow['ubicacion']) && $evRow['ubicacion'] !== null) {
        $eventLoc = $evRow['ubicacion'];
      }

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
    } else {
      $errors[] = 'El evento no está disponible.';
      $eventId = 0;
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
      try {
        tickex_free_checkout_ensure_schema($pdoLocal);
        $stFreeCfg = $pdoLocal->prepare('SELECT ticket_type_id FROM event_free_checkout_configs WHERE evento_id = :eid LIMIT 1');
        $stFreeCfg->execute(array(':eid' => $eventId));
        $freeCheckoutTypeId = (int)$stFreeCfg->fetchColumn();
      } catch (Exception $e) {
        $freeCheckoutTypeId = 0;
      }

      $colActivo = isset($colsTe['activo']) ? 'activo' : null;
      $colPublic = null; $colVentaHasta = null;
      foreach (array('publico','visible_publico','venta_publico') as $c) {
        if (isset($colsTe[$c])) { $colPublic = $c; break; }
      }
      if (isset($colsTe['venta_hasta'])) { $colVentaHasta = 'venta_hasta'; }
      $sqlTp = "SELECT id, nombre, tipo, precio, cantidad_total, cantidad_disponible";
      if (isset($colsTe['qr_quantity'])) $sqlTp .= ", qr_quantity";
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
        if ($freeCheckoutTypeId > 0 && isset($r['id']) && (int)$r['id'] === $freeCheckoutTypeId) {
          continue; // reservar esta entrada para el checkout free
        }
        $ticketPrice = isset($r['precio']) ? (float)$r['precio'] : 0;
        $ticketTipo = isset($r['tipo']) ? strtoupper(trim((string)$r['tipo'])) : '';
        if ($ticketPrice <= 0) {
          continue; // nunca mostrar entradas gratis en el checkout pago
        }
        if ($ticketTipo === 'FREE' || $ticketTipo === 'GRATIS' || $ticketTipo === 'CORTESIA' || $ticketTipo === 'CORTESÍA') {
          continue; // blindaje extra por tipo lógico
        }
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
          'Name'  => isset($r['nombre']) ? $r['nombre'] : 'Entrada',
          'Price' => $ticketPrice,
          'Available' => isset($r['cantidad_disponible']) ? (int)$r['cantidad_disponible'] : (isset($r['cantidad_total']) ? (int)$r['cantidad_total'] : null),
          'QrQuantity' => tickex_ticket_qr_quantity(isset($r['qr_quantity']) ? $r['qr_quantity'] : 1),
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
        $fallbackTicket = array('Id' => $r['tipo'], 'Name' => $r['tipo'], 'Price' => (float)$r['precio'], 'QrQuantity' => 1);
        if (_tickex_checkout_is_hidden_free_ticket($fallbackTicket, $freeCheckoutTypeId)) {
          continue;
        }
        $ticketTypes[] = $fallbackTicket;
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
$basePath = rtrim(dirname(isset($_SERVER['SCRIPT_NAME']) ? $_SERVER['SCRIPT_NAME'] : '/'), '/\\');
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
  if (_tickex_checkout_is_hidden_free_ticket($tt, $freeCheckoutTypeId)) {
    continue;
  }
  $price = isset($tt['Price']) ? (float)$tt['Price'] : 0;
  $qrQuantity = tickex_ticket_qr_quantity(isset($tt['QrQuantity']) ? $tt['QrQuantity'] : 1);
  $availableQr = isset($tt['Available']) ? $tt['Available'] : null;
  $entryOptions[] = array(
    'id'    => $tt['Id'],
    'name'  => $tt['Name'],
    'price' => $price,
    'qr_quantity' => $qrQuantity,
    'avail' => tickex_ticket_package_capacity($availableQr, $qrQuantity),
    'available_qr' => $availableQr,
  );
}
if (empty($entryOptions)) {
  $fallbackPrice = $defaults['amount'] !== '' ? (float)$defaults['amount'] : 0;
  $entryOptions[] = array(
    'id'    => 'general',
    'name'  => $defaults['concept'] !== '' ? $defaults['concept'] : 'Entrada general',
    'price' => $fallbackPrice > 0 ? $fallbackPrice : 0,
    'avail' => null,
    'available_qr' => null,
    'qr_quantity' => 1,
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
      $selectedTickets[] = array(
        'id' => $tidStr,
        'name' => $opt['name'],
        'qty' => $qty,
        'price' => $opt['price'],
        'qr_quantity' => tickex_ticket_qr_quantity(isset($opt['qr_quantity']) ? $opt['qr_quantity'] : 1),
      );
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
  $isLoggedCliente = isset($_SESSION['usuario_id']) && (int)$_SESSION['usuario_id'] > 0;

  // Para poder trazar cualquier fallo del paso de pago
  if ($action === 'pay') {
    // Notificación: "Te llegó un Tickex"
    if (empty($errors)) {
      require_once __DIR__ . '/inc/notificaciones.php';
      // Buscar el usuario (cliente) por email
      $pdoNotif = db();
      $stUser = $pdoNotif->prepare('SELECT id FROM registro_pendientes WHERE email = :email LIMIT 1');
      $stUser->execute([':email' => $email]);
      $userIdNotif = (int)$stUser->fetchColumn();
      if ($userIdNotif > 0) {
        $refNotif = isset($_POST['ref']) ? trim((string)$_POST['ref']) : '';
        if ($refNotif === '') $refNotif = 'str-' . $eventId;
        add_notification($userIdNotif, '¡Te llegó un Tickex! Ya podés ver tu entrada en Mis Tickex.', 'tickex', [ 'evento_id' => $eventId, 'ref' => $refNotif ]);
      }
    }
    $tmpRefForId = isset($_POST['ref']) ? (string)$_POST['ref'] : (string)(isset($defaults['ref']) ? $defaults['ref'] : '');
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

  // Captcha anti-bot (solo para invitados; opcional si hay keys configuradas)
  if ($action === 'pay' && !$isLoggedCliente) {
    tickex_turnstile_verify_post($errors);
  }

  $concept = $eventName;
  $ref = trim(isset($_POST['ref']) ? (string)$_POST['ref'] : (string)($defaults['ref'] !== '' ? $defaults['ref'] : tickex_totalcoin_new_reference((string)$eventId)));
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

  $ticketSubtotal = $total;
  $serviceFeeAmount = 0;
  if ($paymentProvider === 'mercadopago' && $ticketSubtotal > 0) {
    $checkoutBreakdown = tickex_mp_checkout_breakdown($ticketSubtotal, $checkoutServicePercent, $checkoutMpCostPercent);
    $serviceFeeAmount = (float)$checkoutBreakdown['service_fee'];
    $total = (float)$checkoutBreakdown['checkout_total'];
    if (!$marketplaceSalesEnabled) {
      $errors[] = 'Las ventas de este organizador todavia no estan habilitadas. Conecta Mercado Pago o consulta con Tickex.';
    }
  } elseif ($paymentProvider === 'unavailable' && $ticketSubtotal > 0) {
    $errors[] = 'El medio de pago de este evento no esta disponible. No se genero ningun cobro.';
  }

  if ($total <= 0 && empty($selectedTickets)) {
    $errors[] = 'Seleccioná al menos una entrada.';
  }

  if ($action === 'preview') {
    // Pasar al paso de confirmación (form visible)
    $step = 'confirm';
    $preview['selected'] = $selectedTickets;
    $preview['subtotal'] = $ticketSubtotal;
    $preview['service_fee'] = $serviceFeeAmount;
    $preview['service_percent'] = $checkoutServicePercent;
    $preview['total'] = $total;
    $preview['ref'] = $ref;
    $preview['dni'] = trim((string)(isset($defaults['dni']) ? $defaults['dni'] : ''));
    $preview['first_name'] = trim((string)(isset($defaults['first_name']) ? $defaults['first_name'] : ''));
    $preview['last_name'] = trim((string)(isset($defaults['last_name']) ? $defaults['last_name'] : ''));
    $preview['email'] = trim((string)(isset($defaults['email']) ? $defaults['email'] : ''));
  } elseif ($action === 'pay') {
    $step = 'confirm';
    $dni = trim((string)(isset($_POST['dni']) ? $_POST['dni'] : $defaults['dni']));
    $last = trim((string)(isset($_POST['last_name']) ? $_POST['last_name'] : $defaults['last_name']));
    $first = trim((string)(isset($_POST['first_name']) ? $_POST['first_name'] : $defaults['first_name']));
    $email = trim((string)(isset($_POST['email']) ? $_POST['email'] : $defaults['email']));
    $createAccount = 0;
    if (isset($_POST['create_account'])) {
      $v = (string)$_POST['create_account'];
      if ($v === '1' || $v === 'on' || $v === 'true') $createAccount = 1;
    }

    $preview['selected'] = $selectedTickets;
    $preview['subtotal'] = $ticketSubtotal;
    $preview['service_fee'] = $serviceFeeAmount;
    $preview['service_percent'] = $checkoutServicePercent;
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
            } catch (Exception $_t) {
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
            } catch (Exception $_t) {
              // ignore
            }
          }
        } catch (Exception $_t) {
          try {
            if ($lastDebugId !== '' && function_exists('tc_debug_log')) {
              tc_debug_log($lastDebugId, 'register_error', array(
                'event_id' => (int)$eventId,
                'msg' => (string)$_t->getMessage(),
              ));
            }
          } catch (Exception $_t2) {
            // ignore
          }
          // No bloquear pago por error de registro/email
        }
      }

      if ($total > 0) {
        $claimPdo = null;
        $claimFingerprint = '';
        $claimOwned = false;
        try {
          $ticketsJson = json_encode($selectedTickets, JSON_UNESCAPED_UNICODE);
          if ($ticketsJson === false || $ticketsJson === null) $ticketsJson = '';
          $claimPdo = db();
          $claimFingerprint = tickex_totalcoin_checkout_fingerprint($eventId, $total, $email, $ticketsJson);
          $claim = tickex_totalcoin_checkout_claim($claimPdo, $ref, $claimFingerprint, 120);
          $claimResult = isset($claim['result']) ? (string)$claim['result'] : 'pending';
          if ($claimResult === 'ready' && !empty($claim['payment_url'])) {
            $paymentUrl = (string)$claim['payment_url'];
          } elseif ($claimResult === 'acquired') {
            $claimOwned = true;
          } elseif ($claimResult === 'conflict') {
            throw new Exception('La referencia ya pertenece a otra selección. Recargá el checkout.');
          } else {
            throw new Exception('El checkout ya se está generando. Esperá unos segundos y volvé a intentar.');
          }

          try {
            if ($lastDebugId !== '' && function_exists('tc_debug_log')) {
              tc_debug_log($lastDebugId, 'gateway_start', array('event_id' => (int)$eventId, 'ref' => (string)$ref, 'amount' => (float)$total));
            }
          } catch (Exception $_t) {
            // ignore
          }

          // SenForms crea un PaymentToken antes del checkout. En Tickex usamos
          // la referencia firmada para que CS/CP/CF siempre identifiquen la orden.
          if ($claimOwned) {
            if ($paymentProvider === 'mercadopago') {
              $eventPaymentConfig = tickex_mp_event_config($claimPdo, $eventId);
              if ($eventOwnerAdminId <= 0) throw new Exception('El evento no tiene un organizador asociado.');
              $mpCheckout = tickex_mp_create_preference($claimPdo, $eventOwnerAdminId, array(
                'amount' => $total,
                'concept' => $concept,
                'dni' => $dni,
                'reference' => $ref,
                'last_name' => $last,
                'first_name' => $first,
                'email' => $email,
                'event_id' => $eventId,
                'fee_percent' => isset($eventPaymentConfig['marketplace_fee_percent']) ? (float)$eventPaymentConfig['marketplace_fee_percent'] : 0,
                'marketplace_fee' => $checkoutBreakdown ? (float)$checkoutBreakdown['marketplace_fee'] : 0,
              ));
              $paymentUrl = (string)$mpCheckout['payment_url'];
              $gatewayRequestId = (string)$mpCheckout['request_id'];
              $paymentProviderPreferenceId = (string)$mpCheckout['preference_id'];
              $paymentProviderFee = (float)$mpCheckout['marketplace_fee'];
            } else {
              $tcCfg = tc_config();
              $tcCallbacks = tickex_totalcoin_build_callbacks($tcCfg['callback_base'], $ref);
              $paymentUrl = tc_checkout($total, $concept, $dni, $ref, $last, $first, $email, null, $tcCallbacks);
            }
          }

          try {
            if ($lastDebugId !== '' && function_exists('tc_debug_log')) {
              tc_debug_log($lastDebugId, 'gateway_ok', array('event_id' => (int)$eventId, 'ref' => (string)$ref));
            }
          } catch (Exception $_t) {
            // ignore
          }

          // Persistir orden (requestId) para auditoría/atribución (revendedor)
          if ($paymentUrl) {
            $requestId = (string)$gatewayRequestId;
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
                if (preg_match('/[?&]requestId=([^&]+)/', $paymentUrl, $m)) {
                  $requestId = urldecode($m[1]);
                }
              }
            } catch (Exception $_e) {}

            if ($claimOwned && $claimPdo) {
              tickex_totalcoin_checkout_claim_ready($claimPdo, $ref, $claimFingerprint, $requestId, $paymentUrl);
            }

            if ($requestId !== '') {
              try {
                $pdoSave = db();
                communication_tracking_ensure_order_columns($pdoSave);
                $communicationAttribution = communication_tracking_attribution_for_event($pdoSave, $communicationTrackingToken, $eventId);
                $ip = isset($_SERVER['REMOTE_ADDR']) ? (string)$_SERVER['REMOTE_ADDR'] : '';
                $ua = isset($_SERVER['HTTP_USER_AGENT']) ? (string)$_SERVER['HTTP_USER_AGENT'] : '';
                $stIns = $pdoSave->prepare("INSERT OR IGNORE INTO tc_orders (request_id, state, evento_id, ref, concept, amount, buyer_dni, buyer_last, buyer_first, buyer_email, revendedor_id, selected_tickets_json, payment_url, ip, user_agent, communication_campaign_id, communication_run_id, communication_recipient_fingerprint, communication_tracking_token, updated_at)
                  VALUES (:rid, :st, :eid, :ref, :c, :am, :dni, :bl, :bf, :be, :rev, :tj, :pu, :ip, :ua, :ccid, :crid, :crfp, :ctok, datetime('now'))");
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
                  ':ccid' => $communicationAttribution ? (int)$communicationAttribution['campaign_id'] : null,
                  ':crid' => $communicationAttribution ? (int)$communicationAttribution['run_id'] : null,
                  ':crfp' => $communicationAttribution ? (string)$communicationAttribution['recipient_fingerprint'] : null,
                  ':ctok' => $communicationAttribution ? (string)$communicationAttribution['tracking_token'] : null,
                ));

                // si ya existía, actualizar los campos de atribución
              $stUp = $pdoSave->prepare("UPDATE tc_orders SET evento_id=:eid, ref=:ref, concept=:c, amount=:am, buyer_dni=:dni, buyer_last=:bl, buyer_first=:bf, buyer_email=:be, revendedor_id=:rev, selected_tickets_json=:tj, payment_url=:pu, communication_campaign_id=COALESCE(communication_campaign_id,:ccid), communication_run_id=COALESCE(communication_run_id,:crid), communication_recipient_fingerprint=COALESCE(communication_recipient_fingerprint,:crfp), communication_tracking_token=COALESCE(communication_tracking_token,:ctok), updated_at=datetime('now') WHERE request_id=:rid");
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
                ':ccid' => $communicationAttribution ? (int)$communicationAttribution['campaign_id'] : null,
                ':crid' => $communicationAttribution ? (int)$communicationAttribution['run_id'] : null,
                ':crfp' => $communicationAttribution ? (string)$communicationAttribution['recipient_fingerprint'] : null,
                ':ctok' => $communicationAttribution ? (string)$communicationAttribution['tracking_token'] : null,
              ));
              tickex_mp_ensure_order_columns($pdoSave);
              $stProvider = $pdoSave->prepare("UPDATE tc_orders SET payment_provider=:provider, provider_preference_id=COALESCE(provider_preference_id,:preference), marketplace_fee=COALESCE(marketplace_fee,:fee), seller_admin_id=COALESCE(seller_admin_id,:seller), ticket_subtotal=:subtotal, service_fee_amount=:service_fee, service_fee_percent=:service_percent, mp_cost_estimate_percent=:mp_cost_percent, updated_at=CURRENT_TIMESTAMP WHERE request_id=:rid");
              $stProvider->execute(array(
                ':provider' => $paymentProvider,
                ':preference' => $paymentProvider === 'mercadopago' ? ($paymentProviderPreferenceId !== '' ? $paymentProviderPreferenceId : preg_replace('/^mp-/', '', $requestId)) : null,
                ':fee' => $paymentProvider === 'mercadopago' ? $paymentProviderFee : null,
                ':seller' => $eventOwnerAdminId > 0 ? $eventOwnerAdminId : null,
                ':subtotal' => isset($ticketSubtotal) ? (float)$ticketSubtotal : (float)$total,
                ':service_fee' => $paymentProvider === 'mercadopago' ? (float)$serviceFeeAmount : 0,
                ':service_percent' => $paymentProvider === 'mercadopago' ? (float)$checkoutServicePercent : 0,
                ':mp_cost_percent' => $paymentProvider === 'mercadopago' ? (float)$checkoutMpCostPercent : 0,
                ':rid' => $requestId,
              ));
              try {
                $evPdo = db();
                $stOrdId = $evPdo->prepare("SELECT id FROM tc_orders WHERE request_id = :rid LIMIT 1");
                $stOrdId->execute(array(':rid' => $requestId));
                $ordRow = $stOrdId->fetch(PDO::FETCH_ASSOC);
                log_order_event($evPdo, $ordRow ? (int)$ordRow['id'] : null, $requestId, 'order_checkout_created', array(
                  'evento_id' => (int)$eventId,
                  'amount' => (float)$total,
                  'buyer_email' => (string)$email,
                ));
              } catch (Exception $_evEx) {}
            } catch (Exception $e) {
              try { error_log('[TotalCoin] failed to persist order: ' . $e->getMessage()); } catch (Exception $_e) {}
            }
              try {
                // Structured instrumentation for Phase 0: record checkout persistence
                $dbg = array(
                  'request_id' => $requestId,
                  'payment_url' => $paymentUrl,
                  'tickets' => (isset($ticketsJson) ? $ticketsJson : null),
                  'event_id' => (int)$eventId,
                  'ref' => (string)$ref,
                  'buyer_email' => (string)$email,
                );
                if (function_exists('tc_debug_log')) tc_debug_log('checkout_totalcoin', 'persisted_tc_order', $dbg);
              } catch (Exception $_t) {}
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
                if (preg_match('/[?&]requestId=([^&]+)/', $paymentUrl, $mGo)) {
                  $ridForGo = urldecode($mGo[1]);
                }
              }
            } catch (Exception $_t) {
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
            } catch (Exception $_t) {
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
        } catch (Exception $e) {
          if ($claimOwned && $claimPdo && empty($paymentUrl)) {
            try { tickex_totalcoin_checkout_claim_failed($claimPdo, $ref, $claimFingerprint, $e->getMessage()); } catch (Exception $_claimEx) {}
          }
          $debugId = $lastDebugId !== '' ? $lastDebugId : ('TC-' . date('Ymd-His') . '-' . substr(sha1((string)$ref . '|' . (string)$eventId . '|' . microtime(true)), 0, 8));
          // No exponer detalles internos del gateway al público
          try {
            error_log('[Payment] ' . $debugId . ' checkout error: ' . $e->getMessage());
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
          } catch (Exception $_e) {
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
                  <?php if (isset($ln['qr_quantity']) && (int)$ln['qr_quantity'] > 1): ?>
                    · entrega <?php echo (int)$ln['qr_quantity']; ?> QR por unidad
                  <?php endif; ?>
                </li>
              <?php endforeach; ?>
            </ul>
            <?php if ((float)$preview['service_fee'] > 0): ?>
              <div style="margin-top:10px;display:grid;gap:4px;font-size:14px;">
                <div>Entradas: $<?php echo e(number_format((float)$preview['subtotal'], 0, ',', '.')); ?></div>
                <div>Costo de servicio (<?php echo e(number_format((float)$preview['service_percent'], 2, ',', '.')); ?>%): $<?php echo e(number_format((float)$preview['service_fee'], 0, ',', '.')); ?></div>
              </div>
            <?php endif; ?>
            <div style="margin-top:10px;font-size:16px;font-weight:700;">Total a pagar: $<?php echo e(number_format((float)$preview['total'], 0, ',', '.')); ?></div>
          <?php else: ?>
            <div class="muted" style="margin-top:6px;">No hay entradas seleccionadas.</div>
          <?php endif; ?>
        </div>

        <form method="post" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:10px;align-items:end;">
          <input type="hidden" name="csrf" value="<?php echo e($csrfTokConfirm); ?>">
          <input type="hidden" name="action" value="pay">
          <input type="hidden" name="ref" value="<?php echo e($preview['ref'] !== '' ? $preview['ref'] : ($defaults['ref'] !== '' ? $defaults['ref'] : tickex_totalcoin_new_reference((string)$eventId))); ?>">
          <input type="hidden" name="aff" value="<?php echo (int)$revendedorId; ?>">
          <?php if ($communicationTrackingToken !== ''): ?><input type="hidden" name="ct" value="<?php echo e($communicationTrackingToken); ?>"><?php endif; ?>

          <?php foreach ((isset($preview['selected']) ? $preview['selected'] : array()) as $ln): ?>
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

            <div style="grid-column:1 / -1;">
              <?php tickex_turnstile_widget(array('theme' => 'auto')); ?>
            </div>
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
    <input type="hidden" name="ref" value="<?php echo e($defaults['ref'] !== '' ? $defaults['ref'] : tickex_totalcoin_new_reference((string)$eventId)); ?>">
    <input type="hidden" name="aff" value="<?php echo (int)$revendedorId; ?>">
    <?php if ($communicationTrackingToken !== ''): ?><input type="hidden" name="ct" value="<?php echo e($communicationTrackingToken); ?>"><?php endif; ?>

    <div class="card" style="background:var(--panel-2);border-color:var(--line);margin:0 0 12px 0;">
      <h3 style="margin:0 0 8px;">Elige tus entradas</h3>
      <div style="font-size:14px;color:var(--muted);margin-bottom:10px;">Selecciona cuántas entradas de cada tipo deseas comprar.</div>

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
          <div class="card" style="padding:16px;margin-bottom:12px;border:1px solid var(--line);background:var(--panel);">
            <input type="hidden" name="ticket_id[<?php echo $idx; ?>]" value="<?php echo e($opt['id']); ?>">
            <h4 style="margin:0 0 8px 0;font-size:18px;" data-ticket-name="<?php echo e($opt['name']); ?>"><?php echo e($opt['name']); ?></h4>
            <div style="font-size:24px;font-weight:700;color:var(--primary);margin-bottom:8px;">$<?php echo e(number_format($opt['price'],0,',','.')); ?></div>
            <?php if ((int)$opt['qr_quantity'] > 1): ?>
              <div style="font-size:13px;color:var(--ok);font-weight:700;margin-bottom:8px;">
                Cada unidad entrega <?php echo (int)$opt['qr_quantity']; ?> QR independientes
              </div>
            <?php endif; ?>
            <div style="display:flex;align-items:center;gap:8px;opacity:<?php echo $isSoldOut ? '0.6' : '1'; ?>;">
              <select
                name="qty[<?php echo $idx; ?>]"
                data-idx="<?php echo $idx; ?>"
                data-price="<?php echo e((string)$opt['price']); ?>"
                data-name="<?php echo e((string)$opt['name']); ?>"
                data-qr-quantity="<?php echo (int)$opt['qr_quantity']; ?>"
                style="padding:4px 8px;"
                <?php echo $isSoldOut ? 'disabled' : ''; ?>
              >
                <?php if(!$isSoldOut): ?>
                  <?php for($i=0;$i<=$maxQty;$i++): ?>
                    <option value="<?php echo $i; ?>" <?php echo $i===0?'selected':''; ?>><?php echo $i; ?></option>
                  <?php endfor; ?>
                <?php else: ?>
                  <option value="0">0</option>
                <?php endif; ?>
              </select>
              <?php if($isSoldOut): ?>
                <span style="color:#d32f2f;font-weight:600;">Agotado</span>
              <?php endif; ?>
            </div>
            <?php if(!$isSoldOut && $avail !== null && $avail <= 5): ?>
              <div style="color:#f57c00;font-size:12px;font-weight:500;margin-top:4px;">
                Últimas entradas
              </div>
            <?php endif; ?>
          </div>
        <?php endforeach; ?>

        <div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:10px;margin-top:12px;">
          <div style="text-align:right;">
            <?php if ($checkoutServicePercent > 0): ?><div class="muted" style="font-size:13px;">Costo de servicio <?php echo e(number_format($checkoutServicePercent, 2, ',', '.')); ?>%: <span id="serviceDisplay">$0</span></div><?php endif; ?>
            <div style="font-size:18px;font-weight:700;">Total a pagar: <span id="totalDisplay">$0</span></div>
          </div>
          <button class="btn" type="button" id="btnContinue">Comprar entradas</button>
        </div>
      </div>

      <div id="stepConfirm" class="tickex-hidden" style="margin-top:12px;">
        <div class="card" style="margin:0;background:transparent;border:1px solid var(--line);">
          <div class="muted" style="font-size:12px;">Confirmación</div>
          <ul id="confirmLines" style="margin:8px 0 0 18px;"></ul>
          <?php if ($checkoutServicePercent > 0): ?><div class="muted" style="margin-top:10px;font-size:13px;">Costo de servicio <?php echo e(number_format($checkoutServicePercent, 2, ',', '.')); ?>%: <span id="serviceConfirm">$0</span></div><?php endif; ?>
          <div style="margin-top:6px;font-size:16px;font-weight:700;">Total a pagar: <span id="totalConfirm">$0</span></div>
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

            <div style="grid-column:1 / -1;">
              <?php tickex_turnstile_widget(array('theme' => 'auto')); ?>
            </div>
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
    const serviceDisplay = document.getElementById('serviceDisplay');
    const stepSelect = document.getElementById('stepSelect');
    const stepConfirm = document.getElementById('stepConfirm');
    const confirmLines = document.getElementById('confirmLines');
    const totalConfirm = document.getElementById('totalConfirm');
    const serviceConfirm = document.getElementById('serviceConfirm');
    const servicePercent = <?php echo json_encode((float)$checkoutServicePercent); ?>;
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
        const qrQuantity = parseInt(sel.getAttribute('data-qr-quantity') || '1', 10) || 1;
        if (qty > 0) {
          lines.push({ name, qty, price, qrQuantity, total: price * qty });
        }
      });
      return lines;
    }

    function recalc() {
      const lines = readLines();
      let subtotal = 0;
      lines.forEach(ln => { subtotal += (ln.total || 0); });
      const serviceFee = Math.round((subtotal * servicePercent / 100) * 100) / 100;
      const total = subtotal + serviceFee;
      if (serviceDisplay) serviceDisplay.textContent = '$' + serviceFee.toLocaleString('es-AR');
      totalDisplay.textContent = '$' + total.toLocaleString('es-AR');
      return { lines, subtotal, serviceFee, total };
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
            ' · $' + (Number(ln.price || 0)).toLocaleString('es-AR') +
            ((ln.qrQuantity || 1) > 1 ? ' · entrega ' + ln.qrQuantity + ' QR por unidad' : '');
          confirmLines.appendChild(li);
        });
      }
      if (totalConfirm) {
        totalConfirm.textContent = '$' + r.total.toLocaleString('es-AR');
      }
      if (serviceConfirm) {
        serviceConfirm.textContent = '$' + r.serviceFee.toLocaleString('es-AR');
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
