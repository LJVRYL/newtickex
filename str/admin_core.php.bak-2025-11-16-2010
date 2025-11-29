<?php
$dbFile = __DIR__ . '/save_the_rave.sqlite';

if (!file_exists($dbFile)) {
    die('Base de datos no encontrada.');
}

try {
    $pdo = new PDO('sqlite:' . $dbFile);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (Exception $e) {
    die('Error al leer la base: ' . htmlspecialchars($e->getMessage()));
}

/**
 * Eliminar entrada (si viene ?delete=ID)
 */
if (isset($_GET['delete'])) {
    $id = (int) $_GET['delete'];
    if ($id > 0) {
        $stmtDel = $pdo->prepare('DELETE FROM entradas WHERE id = :id');
        $stmtDel->execute([':id' => $id]);
        // Redirigimos para evitar re-envío al refrescar
        $baseUrl = strtok($_SERVER['REQUEST_URI'], '?');
        header('Location: ' . $baseUrl);
        exit;
    }
}

/**
 * Helper para mostrar descripción legible del tipo
 */
function pretty_tipo($tipoRaw) {
    $tipo = $tipoRaw;
    if ($tipo === null || $tipo === '') $tipo = 'desconocido';

    switch ($tipo) {
        case 'ANTICIPADA':    return 'Anticipada';
        case 'FREE':          return 'FREE (lista / formulario)';
        case 'PUERTA_10000':  return 'Lista puerta $10.000';
        case 'PUERTA_15000':  return 'Lista puerta $15.000';
        case 'OTRO_NOMBRE':   return 'Otro nombre (ver quién pagó)';
        default:              return ucfirst($tipo);
    }
}

/**
 * Filtro por tipo (?tipo=FREE, ANTICIPADA, etc.)
 */
$tipoFiltro = isset($_GET['tipo']) ? trim($_GET['tipo']) : '';
$allowedTipos = ['', 'ANTICIPADA', 'FREE', 'PUERTA_10000', 'PUERTA_15000', 'OTRO_NOMBRE'];

if (!in_array($tipoFiltro, $allowedTipos, true)) {
    $tipoFiltro = '';
}

if ($tipoFiltro === '') {
    $stmt = $pdo->query('SELECT * FROM entradas ORDER BY id DESC');
} else {
    $stmt = $pdo->prepare('SELECT * FROM entradas WHERE tipo = :tipo ORDER BY id DESC');
    $stmt->execute([':tipo' => $tipoFiltro]);
}

$entradas = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8" />
  <title>Save The Rave – Admin</title>
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <style>
    body {
      font-family: system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
      background: #111;
      color: #f5f5f5;
      padding: 20px;
    }
    h1 {
      margin-bottom: 8px;
    }
    .subtitle {
      color: #bbb;
      font-size: 0.9rem;
      margin-bottom: 16px;
    }
    .filters {
      display: flex;
      flex-wrap: wrap;
      gap: 8px;
      align-items: center;
      margin-bottom: 12px;
    }
    .filters form {
      display: flex;
      flex-wrap: wrap;
      gap: 8px;
      align-items: center;
    }
    .btn-filter {
      border: 0;
      background: #222;
      color: #eee;
      padding: 4px 10px;
      border-radius: 999px;
      font-size: 0.8rem;
      cursor: pointer;
    }
    .btn-filter.active {
      background: #3b82f6;
      color: #fff;
    }
    .search-input {
      padding: 4px 8px;
      border-radius: 999px;
      border: 1px solid #333;
      background: #000;
      color: #f5f5f5;
      font-size: 0.85rem;
    }
    .btn-small {
      border: none;
      border-radius: 999px;
      padding: 4px 10px;
      font-size: 0.75rem;
      cursor: pointer;
      text-decoration: none;
      display: inline-block;
      background: #333;
      color: #eee;
    }
    .btn-small:hover {
      background: #444;
    }
    .btn-checkin {
      background: #16a34a;
      color: #fff;
    }
    .btn-checkin:hover {
      background: #15803d;
    }
    .btn-delete {
      background: #b91c1c;
      color: #fff;
    }
    .btn-delete:hover {
      background: #991b1b;
    }
    table {
      border-collapse: collapse;
      width: 100%;
      max-width: 100%;
      background: #1a1a1a;
    }
    th, td {
      border: 1px solid #333;
      padding: 6px 8px;
      font-size: 0.8rem;
      vertical-align: middle;
    }
    th {
      background: #222;
      text-align: left;
      position: sticky;
      top: 0;
      z-index: 1;
    }
    tr:nth-child(even) {
      background: #161616;
    }
    .chip {
      display: inline-block;
      padding: 2px 8px;
      border-radius: 999px;
      font-size: 0.75rem;
    }
    .chip-ok {
      background: #234326;
      color: #8df09e;
    }
    .chip-pending {
      background: #443222;
      color: #ffd38a;
    }
    .chip-tipo {
      background: #222;
      color: #eee;
    }
    .code {
      font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, "Liberation Mono", "Courier New", monospace;
      font-size: 0.75rem;
    }
    .col-nombre {
      min-width: 120px;
    }
    .actions {
      display: flex;
      flex-direction: column;
      gap: 4px;
    }
    @media (max-width: 900px) {
      table {
        font-size: 0.75rem;
      }
      th, td {
        padding: 4px;
      }
    }
  </style>
</head>
<body>
  <h1>Save The Rave – Entradas registradas</h1>
  <div class="subtitle">
    Panel de control de listas, tipos de entrada y check-in.
  </div>

  <div class="filters">
    <form method="get" action="">
      <?php
        $tiposUi = [
          ''             => 'Todas',
          'FREE'         => 'FREE / Formulario',
          'ANTICIPADA'   => 'Anticipadas',
          'PUERTA_10000' => 'Lista $10.000',
          'PUERTA_15000' => 'Lista $15.000',
          'OTRO_NOMBRE'  => 'Con otro nombre',
        ];
        foreach ($tiposUi as $valor => $label):
          $active = ($tipoFiltro === $valor) ? 'active' : '';
      ?>
        <button type="submit" name="tipo" value="<?php echo htmlspecialchars($valor); ?>" class="btn-filter <?php echo $active; ?>">
          <?php echo htmlspecialchars($label); ?>
        </button>
      <?php endforeach; ?>
    </form>

    <input
      type="text"
      id="search"
      class="search-input"
      placeholder="Buscar por nombre / email..."
    />

    <button type="button" id="btn-sort-az" class="btn-filter">
      Ordenar A → Z
    </button>
  </div>

  <?php if (empty($entradas)): ?>
    <p>No hay entradas registradas para este filtro.</p>
  <?php else: ?>
    <table id="tabla-entradas">
      <thead>
        <tr>
          <th>#</th>
          <th>Nombre / Alias</th>
          <th>Email</th>
          <th>Fecha registro</th>
          <th>Tipo de entrada</th>
          <th>Código</th>
          <th>Estado</th>
          <th>Check IN</th>
          <th>Ver entrada</th>
          <th>Email / copiar texto</th>
          <th>Eliminar</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($entradas as $e): ?>
          <?php
            $id         = (int) $e['id'];
            $nombre     = $e['nombre'];
            $email      = $e['email'];
            $fecha      = $e['fecha_registro'];
            $codigo     = $e['codigo'];
            $tipoRaw    = isset($e['tipo']) ? $e['tipo'] : '';
            $tipoDesc   = pretty_tipo($tipoRaw);
            $checked    = ((int) $e['checked_in'] === 1);
            $checkedTxt = $checked ? 'Chequeado' : 'Pendiente';
            $checkinUrl = 'https://str.tickex.com.ar/checkin.php?c=' . urlencode($codigo);
            $ticketUrl  = 'https://str.tickex.com.ar/ticket.php?c=' . urlencode($codigo);
            $emailUrl   = 'https://str.tickex.com.ar/email_template.php?c=' . urlencode($codigo);
          ?>
          <tr class="fila-entrada" data-nombre="<?php echo htmlspecialchars(mb_strtolower($nombre . ' ' . $email, 'UTF-8')); ?>">
            <td><?php echo $id; ?></td>
            <td class="col-nombre"><?php echo htmlspecialchars($nombre); ?></td>
            <td><?php echo htmlspecialchars($email); ?></td>
            <td><?php echo htmlspecialchars($fecha); ?></td>
            <td>
              <span class="chip chip-tipo">
                <?php echo htmlspecialchars($tipoDesc); ?>
              </span>
            </td>
            <td class="code"><?php echo htmlspecialchars($codigo); ?></td>
            <td>
              <?php if ($checked): ?>
                <span class="chip chip-ok">Chequeado</span>
              <?php else: ?>
                <span class="chip chip-pending">Pendiente</span>
              <?php endif; ?>
            </td>
            <td>
              <?php if ($checked): ?>
                <!-- Ya chequeado: no mostramos botón -->
              
              <?php else: ?>
                <a
                  href="<?php echo htmlspecialchars($checkinUrl); ?>"
                  target="_blank"
                  rel="noopener noreferrer"
                  class="btn-small btn-checkin"
                >
                  Check IN
                </a>
              <?php endif; ?>
            </td>
            <td>
              <a
                href="<?php echo htmlspecialchars($ticketUrl); ?>"
                target="_blank"
                rel="noopener noreferrer"
                class="btn-small"
         
                Ver entrada
              </a>
            </td>
            <td>
              <div class="actions">
                <a
                  href="<?php echo htmlspecialchars($emailUrl); ?>"
                  target="_blank"
                  rel="noopener noreferrer"
                  class="btn-small"
                >
                  Ver texto para email
                </a>
              </div>
            </td>
            <td>
              <a
                href="?delete=<?php echo $id; ?>"
                class="btn-small btn-delete"
                onclick="return confirm('¿Seguro que querés eliminar la entrada #<?php echo $id; ?>?');"
              >
                Eliminar
              </a>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  <?php endif; ?>

  <script>
    // Buscador por nombre / email
    (function() {
      var searchInput = document.getElementById('search');
      if (!searchInput) return;

      var rows = document.querySelectorAll('#tabla-entradas tbody tr.fila-entrada');

      searchInput.addEventListener('input', function() {
        var q = searchInput.value.toLowerCase().trim();
        rows.forEach(function(row) {
          var text = row.getAttribute('data-nombre') || '';
          if (!q || text.indexOf(q) !== -1) {
            row.style.display = '';
          } else {
            row.style.display = 'none';
          }
        });
      });
    })();

    // Ordenar A→Z por nombre (en el DOM)
    (function() {
      var btnSort = document.getElementById('btn-sort-az');
      if (!btnSort) return;

      btnSort.addEventListener('click', function() {
        var tbody = document.querySelector('#tabla-entradas tbody');
        if (!tbody) return;

        var rowsArray = Array.prototype.slice.call(
          tbody.querySelectorAll('tr.fila-entrada')
        );

        rowsArray.sort(function(a, b) {
          var an = a.querySelector('.col-nombre')?.textContent.toLowerCase() || '';
          var bn = b.querySelector('.col-nombre')?.textContent.toLowerCase() || '';
          if (an < bn) return -1;
          if (an > bn) return 1;
          return 0;
        });

        rowsArray.forEach(function(r) {
          tbody.appendChild(r);
        });
      });
    })();
  </script>
</body>
</html>
