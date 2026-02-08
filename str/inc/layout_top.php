<?php
// Fallback: algunas páginas incluyen layout_top sin definir e()
if (!function_exists('e')) {
    function e($s) { return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8'); }
}

if (!isset($title)) {
    $title = 'Tickex';
}

$themeClass = 'theme-dark';
if (!empty($_SESSION['ui_theme']) && in_array($_SESSION['ui_theme'], array('theme-light', 'theme-dark'), true)) {
    $themeClass = $_SESSION['ui_theme'];
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
    <link rel="stylesheet" href="assets/str.css">
    <link rel="stylesheet" href="assets/str-theme.css">
</head>
<body class="<?php echo htmlspecialchars($themeClass, ENT_QUOTES, 'UTF-8'); ?>">
<div class="topbar">
    <div class="wrap">
        <a class="logo" href="panel_admin.php">TICKEX</a>
        <?php include __DIR__ . '/nav.php'; ?>
        <div class="userchip">
            <?php if (!empty($_SESSION['usuario'])): ?>
                <span><?php echo e($_SESSION['usuario']); ?></span>
                <a class="link" href="logout_usuario.php">Salir</a>
            <?php endif; ?>
        </div>
    </div>
</div>
<div class="wrap">
<?php
$flashes = function_exists('flash_get_all') ? flash_get_all() : array();
foreach ($flashes as $f) {
    $t = e($f['type']);
    $m = e($f['msg']);
    echo "<div class='flash $t'>$m</div>";
}
?>
