<?php
require_once __DIR__.'/inc/bootstrap.php';
$title = "Evento publicado – TICKEX";

require_login();

$cu = current_user();
$tipoGlobal = isset($_SESSION['tipo_global']) ? $_SESSION['tipo_global'] : (isset($cu['rol'])?$cu['rol']:'');
if (!in_array($tipoGlobal, array('admin_evento','super_admin','superadmin'), true)) {
    header("Location: login.php");
    exit;
}

$adminId  = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : (isset($cu['id'])?(int)$cu['id']:0);
$eventoId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($eventoId <= 0) {
    http_response_code(400);
    echo "ID de evento inválido.";
    exit;
}

try {
    $pdo = db();
} catch (Exception $e) {
    http_response_code(500);
    echo "Error DB: " . e($e->getMessage());
    exit;
}

// ===== Evento =====
$stEv = $pdo->prepare("SELECT * FROM eventos WHERE id = :id");
$stEv->execute(array(':id' => $eventoId));
$evento = $stEv->fetch(PDO::FETCH_ASSOC);
if (!$evento) {
    http_response_code(404);
    echo "Evento no encontrado.";
    exit;
}

// Detectar columnas opcionales
$colsEv = $pdo->query("PRAGMA table_info(eventos)")->fetchAll(PDO::FETCH_ASSOC);
$hasCreadoPor   = false;
$hasPublicadoEn = false;
foreach($colsEv as $c){
    if (isset($c['name']) && $c['name']==='creado_por_admin_id') $hasCreadoPor   = true;
    if (isset($c['name']) && $c['name']==='publicado_en')        $hasPublicadoEn = true;
}

// admin_evento solo puede publicar sus propios eventos
if ($tipoGlobal === 'admin_evento' && $hasCreadoPor) {
    $creador = isset($evento['creado_por_admin_id']) ? (int)$evento['creado_por_admin_id'] : 0;
    if ($creador !== $adminId) {
        http_response_code(403);
        echo "No podés publicar este evento.";
        exit;
    }
}

$ok = false;
$mensaje = '';

try {
    if ($hasPublicadoEn) {
        $stPub = $pdo->prepare("UPDATE eventos SET publicado_en = datetime('now') WHERE id = :id");
        $stPub->execute(array(':id' => $eventoId));
        if ($stPub->rowCount() > 0) {
            $ok = true;
            $mensaje = "El evento <strong>".e($evento['nombre'])."</strong> fue publicado correctamente.";
        } else {
            $mensaje = "No se pudo marcar el evento como publicado.";
        }
    } else {
        // No existe columna publicado_en, no rompemos
        $ok = true;
        $mensaje = "El evento <strong>".e($evento['nombre'])."</strong> se considera publicado.<br>
                    (Si querés guardar la fecha de publicación, agregá una columna
                    <code>publicado_en</code> en la tabla <code>eventos</code>.)";
    }
} catch (Exception $e) {
    $ok = false;
    $mensaje = "Error al publicar el evento: " . e($e->getMessage());
}

// Traer tipos del evento para mostrar resumen
$stTE = $pdo->prepare("SELECT * FROM tipos_entrada WHERE evento_id=? ORDER BY id DESC");
$stTE->execute(array($eventoId));
$tiposEvento = $stTE->fetchAll(PDO::FETCH_ASSOC);

include __DIR__.'/inc/layout_top.php';
?>

<div class="card">
  <h2><?php echo $ok ? "Evento publicado correctamente" : "No se pudo publicar el evento"; ?></h2>
  <p style="margin-top:8px;">
    <?php echo $mensaje; ?>
  </p>
</div>

<div class="card">
  <h3>Resumen del evento</h3>
  <div style="display:flex;gap:16px;flex-wrap:wrap;margin-top:8px;align-items:flex-start;">
    <div style="flex:1 1 220px;">
      <div class="muted">Nombre</div>
      <div><?php echo e($evento['nombre']); ?></div>

      <div class="muted" style="margin-top:8px;">Slug / URL</div>
      <div><code><?php echo e($evento['slug']); ?></code></div>

      <div class="muted" style="margin-top:8px;">Fechas</div>
      <div>
        <?php
          $fd = isset($evento['fecha_desde']) ? $evento['fecha_desde'] : '';
          $fh = isset($evento['fecha_hasta']) ? $evento['fecha_hasta'] : '';
          if ($fd==='' && $fh==='') {
              echo '<span class="muted">Sin fecha definida</span>';
          } else {
              echo e($fd);
              if ($fh!=='') echo ' → '.e($fh);
          }
        ?>
      </div>

      <div class="muted"
      <div><?php echo nl2br(e(isset($evento['descripcion'])?$evento['descripcion']:'')); ?></div>
    </div>

    <div>
      <div class="muted">Flyer</div>
      <?php
        $flyerOk = (!empty($evento['flyer_filename']) && file_exists(__DIR__ . '/' . $evento['flyer_filename']));
      ?>
      <?php if($flyerOk): ?>
        <img src="<?php echo e($evento['flyer_filename']); ?>" alt="Flyer"
             style="width:160px;height:160px;object-fit:cover;border-radius:10px;border:1px solid var(--line);background:#000;">
      <?php else: ?>
        <div class="muted">Sin flyer.</div>
      <?php endif; ?>
    </div>
  </div>
</div>

<div class="card">
  <h3>Entradas publicadas para este evento</h3>

  <?php if(empty($tiposEvento)): ?>
    <div class="muted" style="margin-top:8px;">
      No hay tipos de entrada asociados a este evento todavía.
    </div>
  <?php else: ?>
    <table class="table" style="margin-top:8px;">
      <thead>
        <tr>
          <th>ID</th>
          <th>Categoría</th>
          <th>Nombre</th>
          <th>Tipo</th>
          <th>Precio</th>
          <th>Cantidad</th>
          <th>Hora límite</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach($tiposEvento as $te): ?>
          <tr>
            <td><?php echo (int)$te['id']; ?></td>
            <td><?php echo e($te['categoria']); ?></td>
            <td><?php echo e($te['nombre']); ?></td>
            <td><?php echo e($te['tipo_venta']); ?></td>
            <td>$<?php echo (int)$te['precio']; ?></td>
            <td><?php echo (int)$te['cantidad_total']; ?></td>
            <td><?php echo e($te['hora_limite']); ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  <?php endif; ?>
</div>

<div class="card">
  <div style="display:flex;gap:8px;flex-wrap:wrap;">
    <a class="btn" href="panel_admin.php">⬅ Volver al panel</a>
    <a class="btn secondary" href="editar_evento.php?id=<?php echo (int)$eventoId; ?>">✏️ Editar evento</a>
  </div>
</div>

<?php include __DIR__.'/inc/layout_bottom.php'; ?>
