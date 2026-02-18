<?php
require_once __DIR__.'/inc/bootstrap.php';
$title = "Puerta – Check-in";

require_login();

$cu = current_user();
$tipoGlobal = isset($_SESSION['tipo_global']) ? $_SESSION['tipo_global'] : (isset($cu['rol'])?$cu['rol']:'');
$rolEvento  = isset($_SESSION['rol_evento']) ? $_SESSION['rol_evento'] : (isset($cu['rol_evento'])?$cu['rol_evento']:'');

// Permitidos: staff puerta (principal). También dejamos super/admin por si querés usarlo.
$permitido = false;
if ($tipoGlobal === 'staff_evento' && $rolEvento === 'puerta') $permitido = true;
if ($tipoGlobal === 'admin_evento' || $tipoGlobal === 'super_admin' || $tipoGlobal === 'superadmin') $permitido = true;

if (!$permitido) {
    http_response_code(403);
    include __DIR__.'/inc/layout_top.php';
    echo "<div class='card error'><h2>Acceso denegado</h2><p>Este panel es solo para Puerta.</p></div>";
    include __DIR__.'/inc/layout_bottom.php';
    exit;
}

// ========================
//  EVENTO ACTUAL
// ========================
$eventoId = 0;
if (isset($_GET['evento_id'])) {
    $eventoId = (int)$_GET['evento_id'];
} elseif (isset($_SESSION['evento_id'])) {
    $eventoId = (int)$_SESSION['evento_id'];
}
if ($eventoId <= 0) abort_404("ID de evento inválido.");

$pdo = db();

// Datos del evento
$eventoNombre = '';
$eventoSlug   = '';
$stmtEvInfo = $pdo->prepare("SELECT nombre, slug FROM eventos WHERE id = :id LIMIT 1");
$stmtEvInfo->execute(array(':id'=>$eventoId));
$evInfo = $stmtEvInfo->fetch(PDO::FETCH_ASSOC);
if ($evInfo) {
    $eventoNombre = $evInfo['nombre'];
    $eventoSlug   = $evInfo['slug'];
}

// ========================
//  FILTROS
// ========================
$q = isset($_GET['q']) ? trim($_GET['q']) : '';
$filtroTipo = isset($_GET['tipo']) ? trim($_GET['tipo']) : '';
$filtroEstado = isset($_GET['estado']) ? trim($_GET['estado']) : '';

$where  = array("evento_id = :evento_id");
$params = array(':evento_id'=>$eventoId);

if ($q !== '') {
    $where[] = "(nombre LIKE :q OR codigo LIKE :q)";
    $params[':q'] = '%'.$q.'%';
}
if ($filtroTipo !== '') {
    $where[] = "tipo = :tipo";
    $params[':tipo'] = $filtroTipo;
}
if ($filtroEstado === 'checkin_ok') $where[] = "checked_in = 1";
if ($filtroEstado === 'pendiente')  $where[] = "checked_in = 0";

$whereSql = "WHERE ".implode(" AND ", $where);

// ========================
//  CONTADORES
// ========================
$stmtTotal = $pdo->prepare("SELECT COUNT(*) FROM entradas $whereSql");
$stmtTotal->execute($params);
$total = (int)$stmtTotal->fetchColumn();

$stmtCheck = $pdo->prepare("SELECT COUNT(*) FROM entradas $whereSql AND checked_in=1");
$stmtCheck->execute($params);
$checkins = (int)$stmtCheck->fetchColumn();

$faltan = max(0, $total - $checkins);

// ========================
//  ROWS
// ========================
$sql = "SELECT id, nombre, tipo, codigo, checked_in FROM entradas $whereSql ORDER BY id DESC";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

// tipos para combo (solo del evento)
$tiposStmt = $pdo->prepare("SELECT DISTINCT tipo FROM entradas WHERE evento_id=? ORDER BY tipo ASC");
$tiposStmt->execute(array($eventoId));
$tipos = $tiposStmt->fetchAll(PDO::FETCH_COLUMN);

include __DIR__.'/inc/layout_top.php';
?>

<style>
  /* Ajustes visuales puerta */
  .table th, .table td { padding: 12px 12px; }
  .actions, .ticketcol { white-space: nowrap; }
</style>

<div class="card" style="display:flex;gap:8px;flex-wrap:wrap;align-items:center;">
  <a class="btn secondary" href="panel_admin.php">⬅ Panel</a>

  <a class="btn" href="nueva_entrada.php?evento_id=<?php echo $eventoId; ?>"
     style="background:var(--ok);color:#04150a;">
    + Nueva entrada
  </a>

  <button class="btn" type="button" id="btnScan">📷 Escanear QR</button>

  <span style="flex:1 1 auto;"></span>
  <a class="btn danger" href="login.php?logout=1">Salir</a>
</div>

<div class="card">
  <h2>Puerta – Check-in</h2>
  <div style="color:var(--muted);font-size:14px;margin-top:4px;">
    Evento: <strong><?php echo e($eventoNombre); ?></strong>
    <?php if($eventoSlug!==''): ?> (<?php echo e($eventoSlug); ?>)<?php endif; ?><br>
    Usuario: <strong><?php echo e($_SESSION['usuario']); ?></strong>
  </div>

  <div style="display:flex;gap:12px;flex-wrap:wrap;margin-top:12px;">
    <div class="card" style="min-width:160px;background:var(--panel-2);">
      <div style="font-size:12px;color:var(--muted);">Entradas (filtros)</div>
      <div style="font-size:22px;font-weight:800;margin-top:4px;"><?php echo $total; ?></div>
    </div>
    <div class="card" style="min-width:160px;background:var(--panel-2);">
      <div style="font-size:12px;color:var(--muted);">Check-ins</div>
      <div style="font-size:22px;font-weight:800;margin-top:4px;"><?php echo $checkins; ?></div>
    </div>
    <div class="card" style="min-width:160px;background:var(--panel-2);">
      <div style="font-size:12px;color:var(--muted);">Faltan</div>
      <div style="font-size:22px;font-weight:800;margin-top:4px;"><?php echo $faltan; ?></div>
    </div>
  </div>
</div>

<div class="card">
  <h3>Filtros</h3>
  <form method="get" style="margin-top:8px;">
    <input type="hidden" name="evento_id" value="<?php echo $eventoId; ?>">
    <div style="display:grid;grid-template-columns:2fr 1fr 1fr auto;gap:8px;align-items:end;">
      <div>
        <label>Buscar (nombre o código)</label>
        <input type="text" name="q" value="<?php echo e($q); ?>" placeholder="Buscar...">
      </div>
      <div>
        <label>Tipo</label>
        <select name="tipo">
          <option value="">Todos</option>
          <?php foreach ($tipos as $t): ?>
            <option value="<?php echo e($t); ?>" <?php if($t===$filtroTipo) echo 'selected'; ?>>
              <?php echo e($t); ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>
      <div>
        <label>Estado</label>
        <select name="estado">
          <option value="">Todos</option>
          <option value="checkin_ok" <?php if($filtroEstado==='checkin_ok') echo 'selected'; ?>>Checkeados</option>
          <option value="pendiente"  <?php if($filtroEstado==='pendiente')  echo 'selected'; ?>>Pendientes</option>
        </select>
      </div>
      <div>
        <button class="btn secondary" type="submit">Filtrar</button>
      </div>
    </div>
  </form>
</div>

<div class="card">
  <h3>Lista del evento</h3>

  <?php if(!$rows): ?>
    <div style="color:var(--muted);font-size:14px;">No hay entradas para mostrar.</div>
  <?php else: ?>
    <div style="overflow:auto;margin-top:8px;">
      <table class="table" style="min-width:720px;">
        <thead>
          <tr>
            <th>ID</th>
            <th>Nombre</th>
            <th>Tipo</th>
            <th>Estado</th>
            <th class="actions">Acciones</th>
            <th class="ticketcol">Ticket</th>
          </tr>
        </thead>
        <tbody>
        <?php foreach($rows as $r): ?>
          <?php
            $estadoOk = ((int)$r['checked_in']===1);
            $codigo = isset($r['codigo']) ? trim($r['codigo']) : '';
          ?>
          <tr>
            <td><?php echo (int)$r['id']; ?></td>
            <td><?php echo e($r['nombre']); ?></td>
            <td><?php echo e($r['tipo']); ?></td>
            <td>
              <?php if($estadoOk): ?>
                <span style="color:var(--ok);font-weight:700;">Check-in OK</span>
              <?php else: ?>
                <span style="color:var(--warn);font-weight:700;">Pendiente</span>
              <?php endif; ?>
            </td>

            <!-- ACCION SOLO CHECK-IN -->
            <td class="actions">
              <?php if(!$estadoOk && $codigo!==''): ?>
                <a class="btn"
                   style="background:var(--ok);color:#04150a;padding:6px 12px;font-size:12px;"
                   href="checkin.php?c=<?php echo urlencode($codigo); ?>&evento_id=<?php echo $eventoId; ?>">
                  CHECK-IN
                </a>
              <?php else: ?>
                <span style="color:var(--muted);font-size:13px;">—</span>
              <?php endif; ?>
            </td>

            <!-- VER TICKET CON OJO -->
            <td class="ticketcol">
              <?php if($codigo!==''): ?>
                <a class="btn secondary"
                   style="padding:6px 10px;font-size:14px;"
                   title="Ver ticket"
                   target="_blank"
                   href="ticket.php?c=<?php echo urlencode($codigo); ?>">
                  👁️
                </a>
              <?php else: ?>
                <span style="color:var(--muted);font-size:13px;">—</span>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
</div>

<!-- Modal simple para cámara -->
<div id="scanModal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.75);z-index:9999;align-items:center;justify-content:center;padding:16px;">
  <div class="card" style="max-width:520px;width:100%;position:relative;">
    <h3>Escanear QR</h3>
    <div id="scanMsg" style="color:var(--muted);font-size:14px;margin-bottom:8px;"></div>
    <video id="scanVideo" style="width:100%;border-radius:12px;background:#000;" playsinline></video>
    <canvas id="scanCanvas" style="display:none;"></canvas>
    <div style="display:flex;gap:8px;margin-top:10px;">
      <button class="btn secondary" type="button" id="btnCloseScan">Cerrar</button>
    </div>
  </div>
</div>

<script>
(function(){
  const btnScan = document.getElementById('btnScan');
  const modal = document.getElementById('scanModal');
  const btnClose = document.getElementById('btnCloseScan');
  const video = document.getElementById('scanVideo');
  const canvas = document.getElementById('scanCanvas');
  const msg = document.getElementById('scanMsg');
  let stream = null;
  let rafId = null;

  function stopScan(){
    if(rafId) cancelAnimationFrame(rafId);
    rafId = null;
    if(stream){
      stream.getTracks().forEach(t=>t.stop());
      stream = null;
    }
    modal.style.display = 'none';
  }

  async function startScan(){
    modal.style.display = 'flex';
    msg.textContent = 'Inicializando cámara...';

    if(!('BarcodeDetector' in window)){
      msg.textContent = 'Tu navegador no soporta escaneo nativo. Usá búsqueda manual por código.';
      return;
    }

    try{
      stream = await navigator.mediaDevices.getUserMedia({ video: { facingMode: 'environment' }, audio:false });
      video.srcObject = stream;
      await video.play();
      msg.textContent = 'Apuntá al QR del ticket...';

      const detector = new BarcodeDetector({ formats: ['qr_code'] });

      const loop = async () => {
        if(video.readyState >= 2){
          const w = video.videoWidth, h = video.videoHeight;
          canvas.width = w; canvas.height = h;
          const ctx = canvas.getContext('2d');
          ctx.drawImage(video,0,0,w,h);

          try{
            const codes = await detector.detect(canvas);
            if(codes && codes.length){
              const val = codes[0].rawValue || '';
              if(val){
                stopScan();
                const eid = <?php echo (int)$eventoId; ?>;
                location.href = 'checkin.php?c=' + encodeURIComponent(val) + '&evento_id=' + eid;
                return;
              }
            }
          }catch(e){}
        }
        rafId = requestAnimationFrame(loop);
      };
      loop();
    }catch(err){
      msg.textContent = 'No pude abrir la cámara: ' + err;
    }
  }

  btnScan.addEventListener('click', startScan);
  btnClose.addEventListener('click', stopScan);
})();
</script>

<?php include __DIR__.'/inc/layout_bottom.php'; ?>
