<?php
// Fallback: algunas páginas incluyen layout_top sin definir e()
if (!function_exists('e')) {
  function e($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
}
?>
<?php if(!isset($title)) $title="TICKEX"; ?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <title><?php echo e($title); ?></title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <link rel="stylesheet" href="assets/str.css">
</head>
<body>
<div class="topbar">
  <div class="wrap">
    <a class="logo" href="panel_admin.php">TICKEX</a>
    <?php include __DIR__.'/nav.php'; ?>
    <div class="userchip">
      <?php if(!empty($_SESSION['usuario'])): ?>
        <span><?php echo e($_SESSION['usuario']); ?></span>
        <a class="link" href="login.php?logout=1">Salir</a>
      <?php endif; ?>
    </div>
  </div>
</div>
<div class="wrap">
<?php
$flashes = function_exists('flash_get_all') ? flash_get_all() : array();
foreach($flashes as $f){
    $t = e($f['type']); $m = e($f['msg']);
    echo "<div class='flash $t'>$m</div>";
}
?>
