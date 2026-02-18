<?php
// inc/db.php (PHP5-safe)
function db(){
    static $pdo = null;
    if ($pdo) return $pdo;

    $dbFile = __DIR__ . '/../save_the_rave.sqlite';
    if (!file_exists($dbFile)) {
        die("Base no encontrada: ".$dbFile);
    }

    try {
        $pdo = new PDO('sqlite:' . $dbFile);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    } catch (Exception $ex) {
        die("Error DB: " . htmlspecialchars($ex->getMessage(), ENT_QUOTES, 'UTF-8'));
    }

    return $pdo;
}
