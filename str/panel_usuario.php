<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

session_start();

$flashOk = '';
$flashError = '';

if (!isset($_SESSION['usuario_id']) || (int)$_SESSION['usuario_id'] <= 0) {
  header('Location: login.php');
  exit;
}

$dbFile = __DIR__ . '/save_the_rave.sqlite';

try {
  $pdo = new PDO('sqlite:' . $dbFile);
  $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (Exception $e) {
  header('Content-Type: text/plain; charset=utf-8');
  echo "Error al conectar a la base de datos: " . $e->getMessage();
  exit;
}

// Garantizar tabla de registros pendientes (fuente de datos de clientes)
$pdo->exec("CREATE TABLE IF NOT EXISTS registro_pendientes (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  email TEXT NOT NULL,
  token TEXT NOT NULL,
  nombre TEXT,
  apellido TEXT,
  apodo TEXT,
  dni TEXT,
  cbu TEXT,
  genero TEXT,
  foto_path TEXT,
  next_url TEXT,
  creado_en TEXT,
  completado_en TEXT,
  password_hash TEXT
)");

// Backfill columna password_hash si falta
try {
  $cols = $pdo->query("PRAGMA table_info(registro_pendientes)")->fetchAll(PDO::FETCH_ASSOC);
  $hasPass = false;
  $hasCbu = false;
  foreach ($cols as $c) {
    if (isset($c['name']) && $c['name'] === 'password_hash') { $hasPass = true; break; }
  }
  if (!$hasPass) {
    $pdo->exec("ALTER TABLE registro_pendientes ADD COLUMN password_hash TEXT");
  }

  foreach ($cols as $c) {
    if (isset($c['name']) && $c['name'] === 'cbu') { $hasCbu = true; break; }
  }
  if (!$hasCbu) {
    $pdo->exec("ALTER TABLE registro_pendientes ADD COLUMN cbu TEXT");
  }
} catch (Exception $e) {
  // ignore
}

$pdo->exec("CREATE TABLE IF NOT EXISTS entradas_ocultas_usuario (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  entrada_id INTEGER NOT NULL,
  email TEXT NOT NULL,
  creado_en TEXT
)");

$usuarioId = (int)$_SESSION['usuario_id'];

// Asegurar tablas mínimas para staff (nuevo modelo: staff como rol adicional de cliente)
try {
  $pdo->exec("CREATE TABLE IF NOT EXISTS staff_admins (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    owner_admin_id INTEGER NOT NULL,
    cliente_id INTEGER NOT NULL,
    rol_staff TEXT,
    activo INTEGER NOT NULL DEFAULT 1,
    created_at TEXT NOT NULL DEFAULT (datetime('now')),
    UNIQUE(owner_admin_id, cliente_id)
  )");
  $pdo->exec("CREATE INDEX IF NOT EXISTS idx_staff_admins_cliente ON staff_admins(cliente_id)");
} catch (Exception $e) {
  // ignore
}

$revendedorActivo = false;
try {
  $stRev = $pdo->prepare("SELECT id FROM revendedores WHERE cliente_id = :cid AND activo = 1 ORDER BY id DESC LIMIT 1");
  $stRev->execute(array(':cid' => $usuarioId));
  $revendedorActivo = (bool)$stRev->fetchColumn();
} catch (Exception $e) {
  $revendedorActivo = false;
}

$staffActivo = false;
try {
  $stStaff = $pdo->prepare("SELECT 1 FROM staff_admins WHERE cliente_id = :cid AND activo = 1 LIMIT 1");
  $stStaff->execute(array(':cid' => $usuarioId));
  $staffActivo = (bool)$stStaff->fetchColumn();
} catch (Exception $e) {
  $staffActivo = false;
}

try {
    // Datos del usuario desde registro_pendientes (clientes)
    $stmt = $pdo->prepare("
      SELECT id, nombre, apellido, email, dni, 'cliente' AS rol, 1 AS email_confirmado, creado_en, completado_en, apodo, genero, password_hash
      FROM registro_pendientes
      WHERE id = :id
      LIMIT 1
    ");
    $stmt->execute(array(':id' => $usuarioId));
    $u = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$u) {
        // El usuario ya no existe -> limpiar sesión
        $_SESSION = array();
        session_destroy();
        header('Location: login.php');
        exit;
    }

    $nombreCompleto  = trim($u['nombre'] . ' ' . $u['apellido']);
    $emailConfirmado = ((int)$u['email_confirmado'] === 1);
    $rol             = $u['rol'];
    $rolDisplay       = $rol;
    if ($staffActivo) {
      $rolDisplay = $rol . ' + staff';
    }
    $emailUsuario    = $u['email'];

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'hide_ticket') {
      $ticketId = isset($_POST['ticket_id']) ? (int)$_POST['ticket_id'] : 0;
      if ($ticketId > 0) {
        $stmtChk = $pdo->prepare("SELECT id FROM entradas WHERE id = :id AND email = :email LIMIT 1");
        $stmtChk->execute(array(':id' => $ticketId, ':email' => $emailUsuario));
        if ($stmtChk->fetch(PDO::FETCH_ASSOC)) {
          $stmtHide = $pdo->prepare("INSERT INTO entradas_ocultas_usuario (entrada_id, email, creado_en) VALUES (:id, :email, datetime('now'))");
          $stmtHide->execute(array(':id' => $ticketId, ':email' => $emailUsuario));
        }
      }
      header('Location: panel_usuario.php');
      exit;
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'change_password') {
      $passActual = isset($_POST['pass_actual']) ? trim($_POST['pass_actual']) : '';
      $passNueva  = isset($_POST['pass_nueva']) ? trim($_POST['pass_nueva']) : '';
      $passRepite = isset($_POST['pass_repite']) ? trim($_POST['pass_repite']) : '';

      if ($passNueva === '' || $passRepite === '') {
        $flashError = 'La nueva contraseña es obligatoria.';
      } elseif (strlen($passNueva) < 6) {
        $flashError = 'La nueva contraseña debe tener al menos 6 caracteres.';
      } elseif ($passNueva !== $passRepite) {
        $flashError = 'La nueva contraseña y su repeticion no coinciden.';
      } else {
        $hashActual = isset($u['password_hash']) ? (string)$u['password_hash'] : '';
        $needsOld   = ($hashActual !== '');

        // Validar contraseña actual solo si ya había una definida
        if ($needsOld) {
          $okOld = false;
          if (function_exists('password_verify') && strpos($hashActual, '$2') === 0) {
            $okOld = password_verify($passActual, $hashActual);
          }
          if (!$okOld && strlen($hashActual) === 32 && ctype_xdigit($hashActual)) {
            $okOld = (md5($passActual) === strtolower($hashActual));
          }
          if (!$okOld && $hashActual !== '') {
            $okOld = ($passActual !== '' && $passActual === $hashActual);
          }

          if (!$okOld) {
            $flashError = 'La contraseña actual no es correcta.';
          }
        }

        if ($flashError === '') {
          $newHash = function_exists('password_hash') ? password_hash($passNueva, PASSWORD_DEFAULT) : md5($passNueva);
          $stmtPwd = $pdo->prepare("UPDATE registro_pendientes SET password_hash = :h WHERE id = :id");
          $stmtPwd->execute(array(':h' => $newHash, ':id' => (int)$u['id']));
          $u['password_hash'] = $newHash;
          $flashOk = 'Contraseña actualizada. Usala para iniciar sesión desde ahora.';
        }
      }
    }

    // Últimas entradas asociadas a su email
    $showAll = isset($_GET['ver_todas']) && $_GET['ver_todas'] === '1';

    // Detectar columnas disponibles en eventos
    $evCols = $pdo->query("PRAGMA table_info(eventos)")->fetchAll(PDO::FETCH_ASSOC);
    $colMap = array();
    foreach ($evCols as $c) { $colMap[$c['name']] = true; }
    $hasFlyer = isset($colMap['flyer']);
    $hasFlyerFile = isset($colMap['flyer_filename']);
    $hasFechaDesde = isset($colMap['fecha_desde']);
    $hasLugar = isset($colMap['lugar']);
    $hasUbic = isset($colMap['ubicacion']);

    $selectEv = array('ev.nombre AS evento_nombre');
    if ($hasFlyerFile) $selectEv[] = 'ev.flyer_filename';
    if ($hasFlyer) $selectEv[] = 'ev.flyer';
    if ($hasFechaDesde) $selectEv[] = 'ev.fecha_desde';
    if ($hasLugar) $selectEv[] = 'ev.lugar';
    if ($hasUbic) $selectEv[] = 'ev.ubicacion';
    $selStr = implode(",\n            ", $selectEv);

    $sqlT = "
      SELECT
        e.id,
        e.codigo,
        e.evento_id,
        e.fecha_registro,
        e.tipo,
        e.monto_pagado,
        e.checked_in,
        $selStr
      FROM entradas e
      LEFT JOIN eventos ev ON ev.id = e.evento_id
      LEFT JOIN entradas_ocultas_usuario eo ON eo.entrada_id = e.id AND eo.email = :email
      WHERE e.email = :email
        " . ($showAll ? "" : "AND (e.checked_in IS NULL OR e.checked_in = 0) AND eo.id IS NULL") . "
      ORDER BY e.fecha_registro DESC, e.id DESC
      LIMIT 50
    ";
    $stmtT = $pdo->prepare($sqlT);
    $stmtT->execute(array(':email' => $emailUsuario));
    $tickets = $stmtT->fetchAll(PDO::FETCH_ASSOC);

} catch (Exception $e) {
    header('Content-Type: text/plain; charset=utf-8');
    echo "Error al cargar datos del usuario: " . $e->getMessage();
    exit;
}

include __DIR__ . '/inc/layout_top.php';
$isApp = (!empty($_COOKIE['tickex_app']) && (string)$_COOKIE['tickex_app'] === '1');
?>
<div class="card" style="max-width:900px;margin:0 auto 16px auto;">
  <div style="display:flex;align-items:center;gap:16px;flex-wrap:wrap;">
    <div>
      <img src="tickex-logo_sobre_oscuro.svg"
           alt="Tickex"
           style="height:80px;display:block;">
    </div>
    <div>
      <?php if (!$isApp): ?>
        <h2 style="margin:0;">Mi cuenta Tickex</h2>
      <?php endif; ?>
      <?php if ($isApp): ?>
        <?php
          $nombreHola = '';
          if (isset($u['nombre']) && trim((string)$u['nombre']) !== '') {
            $nombreHola = trim((string)$u['nombre']);
          } elseif ($nombreCompleto !== '') {
            $nombreHola = $nombreCompleto;
          } else {
            $nombreHola = $emailUsuario;
          }
          $tickexId = ($u['apodo'] && $u['apodo'] !== '') ? $u['apodo'] : ('#'.$u['id']);
        ?>
        <div class="app-profile">
          <div class="app-hello">Hola, <strong><?php echo htmlspecialchars($nombreHola, ENT_QUOTES, 'UTF-8'); ?></strong></div>
          <div class="app-email">
            <?php if ($emailConfirmado): ?>
              <span class="app-badge app-badge-ok">✔ Email verificado</span>
            <?php else: ?>
              <span class="app-badge app-badge-err">✖ Email pendiente de verificación</span>
            <?php endif; ?>
            <span class="app-email-text"><?php echo htmlspecialchars($emailUsuario, ENT_QUOTES, 'UTF-8'); ?></span>
          </div>
          <div class="app-meta">Rol: <?php echo htmlspecialchars($rol, ENT_QUOTES, 'UTF-8'); ?></div>
          <?php if ($staffActivo): ?>
            <div class="app-meta">Staff: sí</div>
          <?php endif; ?>
          <div class="app-meta">Tickex ID: <?php echo htmlspecialchars($tickexId, ENT_QUOTES, 'UTF-8'); ?></div>
        </div>
      <?php else: ?>
        <div style="color:var(--muted);margin-top:4px;">
          Hola,
          <strong>
            <?php
            echo htmlspecialchars(
                $nombreCompleto !== '' ? $nombreCompleto : $emailUsuario,
                ENT_QUOTES,
                'UTF-8'
            );
            ?>
          </strong>
        </div>
        <div style="margin-top:6px;font-size:13px;">
          <?php if ($emailConfirmado): ?>
            <span style="background:#1b8a3a;color:white;padding:2px 8px;border-radius:999px;font-size:12px;">
              ✔ Email verificado
            </span>
          <?php else: ?>
            <span style="background:#b34747;color:white;padding:2px 8px;border-radius:999px;font-size:12px;">
              ✖ Email pendiente de verificación
            </span>
          <?php endif; ?>
          <span style="margin-left:8px;color:var(--muted);">
            Rol: <?php echo htmlspecialchars($rolDisplay, ENT_QUOTES, 'UTF-8'); ?>
          </span>
        </div>
      <?php endif; ?>
    </div>
  </div>
</div>

<?php if ($flashOk !== ''): ?>
  <div class="card" style="max-width:900px;margin:0 auto 12px auto;">
    <div class="flash ok"><?php echo e($flashOk); ?></div>
  </div>
<?php endif; ?>

<?php if ($flashError !== ''): ?>
  <div class="card" style="max-width:900px;margin:0 auto 12px auto;">
    <div class="flash err"><?php echo e($flashError); ?></div>
  </div>
<?php endif; ?>

<div style="max-width:900px;margin:0 auto;display:flex;flex-direction:column;gap:16px;">

    <!-- Mis últimos Tickex -->
    <div class="card">
      <div style="display:flex;align-items:center;justify-content:space-between;gap:10px;flex-wrap:wrap;<?php echo $isApp ? 'justify-content:flex-start;' : ''; ?>">
        <?php if (!$isApp): ?>
          <h3 style="margin:0;display:flex;align-items:center;gap:8px;flex-wrap:wrap;">Mis Tickex <span class="pill" style="background:var(--panel-2);border:1px solid var(--line);">Tickex ID: <?php echo htmlspecialchars(($u['apodo'] && $u['apodo']!=='') ? $u['apodo'] : ('#'.$u['id']), ENT_QUOTES, 'UTF-8'); ?></span></h3>
        <?php endif; ?>
        <div class="app-row-actions" style="display:flex;gap:8px;flex-wrap:wrap;">
          <?php if ($revendedorActivo): ?>
            <a class="btn" href="panel_revendedor.php">Dashboard revendedor</a>
          <?php endif; ?>
          <?php if ($staffActivo): ?>
            <a class="btn" href="panel_staff.php">Dashboard staff</a>
          <?php endif; ?>
          <a class="btn secondary" href="panel_usuario.php?ver_todas=1">Ver todas</a>
          <a class="btn secondary" href="panel_usuario.php">Solo pendientes</a>
        </div>
      </div>
      <?php if (!empty($tickets)): ?>
        <div style="max-width:900px;margin:0 auto;display:flex;flex-direction:column;gap:12px;margin-top:10px;">
          <?php foreach ($tickets as $t): ?>
            <?php
              $flyer = null;
              if (isset($t['flyer_filename']) && $t['flyer_filename'] && file_exists(__DIR__.'/'.$t['flyer_filename'])) {
                $flyer = $t['flyer_filename'];
              } elseif (isset($t['flyer']) && $t['flyer']) {
                $flyer = $t['flyer'];
              }
              $eventName = isset($t['evento_nombre']) && $t['evento_nombre'] ? $t['evento_nombre'] : ('Evento #'.$t['evento_id']);
              $loc = '';
              if (isset($t['lugar']) && $t['lugar']) $loc = $t['lugar'];
              elseif (isset($t['ubicacion']) && $t['ubicacion']) $loc = $t['ubicacion'];
              $ticketUrl = 'entrada.php?c=' . urlencode($t['codigo']);
            ?>
            <div style="border:1px solid var(--line);border-radius:12px;overflow:hidden;background:var(--panel-2);display:flex;gap:12px;align-items:stretch;width:100%;">
              <div style="flex:0 0 140px;height:140px;display:flex;align-items:center;justify-content:center;background:var(--panel-3);border-radius:8px;margin:12px 0 12px 12px;overflow:hidden;">
                <?php if ($flyer): ?>
                  <img src="<?php echo e($flyer); ?>" alt="Flyer" style="width:100%;height:100%;max-width:140px;max-height:140px;object-fit:cover;aspect-ratio:1/1;display:block;margin:auto;">
                <?php else: ?>
                  <div style="width:100%;height:100%;display:flex;align-items:center;justify-content:center;color:var(--muted);font-size:12px;">Sin flyer</div>
                <?php endif; ?>
              </div>
              <div style="padding:12px;display:flex;flex-direction:column;gap:6px;flex:1;">
                <div style="display:flex;justify-content:space-between;gap:8px;align-items:center;flex-wrap:wrap;">
                  <span style="font-weight:700;"><?php echo e($eventName); ?></span>
                  <span class="pill" style="border:1px solid var(--line);">Entrada <?php echo e($t['tipo']); ?></span>
                </div>
                <div style="font-size:13px;color:var(--muted);display:grid;grid-template-columns:1fr;gap:4px;">
                  <?php if (!empty($t['fecha_desde'])): ?><span>📅 <?php echo e($t['fecha_desde']); ?></span><?php endif; ?>
                  <?php if ($loc): ?><span>📍 <?php echo e($loc); ?></span><?php endif; ?>
                  <span>Emitida: <?php echo e($t['fecha_registro']); ?></span>
                </div>
                <div style="font-size:18px;font-weight:700;">
                  <?php
                    $m = (int)$t['monto_pagado'];
                    echo $m > 0 ? '$'.number_format($m/100,2,',','.') : 'Cortesía';
                  ?>
                </div>
                <div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center;">
                  <a class="btn secondary" href="<?php echo e($ticketUrl); ?>" target="_blank" rel="noopener">Ver Tickex</a>
                  <?php if (!$showAll): ?>
                    <form method="post" style="margin:0;" onsubmit="return confirm('¿Ocultar este Tickex de tu lista?');">
                      <input type="hidden" name="action" value="hide_ticket">
                      <input type="hidden" name="ticket_id" value="<?php echo (int)$t['id']; ?>">
                      <button class="btn danger" type="submit" title="Ocultar"><span aria-hidden="true">🗑</span></button>
                    </form>
                  <?php endif; ?>
                </div>
              </div>
            </div>
          <?php endforeach; ?>
        </div>
      <?php else: ?>
        <p style="margin-top:8px;font-size:14px;">
          Todavía no tenés Tickex asociados a este email.
        </p>
        <p style="font-size:13px;color:var(--muted);">
          Cuando compres entradas con este correo, van a aparecer acá automáticamente.
        </p>
      <?php endif; ?>
    </div>

  </div>

  <!-- Columna derecha eliminada: solo cards de Tickex -->
</div>

<footer style="max-width:900px;margin:32px auto 0 auto;text-align:center;font-size:13px;color:var(--muted);">
  <a href="#" style="color:var(--muted);text-decoration:underline;">Centro de Ayuda / FAQ</a> ·
  <a href="#" style="color:var(--muted);text-decoration:underline;">Contactar soporte</a>
</footer>

<?php include __DIR__ . '/inc/layout_bottom.php'; ?>
