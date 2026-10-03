<?php

function tickex_mobile_staff_invite_token()
{
    if (function_exists('random_bytes')) return bin2hex(random_bytes(16));
    return bin2hex(openssl_random_pseudo_bytes(16));
}

function tickex_mobile_admin_invite_staff($pdo, $admin, $eventId, $body, $mailSender = null, $notifier = null)
{
    $ownerId = tickex_mobile_admin_event_owner($pdo, $admin, $eventId);
    if ($ownerId <= 0) return array('error' => 'forbidden');
    foreach (array('email', 'tickexId', 'message', 'roleCode') as $field) if (isset($body[$field]) && !is_string($body[$field])) return array('error' => 'invalid_invitation');
    $email = isset($body['email']) ? trim((string)$body['email']) : '';
    $tickexId = isset($body['tickexId']) ? trim((string)$body['tickexId']) : '';
    $message = isset($body['message']) ? trim((string)$body['message']) : '';
    $role = isset($body['roleCode']) ? trim((string)$body['roleCode']) : 'puerta';
    if (($email === '' && $tickexId === '') || strlen($tickexId) > 80 || strlen($message) > 300) return array('error' => 'invalid_invitation');
    $roles = tickex_mobile_admin_roles($pdo, $admin, $eventId);
    $roleAllowed = false;
    foreach ($roles['roles'] as $available) if ($available['code'] === $role) $roleAllowed = true;
    if (!$roleAllowed) return array('error' => 'invalid_role');

    $clientId = 0;
    if ($tickexId !== '') {
        if (preg_match('/^#?([1-9][0-9]*)$/', $tickexId, $match)) {
            $find = $pdo->prepare('SELECT id, email FROM registro_pendientes WHERE id = :id LIMIT 1');
            $find->execute(array(':id' => (int)$match[1]));
        } else {
            $find = $pdo->prepare('SELECT id, email FROM registro_pendientes WHERE lower(apodo) = lower(:id) LIMIT 1');
            $find->execute(array(':id' => $tickexId));
        }
        $found = $find->fetch(PDO::FETCH_ASSOC);
        if (!$found) return array('error' => 'client_not_found');
        $clientId = (int)$found['id'];
        if ($email === '') $email = (string)$found['email'];
        if (strtolower($email) !== strtolower((string)$found['email'])) return array('error' => 'identity_mismatch');
    }
    if ($email === '' || strlen($email) > 254 || !filter_var($email, FILTER_VALIDATE_EMAIL)) return array('error' => 'invalid_email');

    $pdo->beginTransaction();
    try {
        $duplicate = $pdo->prepare("SELECT 1 FROM staff_admin_invitaciones WHERE owner_admin_id = :owner AND lower(email) = lower(:email) AND estado = 'pending' LIMIT 1");
        $duplicate->execute(array(':owner' => $ownerId, ':email' => $email));
        if ($duplicate->fetchColumn()) { $pdo->rollBack(); return array('error' => 'already_pending'); }
        $find = $pdo->prepare('SELECT id, completado_en, password_hash, nombre, apellido FROM registro_pendientes WHERE lower(email) = lower(:email) ORDER BY id DESC LIMIT 1');
        $find->execute(array(':email' => $email));
        $registration = $find->fetch(PDO::FETCH_ASSOC);
        if ($registration && $clientId <= 0) $clientId = (int)$registration['id'];
        $needsRegistration = !$registration || (trim((string)$registration['password_hash']) === '' && trim((string)$registration['completado_en']) === '');
        $inviteToken = tickex_mobile_staff_invite_token();
        $registrationToken = '';
        if ($needsRegistration) {
            $registrationToken = tickex_mobile_staff_invite_token();
            $next = 'panel_usuario_mi_perfil.php?staff_invite=' . urlencode($inviteToken);
            if ($registration) {
                $update = $pdo->prepare('UPDATE registro_pendientes SET token = :token, completado_en = NULL, next_url = :next, creado_en = :created WHERE id = :id');
                $update->execute(array(':token' => $registrationToken, ':next' => $next, ':created' => date('Y-m-d H:i:s'), ':id' => (int)$registration['id']));
            } else {
                $insert = $pdo->prepare('INSERT INTO registro_pendientes (email, token, nombre, apellido, next_url, creado_en) VALUES (:email, :token, :first, :last, :next, :created)');
                $insert->execute(array(':email' => $email, ':token' => $registrationToken, ':first' => '', ':last' => '', ':next' => $next, ':created' => date('Y-m-d H:i:s')));
                $clientId = (int)$pdo->lastInsertId();
            }
        }
        $insert = $pdo->prepare("INSERT INTO staff_admin_invitaciones (owner_admin_id, email, token, mensaje, rol_staff, estado, cliente_id, created_at) VALUES (:owner, :email, :token, :message, :role, 'pending', :client, datetime('now'))");
        $insert->execute(array(':owner' => $ownerId, ':email' => $email, ':token' => $inviteToken, ':message' => $message !== '' ? $message : null, ':role' => $role, ':client' => $clientId > 0 ? $clientId : null));
        $invitationId = (int)$pdo->lastInsertId();
        $pdo->commit();
    } catch (Exception $error) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $error; }

    $base = 'https://str.tickex.com.ar';
    $profileUrl = $base . '/panel_usuario_mi_perfil.php?staff_invite=' . urlencode($inviteToken);
    $registerUrl = $needsRegistration ? $base . '/completar_registro.php?token=' . urlencode($registrationToken) : '';
    if ($notifier === null) {
        require_once __DIR__ . '/notificaciones.php';
        $notifier = 'add_notification';
    }
    try {
        if ($clientId > 0) call_user_func($notifier, $clientId, 'Te invitaron a ser parte del staff de un evento. Revisá tu perfil para aceptar.', 'staff_invite', array('admin_id' => $ownerId, 'inv_id' => $invitationId), $pdo);
    } catch (Exception $ignored) {}
    if ($mailSender === null) {
        require_once __DIR__ . '/mail.php';
        $mailSender = 'tickex_send_mail_template';
    }
    $mailSent = false;
    try {
        $mailSent = call_user_func($mailSender, $email, 'staff_invite', array('email' => $email, 'perfil_url' => $profileUrl, 'register_url' => $registerUrl, 'mensaje' => $message), array('context' => 'staff_invite', 'related_table' => 'staff_admin_invitaciones', 'related_id' => $invitationId), array('subject' => 'Invitación a staff - Tickex', 'body' => "Hola,\n\nTe invitaron a ser parte del staff.\n\nSi todavía no tenés cuenta, completá el registro desde este link:\n{{register_url}}\n\nSi ya tenés cuenta, ingresá y aceptá la invitación desde tu perfil:\n{{perfil_url}}\n\nTickex\n", 'from_email' => 'no-reply@tickex.com.ar', 'from_name' => 'Tickex', 'reply_to' => 'no-reply@tickex.com.ar', 'extra_params' => '-f no-reply@tickex.com.ar', 'is_html' => 0));
    } catch (Exception $ignored) {}
    return array('id' => (string)$invitationId, 'email' => $email, 'roleCode' => $role, 'status' => 'pending', 'emailSent' => (bool)$mailSent);
}
