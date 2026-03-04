<?php
// Dashboard para staff de puerta
require_once __DIR__ . '/inc/auth.php';
require_once __DIR__ . '/inc/layout_top.php';

$cu = current_user();
if (!isset($cu['tipo_global']) || $cu['tipo_global'] !== 'puerta') {
    header('Location: panel_admin.php');
    exit;
}
?>
<div class="card" style="max-width:900px;margin:24px auto;">
  <h2>Check-in de Puerta</h2>
  <div style="display:flex;flex-wrap:wrap;gap:24px;align-items:flex-start;">
    <div style="flex:2;min-width:320px;">
      <form id="buscador-form" method="get" style="margin-bottom:16px;">
        <input type="text" name="q" id="q" placeholder="Buscar usuario por nombre, DNI o código..." style="width:100%;padding:8px;">
        <button type="submit" class="btn" style="margin-top:8px;">Buscar</button>
      </form>
      <div id="resultados">
        <!-- Aquí se mostrarán los resultados de búsqueda -->
      </div>
    </div>
    <div style="flex:1;min-width:260px;">
      <div id="qr-section" style="text-align:center;margin-bottom:24px;">
        <button class="btn" id="btn-abrir-qr" style="margin-bottom:8px;">📷 Escanear QR</button>
        <div id="qr-reader" style="display:none;"></div>
      </div>
      <div id="contadores" style="margin-bottom:24px;">
        <strong>Check-ins realizados:</strong> <span id="checkin-count">0</span><br>
        <strong>Entradas vendidas en puerta:</strong> <span id="ventas-count">0</span>
      </div>
      <button class="btn secondary" onclick="window.location.href='panel_admin.php'">Volver</button>
    </div>
  </div>
</div>

<!-- Modal de confirmación de check-in -->
<div id="modal-confirm" style="display:none;position:fixed;top:0;left:0;width:100vw;height:100vh;background:rgba(0,0,0,0.4);align-items:center;justify-content:center;z-index:1000;">
  <div style="background:#fff;padding:24px;border-radius:8px;max-width:340px;text-align:center;">
    <div id="modal-msg">¿Confirmar check-in?</div>
    <div style="margin-top:18px;">
      <button class="btn" id="btn-confirmar">Confirmar</button>
      <button class="btn secondary" id="btn-cancelar">Cancelar</button>
    </div>
  </div>
</div>

<script src="https://unpkg.com/html5-qrcode" type="text/javascript"></script>
<script>
// --- Buscador manual ---
const form = document.getElementById('buscador-form');
const resultadosDiv = document.getElementById('resultados');
const modal = document.getElementById('modal-confirm');
const modalMsg = document.getElementById('modal-msg');
let checkinId = null;

form.onsubmit = function(e) {
  e.preventDefault();
  const q = document.getElementById('q').value.trim();
  if (!q) return;
  resultadosDiv.innerHTML = 'Buscando...';
  fetch('puerta_api.php?action=buscar&q=' + encodeURIComponent(q))
    .then(r => r.json())
    .then(data => {
      if (data.resultados && data.resultados.length) {
        resultadosDiv.innerHTML = data.resultados.map(u => `
          <div class='card' style='margin-bottom:10px;'>
            <strong>${u.nombre}</strong> <span style='color:#888;'>(${u.entrada})</span><br>
            ${u.checkin ? '<span style="color:green;">Ya chequeado</span>' : `<button class='btn' onclick='confirmarCheckin(${u.id}, "${u.nombre}", "${u.entrada}")'>Check-in</button>`}
          </div>
        `).join('');
      } else {
        resultadosDiv.innerHTML = '<div class="muted">No se encontraron resultados.</div>';
      }
    });
};

window.confirmarCheckin = function(id, nombre, entrada) {
  checkinId = id;
  modalMsg.innerHTML = `¿Confirmar check-in para <strong>${nombre}</strong> (${entrada})?`;
  modal.style.display = 'flex';
};
document.getElementById('btn-confirmar').onclick = function() {
  if (!checkinId) return;
  fetch('puerta_api.php?action=checkin', {
    method: 'POST',
    headers: {'Content-Type': 'application/x-www-form-urlencoded'},
    body: 'id=' + encodeURIComponent(checkinId)
  })
    .then(r => r.json())
    .then(data => {
      modal.style.display = 'none';
      checkinId = null;
      form.onsubmit(new Event('submit')); // Refresca resultados
      cargarContadores();
    });
};
document.getElementById('btn-cancelar').onclick = function() {
  modal.style.display = 'none';
  checkinId = null;
};

// --- QR ---
const btnQR = document.getElementById('btn-abrir-qr');
const qrReaderDiv = document.getElementById('qr-reader');
let qrScanner = null;
btnQR.onclick = function() {
  qrReaderDiv.style.display = 'block';
  if (!qrScanner) {
    qrScanner = new Html5Qrcode('qr-reader');
  }
  qrScanner.start({ facingMode: 'environment' }, { fps: 10, qrbox: 250 }, qrCodeSuccess);
};
function qrCodeSuccess(decodedText) {
  qrScanner.stop();
  qrReaderDiv.style.display = 'none';
  // Buscar por código escaneado
  document.getElementById('q').value = decodedText;
  form.onsubmit(new Event('submit'));
}

// --- Contadores ---
function cargarContadores() {
  fetch('puerta_api.php?action=contadores')
    .then(r => r.json())
    .then(data => {
      document.getElementById('checkin-count').textContent = data.checkin_count;
      document.getElementById('ventas-count').textContent = data.ventas_count;
    });
}
cargarContadores();
</script>
<?php require_once __DIR__ . '/inc/layout_bottom.php'; ?>
