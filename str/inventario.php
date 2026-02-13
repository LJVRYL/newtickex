<?php
require_once __DIR__.'/inc/bootstrap.php';
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
// Setup tablas
// ------------------------------------------------------------------
function ensure_inv_tables($pdo) {
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS inv_items (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            codigo TEXT NOT NULL UNIQUE,
            nombre TEXT NOT NULL,
            descripcion TEXT,
            dueno TEXT,
            estado TEXT DEFAULT 'OK',
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        )");
        $pdo->exec("CREATE TABLE IF NOT EXISTS inv_logs (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            item_id INTEGER NOT NULL,
            nota TEXT,
            foto_path TEXT,
            estado TEXT,
            created_by INTEGER,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        )");
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_inv_logs_item ON inv_logs(item_id)");
    } catch (Exception $e) {
        // silent
    }
}
ensure_inv_tables($pdo);

// ------------------------------------------------------------------
// Helpers
// ------------------------------------------------------------------
function inv_generate_code() {
    $r = bin2hex(function_exists('random_bytes') ? random_bytes(3) : openssl_random_pseudo_bytes(3));
    return 'ITM-' . strtoupper($r);
}

function inv_upload_photo($file) {
    if (!isset($file) || !isset($file['tmp_name']) || $file['error'] !== UPLOAD_ERR_OK) return '';
    $tmp = $file['tmp_name'];
    $mime = mime_content_type($tmp);
    if (strpos($mime, 'image/') !== 0) return '';
    $dir = __DIR__ . '/uploads_inventario';
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }
    $ext = pathinfo($file['name'], PATHINFO_EXTENSION);
    if ($ext === '') $ext = 'jpg';
    $fname = 'inv_' . date('Ymd_His') . '_' . bin2hex(function_exists('random_bytes') ? random_bytes(3) : openssl_random_pseudo_bytes(3)) . '.' . $ext;
    $dest = $dir . '/' . $fname;
    if (move_uploaded_file($tmp, $dest)) {
        return 'uploads_inventario/' . $fname;
    }
    return '';
}

// ------------------------------------------------------------------
// Acciones POST
// ------------------------------------------------------------------
$flash = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = isset($_POST['action']) ? $_POST['action'] : '';

    if ($action === 'create_item') {
        $nombre = isset($_POST['nombre']) ? trim($_POST['nombre']) : '';
        $desc   = isset($_POST['descripcion']) ? trim($_POST['descripcion']) : '';
        $dueno  = isset($_POST['dueno']) ? trim($_POST['dueno']) : '';
        $estado = isset($_POST['estado']) ? trim($_POST['estado']) : 'OK';
        if ($nombre === '') {
            $flash = 'El nombre es obligatorio';
        } else {
            $code = inv_generate_code();
            $stmt = $pdo->prepare("INSERT INTO inv_items (codigo, nombre, descripcion, dueno, estado) VALUES (:c,:n,:d,:o,:e)");
            $stmt->execute(array(
                ':c' => $code,
                ':n' => $nombre,
                ':d' => $desc,
                ':o' => $dueno,
                ':e' => $estado,
            ));
            $flash = 'Item creado';
        }
    } elseif ($action === 'add_log') {
        $itemId = isset($_POST['item_id']) ? (int)$_POST['item_id'] : 0;
        $nota   = isset($_POST['nota']) ? trim($_POST['nota']) : '';
        $estado = isset($_POST['estado']) ? trim($_POST['estado']) : '';
        $foto   = inv_upload_photo(isset($_FILES['foto']) ? $_FILES['foto'] : null);
        if ($itemId > 0) {
            $stmt = $pdo->prepare("INSERT INTO inv_logs (item_id, nota, foto_path, estado, created_by) VALUES (:i,:n,:f,:e,:u)");
            $stmt->execute(array(
                ':i' => $itemId,
                ':n' => $nota,
                ':f' => $foto,
                ':e' => $estado,
                ':u' => isset($cu['id']) ? (int)$cu['id'] : null,
            ));
            if ($estado !== '') {
                $up = $pdo->prepare("UPDATE inv_items SET estado = :e WHERE id = :i");
                $up->execute(array(':e' => $estado, ':i' => $itemId));
            }
            $flash = 'Log agregado';
        }
    }
}

// ------------------------------------------------------------------
// Filtros y datos
// ------------------------------------------------------------------
$q      = isset($_GET['q']) ? trim($_GET['q']) : '';
$estadoF= isset($_GET['estado']) ? trim($_GET['estado']) : '';
$viewId = isset($_GET['item_id']) ? (int)$_GET['item_id'] : 0;
$viewCode = isset($_GET['code']) ? trim($_GET['code']) : '';
$printMode = isset($_GET['print']) && $_GET['print'] == '1';

// Obtener item a ver
$viewItem = null; $logs = array();
if ($viewId > 0 || $viewCode !== '') {
    $where = $viewId > 0 ? 'id = :id' : 'codigo = :c';
    $st = $pdo->prepare("SELECT * FROM inv_items WHERE $where LIMIT 1");
    $st->execute($viewId > 0 ? array(':id'=>$viewId) : array(':c'=>$viewCode));
    $viewItem = $st->fetch(PDO::FETCH_ASSOC);
    if ($viewItem) {
        $stl = $pdo->prepare("SELECT * FROM inv_logs WHERE item_id = :id ORDER BY created_at DESC, id DESC");
        $stl->execute(array(':id' => (int)$viewItem['id']));
        $logs = $stl->fetchAll(PDO::FETCH_ASSOC);
    }
}

// Listado
$items = array();
try {
    $where = array();
    $params = array();
    if ($q !== '') {
        $where[] = "(nombre LIKE :q OR descripcion LIKE :q OR dueno LIKE :q OR codigo LIKE :q)";
        $params[':q'] = '%'.$q.'%';
    }
    if ($estadoF !== '') {
        $where[] = "estado = :es";
        $params[':es'] = $estadoF;
    }
    $sql = "SELECT * FROM inv_items" . (!empty($where) ? " WHERE " . implode(' AND ', $where) : '') . " ORDER BY created_at DESC, id DESC";
    $stm = $pdo->prepare($sql);
    $stm->execute($params);
    $items = $stm->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {}

// QR base
$baseUrl = (isset($_SERVER['HTTP_HOST']) ? 'http://'.$_SERVER['HTTP_HOST'] : 'https://str.tickex.com.ar');

if ($printMode && $viewItem) {
    $qrData = $baseUrl . '/inventario.php?code=' . urlencode($viewItem['codigo']);
    $qrUrl = 'https://api.qrserver.com/v1/create-qr-code/?size=180x180&data=' . urlencode($qrData);
    ?><!DOCTYPE html>
<html><head><meta charset="UTF-8"><style>
  @page { size: 62mm 29mm; margin: 2mm; }
  body{margin:0;font-family:Arial, sans-serif;}
  .label{width:62mm;height:29mm;display:flex;align-items:center;justify-content:space-between;padding:3mm;box-sizing:border-box;}
  .txt{font-size:11px;line-height:1.2;}
  .txt strong{display:block;font-size:12px;}
</style></head><body>
<div class="label">
  <div class="txt">
    <strong>STR</strong>
    <?php echo e($viewItem['nombre']); ?><br>
    <?php echo e($viewItem['codigo']); ?><br>
    Estado: <?php echo e($viewItem['estado']); ?>
  </div>
  <div><img src="<?php echo e($qrUrl); ?>" alt="QR" style="width:120px;height:120px;"></div>
</div>
</body></html><?php
    exit;
}

$title = 'Inventario';
include __DIR__.'/inc/layout_top.php';
?>

<?php if ($flash): ?>
  <div class="flash ok" style="margin-top:12px;"><?php echo e($flash); ?></div>
<?php endif; ?>

<div class="card" style="margin-top:12px;">
  <div style="display:flex;flex-wrap:wrap;gap:12px;align-items:center;justify-content:space-between;">
    <div>
      <h2 style="margin:0;">Inventario</h2>
      <div style="color:var(--muted);margin-top:4px;">Carga de equipos, cajas, luces, etc. con QR y estado.</div>
    </div>
    <div style="display:flex;gap:8px;">
      <div class="pill">Items: <?php echo count($items); ?></div>
    </div>
  </div>
</div>

<div class="card" style="margin-top:12px;">
  <h3 style="margin-top:0;">Agregar item</h3>
  <form method="post" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:10px;">
    <input type="hidden" name="action" value="create_item">
    <div>
      <label>Nombre</label>
      <input type="text" name="nombre" required placeholder="Ej: Caja de cables">
    </div>
    <div>
      <label>Dueño</label>
      <input type="text" name="dueno" placeholder="STR, proveedor, etc">
    </div>
    <div>
      <label>Estado</label>
      <select name="estado">
        <option value="OK">OK</option>
        <option value="EN FALTA">En falta</option>
        <option value="PERDIDO">Perdido</option>
      </select>
    </div>
    <div style="grid-column:1/-1;">
      <label>Descripción</label>
      <textarea name="descripcion" rows="2" placeholder="Detalle, marca/modelo, notas"></textarea>
    </div>
    <div style="grid-column:1/-1;display:flex;justify-content:flex-end;">
      <button class="btn" type="submit">Guardar</button>
    </div>
  </form>
</div>

<div class="card" style="margin-top:12px;">
  <form method="get" style="display:flex;flex-wrap:wrap;gap:10px;align-items:flex-end;">
    <div style="flex:1 1 240px;min-width:220px;">
      <label>Buscar</label>
      <input type="text" name="q" value="<?php echo e($q); ?>" placeholder="Nombre, código, dueño...">
    </div>
    <div style="min-width:160px;">
      <label>Estado</label>
      <select name="estado">
        <option value="" <?php echo $estadoF===''?'selected':''; ?>>Todos</option>
        <option value="OK" <?php echo $estadoF==='OK'?'selected':''; ?>>OK</option>
        <option value="EN FALTA" <?php echo $estadoF==='EN FALTA'?'selected':''; ?>>En falta</option>
        <option value="PERDIDO" <?php echo $estadoF==='PERDIDO'?'selected':''; ?>>Perdido</option>
      </select>
    </div>
    <div>
      <button class="btn" type="submit">Aplicar</button>
    </div>
  </form>
</div>

<div class="card" style="margin-top:12px;">
  <div style="overflow:auto;">
    <table class="table" style="min-width:760px;">
      <thead>
        <tr>
          <th>Código</th>
          <th>Nombre</th>
          <th>Dueño</th>
          <th>Estado</th>
          <th>Creado</th>
          <th>Acciones</th>
        </tr>
      </thead>
      <tbody>
        <?php if (empty($items)): ?>
          <tr><td colspan="6" style="text-align:center;color:var(--muted);">Sin items</td></tr>
        <?php else: ?>
          <?php foreach ($items as $it): ?>
            <tr>
              <td><?php echo e($it['codigo']); ?></td>
              <td><?php echo e($it['nombre']); ?></td>
              <td><?php echo $it['dueno'] !== '' ? e($it['dueno']) : '<span style="color:var(--muted);">—</span>'; ?></td>
              <td><?php echo e($it['estado']); ?></td>
              <td><?php echo e($it['created_at']); ?></td>
              <td>
                <a class="btn secondary" href="inventario.php?item_id=<?php echo (int)$it['id']; ?>" style="padding:4px 10px;font-size:12px;">Ver</a>
              </td>
            </tr>
          <?php endforeach; ?>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<?php if ($viewItem): ?>
<div class="card" style="margin-top:12px;">
  <div style="display:flex;flex-wrap:wrap;gap:16px;align-items:flex-start;justify-content:space-between;">
    <div style="flex:1 1 320px;">
      <h3 style="margin-top:0;">Detalle</h3>
      <div><strong>Nombre:</strong> <?php echo e($viewItem['nombre']); ?></div>
      <div><strong>Código:</strong> <?php echo e($viewItem['codigo']); ?></div>
      <div><strong>Dueño:</strong> <?php echo $viewItem['dueno'] !== '' ? e($viewItem['dueno']) : '—'; ?></div>
      <div><strong>Estado:</strong> <?php echo e($viewItem['estado']); ?></div>
      <div><strong>Descripción:</strong><br><?php echo $viewItem['descripcion'] !== '' ? nl2br(e($viewItem['descripcion'])) : '<span style="color:var(--muted);">Sin descripción</span>'; ?></div>
      <div style="margin-top:10px;display:flex;gap:8px;flex-wrap:wrap;">
        <?php $qrData = $baseUrl . '/inventario.php?code=' . urlencode($viewItem['codigo']);
              $qrUrl = 'https://api.qrserver.com/v1/create-qr-code/?size=220x220&data=' . urlencode($qrData); ?>
        <img src="<?php echo e($qrUrl); ?>" alt="QR" style="width:160px;height:160px;background:#fff;padding:6px;border-radius:10px;">
        <div style="display:flex;flex-direction:column;gap:8px;">
          <a class="btn" href="inventario.php?code=<?php echo urlencode($viewItem['codigo']); ?>&print=1" target="_blank" style="width:180px;">Imprimir etiqueta 29x62</a>
          <a class="btn secondary" href="<?php echo e($qrUrl); ?>" target="_blank" style="width:180px;">Abrir QR</a>
        </div>
      </div>
    </div>
    <div style="flex:1 1 320px;">
      <h3 style="margin-top:0;">Agregar log / foto</h3>
      <form method="post" enctype="multipart/form-data" style="display:grid;grid-template-columns:1fr;gap:8px;">
        <input type="hidden" name="action" value="add_log">
        <input type="hidden" name="item_id" value="<?php echo (int)$viewItem['id']; ?>">
        <div>
          <label>Estado</label>
          <select name="estado">
            <option value="">(No cambiar)</option>
            <option value="OK">OK</option>
            <option value="EN FALTA">En falta</option>
            <option value="PERDIDO">Perdido</option>
          </select>
        </div>
        <div>
          <label>Nota</label>
          <textarea name="nota" rows="2" placeholder="Ej: Golpe leve en esquina"></textarea>
        </div>
        <div>
          <label>Foto (opcional)</label>
          <input type="file" name="foto" accept="image/*">
        </div>
        <div style="display:flex;justify-content:flex-end;">
          <button class="btn" type="submit">Guardar log</button>
        </div>
      </form>
    </div>
  </div>
</div>

<div class="card" style="margin-top:12px;">
  <h3 style="margin-top:0;">Historial</h3>
  <div style="display:grid;gap:10px;">
    <?php if (empty($logs)): ?>
      <div style="color:var(--muted);">Sin registros</div>
    <?php else: ?>
      <?php foreach ($logs as $lg): ?>
        <div style="border:1px solid var(--line);padding:10px;border-radius:8px;display:flex;gap:12px;align-items:flex-start;">
          <div style="min-width:120px;color:var(--muted);font-size:12px;">
            <?php echo e($lg['created_at']); ?><br>
            <?php echo $lg['estado'] !== '' ? e($lg['estado']) : ''; ?>
          </div>
          <div style="flex:1;">
            <?php echo $lg['nota'] !== '' ? nl2br(e($lg['nota'])) : '<span style="color:var(--muted);">Sin nota</span>'; ?>
          </div>
          <?php if (!empty($lg['foto_path'])): ?>
            <div style="min-width:140px;">
              <a href="<?php echo e($lg['foto_path']); ?>" target="_blank">
                <img src="<?php echo e($lg['foto_path']); ?>" alt="foto" style="max-width:140px;border-radius:6px;">
              </a>
            </div>
          <?php endif; ?>
        </div>
      <?php endforeach; ?>
    <?php endif; ?>
  </div>
</div>
<?php endif; ?>

<?php include __DIR__.'/inc/layout_bottom.php'; ?>
