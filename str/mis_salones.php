<?php
require_once __DIR__.'/inc/bootstrap.php';
$title = "Mis salones";

require_login();

$cu = current_user();
$tipoGlobal = isset($_SESSION['tipo_global'])
    ? $_SESSION['tipo_global']
    : (isset($cu['rol']) ? $cu['rol'] : '');

if (!in_array($tipoGlobal, array('admin_evento','super_admin','superadmin'), true)) {
    abort_404("No tenés permiso para ver esta página.");
}

$adminId = isset($_SESSION['user_id'])
    ? (int)$_SESSION['user_id']
    : (isset($cu['id']) ? (int)$cu['id'] : 0);

try {
    $pdo = db();
} catch (Exception $e) {
    http_response_code(500);
    echo "Error DB: " . e($e->getMessage());
    exit;
}

// Helper local para parsear listas "1, 2, 5"
function parse_indices($txt, $max) {
    $txt = trim((string)$txt);
    if ($txt === '') return array();

    $parts = preg_split('/[,\s;]+/', $txt);
    $out = array();
    foreach ($parts as $p) {
        $p = trim($p);
        if ($p === '') continue;
        $n = (int)$p;
        if ($n >= 1 && $n <= $max && !in_array($n, $out, true)) {
            $out[] = $n;
        }
    }
    sort($out);
    return $out;
}

$error = '';
$okMsg = '';

// =======================
// CREAR NUEVO SALÓN
// =======================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['crear_salon'])) {

    $nombre  = isset($_POST['nombre']) ? trim($_POST['nombre']) : '';
    $filas   = isset($_POST['filas']) ? (int)$_POST['filas'] : 0;
    $cols    = isset($_POST['columnas']) ? (int)$_POST['columnas'] : 0;
    $txtPF   = isset($_POST['pasillos_filas']) ? trim($_POST['pasillos_filas']) : '';
    $txtPC   = isset($_POST['pasillos_columnas']) ? trim($_POST['pasillos_columnas']) : '';

    if ($nombre === '') {
        $error = "El nombre del salón es obligatorio.";
    } elseif ($filas <= 0) {
        $error = "La cantidad de filas debe ser mayor a 0.";
    } elseif ($cols <= 0) {
        $error = "La cantidad de columnas debe ser mayor a 0.";
    }

    if ($error === '') {
        $pf = parse_indices($txtPF, $filas);
        $pc = parse_indices($txtPC, $cols);

        $layout = array(
            'filas'             => $filas,
            'columnas'          => $cols,
            'pasillos_filas'    => $pf,
            'pasillos_columnas' => $pc
        );
        $layoutJson = json_encode($layout);

        try {
            $st = $pdo->prepare("
                INSERT INTO salones
                  (admin_id, nombre, filas, columnas,
                   pasillos_filas, pasillos_columnas, layout_json, creado_en)
                VALUES
                  (:aid, :n, :f, :c, :pf, :pc, :lj, datetime('now'))
            ");
            $st->execute(array(
                ':aid' => $adminId,
                ':n'   => $nombre,
                ':f'   => $filas,
                ':c'   => $cols,
                ':pf'  => implode(',', $pf),
                ':pc'  => implode(',', $pc),
                ':lj'  => $layoutJson
            ));
            $okMsg = "Salón creado correctamente.";
        } catch (Exception $e) {
            $error = "Error al crear el salón: " . e($e->getMessage());
        }
    }
}

// =======================
// LISTAR SALONES
// =======================
try {
    if ($tipoGlobal === 'super_admin' || $tipoGlobal === 'superadmin') {
        $stSal = $pdo->prepare("SELECT * FROM salones ORDER BY creado_en DESC, id DESC");
        $stSal->execute();
    } else {
        $stSal = $pdo->prepare("
            SELECT * FROM salones
            WHERE admin_id = :aid
            ORDER BY creado_en DESC, id DESC
        ");
        $stSal->execute(array(':aid' => $adminId));
    }
    $salones = $stSal->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    http_response_code(500);
    echo "Error al listar salones: " . e($e->getMessage());
    exit;
}

include __DIR__.'/inc/layout_top.php';
?>

<div class="card" style="display:flex;gap:8px;flex-wrap:wrap;align-items:center;">
  <a class="btn secondary" href="panel_admin.php">⬅ Volver al panel</a>
  <span style="flex:1 1 auto;"></span>
</div>

<?php if ($error): ?>
  <div class="flash err"><?php echo e($error); ?></div>
<?php endif; ?>
<?php if ($okMsg): ?>
  <div class="flash ok"><?php echo e($okMsg); ?></div>
<?php endif; ?>

<div class="card">
  <h2>Mis salones</h2>
  <p class="muted">
    Acá definís la estructura básica de tu sala (filas, columnas y pasillos).
    Más adelante se puede vincular un salón a un evento y usarlo para entradas numeradas.
  </p>

  <form method="post" style="margin-top:12px;display:grid;gap:8px;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));align-items:flex-end;">
    <div>
      <label for="nombre">Nombre del salón</label>
      <input type="text" id="nombre" name="nombre" required placeholder="Ej: Teatro Principal">
    </div>

    <div>
      <label for="filas">Filas</label>
      <input type="number" id="filas" name="filas" min="1" value="10">
      <div class="muted" style="font-size:12px;">Cantidad de filas (1, 2, 3...)</div>
    </div>

    <div>
      <label for="columnas">Columnas / butacas por fila</label>
      <input type="number" id="columnas" name="columnas" min="1" value="20">
      <div class="muted" style="font-size:12px;">Cantidad de butacas en cada fila</div>
    </div>

    <div>
      <label for="pasillos_filas">Pasillos (filas)</label>
      <input type="text" id="pasillos_filas" name="pasillos_filas" placeholder="Ej: 5, 10">
      <div class="muted" style="font-size:12px;">Números de fila que son pasillo (separadas por coma)</div>
    </div>

    <div>
      <label for="pasillos_columnas">Pasillos (columnas)</label>
      <input type="text" id="pasillos_columnas" name="pasillos_columnas" placeholder="Ej: 4, 8, 12">
      <div class="muted" style="font-size:12px;">Números de columna que son pasillo</div>
    </div>

    <div>
      <button class="btn" type="submit" name="crear_salon" value="1" style="margin-top:20px;">
        Crear salón
      </button>
    </div>
  </form>
</div>

<div class="card">
  <h3>Salones existentes</h3>

  <?php if (empty($salones)): ?>
    <div class="muted">Todavía no creaste ningún salón.</div>
  <?php else: ?>
    <table class="table" style="margin-top:8px;">
      <thead>
        <tr>
          <th>ID</th>
          <th>Nombre</th>
          <th>Filas × Columnas</th>
          <th>Pasillos filas</th>
          <th>Pasillos columnas</th>
          <th>Creado</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($salones as $s): ?>
          <tr>
            <td><?php echo (int)$s['id']; ?></td>
            <td><?php echo e($s['nombre']); ?></td>
            <td><?php echo (int)$s['filas']; ?> × <?php echo (int)$s['columnas']; ?></td>
            <td><?php echo e($s['pasillos_filas']); ?></td>
            <td><?php echo e($s['pasillos_columnas']); ?></td>
            <td><?php echo e($s['creado_en']); ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  <?php endif; ?>
</div>

<?php include __DIR__.'/inc/layout_bottom.php'; ?>
