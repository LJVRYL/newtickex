<?php
// Producer controls for the existing web FREE checkout. Issuance stays in acceso.php.
function tickex_mobile_admin_free_checkout($pdo, $admin, $eventId)
{
    if (!tickex_mobile_admin_can_event($pdo, $admin, $eventId)) return array('error' => 'forbidden');
    $read = $pdo->prepare('SELECT enabled, ticket_type_id, max_uses, captcha_required, unique_email FROM event_free_checkout_configs WHERE evento_id = :event LIMIT 1');
    $read->execute(array(':event' => $eventId));
    $row = $read->fetch(PDO::FETCH_ASSOC);
    return array(
        'eventId' => (string)$eventId,
        'enabled' => $row ? (int)$row['enabled'] === 1 : false,
        'ticketTypeId' => $row ? (string)$row['ticket_type_id'] : null,
        'maxUses' => $row && $row['max_uses'] !== null ? (int)$row['max_uses'] : null,
        'captchaRequired' => $row ? (int)$row['captcha_required'] === 1 : true,
        'uniqueEmail' => $row ? (int)$row['unique_email'] === 1 : true,
        'checkoutUrl' => 'https://str.tickex.com.ar/acceso.php?evento_id=' . (int)$eventId,
    );
}

function tickex_mobile_admin_save_free_checkout($pdo, $admin, $eventId, $body)
{
    if (!tickex_mobile_admin_can_event($pdo, $admin, $eventId)) return array('error' => 'forbidden');
    foreach (array('enabled', 'captchaRequired', 'uniqueEmail') as $key) {
        if (!array_key_exists($key, $body) || !is_bool($body[$key])) return array('error' => 'invalid_config');
    }
    $typeId = isset($body['ticketTypeId']) ? filter_var($body['ticketTypeId'], FILTER_VALIDATE_INT, array('options' => array('min_range' => 1))) : false;
    if ($typeId === false) return array('error' => 'invalid_type');
    $maxUses = null;
    if (array_key_exists('maxUses', $body) && $body['maxUses'] !== null) {
        $maxUses = filter_var($body['maxUses'], FILTER_VALIDATE_INT, array('options' => array('min_range' => 1, 'max_range' => 1000000)));
        if ($maxUses === false) return array('error' => 'invalid_config');
    }
    $type = $pdo->prepare('SELECT tipo, precio, activo FROM tipos_entrada WHERE id = :type AND evento_id = :event LIMIT 1');
    $type->execute(array(':type' => $typeId, ':event' => $eventId));
    $row = $type->fetch(PDO::FETCH_ASSOC);
    if (!$row || strtoupper((string)$row['tipo']) !== 'FREE' || (float)$row['precio'] !== 0.0 || (int)$row['activo'] !== 1) return array('error' => 'invalid_type');
    $pdo->beginTransaction();
    try {
        $exists = $pdo->prepare('SELECT id FROM event_free_checkout_configs WHERE evento_id = :event LIMIT 1');
        $exists->execute(array(':event' => $eventId));
        $configId = $exists->fetchColumn();
        if ($configId === false) {
            $write = $pdo->prepare("INSERT INTO event_free_checkout_configs (evento_id, enabled, ticket_type_id, max_uses, captcha_required, unique_email, created_by_admin_id, updated_by_admin_id, created_at, updated_at) VALUES (:event, :enabled, :type, :max_uses, :captcha, :unique_email, :admin, :admin, datetime('now'), datetime('now'))");
        } else {
            $write = $pdo->prepare("UPDATE event_free_checkout_configs SET enabled = :enabled, ticket_type_id = :type, max_uses = :max_uses, captcha_required = :captcha, unique_email = :unique_email, updated_by_admin_id = :admin, updated_at = datetime('now') WHERE evento_id = :event");
        }
        $write->execute(array(':event' => $eventId, ':enabled' => $body['enabled'] ? 1 : 0, ':type' => $typeId, ':max_uses' => $maxUses, ':captcha' => $body['captchaRequired'] ? 1 : 0, ':unique_email' => $body['uniqueEmail'] ? 1 : 0, ':admin' => (int)$admin['id']));
        $pdo->commit();
    } catch (Exception $error) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $error;
    }
    return tickex_mobile_admin_free_checkout($pdo, $admin, $eventId);
}
