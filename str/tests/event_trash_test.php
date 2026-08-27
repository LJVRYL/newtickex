<?php
require_once __DIR__ . '/../inc/event_trash.php';

$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->exec('PRAGMA foreign_keys = ON');
$pdo->exec('CREATE TABLE eventos (id INTEGER PRIMARY KEY, nombre TEXT NOT NULL)');
$pdo->exec('CREATE TABLE entradas (id INTEGER PRIMARY KEY, evento_id INTEGER NOT NULL, FOREIGN KEY(evento_id) REFERENCES eventos(id))');
$pdo->exec("INSERT INTO eventos (id,nombre) VALUES (16,'Evento de prueba')");
$pdo->exec('INSERT INTO entradas (id,evento_id) VALUES (1,16)');

function trash_assert($condition, $message)
{
    if (!$condition) {
        fwrite(STDERR, 'FAIL: ' . $message . PHP_EOL);
        exit(1);
    }
    echo 'PASS: ' . $message . PHP_EOL;
}

trash_assert(tickex_event_soft_delete($pdo, 16), 'event with sold entries moves to trash');
trash_assert((int)$pdo->query('SELECT COUNT(*) FROM eventos WHERE id=16')->fetchColumn() === 1, 'event row is preserved');
trash_assert((int)$pdo->query('SELECT COUNT(*) FROM entradas WHERE evento_id=16')->fetchColumn() === 1, 'sold entries are preserved');
trash_assert((string)$pdo->query('SELECT borrado_en FROM eventos WHERE id=16')->fetchColumn() !== '', 'trash timestamp is stored');
trash_assert(tickex_event_restore($pdo, 16), 'event can be restored');
trash_assert($pdo->query('SELECT borrado_en FROM eventos WHERE id=16')->fetchColumn() === null, 'restored event is active');
