<?php
$dbFile = __DIR__ . '/save_the_rave.sqlite';

if (!file_exists($dbFile)) {
    die('Base de datos no encontrada.');
}

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($id <= 0) {
    http_response_code(400);
    echo 'ID inválido.';
    exit;
}

try {
    $pdo = new PDO('sqlite:' . $dbFile);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (Exception $e) {
    http_response_code(500);
    echo 'Error al conectar a la base: ' . htmlspecialchars($e->getMessage());
    exit;
}

// Borramos sólo esa fila
$stmt = $pdo->prepare("DELETE FROM entradas WHERE id = :id");
$stmt->execute([':id' => $id]);

// Volvemos al panel del evento si se proporcionó
$eventoId = isset($_GET['evento_id']) ? (int)$_GET['evento_id'] : 0;
$msg = urlencode('Entrada eliminada (ID ' . $id . ')');
if ($eventoId > 0) {
    header('Location: panel_evento.php?evento_id=' . $eventoId . '&msg=' . $msg);
} else {
    header('Location: admin.php?msg=' . $msg);
}
exit;
