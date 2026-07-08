<?php
require_once __DIR__ . '/../inc/bootstrap.php';
require_once __DIR__ . '/../inc/communication_execution_engine.php';
require_once __DIR__ . '/../inc/communication_ops.php';

require_login();
$cu = current_user();
$tipoGlobal = isset($cu['tipo_global']) ? (string)$cu['tipo_global'] : (isset($_SESSION['tipo_global']) ? (string)$_SESSION['tipo_global'] : '');
$isSuper = in_array($tipoGlobal, array('super_admin', 'superadmin'), true);
$isAllowed = (is_admin() && ($isSuper || $tipoGlobal === 'admin_evento'));

if (!$isAllowed) {
    http_response_code(403);
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode(array('ok' => false, 'error' => 'Acceso restringido.'), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

$campaignId = isset($_GET['campaign_id']) ? (int)$_GET['campaign_id'] : 0;
$commandId = isset($_GET['command_id']) ? (int)$_GET['command_id'] : 0;
$organizationId = 1;
$adminId = 0;
if (isset($_SESSION['admin_id'])) $adminId = (int)$_SESSION['admin_id'];
elseif (isset($_SESSION['user_id'])) $adminId = (int)$_SESSION['user_id'];
elseif (isset($_SESSION['usuario_id'])) $adminId = (int)$_SESSION['usuario_id'];

$pdo = db();
communication_execution_ensure_schema($pdo);
communication_ops_ensure_schema($pdo);

$history = array('campaign' => null, 'runs' => array());
if ($campaignId > 0) {
    $history = communication_ops_fetch_run_history($pdo, $organizationId, $adminId, $isSuper, $campaignId);
}

$latestRun = null;
if (!empty($history['runs'])) {
    $latestRun = $history['runs'][0];
}

$commandStatus = null;
if ($commandId > 0) {
    $st = $pdo->prepare('SELECT id, campaign_id, status, attempt_count, error_text, result_json, updated_at, created_at FROM communication_execution_commands WHERE id = :id LIMIT 1');
    $st->execute(array(':id' => $commandId));
    $commandStatus = $st->fetch(PDO::FETCH_ASSOC);
}

$state = communication_ops_fetch_engine_state($pdo, $organizationId, $adminId, $isSuper);

header('Content-Type: application/json; charset=UTF-8');
echo json_encode(array(
    'ok' => true,
    'campaign_id' => $campaignId,
    'command_id' => $commandId,
    'command' => $commandStatus,
    'latest_run' => $latestRun,
    'runs' => $history['runs'],
    'engine_state' => $state,
), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
