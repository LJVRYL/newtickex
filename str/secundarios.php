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

$prefEventoId = isset($_GET['evento_id']) ? (int)$_GET['evento_id'] : 0;

// Utilidad: obtener asignaciones de staff
function get_staff_event_ids($pdo, $staffId) {
  $stmt = $pdo->prepare("SELECT evento_id FROM staff_eventos WHERE staff_id = :id");
  $stmt->execute(array(':id'=>$staffId));
  $rows = $stmt->fetchAll(PDO::FETCH_COLUMN);
  return array_map('intval', $rows ?: array());
}

// Asegurar columna de costo de servicio para staff
function ensure_staff_cost_column($pdo) {
  try {
    $cols = array();
    $st = $pdo->query("PRAGMA table_info(usuarios_admin)");
    if ($st) {
      foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $ci) {
        $cols[$ci['name']] = true;
      }
    }
    if (!isset($cols['costo_servicio'])) {
      $pdo->exec("ALTER TABLE usuarios_admin ADD COLUMN costo_servicio REAL DEFAULT 0");
    }
  } catch (Exception $e) {
    // continuar sin bloquear
  }
}
ensure_staff_cost_column($pdo);

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
   ACTUALIZAR COSTO SERVICIO (POST)
   ========================================================= */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update_costo') {
  $staffId = isset($_POST['staff_id']) ? (int)$_POST['staff_id'] : 0;
  $costoServ = isset($_POST['costo_servicio']) ? (float)$_POST['costo_servicio'] : 0;

  if ($staffId <= 0) {
    flash('warn', 'ID de staff inválido.');
  } else {
    $stQ = $pdo->prepare("SELECT * FROM usuarios_admin WHERE id=? LIMIT 1");
    $stQ->execute(array($staffId));
    $staff = $stQ->fetch(PDO::FETCH_ASSOC);

    if (!$staff || $staff['tipo_global'] !== 'staff_evento') {
      flash('err', 'Staff no encontrado.');
    } else {
      $permitido = false;
      if ($tipoGlobal === 'super_admin' || $tipoGlobal === 'superadmin') {
        $permitido = true;
      } elseif ($hasCreadoPorU) {
        $creadorStaff = isset($staff['creado_por_admin_id']) ? (int)$staff['creado_por_admin_id'] : 0;
        if ($creadorStaff === $adminId) $permitido = true;
      }

      if (!$permitido) {
        flash('err','No tenés permiso para modificar este staff.');
      } else {
        try {
          $up = $pdo->prepare("UPDATE usuarios_admin SET costo_servicio = :c WHERE id = :id");
          $up->execute(array(':c' => $costoServ, ':id' => $staffId));
          flash('ok','Costo actualizado.');
        } catch (Exception $e) {
          flash('err','Error al actualizar: '.$e->getMessage());
        }
      }
    }
  }
}

  /* =========================================================
     ASIGNAR STAFF EXISTENTE A UN EVENTO (POST)
     ========================================================= */
  if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'assign_staff_event') {
    $staffIdAsign = isset($_POST['staff_id']) ? (int)$_POST['staff_id'] : 0;
    $rolEventoSet = trim(isset($_POST['rol_evento']) ? $_POST['rol_evento'] : 'puerta');
    $allEvents    = isset($_POST['all_events']) && (int)$_POST['all_events'] === 1;

    // evento_id puede venir como array (multi-select)
    $eventosAsign = array();
    if (isset($_POST['evento_id'])) {
      if (is_array($_POST['evento_id'])) {
        foreach ($_POST['evento_id'] as $e) {
          $eid = (int)$e;
          if ($eid > 0) $eventosAsign[$eid] = true;
        }
      } else {
        $eid = (int)$_POST['evento_id'];
        if ($eid > 0) $eventosAsign[$eid] = true;
      }
    }

    if ($staffIdAsign <= 0 || (!$allEvents && empty($eventosAsign))) {
      flash('warn', 'Elegí staff y al menos un evento (o Todos).');
    } else {
      $stQ = $pdo->prepare("SELECT * FROM usuarios_admin WHERE id=? LIMIT 1");
      $stQ->execute(array($staffIdAsign));
      $staff = $stQ->fetch(PDO::FETCH_ASSOC);

      if (!$staff || $staff['tipo_global'] !== 'staff_evento') {
        flash('err', 'Staff no encontrado.');
      } else {
        $permitido = false;
        if ($tipoGlobal === 'super_admin' || $tipoGlobal === 'superadmin') {
          $permitido = true;
        } elseif ($hasCreadoPorU) {
          $creadorStaff = isset($staff['creado_por_admin_id']) ? (int)$staff['creado_por_admin_id'] : 0;
          if ($creadorStaff === $adminId) $permitido = true;
        } else {
          $adminEventoId = isset($_SESSION['evento_id']) ? (int)$_SESSION['evento_id'] : 0;
          if ($adminEventoId > 0 && isset($staff['evento_id']) && (int)$staff['evento_id'] === $adminEventoId) {
            $permitido = true;
          }
        }

        if (!$permitido) {
          flash('err', 'No tenés permiso para asignar este staff.');
        } else {
          try {
            // limpiar asignaciones previas
            $del = $pdo->prepare("DELETE FROM staff_eventos WHERE staff_id = :id");
            $del->execute(array(':id'=>$staffIdAsign));

            $selectedIds = array();
            if ($allEvents) {
              $stmtEvAll = $pdo->query("SELECT id FROM eventos");
              $all = $stmtEvAll ? $stmtEvAll->fetchAll(PDO::FETCH_COLUMN) : array();
              foreach ($all as $eid) { $selectedIds[(int)$eid] = true; }
            }
            foreach (array_keys($eventosAsign) as $eid) { $selectedIds[$eid] = true; }

            if (!empty($selectedIds)) {
              $ins = $pdo->prepare("INSERT OR IGNORE INTO staff_eventos (staff_id, evento_id) VALUES (:sid, :eid)");
              foreach ($selectedIds as $eid => $v) {
                $ins->execute(array(':sid'=>$staffIdAsign, ':eid'=>$eid));
              }
              // compat: set evento_id base al primero seleccionado
              $first = array_key_first($selectedIds);
              $upd = $pdo->prepare("UPDATE usuarios_admin SET evento_id = :eid, rol_evento = :rol, activo = 1 WHERE id = :id");
              $upd->execute(array(
                ':eid' => $first,
                ':rol' => $rolEventoSet !== '' ? $rolEventoSet : 'puerta',
                ':id'  => $staffIdAsign,
              ));
            }

            flash('ok', 'Staff asignado a '.count($selectedIds).' evento(s).');
          } catch (Exception $e) {
            flash('err', 'Error al asignar: '.$e->getMessage());
          }
        }
      }
    }
  }

/* =========================================================
   CREAR STAFF (POST)
   ========================================================= */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && (!isset($_POST['action']) || $_POST['action'] !== 'delete_staff')) {
  // Si venimos desde asignación (prefEventoId>0) no mostramos alta aquí, se gestiona en mi staff sin evento seleccionado
  if ($prefEventoId > 0) {
    // ignorar creación en modo asignación
  } else {
    $username  = trim(isset($_POST['username']) ? $_POST['username'] : '');
    $password  = (string)(isset($_POST['password']) ? $_POST['password'] : '');
    $plantilla = trim(isset($_POST['plantilla']) ? $_POST['plantilla'] : 'puerta');
    $eventoId  = (int)(isset($_POST['evento_id']) ? $_POST['evento_id'] : 0);
    $costoServ = isset($_POST['costo_servicio']) ? (float)$_POST['costo_servicio'] : 0;

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
                        (username, password, rol, tipo_global, rol_evento, evento_id, activo, creado_por_admin_id, costo_servicio)
                    VALUES
                        (:u, :p, :rol, :tg, :re, :eid, 1, :creador, :costo)
                ");
                $stmt->execute(array(
                    ':u'       => $username,
                    ':p'       => $password,
                    ':rol'     => $rolEvento,
                    ':tg'      => $tipoGlobalNuevo,
                    ':re'      => $rolEvento,
                    ':eid'     => $eventoId,
                      ':creador' => $adminId,
                      ':costo'   => $costoServ,
                ));
            } else {
                $stmt = $pdo->prepare("
                    INSERT INTO usuarios_admin
                        (username, password, rol, tipo_global, rol_evento, evento_id, activo, costo_servicio)
                    VALUES
                        (:u, :p, :rol, :tg, :re, :eid, 1, :costo)
                ");
                $stmt->execute(array(
                    ':u'   => $username,
                    ':p'   => $password,
                    ':rol' => $rolEvento,
                    ':tg'  => $tipoGlobalNuevo,
                    ':re'  => $rolEvento,
                      ':eid' => $eventoId,
                      ':costo' => $costoServ,
                ));
            }

            flash('ok', "Staff creado: {$username} (evento #{$eventoId}).");
        } catch (Exception $ex) {
            flash('err', "Error al crear staff: " . $ex->getMessage());
        }
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

// ===== Staff visible (listado principal) =====
if ($tipoGlobal === 'super_admin' || $tipoGlobal === 'superadmin') {
  $sqlStaff = "
    SELECT u.id, u.username, u.rol_evento, u.evento_id, u.activo, u.costo_servicio,
      e.nombre AS evento_nombre, e.slug AS evento_slug
    FROM usuarios_admin u
    LEFT JOIN eventos e ON e.id = u.evento_id
    WHERE u.tipo_global='staff_evento'";
  $paramsStaff = array();
  if ($prefEventoId > 0) {
    $sqlStaff .= " AND u.evento_id = :filtro_evento";
    $paramsStaff[':filtro_evento'] = $prefEventoId;
  }
  $sqlStaff .= " ORDER BY u.id DESC";
  $stmtStaff = $pdo->prepare($sqlStaff);
  $stmtStaff->execute($paramsStaff);
  $staffRows = $stmtStaff->fetchAll(PDO::FETCH_ASSOC);
} else {
  if ($hasCreadoPorU) {
    $sqlStaff = "
      SELECT u.id, u.username, u.rol_evento, u.evento_id, u.activo, u.costo_servicio,
           e.nombre AS evento_nombre, e.slug AS evento_slug
      FROM usuarios_admin u
      LEFT JOIN eventos e ON e.id = u.evento_id
      WHERE u.tipo_global='staff_evento'
        AND u.creado_por_admin_id = :aid";
    $paramsStaff = array(':aid'=>$adminId);
    if ($prefEventoId > 0) {
      $sqlStaff .= " AND u.evento_id = :filtro_evento";
      $paramsStaff[':filtro_evento'] = $prefEventoId;
    }
    $sqlStaff .= " ORDER BY u.id DESC";
    $stmtStaff = $pdo->prepare($sqlStaff);
    $stmtStaff->execute($paramsStaff);
    $staffRows = $stmtStaff->fetchAll(PDO::FETCH_ASSOC);
  } else {
    $adminEventoId = isset($_SESSION['evento_id']) ? (int)$_SESSION['evento_id'] : 0;
    if ($prefEventoId > 0) {
      if ($hasCreadoPorEv) {
        $chkEv = $pdo->prepare("SELECT COUNT(*) FROM eventos WHERE id=? AND creado_por_admin_id=?");
        $chkEv->execute(array($prefEventoId, $adminId));
        if ((int)$chkEv->fetchColumn() > 0) {
          $adminEventoId = $prefEventoId;
        }
      } else {
        $adminEventoId = $prefEventoId;
      }
    }
    if ($adminEventoId > 0) {
      $stmtStaff = $pdo->prepare("
        SELECT u.id, u.username, u.rol_evento, u.evento_id, u.activo, u.costo_servicio,
             e.nombre AS evento_nombre, e.slug AS evento_slug
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

// ===== Pool completo de staff (para asignar) =====
if ($tipoGlobal === 'super_admin' || $tipoGlobal === 'superadmin') {
  $stmtAll = $pdo->query("
    SELECT u.id, u.username, u.rol_evento, u.evento_id, u.activo,
           e.nombre AS evento_nombre, e.slug AS evento_slug
    FROM usuarios_admin u
    LEFT JOIN eventos e ON e.id = u.evento_id
    WHERE u.tipo_global='staff_evento'
    ORDER BY u.username ASC, u.id DESC
  ");
  $staffAll = $stmtAll->fetchAll(PDO::FETCH_ASSOC);
} elseif ($hasCreadoPorU) {
  $stmtAll = $pdo->prepare("
    SELECT u.id, u.username, u.rol_evento, u.evento_id, u.activo,
           e.nombre AS evento_nombre, e.slug AS evento_slug
    FROM usuarios_admin u
    LEFT JOIN eventos e ON e.id = u.evento_id
    WHERE u.tipo_global='staff_evento'
      AND u.creado_por_admin_id = :aid
    ORDER BY u.username ASC, u.id DESC
  ");
  $stmtAll->execute(array(':aid'=>$adminId));
  $staffAll = $stmtAll->fetchAll(PDO::FETCH_ASSOC);
} else {
  $adminEventoId = isset($_SESSION['evento_id']) ? (int)$_SESSION['evento_id'] : 0;
  if ($prefEventoId > 0) {
    if ($hasCreadoPorEv) {
      $chkEv = $pdo->prepare("SELECT COUNT(*) FROM eventos WHERE id=? AND creado_por_admin_id=?");
      $chkEv->execute(array($prefEventoId, $adminId));
      if ((int)$chkEv->fetchColumn() > 0) {
        $adminEventoId = $prefEventoId;
      }
    } else {
      $adminEventoId = $prefEventoId;
    }
  }
  if ($adminEventoId > 0) {
    $stmtAll = $pdo->prepare("
      SELECT u.id, u.username, u.rol_evento, u.evento_id, u.activo,
             e.nombre AS evento_nombre, e.slug AS evento_slug
      FROM usuarios_admin u
      LEFT JOIN eventos e ON e.id = u.evento_id
      WHERE u.tipo_global='staff_evento'
        AND u.evento_id = :eid
      ORDER BY u.username ASC, u.id DESC
    ");
    $stmtAll->execute(array(':eid'=>$adminEventoId));
    $staffAll = $stmtAll->fetchAll(PDO::FETCH_ASSOC);
  } else {
    $staffAll = array();
  }
}

include __DIR__.'/inc/layout_top.php';
?>

<div class="card" style="display:flex;gap:8px;flex-wrap:wrap;align-items:center;">
  <a class="btn secondary" href="panel_admin.php">⬅ Volver al panel</a>
  <?php if ($prefEventoId > 0): ?>
    <a class="btn" href="secundarios.php" style="background:var(--ok);color:#04150a;">+ Agregar staff</a>
  <?php endif; ?>
  <span style="flex:1 1 auto;"></span>
  <a class="btn danger" href="login.php?logout=1">Salir</a>
</div>

<div class="card">
  <h2>Mi staff</h2>
  <div style="color:var(--muted);font-size:14px;">
    Acá creás usuarios tipo Puerta (y a futuro otros roles).
  </div>
</div>

<?php if ($prefEventoId <= 0): ?>
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

    <label>Costo de servicio ($)</label>
    <input type="number" step="0.01" min="0" name="costo_servicio" placeholder="0" value="0">

    <button class="btn" type="submit">Crear staff</button>
  </form>
</div>
<?php endif; ?>

<div class="card" style="max-width:900px;">
  <h3>Asignar staff existente a un evento</h3>

  <form method="post" style="display:grid;grid-template-columns:1.2fr 1fr 0.8fr auto;gap:10px;align-items:end;">
    <input type="hidden" name="action" value="assign_staff_event">

    <div>
      <label>Elegí staff</label>
      <div style="display:flex;gap:6px;flex-direction:column;">
        <input type="text" id="filterStaff" placeholder="Filtrar por usuario" oninput="filterStaffOptions()" style="width:100%;">
        <select name="staff_id" id="staffSelect" required style="width:100%;">
          <option value="">Seleccioná staff...</option>
          <?php foreach ($staffAll as $s): ?>
            <?php
              $labelEv = '';
              if (!empty($s['evento_nombre'])) {
                $labelEv = ' (ev: '.$s['evento_nombre'].')';
              } elseif (!empty($s['evento_id'])) {
                $labelEv = ' (ev #'.$s['evento_id'].')';
              }
            ?>
            <option value="<?php echo (int)$s['id']; ?>" data-name="<?php echo e(strtolower($s['username'])); ?>">
              #<?php echo (int)$s['id']; ?> — <?php echo e($s['username']); ?><?php echo e($labelEv); ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>
    </div>

    <div>
      <label>Asignar a evento</label>
      <select name="evento_id" required>
        <option value="">Elegí un evento...</option>
        <?php foreach($eventos as $ev): ?>
          <option value="<?php echo (int)$ev['id']; ?>" <?php echo ($prefEventoId === (int)$ev['id']) ? 'selected' : ''; ?>>
            #<?php echo (int)$ev['id']; ?> — <?php echo e($ev['nombre']); ?> (<?php echo e($ev['slug']); ?>)
          </option>
        <?php endforeach; ?>
      </select>
    </div>

    <div>
      <label>Rol en evento</label>
      <select name="rol_evento">
        <option value="puerta">Puerta (check-in)</option>
      </select>
    </div>

    <div>
      <button class="btn" type="submit" style="margin-top:2px;">Asignar</button>
    </div>
  </form>

  <script>
    function filterStaffOptions(){
      var q = document.getElementById('filterStaff').value.toLowerCase();
      var sel = document.getElementById('staffSelect');
      for (var i=0; i<sel.options.length; i++) {
        var opt = sel.options[i];
        var name = opt.getAttribute('data-name') || '';
        opt.hidden = (q !== '' && name.indexOf(q) === -1);
      }
      // Si la opción seleccionada quedó oculta, reseteamos
      if (sel.selectedIndex > 0 && sel.options[sel.selectedIndex].hidden) {
        sel.selectedIndex = 0;
      }
    }
  </script>
</div>

<div class="card">
  <h3>Staff creado</h3>

  <?php if ($prefEventoId > 0): ?>
    <div class="muted" style="margin-bottom:8px;">Mostrando staff del evento #<?php echo (int)$prefEventoId; ?>.</div>
  <?php endif; ?>

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
            <th>Asignado a</th>
            <th>Costo ($)</th>
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
            <td style="min-width:220px;">
              <?php
                $assignedIds = get_staff_event_ids($pdo, (int)$s['id']);
                $assignedLookup = array();
                foreach ($assignedIds as $eid) $assignedLookup[$eid] = true;
              ?>
              <form method="post" style="display:flex;gap:6px;align-items:center;flex-wrap:wrap;">
                <input type="hidden" name="action" value="assign_staff_event">
                <input type="hidden" name="staff_id" value="<?php echo (int)$s['id']; ?>">
                <select name="evento_id[]" multiple size="3" style="min-width:180px;">
                  <?php foreach ($eventos as $ev): ?>
                    <option value="<?php echo (int)$ev['id']; ?>" <?php echo isset($assignedLookup[(int)$ev['id']]) ? 'selected' : ''; ?>>
                      #<?php echo (int)$ev['id']; ?> — <?php echo e($ev['nombre']); ?>
                    </option>
                  <?php endforeach; ?>
                </select>
                <label style="font-size:12px;display:flex;gap:4px;align-items:center;">
                  <input type="checkbox" name="all_events" value="1"> Todos
                </label>
                <button class="btn secondary" type="submit" style="padding:4px 8px;font-size:12px;">Asignar</button>
              </form>
            </td>
            <td>
              <?php $costo = isset($s['costo_servicio']) ? (float)$s['costo_servicio'] : 0; ?>
              <form method="post" style="display:flex;gap:6px;align-items:center;white-space:nowrap;">
                <input type="hidden" name="action" value="update_costo">
                <input type="hidden" name="staff_id" value="<?php echo (int)$s['id']; ?>">
                <input type="number" step="0.01" min="0" name="costo_servicio" value="<?php echo number_format($costo,2,'.',''); ?>" style="width:110px;">
                <button class="btn secondary" type="submit" style="padding:4px 8px;font-size:12px;">Guardar</button>
              </form>
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
