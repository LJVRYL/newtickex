</div>
<?php
if (!function_exists('tickex_is_app_mode')) {
  function tickex_is_app_mode()
  {
    $ua = isset($_SERVER['HTTP_USER_AGENT']) ? (string)$_SERVER['HTTP_USER_AGENT'] : '';
    $isByUa = ($ua !== '' && stripos($ua, 'TickexAppWebView') !== false);
    $isBySession = (!empty($_SESSION['tickex_app']));
    $isByCookie = (!empty($_COOKIE['tickex_app']) && (string)$_COOKIE['tickex_app'] === '1');
    $isByQuery = (isset($_GET['app']) && (string)$_GET['app'] === '1');
    return ($isByQuery || $isBySession || $isByCookie || $isByUa);
  }
}
$isApp = tickex_is_app_mode();
?>
<?php if (!$isApp): ?>
  <div class="wrap footer" style="padding:16px 18px;margin-top:18px;">
    <small>TICKEX / STR</small>
  </div>
<?php endif; ?>
</body>
</html>
