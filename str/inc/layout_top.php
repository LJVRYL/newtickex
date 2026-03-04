<?php
$cu = isset($cu) ? $cu : (function_exists('current_user') ? current_user() : array());
require_once __DIR__ . '/notificaciones.php';
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
$userIdNotif = 0;
$notifs = array();
$unreadCount = 0;
if ($isLogged) {
  if (isset($cu['id']) && (int)$cu['id'] > 0) {
    $userIdNotif = (int)$cu['id'];
  } elseif (!empty($_SESSION['usuario_id'])) {
    $userIdNotif = (int)$_SESSION['usuario_id'];
  } elseif (!empty($_SESSION['user_id'])) {
    $userIdNotif = (int)$_SESSION['user_id'];
  } elseif (!empty($_SESSION['admin_id'])) {
    $userIdNotif = (int)$_SESSION['admin_id'];
  }

  if ($userIdNotif > 0 && function_exists('get_user_notifications')) {
    $notifs = get_user_notifications($userIdNotif);
    foreach ($notifs as $n) {
      if (empty($n['leida']) || (int)$n['leida'] === 0) {
        $unreadCount++;
      }
    }
  }
}
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
            <?php if (empty($hideNav)): ?>
                <button class="hamburger" id="hamburgerBtn" aria-label="Abrir menú" aria-expanded="false">
                  <span></span>
                  <span></span>
                  <span></span>
                  <span class="chevron" aria-hidden="true"></span>
                </button>

                <div class="quick-links" aria-label="Accesos rápidos">
                  <?php if ($isClient): ?>
                    <a href="panel_usuario.php">Mis Tickex</a>
                    <a href="panel_usuario_mi_perfil.php">Mi perfil</a>
                    <?php if ($isApp): ?>
                      <a href="logout_usuario.php">Salir</a>
                    <?php endif; ?>
                  <?php else: ?>
                    <a href="panel_admin.php">Panel</a>
                    <a href="crear_evento.php">Crear evento</a>
                  <?php endif; ?>
                </div>
                <?php include __DIR__ . '/nav.php'; ?>
            <?php endif; ?>
        <?php endif; ?>
        <div class="userchip">
            <?php if (!empty($_SESSION['usuario'])): ?>
              <div style="position:relative;display:inline-block;margin-right:10px;vertical-align:middle;">
                <button id="btnNotif" type="button" aria-label="Notificaciones" title="Notificaciones" style="background:none;border:none;cursor:pointer;color:inherit;font-size:18px;line-height:1;position:relative;padding:2px 4px;">
                  🔔
                  <?php if ($unreadCount > 0): ?>
                    <span style="position:absolute;top:-6px;right:-8px;min-width:16px;height:16px;padding:0 4px;border-radius:10px;background:#d22;color:#fff;font-size:10px;line-height:16px;text-align:center;"><?php echo (int)$unreadCount; ?></span>
                  <?php endif; ?>
                </button>
                <div id="notifMenu" style="display:none;position:absolute;right:0;top:30px;width:340px;max-width:90vw;max-height:60vh;overflow:auto;background:#0f1720;border:1px solid rgba(255,255,255,.15);border-radius:10px;box-shadow:0 8px 24px rgba(0,0,0,.35);z-index:1100;">
                  <div style="padding:10px 12px;border-bottom:1px solid rgba(255,255,255,.12);font-weight:600;">Notificaciones</div>
                  <?php if (!empty($notifs)): ?>
                    <?php foreach ($notifs as $n): ?>
                      <div style="padding:10px 12px;border-bottom:1px solid rgba(255,255,255,.08);<?php echo (empty($n['leida']) ? 'background:rgba(255,255,255,.04);' : ''); ?>">
                        <div style="font-size:13px;line-height:1.35;"><?php echo e($n['mensaje']); ?></div>
                        <div class="muted" style="font-size:11px;margin-top:4px;"><?php echo e(date('d/m/Y H:i', strtotime((string)$n['created_at']))); ?></div>
                      </div>
                    <?php endforeach; ?>
                  <?php else: ?>
                    <div class="muted" style="padding:12px;">No tenés notificaciones por ahora.</div>
                  <?php endif; ?>
                </div>
              </div>
              <?php if ($isApp && $isClient): ?>
                <?php // En modo app cliente, el logout se muestra arriba en quick-links ?>
              <?php else: ?>
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

<?php if (!empty($_SESSION['usuario'])): ?>
<script>
  (function () {
    var btnNotif = document.getElementById('btnNotif');
    var notifMenu = document.getElementById('notifMenu');
    if (!btnNotif || !notifMenu) return;

    btnNotif.addEventListener('click', function (e) {
      e.preventDefault();
      e.stopPropagation();
      notifMenu.style.display = (notifMenu.style.display === 'block') ? 'none' : 'block';
    });

    document.addEventListener('click', function () {
      notifMenu.style.display = 'none';
    });

    notifMenu.addEventListener('click', function (e) {
      e.stopPropagation();
    });
  })();
</script>
<?php endif; ?>
