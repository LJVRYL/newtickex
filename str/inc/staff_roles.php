<?php
// inc/staff_roles.php

if (!function_exists('tickex_staff_roles_permissions_catalog')) {
  function tickex_staff_roles_permissions_catalog()
  {
    return array(
      'dashboard_view' => 'Ver dashboard staff',
      'checkin_scan' => 'Escanear/check-in',
      'tickets_validate' => 'Validar entradas',
      'sales_view' => 'Ver ventas',
      'reports_view' => 'Ver reportes',
      'staff_manage' => 'Gestionar staff',
    );
  }
}

if (!function_exists('tickex_staff_roles_default_definitions')) {
  function tickex_staff_roles_default_definitions()
  {
    return array(
      array(
        'code' => 'puerta',
        'name' => 'Puerta',
        'permissions' => array('dashboard_view', 'checkin_scan', 'tickets_validate')
      ),
      array(
        'code' => 'acreditacion',
        'name' => 'Acreditación',
        'permissions' => array('dashboard_view', 'tickets_validate')
      ),
      array(
        'code' => 'caja',
        'name' => 'Caja',
        'permissions' => array('dashboard_view', 'sales_view')
      ),
      array(
        'code' => 'staff_evento',
        'name' => 'General',
        'permissions' => array('dashboard_view', 'checkin_scan', 'tickets_validate', 'sales_view', 'reports_view')
      ),
    );
  }
}

if (!function_exists('tickex_staff_roles_ensure_table')) {
  function tickex_staff_roles_ensure_table($pdo)
  {
    try {
      $pdo->exec("CREATE TABLE IF NOT EXISTS staff_roles (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        owner_admin_id INTEGER NOT NULL,
        code TEXT NOT NULL,
        name TEXT NOT NULL,
        permissions_json TEXT,
        is_system INTEGER NOT NULL DEFAULT 0,
        activo INTEGER NOT NULL DEFAULT 1,
        created_at TEXT NOT NULL DEFAULT (datetime('now')),
        updated_at TEXT
      )");
      $pdo->exec("CREATE UNIQUE INDEX IF NOT EXISTS idx_staff_roles_owner_code ON staff_roles(owner_admin_id, code)");
      $pdo->exec("CREATE INDEX IF NOT EXISTS idx_staff_roles_owner ON staff_roles(owner_admin_id)");
    } catch (Exception $e) {
      // ignore
    }
  }
}

if (!function_exists('tickex_staff_roles_seed_defaults')) {
  function tickex_staff_roles_seed_defaults($pdo, $ownerAdminId)
  {
    $oid = (int)$ownerAdminId;
    if ($oid <= 0) return;

    tickex_staff_roles_ensure_table($pdo);
    $defs = tickex_staff_roles_default_definitions();
    foreach ($defs as $d) {
      $code = isset($d['code']) ? (string)$d['code'] : '';
      $name = isset($d['name']) ? (string)$d['name'] : $code;
      $perms = isset($d['permissions']) && is_array($d['permissions']) ? $d['permissions'] : array();
      if ($code === '') continue;

      try {
        $st = $pdo->prepare('SELECT id FROM staff_roles WHERE owner_admin_id = :oid AND code = :c LIMIT 1');
        $st->execute(array(':oid' => $oid, ':c' => $code));
        if (!$st->fetchColumn()) {
          $ins = $pdo->prepare('INSERT INTO staff_roles (owner_admin_id, code, name, permissions_json, is_system, activo, created_at) VALUES (:oid,:c,:n,:p,1,1,datetime(\'now\'))');
          $ins->execute(array(
            ':oid' => $oid,
            ':c' => $code,
            ':n' => $name,
            ':p' => json_encode(array_values($perms)),
          ));
        }
      } catch (Exception $e) {
        // ignore
      }
    }
  }
}

if (!function_exists('tickex_staff_roles_get_all')) {
  function tickex_staff_roles_get_all($pdo, $ownerAdminId)
  {
    $oid = (int)$ownerAdminId;
    if ($oid <= 0) return array();
    tickex_staff_roles_ensure_table($pdo);
    tickex_staff_roles_seed_defaults($pdo, $oid);

    try {
      $st = $pdo->prepare('SELECT id, owner_admin_id, code, name, permissions_json, is_system, activo, created_at, updated_at FROM staff_roles WHERE owner_admin_id = :oid AND activo = 1 ORDER BY is_system DESC, name ASC, id ASC');
      $st->execute(array(':oid' => $oid));
      $rows = $st->fetchAll(PDO::FETCH_ASSOC);
      foreach ($rows as &$r) {
        $p = array();
        if (isset($r['permissions_json']) && trim((string)$r['permissions_json']) !== '') {
          $tmp = json_decode((string)$r['permissions_json'], true);
          if (is_array($tmp)) $p = $tmp;
        }
        $r['permissions'] = $p;
      }
      return $rows;
    } catch (Exception $e) {
      return array();
    }
  }
}

if (!function_exists('tickex_staff_roles_get_map')) {
  function tickex_staff_roles_get_map($pdo, $ownerAdminId)
  {
    $all = tickex_staff_roles_get_all($pdo, $ownerAdminId);
    $map = array();
    foreach ($all as $r) {
      $code = isset($r['code']) ? (string)$r['code'] : '';
      if ($code !== '') $map[$code] = $r;
    }
    return $map;
  }
}

if (!function_exists('tickex_staff_role_permissions')) {
  function tickex_staff_role_permissions($pdo, $ownerAdminId, $roleCode)
  {
    $code = trim((string)$roleCode);
    if ($code === '') $code = 'puerta';
    $map = tickex_staff_roles_get_map($pdo, (int)$ownerAdminId);
    if (isset($map[$code]) && isset($map[$code]['permissions']) && is_array($map[$code]['permissions'])) {
      return $map[$code]['permissions'];
    }

    $defaults = tickex_staff_roles_default_definitions();
    foreach ($defaults as $d) {
      if (isset($d['code']) && (string)$d['code'] === $code) {
        return isset($d['permissions']) && is_array($d['permissions']) ? $d['permissions'] : array();
      }
    }
    return array('dashboard_view');
  }
}

if (!function_exists('tickex_staff_role_label')) {
  function tickex_staff_role_label($pdo, $ownerAdminId, $roleCode)
  {
    $code = trim((string)$roleCode);
    if ($code === '') return 'Puerta';
    $map = tickex_staff_roles_get_map($pdo, (int)$ownerAdminId);
    if (isset($map[$code]) && !empty($map[$code]['name'])) {
      return (string)$map[$code]['name'];
    }

    $defaults = tickex_staff_roles_default_definitions();
    foreach ($defaults as $d) {
      if (isset($d['code']) && (string)$d['code'] === $code) {
        return isset($d['name']) ? (string)$d['name'] : $code;
      }
    }
    return $code;
  }
}
