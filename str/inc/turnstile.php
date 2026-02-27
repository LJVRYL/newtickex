<?php
// inc/turnstile.php — Integración opcional con Cloudflare Turnstile.
// Se activa solo si existen SITE KEY + SECRET.
// Keys:
// - Env: TICKEX_TURNSTILE_SITE_KEY / TICKEX_TURNSTILE_SECRET_KEY
// - Const: TICKEX_TURNSTILE_SITE_KEY / TICKEX_TURNSTILE_SECRET_KEY

// Opción local (no versionada): si existe, puede definir las constantes.
// Archivo esperado: str/.secrets/turnstile.php
try {
  $secretsFile = __DIR__ . '/../.secrets/turnstile.php';
  if (@is_file($secretsFile)) {
    require_once $secretsFile;
  }
} catch (Throwable $_t) {
  // ignore
}

if (!function_exists('tickex_turnstile_site_key')) {
  function tickex_turnstile_site_key()
  {
    if (defined('TICKEX_TURNSTILE_SITE_KEY')) {
      return (string)constant('TICKEX_TURNSTILE_SITE_KEY');
    }
    $v = @getenv('TICKEX_TURNSTILE_SITE_KEY');
    return $v !== false ? (string)$v : '';
  }
}

if (!function_exists('tickex_turnstile_secret_key')) {
  function tickex_turnstile_secret_key()
  {
    if (defined('TICKEX_TURNSTILE_SECRET_KEY')) {
      return (string)constant('TICKEX_TURNSTILE_SECRET_KEY');
    }
    $v = @getenv('TICKEX_TURNSTILE_SECRET_KEY');
    return $v !== false ? (string)$v : '';
  }
}

if (!function_exists('tickex_turnstile_enabled')) {
  function tickex_turnstile_enabled()
  {
    $sk = trim((string)tickex_turnstile_site_key());
    $sec = trim((string)tickex_turnstile_secret_key());
    return ($sk !== '' && $sec !== '');
  }
}

if (!function_exists('tickex_turnstile_render')) {
  function tickex_turnstile_render()
  {
    static $included = false;
    if (!tickex_turnstile_enabled()) return;
    if ($included) return;
    $included = true;
    echo "\n";
    echo '<script src="https://challenges.cloudflare.com/turnstile/v0/api.js" async defer></script>';
    echo "\n";
  }
}

if (!function_exists('tickex_turnstile_widget')) {
  function tickex_turnstile_widget($opts = array())
  {
    if (!tickex_turnstile_enabled()) return;
    $siteKey = htmlspecialchars((string)tickex_turnstile_site_key(), ENT_QUOTES, 'UTF-8');

    $theme = isset($opts['theme']) ? (string)$opts['theme'] : 'auto';
    $size = isset($opts['size']) ? (string)$opts['size'] : 'normal';

    $themeAttr = htmlspecialchars($theme, ENT_QUOTES, 'UTF-8');
    $sizeAttr = htmlspecialchars($size, ENT_QUOTES, 'UTF-8');

    tickex_turnstile_render();

    echo '<div style="margin-top:12px;">';
    echo '<div class="cf-turnstile" data-sitekey="' . $siteKey . '" data-theme="' . $themeAttr . '" data-size="' . $sizeAttr . '"></div>';
    echo '</div>';
  }
}

if (!function_exists('tickex_turnstile_http_post')) {
  function tickex_turnstile_http_post($url, $payload)
  {
    // Preferir cURL si existe.
    if (function_exists('curl_init')) {
      $ch = curl_init($url);
      curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
      curl_setopt($ch, CURLOPT_POST, true);
      curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
      curl_setopt($ch, CURLOPT_TIMEOUT, 6);
      curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 4);
      curl_setopt($ch, CURLOPT_HTTPHEADER, array('Content-Type: application/x-www-form-urlencoded'));
      $resp = curl_exec($ch);
      $err = curl_error($ch);
      $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
      curl_close($ch);
      return array($resp, $code, $err);
    }

    $ctx = stream_context_create(array(
      'http' => array(
        'method' => 'POST',
        'header' => "Content-Type: application/x-www-form-urlencoded\r\n",
        'content' => $payload,
        'timeout' => 6,
      )
    ));

    $resp = @file_get_contents($url, false, $ctx);
    $code = 0;
    $err = '';

    if (isset($http_response_header) && is_array($http_response_header)) {
      foreach ($http_response_header as $h) {
        if (preg_match('~^HTTP/\S+\s+(\d+)~', (string)$h, $m)) {
          $code = (int)$m[1];
          break;
        }
      }
    }

    if ($resp === false) {
      $err = 'http_request_failed';
    }

    return array($resp, $code, $err);
  }
}

if (!function_exists('tickex_turnstile_verify_token')) {
  function tickex_turnstile_verify_token($token, $remoteIp = '')
  {
    if (!tickex_turnstile_enabled()) return array(true, null);

    $tok = trim((string)$token);
    if ($tok === '') return array(false, 'Verificá el captcha para continuar.');

    $secret = (string)tickex_turnstile_secret_key();
    $payload = 'secret=' . rawurlencode($secret) . '&response=' . rawurlencode($tok);
    if ($remoteIp !== '') {
      $payload .= '&remoteip=' . rawurlencode((string)$remoteIp);
    }

    list($resp, $code, $err) = tickex_turnstile_http_post('https://challenges.cloudflare.com/turnstile/v0/siteverify', $payload);

    if ($resp === false || $resp === '' || $code < 200 || $code >= 300) {
      return array(false, 'No pudimos validar el captcha. Probá de nuevo.');
    }

    $data = json_decode((string)$resp, true);
    if (!is_array($data)) {
      return array(false, 'No pudimos validar el captcha. Probá de nuevo.');
    }

    if (!empty($data['success'])) {
      return array(true, null);
    }

    return array(false, 'Captcha inválido. Probá de nuevo.');
  }
}

if (!function_exists('tickex_turnstile_verify_post')) {
  function tickex_turnstile_verify_post(&$errors, $fieldName = 'cf-turnstile-response')
  {
    if (!tickex_turnstile_enabled()) return true;

    $token = isset($_POST[$fieldName]) ? (string)$_POST[$fieldName] : '';
    $rip = isset($_SERVER['REMOTE_ADDR']) ? (string)$_SERVER['REMOTE_ADDR'] : '';

    list($ok, $msg) = tickex_turnstile_verify_token($token, $rip);
    if ($ok) return true;

    if (is_array($errors)) {
      $errors[] = (string)$msg;
    }
    return false;
  }
}
