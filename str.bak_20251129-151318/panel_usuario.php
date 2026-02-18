<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

session_start();

// Si no hay usuario logueado, redirigir al login
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

$usuarioId = (int)$_SESSION['usuario_id'];

try {
    // Datos del usuario
    $stmt = $pdo->prepare("
        SELECT id, nombre, apellido, email, dni, rol, email_confirmado, creado_en
        FROM usuarios
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
    $emailUsuario    = $u['email'];

    // Últimas entradas asociadas a su email
    $stmtT = $pdo->prepare("
        SELECT
            e.id,
            e.codigo,
            e.evento_id,
            e.fecha_registro,
            e.tipo,
            e.monto_pagado
        FROM entradas e
        WHERE e.email = :email
        ORDER BY e.fecha_registro DESC, e.id DESC
        LIMIT 20
    ");
    $stmtT->execute(array(':email' => $emailUsuario));
    $tickets = $stmtT->fetchAll(PDO::FETCH_ASSOC);

} catch (Exception $e) {
    header('Content-Type: text/plain; charset=utf-8');
    echo "Error al cargar datos del usuario: " . $e->getMessage();
    exit;
}

include __DIR__ . '/inc/layout_top.php';
?>
<div class="card" style="max-width:900px;margin:0 auto 16px auto;">
  <div style="display:flex;align-items:center;gap:16px;flex-wrap:wrap;">
    <div>
      <img src="tickex-logo_sobre_oscuro.svg"
           alt="Tickex"
           style="height:80px;display:block;">
    </div>
    <div>
      <h2 style="margin:0;">Mi cuenta Tickex</h2>
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
          Rol: <?php echo htmlspecialchars($rol, ENT_QUOTES, 'UTF-8'); ?>
        </span>
      </div>
    </div>
    <div style="margin-left:auto;">
      <a class="btn secondary" href="logout_usuario.php">Cerrar sesión</a>
    </div>
  </div>
</div>

<div style="max-width:900px;margin:0 auto;display:grid;grid-template-columns:minmax(0,2fr) minmax(0,1.4fr);gap:16px;flex-wrap:wrap;">
  <!-- Columna izquierda -->
  <div style="display:flex;flex-direction:column;gap:16px;">

    <!-- Datos personales -->
    <div class="card">
      <h3>Datos personales</h3>
      <div style="display:grid;grid-template-columns:120px 1fr;row-gap:6px;column-gap:8px;font-size:14px;margin-top:8px;">
        <div style="color:var(--muted);">Nombre</div>
        <div><?php echo htmlspecialchars($u['nombre'], ENT_QUOTES, 'UTF-8'); ?></div>

        <div style="color:var(--muted)
        <div><?php echo htmlspecialchars($u['apellido'], ENT_QUOTES, 'UTF-8'); ?></div>

        <div style="color:var(--muted);">Email</div>
        <div><?php echo htmlspecialchars($emailUsuario, ENT_QUOTES, 'UTF-8'); ?></div>

        <div style="color:var(--muted);">DNI</div>
        <div><?php echo htmlspecialchars($u['dni'], ENT_QUOTES, 'UTF-8'); ?></div>

        <div style="color:var(--muted);">Creado</div>
        <div><?php echo htmlspecialchars($u['creado_en'], ENT_QUOTES, 'UTF-8'); ?></div>
      </div>

      <div style="margin-top:12px;font-size:13px;color:var(--muted);">
        Más adelante vas a poder editar tus datos personales y preferencias de comunicación desde acá.
      </div>
    </div>

    <!-- Mis últimos Tickex -->
    <div class="card">
      <h3>Mis últimos Tickex</h3>
      <?php if (!empty($tickets)): ?>
        <div style="overflow-x:auto;margin-top:8px;">
          <table class="tabla" style="width:100%;font-size:13px;">
            <thead>
              <tr>
                <th>#ID</th>
                <th>Código</th>
                <th>Evento</th>
                <th>Tipo</th>
                <th>Monto</th>
                <th>Fecha</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($tickets as $t): ?>
                <tr>
                  <td><?php echo (int)$t['id']; ?></td>
                  <td><?php echo htmlspecialchars($t['codigo'], ENT_QUOTES, 'UTF-8'); ?></td>
                  <td><?php echo (int)$t['evento_id']; ?></td>
                  <td><?php echo htmlspecialchars($t['tipo'], ENT_QUOTES, 'UTF-8'); ?></td>
                  <td>
                    <?php
                      $m = (int)$t['monto_pagado'];
                      echo $m > 0
                        ? '$' . number_format($m / 100, 2, ',', '.')
                        : '—';
                    ?>
                  </td>
                  <td><?php echo htmlspecialchars($t['fecha_registro'], ENT_QUOTES, 'UTF-8'); ?></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
        <div style="margin-top:8px;font-size:13px;color:var(--muted);">
          Próximamente vas a poder ver el detalle completo y descargar tus entradas desde acá.
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

  <!-- Columna derecha -->
  <div style="display:flex;flex-direction:column;gap:16px;">

    <div class="card">
      <h3>Accesos rápidos</h3>
      <div style="display:flex;flex-direction:column;gap:8px;margin-top:8px;font-size:14px;">
        <a href="#"
           style="text-decoration:none;">
          👉 Mis Tickex (historial de entradas)
          <span style="display:block;font-size:12px;color:var(--muted);">
            Próximamente: listado completo de tus entradas, con QR y estados.
          </span>
        </a>

        <a href="#"
           style="text-decoration:none;">
          💳 Compras y pagos
          <span style="display:block;font-size:12px;color:var(--muted);">
            Próximamente: detalle de compras, medios de pago y estados.
          </span>
        </a>

        <a href="#"
           style="text-decoration:none;">
          🧾 Facturas y comprobantes
          <span style="display:block;font-size:12px;color:var(--muted);">
            Próximamente: descarga de facturas y comprobantes.
          </span>
        </a>

        <a href="#"
           style="text-decoration:none;">
          ❓ Centro de ayuda / FAQ
          <span style="display:block;font-size:12px;color:var(--muted);">
            Próximamente: respuestas a preguntas frecuentes.
          </span>
        </a>

        <a href="#"
           style="text-decoration:none;">
          💬 Contactar soporte
          <span style="display:block;font-size:12px;color:var(--muted);">
            Próximamente: formulario para escribirle a soporte de Tickex.
          </span>
        </a>
      </div>
    </div>

    <div class="card" style="font-size:13px;color:var(--muted);">
      <h3>Información</h3>
      <p>
        Este panel es para vos como usuario final de Tickex:
        la persona que compra entradas y las recibe por email.
      </p>
      <p>
        Los organizadores (boliches, teatros, festivales, etc.) van a tener
        un panel aparte con herramientas para crear eventos y vender entradas.
      </p>
    </div>

  </div>
</div>

<?php include __DIR__ . '/inc/layout_bottom.php'; ?>
