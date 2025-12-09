<?php
$file = __DIR__ . '/puerta.php';
$code = file_get_contents($file);

$search = <<<'SEARCH'
$dbFile = __DIR__ . '/save_the_rave.sqlite';
$pdo = new PDO('sqlite:' . $dbFile);
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

// ===== Filtros =====
SEARCH;

$replace = <<<'REPLACE'
$dbFile = __DIR__ . '/save_the_rave.sqlite';
$pdo = new PDO('sqlite:' . $dbFile);
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

// ==== UNDO CHECK-IN DESDE PUERTA ====
if (isset($_GET['undo'])) {
    $id = (int)$_GET['undo'];
    if ($id > 0) {
        $stmtUndo = $pdo->prepare("
            UPDATE entradas
            SET checked_in = 0,
                checked_in_at = NULL
            WHERE id = :id
        ");
        $stmtUndo->execute([':id' => $id]);
    }
    header('Location: puerta.php');
    exit;
}

// ===== Filtros =====
REPLACE;

if (strpos($code, $search) === false) {
    fwrite(STDERR, "No se encontró el bloque de conexión esperado en puerta.php\n");
    exit(1);
}

$code = str_replace($search, $replace, $code);
file_put_contents($file, $code);
echo "Bloque de UNDO agregado dentro de puerta.php.\n";
