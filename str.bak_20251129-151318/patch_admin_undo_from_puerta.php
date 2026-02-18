<?php
$file = __DIR__ . '/admin.php';
$code = file_get_contents($file);

$search = <<<'SEARCH'
// Deshacer check-in ?undo=ID
if (isset($_GET['undo'])) {
    $id = (int) $_GET['undo'];
    if ($id > 0) {
        $stmtUndo = $pdo->prepare("
            UPDATE entradas
            SET checked_in = 0,
                checked_in_at = NULL
            WHERE id = :id
        ");
        $stmtUndo->execute([':id' => $id]);
    }
    header('Location: admin.php');
    exit;
}
SEARCH;

$replace = <<<'REPLACE'
// Deshacer check-in ?undo=ID
if (isset($_GET['undo'])) {
    $id   = (int) $_GET['undo'];
    $from = isset($_GET['from']) ? $_GET['from'] : '';

    if ($id > 0) {
        $stmtUndo = $pdo->prepare("
            UPDATE entradas
            SET checked_in = 0,
                checked_in_at = NULL
            WHERE id = :id
        ");
        $stmtUndo->execute([':id' => $id]);
    }

    if ($from === 'puerta') {
        header('Location: puerta.php');
    } else {
        header('Location: admin.php');
    }
    exit;
}
REPLACE;

if (strpos($code, $search) === false) {
    fwrite(STDERR, "No se encontró el bloque de undo esperado en admin.php\n");
    exit(1);
}

$code = str_replace($search, $replace, $code);
file_put_contents($file, $code);
echo "Undo en admin.php actualizado para soportar from=puerta.\n";
