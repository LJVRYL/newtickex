<?php
require_once __DIR__ . '/inc/bootstrap.php';
require_login();
$cu = current_user();
$pdo = db();

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($id <= 0) {
    http_response_code(400);
    echo 'ID inválido.';
    exit;
}

$csrf = isset($_GET['csrf']) ? (string)$_GET['csrf'] : '';
if (!tickex_csrf_verify($csrf)) { http_response_code(403); exit('Accion bloqueada'); }
$stEntry = $pdo->prepare('SELECT evento_id FROM entradas WHERE id=:id LIMIT 1');
$stEntry->execute(array(':id'=>$id));
$entryEventId = (int)$stEntry->fetchColumn();
tickex_require_event_access($pdo, $entryEventId, $cu);

// Borramos sólo esa fila
$stmt = $pdo->prepare("DELETE FROM entradas WHERE id = :id");
$stmt->execute([':id' => $id]);

// Volvemos al panel del evento si se proporcionó
$eventoId = $entryEventId;
$msg = urlencode('Entrada eliminada (ID ' . $id . ')');
if ($eventoId > 0) {
    header('Location: panel_evento.php?evento_id=' . $eventoId . '&msg=' . $msg);
} else {
    header('Location: admin.php?msg=' . $msg);
}
exit;
