<?php
require_once __DIR__ . '/inc/bootstrap.php';
require_once __DIR__ . '/inc/unified_tickets.php';

header('Content-Type: application/json; charset=utf-8');
require_login();
$cu = current_user();
$role = isset($cu['tipo_global']) ? (string)$cu['tipo_global'] : '';
$eventRole = isset($cu['rol_evento']) ? (string)$cu['rol_evento'] : '';
$allowed = ($role === 'staff_evento' && $eventRole === 'puerta')
    || in_array($role, array('admin_evento', 'super_admin', 'superadmin'), true);
if (!$allowed) {
    http_response_code(403);
    echo json_encode(array('error' => 'No autorizado'));
    exit;
}

$pdo = db();
$action = isset($_POST['action']) ? (string)$_POST['action'] : (isset($_GET['action']) ? (string)$_GET['action'] : '');
$eventoId = isset($_SESSION['evento_id']) ? (int)$_SESSION['evento_id'] : 0;
if ($eventoId <= 0 || !tickex_can_access_event($pdo, $eventoId, $cu)) {
    http_response_code(404);
    echo json_encode(array('error' => 'Evento no encontrado'));
    exit;
}

if ($action === 'buscar') {
    $q = trim(isset($_POST['q']) ? (string)$_POST['q'] : (isset($_GET['q']) ? (string)$_GET['q'] : ''));
    $entradas = get_unified_entries($pdo, $eventoId, array('q' => $q));
    $resultados = array();
    foreach ($entradas as $e) {
        $resultados[] = array(
            'id' => $e['ticket_id'],
            'nombre' => $e['nombre'],
            'entrada' => $e['tipo'],
            'checkin' => $e['is_checked_in'],
        );
    }
    echo json_encode(array('resultados' => $resultados));
    exit;
}


if ($action === 'checkin') {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !tickex_csrf_verify(isset($_POST['_csrf']) ? (string)$_POST['_csrf'] : '')) {
        http_response_code(403);
        echo json_encode(array('error' => 'Solicitud vencida o inválida'));
        exit;
    }
    $id = isset($_POST['id']) ? (int)$_POST['id'] : 0;
    if ($id > 0) {
        // Solo STR: marcar checked_in=1 y normalizar tickets ocultos
        $colCheck = get_checkin_column($pdo);
        $stmt = $pdo->prepare("UPDATE entradas SET $colCheck = 1, checked_in_at = datetime('now'), oculto = 0 WHERE id = :id AND evento_id = :eid");
        $stmt->execute([':id' => $id, ':eid' => $eventoId]);
        echo json_encode(array('success' => $stmt->rowCount() === 1));
        exit;
    }
    echo json_encode(array('success' => false));
    exit;
}


if ($action === 'contadores') {
    $checkin_count = 0;
    $ventas_count = 0;
    if ($eventoId > 0) {
        $entradas = get_unified_entries($pdo, $eventoId);
        foreach ($entradas as $e) {
            if ($e['is_checked_in']) $checkin_count++;
            $ventas_count++;
        }
    }
    echo json_encode(array(
        'checkin_count' => $checkin_count,
        'ventas_count' => $ventas_count
    ));
    exit;
}

http_response_code(400);
echo json_encode(array('error' => 'Acción no válida'));
