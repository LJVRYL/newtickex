<?php
require_once __DIR__ . '/organizer_site.php';

function tickex_mobile_admin_site($pdo, $admin)
{
    if (!in_array(isset($admin['tipo_global']) ? $admin['tipo_global'] : '', array('admin_evento', 'super_admin'), true)) return array('error' => 'forbidden');
    $adminId = (int)$admin['id'];
    if ($adminId <= 0) return array('error' => 'forbidden');
    $table = $pdo->query("SELECT 1 FROM sqlite_master WHERE type='table' AND name='clientes_sites' LIMIT 1")->fetchColumn();
    if (!$table) $row = array();
    else {
        $read = $pdo->prepare('SELECT * FROM clientes_sites WHERE admin_id=:admin LIMIT 1');
        $read->execute(array(':admin'=>$adminId));
        $row = $read->fetch(PDO::FETCH_ASSOC) ?: array();
    }
    $site = array_merge(tickex_organizer_site_defaults(), $row);
    return array(
        'slug' => (string)$site['slug_publico'], 'name' => (string)$site['nombre_publico'],
        'hero' => (string)$site['texto_hero'], 'intro' => (string)$site['texto_intro'],
        'visible' => (int)$site['visible'] === 1,
        'whatsapp' => (string)$site['whatsapp'], 'instagramUrl' => (string)$site['instagram_url'],
        'tiktokUrl' => (string)$site['tiktok_url'], 'facebookUrl' => (string)$site['facebook_url'],
        'youtubeUrl' => (string)$site['youtube_url'],
        'primaryColor' => (string)$site['primary_color'], 'accentColor' => (string)$site['accent_color'],
        'backgroundColor' => (string)$site['background_color'], 'logoUrl' => (string)$site['logo_url'],
        'customDomain' => (string)$site['custom_domain'], 'customDomainStatus' => (string)$site['custom_domain_status'],
        'publicUrl' => tickex_organizer_site_public_url($site, true),
        'updatedAt' => isset($row['updated_at']) ? (string)$row['updated_at'] : null,
    );
}

function tickex_mobile_admin_site_save($pdo, $admin, $input)
{
    if (!is_array($input)) return array('error'=>'invalid_body');
    $section = isset($input['section']) ? (string)$input['section'] : '';
    $fields = array(
        'identity'=>array('slug'=>'slug_publico','name'=>'nombre_publico','hero'=>'texto_hero','intro'=>'texto_intro','visible'=>'visible'),
        'social'=>array('whatsapp'=>'whatsapp','instagramUrl'=>'instagram_url','tiktokUrl'=>'tiktok_url','facebookUrl'=>'facebook_url','youtubeUrl'=>'youtube_url'),
        'brand'=>array('primaryColor'=>'primary_color','accentColor'=>'accent_color','backgroundColor'=>'background_color','logoUrl'=>'logo_url'),
    );
    if (!isset($fields[$section])) return array('error'=>'invalid_section');
    foreach ($input as $key=>$value) if ($key !== 'section' && $key !== 'expectedUpdatedAt' && !isset($fields[$section][$key])) return array('error'=>'invalid_field');
    if (!array_key_exists('expectedUpdatedAt', $input) || ($input['expectedUpdatedAt'] !== null && !is_string($input['expectedUpdatedAt']))) return array('error'=>'expected_version_required');
    $current = tickex_mobile_admin_site($pdo, $admin);
    if (isset($current['error'])) return $current;
    if ($current['updatedAt'] !== $input['expectedUpdatedAt']) return array('error'=>'conflict');
    if ($section !== 'identity' && $current['slug'] === '') return array('error'=>'identity_required');
    $changes = array();
    foreach ($fields[$section] as $key=>$column) {
        if (!array_key_exists($key, $input)) continue;
        $value = $input[$key];
        if ($key === 'visible') {
            if (!is_bool($value)) return array('error'=>'invalid_visible');
            $changes[$column] = $value ? 1 : 0;
            continue;
        }
        if (!is_string($value)) return array('error'=>'invalid_field');
        $value = trim($value);
        if (strlen($value) > ($key === 'hero' || $key === 'intro' ? 2000 : 500)) return array('error'=>'value_too_long');
        if ($key === 'slug') {
            $value = tickex_organizer_site_slug($value);
            if ($value === '' || strlen($value) > 80) return array('error'=>'invalid_slug');
        } elseif ($key === 'name' && $value === '') return array('error'=>'invalid_name');
        elseif (in_array($key, array('primaryColor','accentColor','backgroundColor'), true)) {
            if (!preg_match('/^#[0-9a-fA-F]{6}$/', $value)) return array('error'=>'invalid_color');
            $value = strtolower($value);
        } elseif ($key === 'logoUrl') {
            try { $value = tickex_organizer_site_asset_url($value); } catch (Exception $e) { return array('error'=>'invalid_logo_url'); }
        } elseif (substr($key, -3) === 'Url' && $value !== '' && !preg_match('~^https://[^\s<>]+$~i', $value)) return array('error'=>'invalid_social_url');
        elseif ($key === 'whatsapp' && $value !== '' && !preg_match('~^(?:\+?[0-9][0-9 ()-]{5,24}|https://wa\.me/[0-9]{7,20})$~i', $value)) return array('error'=>'invalid_whatsapp');
        $changes[$column] = $value;
    }
    if (!$changes) return array('error'=>'empty_update');
    $adminId = (int)$admin['id'];
    try {
        tickex_organizer_site_ensure_schema($pdo);
        $pdo->beginTransaction();
        $read = $pdo->prepare('SELECT * FROM clientes_sites WHERE admin_id=:admin LIMIT 1');
        $read->execute(array(':admin'=>$adminId));
        $row = $read->fetch(PDO::FETCH_ASSOC);
        $version = $row ? (string)$row['updated_at'] : null;
        if ($version !== $input['expectedUpdatedAt']) { $pdo->rollBack(); return array('error'=>'conflict'); }
        if (!$row) {
            if ($section !== 'identity' || !isset($changes['slug_publico']) || !isset($changes['nombre_publico'])) { $pdo->rollBack(); return array('error'=>'identity_required'); }
            $changes['admin_id'] = $adminId;
            $changes['created_at'] = date('c');
        } else {
            if ($section === 'identity' && (isset($changes['slug_publico']) ? $changes['slug_publico'] : $row['slug_publico']) === '') { $pdo->rollBack(); return array('error'=>'invalid_slug'); }
        }
        $changes['updated_at'] = date('c') . '-' . bin2hex(function_exists('random_bytes') ? random_bytes(4) : openssl_random_pseudo_bytes(4));
        $params = array();
        foreach ($changes as $column=>$value) $params[':'.$column] = $value;
        if ($row) {
            $sets = array(); foreach ($changes as $column=>$value) $sets[] = $column . '=:' . $column;
            $params[':admin'] = $adminId;
            $statement = $pdo->prepare('UPDATE clientes_sites SET ' . implode(',', $sets) . ' WHERE admin_id=:admin');
        } else {
            $columns = array_keys($changes);
            $statement = $pdo->prepare('INSERT INTO clientes_sites (' . implode(',', $columns) . ') VALUES (:' . implode(',:', $columns) . ')');
        }
        $statement->execute($params);
        $pdo->commit();
    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        if (strpos($e->getMessage(), 'UNIQUE constraint failed: clientes_sites.slug_publico') !== false) return array('error'=>'slug_taken');
        throw $e;
    }
    return tickex_mobile_admin_site($pdo, $admin);
}
