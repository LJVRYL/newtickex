<?php
require_once __DIR__ . '/inc/auth.php';
require_once __DIR__ . '/inc/bootstrap.php';

// Tipo global (super_admin, admin_evento, staff_evento, etc.)
$tg = isset($_SESSION['tipo_global']) ? $_SESSION['tipo_global'] : '';

if (!in_array($tg, array('admin_evento','super_admin'), true)) {
    header('Location: login.php');
    exit;
}

// Detectar el ID del admin actual.
// ADAPTAR si usás otra clave en $_SESSION.
$admin_id = 0;
if (isset($_SESSION['user_id'])) {
    $admin_id = (int) $_SESSION['user_id'];
} elseif (isset($_SESSION['usuario_id'])) {
    $admin_id = (int) $_SESSION['usuario_id'];
} elseif (isset($_SESSION['admin_id'])) {
    $admin_id = (int) $_SESSION['admin_id'];
}

if ($admin_id <= 0) {
    // Si llegamos acá, algo raro pasa con la sesión.
    die('No se pudo determinar el ID de administrador actual.');
}

// Obtener conexión a la DB.
// Probamos varias formas para no romper nada.
$db = null;
if (isset($GLOBALS['db']) && $GLOBALS['db'] instanceof PDO) {
    $db = $GLOBALS['db'];
} elseif (function_exists('get_db')) {
    $db = get_db();
} else {
    $dbFile = __DIR__ . '/save_the_rave.sqlite';
    if (file_exists($dbFile)) {
        $dsn = 'sqlite:' . $dbFile;
        $db = new PDO($dsn);
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    }
}

if (!$db instanceof PDO) {
    die('No se pudo inicializar la conexión a la base de datos.');
}

// Helper simple para e() si no existe (por compatibilidad)
if (!function_exists('e')) {
    function e($str) {
        return htmlspecialchars((string)$str, ENT_QUOTES, 'UTF-8');
    }
}

$page_title = 'Mi sitio';

// Estado inicial
$errors = array();
$saved  = false;

// Cargar config actual (si existe)
$config = array(
    'slug_publico'   => '',
    'nombre_publico' => '',
    'texto_hero'     => '',
    'texto_intro'    => '',
    'visible'        => 0,
);

// Leer desde DB
try {
    $stmt = $db->prepare('SELECT slug_publico, nombre_publico, texto_hero, texto_intro, visible FROM clientes_sites WHERE admin_id = :admin_id LIMIT 1');
    $stmt->execute(array(':admin_id' => $admin_id));
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($row) {
        $config = $row;
    }
} catch (Exception $e) {
    // Si hay error de DB, no cortamos todo el sitio, solo mostramos luego.
    $errors[] = 'Error al cargar la configuración actual: ' . $e->getMessage();
}

// Procesar POST (guardar cambios)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $nombre_publico = isset($_POST['nombre_publico']) ? trim($_POST['nombre_publico']) : '';
    $slug_publico   = isset($_POST['slug_publico']) ? trim($_POST['slug_publico']) : '';
    $texto_hero     = isset($_POST['texto_hero']) ? trim($_POST['texto_hero']) : '';
    $texto_intro    = isset($_POST['texto_intro']) ? trim($_POST['texto_intro']) : '';
    $visible        = isset($_POST['visible']) ? 1 : 0;

    // Validaciones mínimas
    if ($nombre_publico === '') {
        $errors[] = 'El nombre público del sitio es obligatorio.';
    }

    if ($slug_publico === '') {
        $errors[] = 'El slug público es obligatorio.';
    } else {
        $slug_publico = strtolower($slug_publico);
        // Solo permitimos a-z, 0-9 y guiones.
        $slug_publico = preg_replace('/[^a-z0-9\-]/', '-', $slug_publico);
        $slug_publico = trim($slug_publico, '-');
        if ($slug_publico === '') {
            $errors[] = 'El slug público no puede quedar vacío luego de normalizarlo.';
        }
    }

    if (empty($errors)) {
        $now = date('c');

        try {
            // ¿Ya existe config para este admin?
            $stmt = $db->prepare('SELECT id FROM clientes_sites WHERE admin_id = :admin_id LIMIT 1');
            $stmt->execute(array(':admin_id' => $admin_id));
            $existing = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($existing) {
                // UPDATE
                $stmt = $db->prepare('
                    UPDATE clientes_sites
                    SET slug_publico = :slug_publico,
                        nombre_publico = :nombre_publico,
                        texto_hero = :texto_hero,
                        texto_intro = :texto_intro,
                        visible = :visible,
                        updated_at = :updated_at
                    WHERE admin_id = :admin_id
                ');
                $stmt->execute(array(
                    ':slug_publico'   => $slug_publico,
                    ':nombre_publico' => $nombre_publico,
                    ':texto_hero'     => $texto_hero,
                    ':texto_intro'    => $texto_intro,
                    ':visible'        => $visible,
                    ':updated_at'     => $now,
                    ':admin_id'       => $admin_id,
                ));
            } else {
                // INSERT
                $stmt = $db->prepare('
                    INSERT INTO clientes_sites
                        (admin_id, slug_publico, nombre_publico, texto_hero, texto_intro, visible, created_at, updated_at)
                    VALUES
                        (:admin_id, :slug_publico, :nombre_publico, :texto_hero, :texto_intro, :visible, :created_at, :updated_at)
                ');
                $stmt->execute(array(
                    ':admin_id'       => $admin_id,
                    ':slug_publico'   => $slug_publico,
                    ':nombre_publico' => $nombre_publico,
                    ':texto_hero'     => $texto_hero,
                    ':texto_intro'    => $texto_intro,
                    ':visible'        => $visible,
                    ':created_at'     => $now,
                    ':updated_at'     => $now,
                ));
            }

            $saved = true;

            // Actualizamos $config para que el form refleje lo último guardado
            $config['slug_publico']   = $slug_publico;
            $config['nombre_publico'] = $nombre_publico;
            $config['texto_hero']     = $texto_hero;
            $config['texto_intro']    = $texto_intro;
            $config['visible']        = $visible;

        } catch (Exception $e) {
            $errors[] = 'Error al guardar la configuración: ' . $e->getMessage();
        }
    } else {
        // Si hay errores, mantenemos lo que el usuario escribió
        $config['slug_publico']   = $slug_publico;
        $config['nombre_publico'] = $nombre_publico;
        $config['texto_hero']     = $texto_hero;
        $config['texto_intro']    = $texto_intro;
        $config['visible']        = $visible;
    }
}

require __DIR__ . '/inc/layout_top.php';
?>
<div class="page">
  <div class="page-header">
    <h1>Mi sitio</h1>
    <p class="lead">
      Configurá la mini web pública donde tus clientes finales van a ver tus eventos y comprar entradas.
    </p>
  </div>

  <?php if (!empty($errors)): ?>
    <div class="alert error">
      <ul>
        <?php foreach ($errors as $err): ?>
          <li><?php echo e($err); ?></li>
        <?php endforeach; ?>
      </ul>
    </div>
  <?php elseif ($saved): ?>
    <div class="alert success">
      Configuración guardada correctamente.
    </div>
  <?php endif; ?>

  <div class="card">
    <div class="card-body">
      <form method="post" action="mi_sitio.php">
        <div class="form-group">
          <label for="nombre_publico">Nombre público del sitio</label>
          <input
            type="text"
            id="nombre_publico"
            name="nombre_publico"
            class="form-control"
            value="<?php echo e($config['nombre_publico']); ?>"
            required
          >
          <small class="form-text text-muted">
            Por ejemplo: “Save The Rave”, “Teatro Central”, “Fiestas XYZ”.
          </small>
        </div>

        <div class="form-group">
          <label for="slug_publico">Slug público</label>
          <input
            type="text"
            id="slug_publico"
            name="slug_publico"
            class="form-control"
            value="<?php echo e($config['slug_publico']); ?>"
            required
          >
          <small class="form-text text-muted">
            Solo letras minúsculas, números y guiones. Ejemplo: <code>save-the-rave</code>.
            La URL pública podría ser algo como:
            <code>https://tickex.com.ar/site.php?slug=save-the-rave</code>
            (después vemos las URLs lindas con .htaccess).
          </small>
        </div>

        <div class="form-group">
          <label for="texto_hero">Texto principal (hero)</label>
          <input
            type="text"
            id="texto_hero"
            name="texto_hero"
            class="form-control"
            value="<?php echo e($config['texto_hero']); ?>"
          >
          <small class="form-text text-muted">
            Frase grande de portada. Ejemplo: “Entradas oficiales para todos nuestros eventos”.
          </small>
        </div>

        <div class="form-group">
          <label for="texto_intro">Texto introductorio</label>
          <textarea
            id="texto_intro"
            name="texto_intro"
            class="form-control"
            rows="3"
          ><?php echo e($config['texto_intro']); ?></textarea>
          <small class="form-text text-muted">
            Un breve párrafo explicando quién sos o qué tipo de eventos organizás.
          </small>
        </div>

        <div class="form-group">
          <label>
            <input
              type="checkbox"
              name="visible"
              value="1"
              <?php echo ($config['visible'] ? 'checked' : ''); ?>
            >
            Sitio público activo
          </label>
          <small class="form-text text-muted">
            Si está desmarcado, el sitio quedará oculto para el público (modo mantenimiento).
          </small>
        </div>

        <div class="form-actions">
          <button type="submit" class="btn primary">Guardar cambios</button>
        </div>
      </form>
    </div>
  </div>
</div>
<?php
require __DIR__ . '/inc/layout_bottom.php';
