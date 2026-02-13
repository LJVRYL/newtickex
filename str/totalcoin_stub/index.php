<?php
// totalcoin_stub/index.php
// Pantalla placeholder para simular la "paymentPageUrl" de TotalCoin.

header('Content-Type: text/html; charset=utf-8');

$qs = $_GET;
function h($v){ return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }

$now = date('Y-m-d H:i:s');
?>
<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <title>TotalCoin Stub</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <style>
    body{font-family:system-ui,Segoe UI,Roboto,Arial; margin:24px; background:#0b0b0f; color:#eaeaf2;}
    .card{max-width:900px; margin:auto; padding:20px; border:1px solid #2a2a35; border-radius:12px; background:#12121a;}
    code,pre{background:#0b0b10; padding:10px; border-radius:10px; display:block; overflow:auto; border:1px solid #2a2a35;}
    .ok{color:#7CFC00;}
    .muted{opacity:.8}
    a{color:#9dd6ff}
    .btn{display:inline-block; padding:10px 14px; border-radius:10px; border:1px solid #2a2a35; text-decoration:none; margin-right:8px;}
  </style>
</head>
<body>
  <div class="card">
    <h1>ACÁ VA TOTALCOIN (STUB)</h1>
    <p class="muted">Hora: <?=h($now)?> — Este endpoint existe solo para local/dev.</p>

    <h3>Querystring recibido</h3>
    <pre><?php echo h(json_encode($qs, JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE)); ?></pre>

    <h3>Acciones (solo visual por ahora)</h3>
    <p>
      <a class="btn" href="/totalcoin_stub/?result=success&<?=h(http_build_query($qs))?>">Simular “Pago OK”</a>
      <a class="btn" href="/totalcoin_stub/?result=fail&<?=h(http_build_query($qs))?>">Simular “Pago FAIL”</a>
      <a class="btn" href="http://127.0.0.1:5002/Access/Login" target="_blank">Volver a SenForms</a>
    </p>

    <hr style="border-color:#2a2a35">

    <p class="muted">
      Próximo paso: cuando veamos qué callback espera SenForms (NotificationController / PaymentNotification),
      este stub va a “postear” un payload simulado para marcar pago aprobado.
    </p>
  </div>
</body>
</html>
