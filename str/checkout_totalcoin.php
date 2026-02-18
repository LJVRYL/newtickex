<?php
require_once __DIR__.'/inc/bootstrap.php';
require_once __DIR__.'/inc/totalcoin.php';
require_once __DIR__.'/inc/db.php';

$title = 'Checkout TotalCoin (Tickex)';
$errors = array();
$paymentUrl = null;
$freeSuccess = false;
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
$sessionEmail = $_SESSION['email'] ?? ($_SESSION['usuario'] ?? '');
$sessionFirst = $_SESSION['first_name'] ?? ($_SESSION['nombre'] ?? '');
$sessionLast  = $_SESSION['last_name'] ?? ($_SESSION['apellido'] ?? '');
$sessionDni   = $_SESSION['dni'] ?? '';

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
    $stEv = $pdoLocal->prepare('SELECT * FROM eventos WHERE id = :id LIMIT 1');
    $stEv->execute(array(':id' => $eventId));
    $evRow = $stEv->fetch(PDO::FETCH_ASSOC);
    if ($evRow) {
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

$nextUrl = $_SERVER['REQUEST_URI'];
$loginBase = 'https://str.tickex.com.ar/login.php';

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

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $concept   = $eventName;
  $dni       = trim($_POST['dni'] ?? $defaults['dni']);
  $ref       = trim($_POST['ref'] ?? ($defaults['ref'] !== '' ? $defaults['ref'] : ('str-' . $eventId . '-' . time())));
  $last      = trim($_POST['last_name'] ?? $defaults['last_name']);
  $first     = trim($_POST['first_name'] ?? $defaults['first_name']);
  $email     = trim($_POST['email'] ?? $defaults['email']);

  // Selección múltiple con cantidades
  $selectedTickets = array();
  $total = 0;
  if (!empty($_POST['ticket_id']) && is_array($_POST['ticket_id'])) {
    foreach ($_POST['ticket_id'] as $i => $tid) {
      $tidStr = (string)$tid;
      $qty = isset($_POST['qty'][$i]) ? (int)$_POST['qty'][$i] : 0;
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
  }

  // Si faltan datos de usuario, forzar flujo de login (desde ahí podrá registrarse)
  $needsAuth = ($dni === '' || $last === '' || $first === '' || $email === '' || strpos($email, '@') === false);
  if ($needsAuth) {
    $redir = $loginBase . '?next=' . urlencode($nextUrl);
    if ($email !== '') {
      $redir .= '&email=' . urlencode($email);
    }
    header('Location: ' . $redir);
    exit;
  }

  if ($total <= 0 && empty($selectedTickets)) $errors[] = 'Seleccioná al menos una entrada.';
  if ($concept === '') $errors[] = 'Concepto requerido';
  if ($dni === '') $errors[] = 'DNI requerido';
  if ($ref === '') $errors[] = 'Referencia requerida';
  if ($last === '') $errors[] = 'Apellido requerido';
  if ($first === '') $errors[] = 'Nombre requerido';
  if ($email === '' || strpos($email, '@') === false) $errors[] = 'Email inválido';

  if (empty($errors)) {
    if ($total > 0) {
      try {
        $paymentUrl = tc_checkout($total, $concept, $dni, $ref, $last, $first, $email);
      } catch (Exception $e) {
        $errors[] = $e->getMessage();
      }
    } else {
      // Orden 100% gratuita: se marca como éxito sin pasar por TotalCoin
      $freeSuccess = true;
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
          <strong>¿Ya tenés cuenta?</strong> Iniciá sesión para completar tus datos y seguir con el checkout.
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

  <?php if ($paymentUrl): ?>
    <div class="flash ok">
      <strong>Pago generado.</strong>
      <div>URL de pago: <a class="link" href="<?php echo e($paymentUrl); ?>" target="_blank" rel="noopener noreferrer"><?php echo e($paymentUrl); ?></a></div>
      <div style="margin-top:10px;"><a class="btn secondary" href="<?php echo e($paymentUrl); ?>" target="_blank" rel="noopener noreferrer">Ir al pago</a></div>
    </div>
  <?php endif; ?>
  <?php if ($freeSuccess && !$paymentUrl): ?>
    <div class="flash ok">
      <strong>Entradas gratuitas generadas.</strong>
      <div>No se requirió pago. Se registró tu selección.</div>
    </div>
  <?php endif; ?>

  <form method="post" id="checkoutForm" style="display:grid;gap:12px;">
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

    <!-- Campos ocultos requeridos para el checkout -->
    <input type="hidden" name="concept" value="<?php echo e($eventName); ?>">
    <input type="hidden" name="ref" value="<?php echo e($defaults['ref'] !== '' ? $defaults['ref'] : ('str-' . $eventId . '-' . time())); ?>">
    <input type="hidden" name="dni" value="<?php echo e($defaults['dni'] !== '' ? $defaults['dni'] : ''); ?>">
    <input type="hidden" name="last_name" value="<?php echo e($defaults['last_name']); ?>">
    <input type="hidden" name="first_name" value="<?php echo e($defaults['first_name']); ?>">
    <input type="hidden" name="email" value="<?php echo e($defaults['email']); ?>">
  </form>
</div>

<script>
  (function(){
    const checkboxes = document.querySelectorAll('input[type="checkbox"][name^="ticket_id"]');
    const qtySelects = document.querySelectorAll('select[name^="qty"]');
    const totalDisplay = document.getElementById('totalDisplay');

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
