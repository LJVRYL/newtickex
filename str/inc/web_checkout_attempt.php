<?php
// A public URL selects an event; it never grants ownership of a web payment.
// PHP's session lock serializes form submissions for this browser, including
// concurrent double clicks. Claims remain the database/provider dedupe guard.
function tickex_web_checkout_nonce()
{
    if (function_exists('random_bytes')) return bin2hex(random_bytes(16));
    $strong = false;
    $bytes = openssl_random_pseudo_bytes(16, $strong);
    if ($bytes === false || !$strong) throw new RuntimeException('No se pudo iniciar una compra segura.');
    return bin2hex($bytes);
}

function tickex_web_checkout_principal()
{
    if (!empty($_SESSION['usuario_id'])) return 'buyer:' . (int)$_SESSION['usuario_id'];
    if (!empty($_SESSION['user_id'])) return 'admin:' . (int)$_SESSION['user_id'];
    return 'guest';
}

function tickex_web_checkout_owned($attempt, $eventId)
{
    return is_array($attempt) && (int)$attempt['event'] === (int)$eventId
        && (string)$attempt['principal'] === tickex_web_checkout_principal();
}

function tickex_web_checkout_attempt($eventId, $sourceRef, $post)
{
    if (!isset($_SESSION['web_checkout_attempts'])) $_SESSION['web_checkout_attempts'] = array();
    if (!isset($_SESSION['web_checkout_sources'])) $_SESSION['web_checkout_sources'] = array();
    $source = hash('sha256', (int)$eventId . '|' . tickex_web_checkout_principal() . '|' . (string)$sourceRef);
    $valid = true;
    if ($post !== null) {
        $token = isset($post['checkout_attempt']) ? (string)$post['checkout_attempt'] : '';
        $attempt = isset($_SESSION['web_checkout_attempts'][$token]) ? $_SESSION['web_checkout_attempts'][$token] : null;
        if (tickex_web_checkout_owned($attempt, $eventId) && isset($post['ref'])
            && hash_equals($attempt['ref'], (string)$post['ref'])) {
            $attempt['token'] = $token;
            $attempt['valid'] = true;
            return $attempt;
        }
        // An expired session/legacy form cannot safely distinguish retry from
        // new purchase. Render the entered selection, but do not call a gateway.
        $valid = false;
    }
    $token = isset($_SESSION['web_checkout_sources'][$source]) ? $_SESSION['web_checkout_sources'][$source] : '';
    $attempt = isset($_SESSION['web_checkout_attempts'][$token]) ? $_SESSION['web_checkout_attempts'][$token] : null;
    if (!tickex_web_checkout_owned($attempt, $eventId)) {
        $token = tickex_web_checkout_nonce();
        // mobile- is an existing random capability tied to an authenticated app
        // intent; keep its reference and its server-side account/email guard.
        $ref = stripos((string)$sourceRef, 'mobile-') === 0 ? (string)$sourceRef
            : 'str-' . (int)$eventId . '-' . time() . '-' . tickex_web_checkout_nonce();
        $attempt = array('event'=>(int)$eventId, 'principal'=>tickex_web_checkout_principal(), 'ref'=>$ref, 'payload'=>null);
        $_SESSION['web_checkout_attempts'][$token] = $attempt;
        $_SESSION['web_checkout_sources'][$source] = $token;
    }
    $attempt['token'] = $token;
    $attempt['valid'] = $valid;
    return $attempt;
}

function tickex_web_checkout_freeze($token, $eventId, $payload)
{
    $attempt = isset($_SESSION['web_checkout_attempts'][$token]) ? $_SESSION['web_checkout_attempts'][$token] : null;
    if (!tickex_web_checkout_owned($attempt, $eventId)) return false;
    $fingerprint = hash('sha256', json_encode($payload));
    if ($attempt['payload'] !== null && !hash_equals($attempt['payload'], $fingerprint)) return false;
    $_SESSION['web_checkout_attempts'][$token]['payload'] = $fingerprint;
    return true;
}

function tickex_web_checkout_can_continue($ref, $eventId)
{
    if (empty($_SESSION['web_checkout_attempts'])) return false;
    foreach ($_SESSION['web_checkout_attempts'] as $attempt) {
        if (tickex_web_checkout_owned($attempt, $eventId) && hash_equals($attempt['ref'], (string)$ref)
            && $attempt['payload'] !== null) return true;
    }
    return false;
}

// Persist the dispatch boundary before network I/O. An ambiguous provider result
// must never be retried merely because a worker/session timed out.
function tickex_web_checkout_dispatch($pdo, $ref, $fingerprint)
{
    $dispatch = $pdo->prepare("UPDATE totalcoin_checkout_claims SET status='dispatching',updated_at=CURRENT_TIMESTAMP WHERE ref=:ref AND fingerprint=:fp AND status='creating'");
    $dispatch->execute(array(':ref'=>(string)$ref, ':fp'=>(string)$fingerprint));
    if ($dispatch->rowCount() !== 1) throw new RuntimeException('El intento anterior necesita verificación antes de continuar. No se inició otro pago.');
}
