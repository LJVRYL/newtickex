<?php
// Script rápido para crear/actualizar un sitio demo para admin_id = 1

$dbFile = __DIR__ . '/save_the_rave.sqlite';

if (!file_exists($dbFile)) {
    die("ERROR: No se encontró la base de datos en: " . $dbFile . PHP_EOL);
}

$dsn = 'sqlite:' . $dbFile;

try {
    $pdo = new PDO($dsn);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    $admin_id     = 1; // asumimos que tu usuario principal es ID 1
    $slug_publico = 'save-the-rave';
    $nombre       = 'Save The Rave';
    $texto_hero   = 'Entradas oficiales para Save The Rave';
    $texto_intro  = 'Encontrá acá todas las fechas disponibles.';
    $visible      = 1;
    $now          = date('c');

    // ¿Ya existe un registro para este admin?
    $stmt = $pdo->prepare('SELECT id FROM clientes_sites WHERE admin_id = :admin_id LIMIT 1');
    $stmt->execute(array(':admin_id' => $admin_id));
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($row) {
        $stmt = $pdo->prepare('
            UPDATE clientes_sites
            SET slug_publico = :slug_publico,
                nombre_publico = :nombre_publico,
                texto_hero = :texto_hero,
                texto_intro = :texto_intro,
                visible = :visible,
                updated_at = :updated_at
            WHERE admin_id = :admin_id
        ');
        $stmt->execute(array(
            ':slug_publico'   => $slug_publico,
            ':nombre_publico' => $nombre,
            ':texto_hero'     => $texto_hero,
            ':texto_intro'    => $texto_intro,
            ':visible'        => $visible,
            ':updated_at'     => $now,
            ':admin_id'       => $admin_id,
        ));
        echo "OK: Sitio demo ACTUALIZADO para admin_id={$admin_id}\n";
    } else {
        $stmt = $pdo->prepare('
            INSERT INTO clientes_sites
                (admin_id, slug_publico, nombre_publico, texto_hero, texto_intro, visible, created_at, updated_at)
            VALUES
                (:admin_id, :slug_publico, :nombre_publico, :texto_hero, :texto_intro, :visible, :created_at, :updated_at)
        ');
        $stmt->execute(array(
            ':admin_id'       => $admin_id,
            ':slug_publico'   => $slug_publico,
            ':nombre_publico' => $nombre,
            ':texto_hero'     => $texto_hero,
            ':texto_intro'    => $texto_intro,
            ':visible'        => $visible,
            ':created_at'     => $now,
            ':updated_at'     => $now,
        ));
        echo "OK: Sitio demo CREADO para admin_id={$admin_id}\n";
    }

} catch (Exception $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
    exit(1);
}
