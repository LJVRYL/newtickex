<?php
// Consumer mobile sessions, linked to the same registro_pendientes account as panel_usuario.php.

function tickex_mobile_client($pdo, $email)
{
    $statement = $pdo->prepare("SELECT id, email, nombre, apellido, password_hash, completado_en FROM registro_pendientes WHERE lower(email) = lower(:email) AND completado_en IS NOT NULL AND completado_en <> '' ORDER BY id DESC LIMIT 1");
    $statement->execute(array(':email' => $email));
    return $statement->fetch(PDO::FETCH_ASSOC);
}

function tickex_mobile_password_ok($password, $stored)
{
    $stored = (string)$stored;
    if ($stored === '') return false;
    if (function_exists('password_verify') && strpos($stored, '$2') === 0) return password_verify($password, $stored);
    if (strlen($stored) === 32 && ctype_xdigit($stored)) return function_exists('hash_equals') ? hash_equals(strtolower($stored), md5($password)) : strtolower($stored) === md5($password);
    return function_exists('hash_equals') ? hash_equals($stored, $password) : $stored === $password;
}

function tickex_mobile_profile($row)
{
    return array('id' => (string)$row['id'], 'name' => trim((string)$row['nombre'] . ' ' . (string)$row['apellido']), 'firstName' => (string)$row['nombre'], 'lastName' => (string)$row['apellido'], 'email' => (string)$row['email']);
}

function tickex_mobile_random_token()
{
    if (function_exists('random_bytes')) return bin2hex(random_bytes(32));
    if (function_exists('openssl_random_pseudo_bytes')) {
        $bytes = openssl_random_pseudo_bytes(32);
        if ($bytes !== false) return bin2hex($bytes);
    }
    throw new RuntimeException('secure_random_unavailable');
}

function tickex_mobile_login($pdo, $email, $password, $remoteAddress)
{
    $email = strtolower(trim((string)$email));
    if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 254 || !is_string($password) || $password === '') return null;
    $fingerprint = hash('sha256', $email . '|' . (string)$remoteAddress);
    $cutoff = date('Y-m-d H:i:s', time() - 900);
    $rate = $pdo->prepare('SELECT COUNT(*) FROM mobile_login_attempts WHERE fingerprint = :f AND attempted_at >= :cutoff');
    $rate->execute(array(':f' => $fingerprint, ':cutoff' => $cutoff));
    if ((int)$rate->fetchColumn() >= 5) return null;
    $attempt = $pdo->prepare('INSERT INTO mobile_login_attempts (fingerprint, attempted_at) VALUES (:f, :at)');
    $attempt->execute(array(':f' => $fingerprint, ':at' => date('Y-m-d H:i:s')));

    $blocked = $pdo->prepare('SELECT 1 FROM user_blocks WHERE active = 1 AND lower(email) = lower(:email) LIMIT 1');
    $blocked->execute(array(':email' => $email));
    if ($blocked->fetchColumn()) return null;
    $client = tickex_mobile_client($pdo, $email);
    if (!$client || empty($client['completado_en']) || !tickex_mobile_password_ok($password, $client['password_hash'])) return null;
    $token = tickex_mobile_random_token();
    $created = date('Y-m-d H:i:s');
    $expires = date('Y-m-d H:i:s', time() + 30 * 86400);
    $insert = $pdo->prepare('INSERT INTO mobile_auth_tokens (token_hash, cliente_id, created_at, expires_at) VALUES (:hash, :id, :created, :expires)');
    $insert->execute(array(':hash' => hash('sha256', $token), ':id' => (int)$client['id'], ':created' => $created, ':expires' => $expires));
    return array('accessToken' => $token, 'expiresAt' => tickex_mobile_datetime($expires), 'profile' => tickex_mobile_profile($client));
}

function tickex_mobile_bearer()
{
    $header = '';
    if (isset($_SERVER['HTTP_AUTHORIZATION'])) $header = (string)$_SERVER['HTTP_AUTHORIZATION'];
    elseif (isset($_SERVER['REDIRECT_HTTP_AUTHORIZATION'])) $header = (string)$_SERVER['REDIRECT_HTTP_AUTHORIZATION'];
    elseif (function_exists('getallheaders')) {
        foreach (getallheaders() as $key => $value) if (strtolower($key) === 'authorization') { $header = (string)$value; break; }
    }
    if (!preg_match('/^Bearer ([a-f0-9]{64})$/i', $header, $match)) return null;
    return strtolower($match[1]);
}

function tickex_mobile_authenticated_client($pdo, $token)
{
    if ($token === null) return null;
    $statement = $pdo->prepare("SELECT c.id, c.email, c.nombre, c.apellido, c.completado_en, EXISTS (SELECT 1 FROM user_blocks b WHERE b.active = 1 AND lower(b.email) = lower(c.email)) AS is_blocked FROM mobile_auth_tokens t JOIN registro_pendientes c ON c.id = t.cliente_id WHERE t.token_hash = :hash AND t.revoked_at IS NULL AND t.expires_at > :now LIMIT 1");
    $statement->execute(array(':hash' => hash('sha256', $token), ':now' => date('Y-m-d H:i:s')));
    $client = $statement->fetch(PDO::FETCH_ASSOC);
    if (!$client) return null;
    if ((int)$client['is_blocked'] === 1 || empty($client['completado_en'])) {
        tickex_mobile_logout($pdo, $token);
        return null;
    }
    unset($client['is_blocked'], $client['completado_en']);
    return $client;
}

function tickex_mobile_logout($pdo, $token)
{
    if ($token === null) return;
    $statement = $pdo->prepare('UPDATE mobile_auth_tokens SET revoked_at = :now WHERE token_hash = :hash AND revoked_at IS NULL');
    $statement->execute(array(':now' => date('Y-m-d H:i:s'), ':hash' => hash('sha256', $token)));
}

function tickex_mobile_update_profile($pdo, $clientId, $firstName, $lastName)
{
    $firstName = trim((string)$firstName);
    $lastName = trim((string)$lastName);
    if ($firstName === '' || strlen($firstName) > 100 || strlen($lastName) > 100) return null;
    $statement = $pdo->prepare('UPDATE registro_pendientes SET nombre = :first, apellido = :last WHERE id = :id');
    $statement->execute(array(':first' => $firstName, ':last' => $lastName, ':id' => (int)$clientId));
    $read = $pdo->prepare('SELECT id, email, nombre, apellido FROM registro_pendientes WHERE id = :id LIMIT 1');
    $read->execute(array(':id' => (int)$clientId));
    $row = $read->fetch(PDO::FETCH_ASSOC);
    return $row ? tickex_mobile_profile($row) : null;
}
