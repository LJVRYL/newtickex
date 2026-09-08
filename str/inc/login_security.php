<?php

if (!function_exists('tickex_password_verify_compat')) {
    function tickex_password_verify_compat($plain, $stored)
    {
        $plain = (string)$plain;
        $stored = (string)$stored;
        if ($stored === '') return array('valid' => false, 'needs_upgrade' => false);

        $info = function_exists('password_get_info') ? password_get_info($stored) : array('algo' => 0);
        if (!empty($info['algo'])) {
            $valid = password_verify($plain, $stored);
            return array('valid' => $valid, 'needs_upgrade' => $valid && password_needs_rehash($stored, PASSWORD_DEFAULT));
        }
        if (strlen($stored) === 32 && ctype_xdigit($stored)) {
            $valid = hash_equals(strtolower($stored), md5($plain));
            return array('valid' => $valid, 'needs_upgrade' => $valid);
        }
        $valid = hash_equals($stored, $plain);
        return array('valid' => $valid, 'needs_upgrade' => $valid);
    }
}

if (!function_exists('tickex_password_upgrade')) {
    function tickex_password_upgrade(PDO $pdo, $table, $column, $id, $plain)
    {
        $allowed = array('usuarios_admin' => array('password'), 'registro_pendientes' => array('password_hash'));
        if (!isset($allowed[$table]) || !in_array($column, $allowed[$table], true) || (int)$id <= 0) return false;
        $hash = password_hash((string)$plain, PASSWORD_DEFAULT);
        if (!is_string($hash) || $hash === '') return false;
        $stmt = $pdo->prepare('UPDATE ' . $table . ' SET ' . $column . ' = :hash WHERE id = :id');
        return $stmt->execute(array(':hash' => $hash, ':id' => (int)$id));
    }
}

if (!function_exists('tickex_login_throttled')) {
    function tickex_login_throttled($scope, $maxAttempts, $windowSeconds)
    {
        $key = '_login_guard_' . preg_replace('/[^a-z0-9_]/i', '', (string)$scope);
        $now = time();
        $state = isset($_SESSION[$key]) && is_array($_SESSION[$key]) ? $_SESSION[$key] : array('count' => 0, 'started_at' => $now);
        if (($now - (int)$state['started_at']) > (int)$windowSeconds) $state = array('count' => 0, 'started_at' => $now);
        $_SESSION[$key] = $state;
        return (int)$state['count'] >= (int)$maxAttempts;
    }
}

if (!function_exists('tickex_login_record_failure')) {
    function tickex_login_record_failure($scope)
    {
        $key = '_login_guard_' . preg_replace('/[^a-z0-9_]/i', '', (string)$scope);
        $now = time();
        $state = isset($_SESSION[$key]) && is_array($_SESSION[$key]) ? $_SESSION[$key] : array('count' => 0, 'started_at' => $now);
        $state['count'] = (int)$state['count'] + 1;
        $_SESSION[$key] = $state;
    }
}

if (!function_exists('tickex_login_clear_failures')) {
    function tickex_login_clear_failures($scope)
    {
        $key = '_login_guard_' . preg_replace('/[^a-z0-9_]/i', '', (string)$scope);
        unset($_SESSION[$key]);
    }
}

