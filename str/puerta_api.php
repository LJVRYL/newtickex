<?php
// API para dashboard de staff puerta (búsqueda, check-in, QR)
require_once __DIR__ . '/inc/auth.php';
header('Content-Type: application/json');

$cu = current_user();
if (!isset($cu['tipo_global']) || $cu['tipo_global'] !== 'puerta') {
    http_response_code(403);
    echo json_encode(['error' => 'No autorizado']);
    exit;
}

$action = $_POST['action'] ?? $_GET['action'] ?? '';


require_once __DIR__ . '/inc/bootstrap.php';
require_once __DIR__ . '/inc/unified_tickets.php';
$pdo = db();

if ($action === 'buscar') {
    $q = trim($_POST['q'] ?? $_GET['q'] ?? '');
    $eventoId = isset($_SESSION['evento_id']) ? (int)$_SESSION['evento_id'] : 0;
    if ($eventoId <= 0) {
        echo json_encode(['resultados' => []]);
        exit;
    }
    $entradas = get_unified_entries($pdo, $eventoId, ['q' => $q]);
    $resultados = [];
    foreach ($entradas as $e) {
        $resultados[] = [
            'id' => $e['ticket_id'],
            'nombre' => $e['nombre'],
            'entrada' => $e['tipo'],
            'checkin' => $e['is_checked_in'],
        ];
    }
    echo json_encode(['resultados' => $resultados]);
    exit;
}


if ($action === 'checkin') {
    $id = intval($_POST['id'] ?? 0);
    $eventoId = isset($_SESSION['evento_id']) ? (int)$_SESSION['evento_id'] : 0;
    if ($id > 0 && $eventoId > 0) {
        // Solo STR: marcar checked_in=1 y normalizar tickets ocultos
        $colCheck = get_checkin_column($pdo);
        $stmt = $pdo->prepare("UPDATE entradas SET $colCheck = 1, checked_in_at = datetime('now'), oculto = 0 WHERE id = :id AND evento_id = :eid");
        $stmt->execute([':id' => $id, ':eid' => $eventoId]);
        echo json_encode(['success' => true]);
        exit;
    }
    echo json_encode(['success' => false]);
    exit;
}


if ($action === 'contadores') {
    $eventoId = isset($_SESSION['evento_id']) ? (int)$_SESSION['evento_id'] : 0;
    $checkin_count = 0;
    $ventas_count = 0;
    if ($eventoId > 0) {
        $entradas = get_unified_entries($pdo, $eventoId);
        foreach ($entradas as $e) {
            if ($e['is_checked_in']) $checkin_count++;
            $ventas_count++;
        }
    }
    echo json_encode([
        'checkin_count' => $checkin_count,
        'ventas_count' => $ventas_count
    ]);
    exit;
}

http_response_code(400);
echo json_encode(['error' => 'Acción no válida']);
