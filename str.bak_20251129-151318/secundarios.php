<?php
require_once __DIR__.'/inc/bootstrap.php';
$title = "Mi staff – Administrador";

// ===== AUTH: solo admin_evento o super_admin =====
require_login();

$cu = current_user();
$tipoGlobal = isset($_SESSION['tipo_global']) ? $_SESSION['tipo_global'] : (isset($cu['rol'])?$cu['rol']:'');
if (!in_array($tipoGlobal, array('admin_evento','super_admin','superadmin'), true)) {
    header("Location: login.php");
    exit;
}

$adminId = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : (isset($cu['id'])?(int)$cu['id']:0);
if ($adminId <= 0) {
    http_response_code(400);
    include __DIR__.'/inc/layout_top.php';
    echo "<div class='card error'><h2>Sesión inválida</h2><p>Sin user_id.</p></div>";
    include __DIR__.'/inc/layout_bottom.php';
    exit;
}

$pdo = db();

// ===== Detectar columnas opcionales =====
$colsEv = $pdo->query("PRAGMA table_info(eventos)")->fetchAll(PDO::FETCH_ASSOC);
$hasCreadoPorEv = false;
foreach($colsEv as $c){
    if (isset($c['name']) && $c['name']==='creado_por_admin_id') { $hasCreadoPorEv=true; break; }
}

$colsU = $pdo->query("PRAGMA table_info(usuarios_admin)")->fetchAll(PDO::FETCH_ASSOC);
$hasCreadoPorU = false;
foreach($colsU as $c){
    if (isset($c['name']) && $c['name']==='creado_por_admin_id') { $hasCreadoPorU=true; break; }
}

/* =========================================================
   ELIMINAR STAFF (POST)
   ========================================================= */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete_staff') {
    $staffId = isset($_POST['staff_id']) ? (int)$_POST['staff_id'] : 0;

    if ($staffId <= 0) {
        flash('warn', 'ID de staff inválido.');
    } else {
        // Traer staff
        $stQ = $pdo->prepare("SELECT * FROM usuarios_admin WHERE id=? LIMIT 1");
        $stQ->execute(array($staffId));
        $staff = $stQ->fetch(PDO::FETCH_ASSOC);

        if (!$staff || $staff['tipo_global'] !== 'staff_evento') {
            flash('err', 'Staff no encontrado.');
        } else {
            $permitido = false;

            if ($tipoGlobal === 'super_admin' || $tipoGlobal === 'superadmin') {
                $permitido = true;
            } else {
                // admin_evento: validar creador si existe columna
                if ($hasCreadoPorU) {
                    $creadorStaff = isset($staff['creado_por_admin_id']) ? (int)$staff['creado_por_admin_id'] : 0;
                    if ($creadorStaff === $adminId) $permitido = true;
                } else {
                    // fallback legacy: permitir si pertenece a un evento visible del admin
                    $adminEventoId = isset($_SESSION['evento_id']) ? (int)$_SESSION['evento_id'] : 0;
                    $staffEventoId = isset($staff['evento_id']) ? (int)$staff['evento_id'] : 0;

                    if ($adminEventoId > 0 && $staffEventoId === $adminEventoId) {
                        $permitido = true;
                    } elseif ($hasCreadoPorEv) {
                        $chkEv = $pdo->prepare("SELECT COUNT(*) FROM eventos WHERE id=? AND creado_por_admin_id=?");
                        $chkEv->execute(array($staffEventoId, $adminId));
                        if ((int)$chkEv->fetchColumn() > 0) $permitido = true;
                    }
                }
            }

            if (!$permitido) {
                flash('err','No tenés permiso para eliminar este staff.');
            } else {
                try {
                    $del = $pdo->prepare("DELETE FROM usuarios_admin WHERE id=? AND tipo_global='staff_evento'");
                    $del->execute(array($staffId));
                    flash('ok','Staff eliminado correctamente.');
                } catch (Exception $e) {
                    flash('err','Error al eliminar staff: '.$e->getMessage());
                }
            }
        }
    }
}

/* =========================================================
   CREAR STAFF (POST)
   ========================================================= */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && (!isset($_POST['action']) || $_POST['action'] !== 'delete_staff')) {
    $username  = trim(isset($_POST['username']) ? $_POST['username'] : '');
    $password  = (string)(isset($_POST['password']) ? $_POST['password'] : '');
    $plantilla = trim(isset($_POST['plantilla']) ? $_POST['plantilla'] : 'puerta');
    $eventoId  = (int)(isset($_POST['evento_id']) ? $_POST['evento_id'] : 0);

    if ($username === '' || $password === '') {
        flash('warn', "Usuario y contraseña son obligatorios.");
    } elseif ($eventoId <= 0) {
        flash('warn', "Tenés que asignar un evento.");
    } else {
        $rolEvento  = 'puerta';
        $tipoGlobalNuevo = 'staff_evento';

        try {
            if ($hasCreadoPorU) {
                $stmt = $pdo->prepare("
                    INSERT INTO usuarios_admin
                        (username, password, rol, tipo_global, rol_evento, evento_id, activo, creado_por_admin_id)
                    VALUES
                        (:u, :p, :rol, :tg, :re, :eid, 1, :creador)
                ");
                $stmt->execute(array(
                    ':u'       => $username,
                    ':p'       => $password,
                    ':rol'     => $rolEvento,
                    ':tg'      => $tipoGlobalNuevo,
                    ':re'      => $rolEvento,
                    ':eid'     => $eventoId,
                    ':creador' => $adminId,
                ));
            } else {
                $stmt = $pdo->prepare("
                    INSERT INTO usuarios_admin
                        (username, password, rol, tipo_global, rol_evento, evento_id, activo)
                    VALUES
                        (:u, :p, :rol, :tg, :re, :eid, 1)
                ");
                $stmt->execute(array(
                    ':u'   => $username,
                    ':p'   => $password,
                    ':rol' => $rolEvento,
                    ':tg'  => $tipoGlobalNuevo,
                    ':re'  => $rolEvento,
                    ':eid' => $eventoId,
                ));
            }

            flash('ok', "Staff creado: {$username} (evento #{$eventoId}).");
        } catch (Exception $ex) {
            flash('err', "Error al crear staff: " . $ex->getMessage());
        }
    }
}

// ===== Eventos del admin actual =====
if ($tipoGlobal === 'super_admin' || $tipoGlobal === 'superadmin') {
    $stmtEv = $pdo->query("SELECT id, nombre, slug FROM eventos ORDER BY id DESC");
    $eventos = $stmtEv->fetchAll(PDO::FETCH_ASSOC);
} else {
    if ($hasCreadoPorEv) {
        $stmtEv = $pdo->prepare("
            SELECT id, nombre, slug
            FROM eventos
            WHERE creado_por_admin_id = :aid
            ORDER BY id DESC
        ");
        $stmtEv->execute(array(':aid'=>$adminId));
        $eventos = $stmtEv->fetchAll(PDO::FETCH_ASSOC);
    } else {
        $stmtEv = $pdo->query("SELECT id, nombre, slug FROM eventos ORDER BY id DESC");
        $eventos = $stmtEv->fetchAll(PDO::FETCH_ASSOC);
    }
}

// ===== Staff visible =====
if ($tipoGlobal === 'super_admin' || $tipoGlobal === 'superadmin') {
    $stmtStaff = $pdo->query("
        SELECT u.id, u.username, u.rol_evento, u.evento_id, u.activo,
               e.nombre AS evento_nombre, e.slug AS evento_slug
        FROM usuarios_admin u
        LEFT JOIN eventos e ON e.id = u.evento_id
        WHERE u.tipo_global='staff_evento'
        ORDER BY u.id DESC
    ");
    $staffRows = $stmtStaff->fetchAll(PDO::FETCH_ASSOC);
} else {
    if ($hasCreadoPorU) {
        $stmtStaff = $pdo->prepare("
            SELECT u.id, u.username, u.rol_evento, u.evento_id, u.activo,
                   e.nombre AS evento_nombre, e.slug AS evento_slug
            FROM usuarios_admin u
            LEFT JOIN eventos e ON e.id = u.evento_id
            WHERE u.tipo_global='staff_evento'
              AND u.creado_por_admin_id = :aid
            ORDER BY u.id DESC
        ");
        $stmtStaff->execute(array(':aid'=>$adminId));
        $staffRows = $stmtStaff->fetchAll(PDO::FETCH_ASSOC);
    } else {
        $adminEventoId = isset($_SESSION['evento_id']) ? (int)$_SESSION['evento_id'] : 0;
        if ($adminEventoId > 0) {
            $stmtStaff = $pdo->prepare("
                SELECT u.id, u.username, u.rol_evento, u.evento_id, u.activo,
                       e.nombre AS 
                FROM usuarios_admin u
                LEFT JOIN eventos e ON e.id = u.evento_id
                WHERE u.tipo_global='staff_evento'
                  AND u.evento_id = :eid
                ORDER BY u.id DESC
            ");
            $stmtStaff->execute(array(':eid'=>$adminEventoId));
            $staffRows = $stmtStaff->fetchAll(PDO::FETCH_ASSOC);
        } else {
            $staffRows = array();
        }
    }
}

include __DIR__.'/inc/layout_top.php';
?>

<div class="card" style="display:flex;gap:8px;flex-wrap:wrap;align-items:center;">
  <a class="btn secondary" href="panel_admin.php">⬅ Volver al panel</a>
  <span style="flex:1 1 auto;"></span>
  <a class="btn danger" href="login.php?logout=1">Salir</a>
</div>

<div class="card">
  <h2>Mi staff</h2>
  <div style="color:var(--muted);font-size:14px;">
    Acá creás usuarios tipo Puerta (y a futuro otros roles).
  </div>
</div>

<div class="card" style="max-width:700px;">
  <h3>Crear nuevo staff</h3>

  <form method="post">
    <label>Usuario staff</label>
    <input name="username" required>

    <label>Contraseña</label>
    <input type="text" name="password" required>

    <label>Plantilla / Rol</label>
    <select name="plantilla">
      <option value="puerta">Puerta (check-in)</option>
    </select>

    <label>Asignar a evento</label>
    <select name="evento_id" required>
      <option value="">Elegí un evento...</option>
      <?php foreach($eventos as $ev): ?>
        <option value="<?php echo (int)$ev['id']; ?>">
          #<?php echo (int)$ev['id']; ?> — <?php echo e($ev['nombre']); ?> (<?php echo e($ev['slug']); ?>)
        </option>
      <?php endforeach; ?>
    </select>

    <button class="btn" type="submit">Crear staff</button>
  </form>
</div>

<div class="card">
  <h3>Staff creado</h3>

  <?php if(empty($staffRows)): ?>
    <div style="color:var(--muted);font-size:14px;">No hay staff creado todavía.</div>
  <?php else: ?>
    <div style="overflow:auto;margin-top:8px;">
      <table class="table">
        <thead>
          <tr>
            <th>ID</th>
            <th>Usuario</th>
            <th>Rol</th>
            <th>Evento</th>
            <th>Estado</th>
            <th></th>
          </tr>
        </thead>
        <tbody>
        <?php foreach($staffRows as $s): ?>
          <tr>
            <td><?php echo (int)$s['id']; ?></td>
            <td><?php echo e($s['username']); ?></td>
            <td><?php echo e($s['rol_evento']); ?></td>
            <td>
              <?php
                $en = isset($s['evento_nombre']) ? $s['evento_nombre'] : '';
                $es = isset($s['evento_slug']) ? $s['evento_slug'] : '';
                if ($en !== '') echo e($en) . " (" . e($es) . ")";
                else echo "#".(int)$s['evento_id'];
              ?>
            </td>
            <td>
              <?php if((int)$s['activo']===1): ?>
                <span style="color:var(--ok);font-weight:700;">Activo</span>
              <?php else: ?>
                <span style="color:var(--warn);font-weight:700;">Inactivo</span>
              <?php endif; ?>
            </td>
            <td style="text-align:right;">
              <form method="post" style="margin:0;" onsubmit="return confirm('¿Eliminar staff <?php echo e($s['username']); ?>?');">
                <input type="hidden" name="action" value="delete_staff">
                <input type="hidden" name="staff_id" value="<?php echo (int)$s['id']; ?>">
                <button class="btn danger" type="submit" title="Eliminar" style="padding:6px 10px;font-size:14px;">
                  🗑️
                </button>
              </form>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
</div>

<?php include __DIR__.'/inc/layout_bottom.php'; ?>
