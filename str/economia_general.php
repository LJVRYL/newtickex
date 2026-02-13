<?php
require_once __DIR__.'/inc/bootstrap.php';
require_once __DIR__.'/inc/unified_tickets.php';
require_once __DIR__.'/inc/manual_income.php';
require_once __DIR__.'/inc/produccion.php';

require_login();

$cu = current_user();
$rol = isset($cu['tipo_global']) && $cu['tipo_global'] !== ''
  ? $cu['tipo_global']
  : (isset($cu['rol']) ? $cu['rol'] : (isset($_SESSION['tipo_global']) ? $_SESSION['tipo_global'] : ''));

if (!in_array($rol, array('admin_evento','super_admin','superadmin'), true)) {
    header('Location: /login.php?next=' . urlencode($_SERVER['REQUEST_URI']), true, 302);
    exit;
}

$pdo = db();

// ------------------------------------------------------------------
// Tabla de movimientos económicos globales
// ------------------------------------------------------------------
function ensure_econ_table($pdo) {
  try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS econ_movimientos (
      id INTEGER PRIMARY KEY AUTOINCREMENT,
      tipo TEXT NOT NULL,              -- gasto | presupuesto | inversion
      concepto TEXT NOT NULL,
      categoria TEXT,
      monto REAL NOT NULL,
      estado TEXT,
      evento_id INTEGER,
      notas TEXT,
      creado_por INTEGER,
      incluye_en_totales INTEGER NOT NULL DEFAULT 1,
      created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    )");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_econ_tipo ON econ_movimientos(tipo)");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_econ_evento ON econ_movimientos(evento_id)");

    // agregar columna incluye_en_totales si faltara (bases viejas)
    $cols = array();
    $stc = $pdo->query("PRAGMA table_info(econ_movimientos)");
    if ($stc) {
      $colsInfo = $stc->fetchAll(PDO::FETCH_ASSOC);
      foreach ($colsInfo as $ci) { $cols[$ci['name']] = true; }
    }
    if (!isset($cols['incluye_en_totales'])) {
      $pdo->exec("ALTER TABLE econ_movimientos ADD COLUMN incluye_en_totales INTEGER NOT NULL DEFAULT 1");
    }
  } catch (Exception $e) {
    // ignorar
  }
}

function add_econ_mov($pdo, $data) {
    ensure_econ_table($pdo);
    try {
        $stmt = $pdo->prepare("INSERT INTO econ_movimientos (tipo, concepto, categoria, monto, estado, evento_id, notas, creado_por) VALUES (:t,:c,:cat,:m,:e,:eid,:n,:uid)");
        $stmt->execute(array(
            ':t'   => $data['tipo'],
            ':c'   => $data['concepto'],
            ':cat' => $data['categoria'],
            ':m'   => $data['monto'],
            ':e'   => $data['estado'],
            ':eid' => $data['evento_id'],
            ':n'   => $data['notas'],
            ':uid' => $data['creado_por'],
        ));
        return true;
    } catch (Exception $e) {
        return false;
    }
}

ensure_econ_table($pdo);

// Costo acumulado de staff (toda la base)
function get_staff_cost_total($pdo) {
  try {
    $cols = array();
    $st = $pdo->query("PRAGMA table_info(usuarios_admin)");
    if ($st) {
      foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $ci) { $cols[$ci['name']] = true; }
    }
    if (!isset($cols['costo_servicio'])) return 0;
    $stmt = $pdo->query("SELECT SUM(COALESCE(costo_servicio,0)) FROM usuarios_admin WHERE tipo_global='staff_evento'");
    $val = $stmt ? $stmt->fetchColumn() : 0;
    return $val ? (float)$val : 0;
  } catch (Exception $e) {
    return 0;
  }
}

// ------------------------------------------------------------------
// POST: agregar movimiento
// ------------------------------------------------------------------
$flashMsg = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'add_mov') {
    $tipo = isset($_POST['tipo']) ? trim($_POST['tipo']) : '';
    $concepto = isset($_POST['concepto']) ? trim($_POST['concepto']) : '';
    $categoria = isset($_POST['categoria']) ? trim($_POST['categoria']) : '';
    $monto = isset($_POST['monto']) ? (float)$_POST['monto'] : 0;
    $estado = isset($_POST['estado']) ? trim($_POST['estado']) : '';
    $evento_id = isset($_POST['evento_id']) ? (int)$_POST['evento_id'] : null;
    $notas = isset($_POST['notas']) ? trim($_POST['notas']) : '';

    if (!in_array($tipo, array('gasto','presupuesto','inversion'), true)) {
        $flashMsg = 'Tipo inválido';
    } elseif ($concepto === '' || $monto <= 0) {
        $flashMsg = 'Concepto y monto son obligatorios';
    } else {
        $ok = add_econ_mov($pdo, array(
            'tipo' => $tipo,
            'concepto' => $concepto,
            'categoria' => $categoria,
            'monto' => $monto,
            'estado' => $estado,
            'evento_id' => $evento_id ? $evento_id : null,
            'notas' => $notas,
            'creado_por' => isset($cu['id']) ? (int)$cu['id'] : null,
        ));
        $flashMsg = $ok ? 'Movimiento agregado' : 'Error al guardar el movimiento';
    }
}

// POST: eliminar movimiento
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'del_mov') {
  $id = isset($_POST['id']) ? (int)$_POST['id'] : 0;
  if ($id > 0) {
    $stmtDel = $pdo->prepare("DELETE FROM econ_movimientos WHERE id = :id");
    $stmtDel->execute(array(':id' => $id));
    $flashMsg = 'Movimiento eliminado';
  }
}

// POST: toggle incluye_en_totales
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'toggle_mov') {
  $id = isset($_POST['id']) ? (int)$_POST['id'] : 0;
  $val = isset($_POST['val']) ? (int)$_POST['val'] : 0;
  if ($id > 0) {
    $stmtT = $pdo->prepare("UPDATE econ_movimientos SET incluye_en_totales = :v WHERE id = :id");
    $stmtT->execute(array(':v' => $val, ':id' => $id));
    $flashMsg = $val ? 'Movimiento activado' : 'Movimiento excluido de totales';
  }
}

// ------------------------------------------------------------------
// Eventos disponibles (para filtrar y asociar movimientos)
// ------------------------------------------------------------------
$eventos = array();
try {
    $stEv = $pdo->query("SELECT id, nombre FROM eventos ORDER BY id DESC");
    $eventos = $stEv ? $stEv->fetchAll(PDO::FETCH_ASSOC) : array();
} catch (Exception $e) {
    $eventos = array();
}

// Lista de movimientos recientes
$movimientos = array();
try {
  $stMov = $pdo->query("SELECT * FROM econ_movimientos ORDER BY created_at DESC, id DESC LIMIT 50");
    $movimientos = $stMov ? $stMov->fetchAll(PDO::FETCH_ASSOC) : array();
} catch (Exception $e) {
    $movimientos = array();
}

// Totales de movimientos
$totalGastos = 0; $totalInv = 0; $totalPresu = 0;
foreach ($movimientos as $m) {
    $mt = isset($m['monto']) ? (float)$m['monto'] : 0;
  $incluye = isset($m['incluye_en_totales']) ? ((int)$m['incluye_en_totales'] === 1) : true;
  if (!$incluye) continue;
  if ($m['tipo'] === 'gasto') $totalGastos += $mt;
  elseif ($m['tipo'] === 'inversion') $totalInv += $mt;
  elseif ($m['tipo'] === 'presupuesto') $totalPresu += $mt;
}

// ------------------------------------------------------------------
// Ingresos totales (todas las ventas + ingresos manuales)
// ------------------------------------------------------------------
$ingresosTotales = 0;
$ingresosManual  = 0;
$entradasVendidas = 0;
$totalesPorEvento = array();

// recoger ids de eventos
$eventIds = array();
try {
    $resEv = $pdo->query("SELECT id FROM eventos");
    $rows = $resEv ? $resEv->fetchAll(PDO::FETCH_ASSOC) : array();
    foreach ($rows as $r) {
        $eventIds[] = (int)$r['id'];
    }
} catch (Exception $e) {}
try {
    $resEid = $pdo->query("SELECT DISTINCT evento_id FROM entradas WHERE evento_id IS NOT NULL");
    $r2 = $resEid ? $resEid->fetchAll(PDO::FETCH_ASSOC) : array();
    foreach ($r2 as $r) {
        $eventIds[] = (int)$r['evento_id'];
    }
} catch (Exception $e) {}
$eventIds = array_values(array_unique(array_filter($eventIds)));

foreach ($eventIds as $eid) {
    $stats = get_economic_stats($pdo, $eid);
    $ingresosTotales += $stats['total_recaudado'];
    $ingresosManual  += $stats['manual_income'];
    $entradasVendidas += $stats['entradas_vendidas'];
    $totalesPorEvento[] = array(
        'evento_id' => $eid,
        'recaudado' => $stats['total_recaudado'],
        'manual'    => $stats['manual_income'],
        'entradas'  => $stats['entradas_vendidas'],
    );
}

$staffCost = get_staff_cost_total($pdo);
$artistCostTotal = get_artist_cost_total($pdo);
$saldoProyecto = ($ingresosTotales + $totalPresu) - ($totalGastos + $totalInv + $staffCost + $artistCostTotal);

$title = 'Economía General';
include __DIR__.'/inc/layout_top.php';
?>

<?php if ($flashMsg): ?>
  <div class="flash ok" style="margin-top:12px;">
    <?php echo e($flashMsg); ?>
  </div>
<?php endif; ?>

<div class="card" style="margin-top:12px;">
  <div style="display:flex;flex-wrap:wrap;gap:12px;align-items:center;justify-content:space-between;">
    <div>
      <h2 style="margin:0;">Economía del proyecto</h2>
      <div style="color:var(--muted);margin-top:4px;">Vista general de ingresos, gastos y presupuesto.</div>
    </div>
    <div style="display:flex;gap:8px;">
      <div class="pill">Entradas vendidas: <?php echo (int)$entradasVendidas; ?></div>
      <div class="pill">Eventos: <?php echo count($eventIds); ?></div>
    </div>
  </div>
</div>

<div class="card" style="margin-top:12px;display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:10px;">
  <div class="card" style="margin:0;background:var(--panel-2);">
    <div class="muted" style="font-size:12px;">Ingresos totales</div>
    <div style="font-size:28px;font-weight:700;margin-top:4px;color:var(--ok);">$<?php echo number_format($ingresosTotales,2); ?></div>
    <div style="font-size:12px;color:var(--muted);">Incluye ventas + manuales (neto)</div>
  </div>
  <div class="card" style="margin:0;background:var(--panel-2);">
    <div class="muted" style="font-size:12px;">Manual (otros/varios)</div>
    <div style="font-size:24px;font-weight:700;margin-top:4px;color:<?php echo ($ingresosManual >= 0 ? 'var(--info)' : 'var(--warn)'); ?>;">$<?php echo number_format($ingresosManual,2); ?></div>
  </div>
  <div class="card" style="margin:0;background:var(--panel-2);">
    <div class="muted" style="font-size:12px;">Gastos registrados</div>
    <div style="font-size:24px;font-weight:700;margin-top:4px;color:var(--warn);">$<?php echo number_format($totalGastos,2); ?></div>
  </div>
  <div class="card" style="margin:0;background:var(--panel-2);">
    <div class="muted" style="font-size:12px;">Inversiones</div>
    <div style="font-size:24px;font-weight:700;margin-top:4px;color:var(--warn);">$<?php echo number_format($totalInv,2); ?></div>
  </div>
  <div class="card" style="margin:0;background:var(--panel-2);">
    <div class="muted" style="font-size:12px;">Costo staff</div>
    <div style="font-size:24px;font-weight:700;margin-top:4px;">$<?php echo number_format($staffCost,2); ?></div>
  </div>
  <div class="card" style="margin:0;background:var(--panel-2);">
    <div class="muted" style="font-size:12px;">Artística</div>
    <div style="font-size:24px;font-weight:700;margin-top:4px;">$<?php echo number_format($artistCostTotal,2); ?></div>
  </div>
  <div class="card" style="margin:0;background:var(--panel-2);">
    <div class="muted" style="font-size:12px;">Presupuesto disponible</div>
    <div style="font-size:28px;font-weight:700;margin-top:4px;color:<?php echo $saldoProyecto>=0?'var(--ok)':'var(--warn)'; ?>;">$<?php echo number_format($saldoProyecto,2); ?></div>
    <div style="font-size:12px;color:var(--muted);">Presupuesto + ingresos − gastos − inversiones</div>
  </div>
</div>

<div class="card" style="margin-top:12px;">
  <h3 style="margin-top:0;">Agregar movimiento</h3>
  <form method="post" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:10px;">
    <input type="hidden" name="action" value="add_mov">
    <div>
      <label>Tipo</label>
      <select name="tipo" required>
        <option value="gasto">Gasto</option>
        <option value="presupuesto">Presupuesto / fondo</option>
        <option value="inversion">Inversión</option>
      </select>
    </div>
    <div>
      <label>Concepto</label>
      <input type="text" name="concepto" required placeholder="Ej: Compra de luces">
    </div>
    <div>
      <label>Categoría</label>
      <input type="text" name="categoria" placeholder="Producción, Marketing...">
    </div>
    <div>
      <label>Monto</label>
      <input type="number" step="0.01" name="monto" required>
    </div>
    <div>
      <label>Estado</label>
      <input type="text" name="estado" placeholder="Pendiente, Pagado, Cotización">
    </div>
    <div>
      <label>Evento asociado (opcional)</label>
      <select name="evento_id">
        <option value="">— Global —</option>
        <?php foreach ($eventos as $ev): ?>
          <option value="<?php echo (int)$ev['id']; ?>"><?php echo e($ev['nombre']); ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div style="grid-column:1/-1;">
      <label>Notas</label>
      <textarea name="notas" rows="2" placeholder="Detalles, links de cotización, etc."></textarea>
    </div>
    <div style="grid-column:1/-1;display:flex;justify-content:flex-end;">
      <button class="btn" type="submit">Guardar</button>
    </div>
  </form>
</div>

<div class="card" style="margin-top:12px;">
  <h3 style="margin-top:0;">Últimos movimientos</h3>
  <div style="overflow:auto;">
    <table class="table" style="min-width:760px;">
      <thead>
        <tr>
          <th>Fecha</th>
          <th>Tipo</th>
          <th>Concepto</th>
          <th>Categoría</th>
          <th style="text-align:right;">Monto</th>
          <th>Estado</th>
          <th>Evento</th>
          <th style="text-align:center;">Totales</th>
          <th style="text-align:center;">Acciones</th>
        </tr>
      </thead>
      <tbody>
        <?php if (empty($movimientos)): ?>
          <tr><td colspan="9" style="text-align:center;color:var(--muted);">Sin movimientos</td></tr>
        <?php else: ?>
          <?php foreach ($movimientos as $m): ?>
            <tr>
              <td><?php echo e(isset($m['created_at']) ? $m['created_at'] : ''); ?></td>
              <td>
                <span class="pill" style="background:var(--panel-2);border:1px solid var(--line);font-size:12px;">
                  <?php echo e($m['tipo']); ?>
                </span>
              </td>
              <td><?php echo e($m['concepto']); ?></td>
              <td><?php echo $m['categoria'] !== '' ? e($m['categoria']) : '<span style="color:var(--muted);">—</span>'; ?></td>
              <td style="text-align:right;font-weight:700;">$<?php echo number_format((float)$m['monto'],2); ?></td>
              <td><?php echo $m['estado'] !== '' ? e($m['estado']) : '<span style="color:var(--muted);">—</span>'; ?></td>
              <td>
                <?php
                  $eid = isset($m['evento_id']) ? (int)$m['evento_id'] : 0;
                  if ($eid && !empty($eventos)) {
                      $found = null;
                      foreach ($eventos as $ev) {
                          if ((int)$ev['id'] === $eid) { $found = $ev['nombre']; break; }
                      }
                      echo $found ? e($found) : ('Evento ' . $eid);
                  } else {
                      echo '<span style="color:var(--muted);">—</span>';
                  }
                ?>
              </td>
              <td style="text-align:center;">
                <?php $incl = isset($m['incluye_en_totales']) ? (int)$m['incluye_en_totales'] : 1; ?>
                <form method="post" style="margin:0;">
                  <input type="hidden" name="action" value="toggle_mov">
                  <input type="hidden" name="id" value="<?php echo (int)$m['id']; ?>">
                  <input type="hidden" name="val" value="<?php echo $incl ? 0 : 1; ?>">
                  <button class="btn secondary" type="submit" style="padding:4px 10px;font-size:12px;">
                    <?php echo $incl ? 'Ocultar' : 'Mostrar'; ?>
                  </button>
                </form>
              </td>
              <td style="text-align:center;">
                <form method="post" style="margin:0;" onsubmit="return confirm('¿Eliminar movimiento?');">
                  <input type="hidden" name="action" value="del_mov">
                  <input type="hidden" name="id" value="<?php echo (int)$m['id']; ?>">
                  <button class="btn secondary" type="submit" style="padding:4px 10px;font-size:12px;background:var(--panel-2);color:var(--warn);border:1px solid var(--line);">Eliminar</button>
                </form>
              </td>
            </tr>
          <?php endforeach; ?>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<div class="card" style="margin-top:12px;">
  <h3 style="margin-top:0;">Recaudación por evento</h3>
  <div style="overflow:auto;">
    <table class="table" style="min-width:600px;">
      <thead>
        <tr>
          <th>Evento</th>
          <th style="text-align:right;">Entradas</th>
          <th style="text-align:right;">Ingresos</th>
          <th style="text-align:right;">Manuales</th>
        </tr>
      </thead>
      <tbody>
        <?php if (empty($totalesPorEvento)): ?>
          <tr><td colspan="4" style="text-align:center;color:var(--muted);">Sin datos</td></tr>
        <?php else: ?>
          <?php foreach ($totalesPorEvento as $te): ?>
            <tr>
              <td>#<?php echo (int)$te['evento_id']; ?></td>
              <td style="text-align:right;"><?php echo (int)$te['entradas']; ?></td>
              <td style="text-align:right;">$<?php echo number_format($te['recaudado'],2); ?></td>
              <td style="text-align:right;">$<?php echo number_format($te['manual'],2); ?></td>
            </tr>
          <?php endforeach; ?>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<?php include __DIR__.'/inc/layout_bottom.php'; ?>
