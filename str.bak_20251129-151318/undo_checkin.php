<?php
session_start();

// Sólo admin o puerta pueden deshacer check-in
if (!isset($_SESSION['rol']) || !in_array($_SESSION['rol'], ['admin', 'puerta'])) {
    header('Location: admin.php');
    exit;
}

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($id <= 0) {
    // ID inválido, simplemente volvemos según rol
    if ($_SESSION['rol'] === 'puerta') {
        header('Location: puerta.php');
    } else {
        header('Location: admin.php');
    }
    exit;
}

$dbFile = __DIR__ . '/save_the_rave.sqlite';

try {
    $pdo = new PDO('sqlite:' . $dbFile);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    $stmtUndo = $pdo->prepare("
        UPDATE entradas
        SET checked_in = 0,
            checked_in_at = NULL
        WHERE id = :id
    ");
    $stmtUndo->execute([':id' => $id]);

} catch (Throwable $e) {
    // Si algo falla, podés loguearlo si querés
    // Por simplicidad, igual redirigimos
}

// Volver al panel correspondiente
if ($_SESSION['rol'] === 'puerta') {
    header('Location: puerta.php');
} else {
    header('Location: admin.php');
}
exit;
