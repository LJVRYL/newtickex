<?php
// Fallback: algunas páginas incluyen layout_top sin definir e()
if (!function_exists('e')) {
    function e($s) { return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8'); }
}

if (!isset($title)) {
    $title = 'Tickex';
}

if (!function_exists('tickex_is_app_mode')) {
  function tickex_is_app_mode()
  {
    $ua = isset($_SERVER['HTTP_USER_AGENT']) ? (string)$_SERVER['HTTP_USER_AGENT'] : '';
    $isByUa = ($ua !== '' && stripos($ua, 'TickexAppWebView') !== false);

    $isByQuery = false;
    if (isset($_GET['app'])) {
      $isByQuery = ((string)$_GET['app'] === '1');
      if ($isByQuery) {
        // persistir para el resto de pantallas
        $_SESSION['tickex_app'] = 1;
        // cookie para pantallas que no pasan por bootstrap
        $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
        @setcookie('tickex_app', '1', time() + 60*60*24*30, '/', '', $secure, true);
      }
    }

    $isBySession = (!empty($_SESSION['tickex_app']));
    $isByCookie = (!empty($_COOKIE['tickex_app']) && (string)$_COOKIE['tickex_app'] === '1');
    return ($isByQuery || $isBySession || $isByCookie || $isByUa);
  }
}

$themeClass = 'theme-dark';
if (!empty($_SESSION['ui_theme']) && in_array($_SESSION['ui_theme'], array('theme-light', 'theme-dark'), true)) {
  $themeClass = $_SESSION['ui_theme'];
}
$isLogged = !empty($_SESSION['usuario']);
$bodyClass = trim($themeClass . ($isLogged ? '' : ' no-nav'));
$role = isset($_SESSION['rol']) ? $_SESSION['rol'] : (isset($_SESSION['tipo_global']) ? $_SESSION['tipo_global'] : '');
$isClient = ($role === 'cliente');
$bodyClass = $isClient ? ($bodyClass . ' no-nav') : $bodyClass;

$isApp = tickex_is_app_mode();
if ($isApp) {
  $bodyClass .= ' app-shell';
  if ($isLogged && $isClient) {
    $bodyClass .= ' app-client';
  } elseif ($isLogged && !$isClient) {
    $bodyClass .= ' app-admin';
  }
}

$page = basename(isset($_SERVER['SCRIPT_NAME']) ? (string)$_SERVER['SCRIPT_NAME'] : '');
if ($page === 'login.php') {
  $bodyClass .= ' page-login';
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?php echo htmlspecialchars($title ?? 'Tickex', ENT_QUOTES, 'UTF-8'); ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="assets/str.css?v=20260219_2">
    <link rel="stylesheet" href="assets/str-theme.css?v=20260215">
</head>
<body class="<?php echo htmlspecialchars($bodyClass, ENT_QUOTES, 'UTF-8'); ?>">
<div class="topbar">
    <div class="wrap">
    <a class="logo" href="<?php echo $isClient ? 'panel_usuario.php' : 'panel_admin.php'; ?>">TICKEX</a>
        <?php if ($isLogged): ?>
            <?php if (!$isClient): ?>
              <?php if (empty($hideNav)): ?>
                <button class="hamburger" id="hamburgerBtn" aria-label="Abrir menú" aria-expanded="false">
                  <span></span>
                  <span></span>
                  <span></span>
                  <span class="chevron" aria-hidden="true"></span>
                </button>

                <div class="quick-links" aria-label="Accesos rápidos">
                  <a href="panel_admin.php">Panel</a>
                  <a href="crear_evento.php">Crear evento</a>
                </div>
                <?php include __DIR__ . '/nav.php'; ?>
              <?php endif; ?>
            <?php else: ?>
              <div class="quick-links" aria-label="Accesos rápidos">
                <a href="panel_usuario.php">Mis Tickex</a>
                <a href="panel_usuario_mi_perfil.php">Mi perfil</a>
              </div>
            <?php endif; ?>
        <?php endif; ?>
        <div class="userchip">
            <?php if (!empty($_SESSION['usuario'])): ?>
              <?php
              $userMail = $_SESSION['usuario'];
              // Mostrar solo primeras letras del email (ej: jua...@dominio.com)
              if (strpos($userMail, '@') !== false) {
                list($userName, $userDomain) = explode('@', $userMail, 2);
                $n = ($isApp ? 2 : 3);
                $shortUser = mb_substr($userName, 0, $n) . '...@' . $userDomain;
              } else {
                $n = ($isApp ? 2 : 3);
                $shortUser = mb_substr($userMail, 0, $n) . '...';
              }
              ?>
              <?php if (!$isApp): ?>
                <span title="<?php echo e($userMail); ?>"><?php echo e($shortUser); ?></span>
              <?php endif; ?>
              <a class="link" href="logout_usuario.php">Salir</a>
            <?php endif; ?>
        </div>
    </div>
</div>
<?php if (empty($hideNav) && $isLogged && !$isClient): ?>
<div id="navOverlay" class="nav-overlay"></div>
<?php endif; ?>
<div class="wrap">
<?php
$flashes = function_exists('flash_get_all') ? flash_get_all() : array();
foreach ($flashes as $f) {
    $t = e($f['type']);
    $m = e($f['msg']);
    echo "<div class='flash $t'>$m</div>";
}
?>
<?php if (empty($hideNav) && !$isClient): ?>
<script>
  const hamburger = document.getElementById('hamburgerBtn');
  const nav = document.querySelector('.nav');
  const overlay = document.getElementById('navOverlay');
  function closeNav() {
    if (hamburger) {
      hamburger.classList.remove('active');
      hamburger.setAttribute('aria-expanded','false');
    }
    if (nav) nav.classList.remove('active');
    if (overlay) overlay.classList.remove('active');
  }
  if(hamburger && nav) {
    hamburger.addEventListener('click', function() {
      const isOpen = nav.classList.toggle('active');
      hamburger.classList.toggle('active', isOpen);
      hamburger.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
      if (overlay) overlay.classList.toggle('active', isOpen);
    });
    nav.addEventListener('click', function(e) {
      if(e.target.tagName === 'A') {
        closeNav();
      }
    });
  }
  if (overlay) {
    overlay.addEventListener('click', closeNav);
  }
</script>
<?php endif; ?>
