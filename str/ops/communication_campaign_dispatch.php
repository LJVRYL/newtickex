<?php
require_once __DIR__ . '/../inc/bootstrap.php';
require_once __DIR__ . '/../inc/communication_execution_engine.php';
require_once __DIR__ . '/../inc/communication_ops.php';
require_once __DIR__ . '/../inc/communication_contacts.php';

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

$provided = isset($_POST['csrf']) ? (string)$_POST['csrf'] : '';
if (function_exists('tickex_csrf_verify') && !tickex_csrf_verify($provided)) {
    http_response_code(400);
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode(array('ok' => false, 'error' => 'CSRF invalido.'), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

$campaignId = isset($_POST['campaign_id']) ? (int)$_POST['campaign_id'] : 0;
$mode = isset($_POST['mode']) ? trim((string)$_POST['mode']) : 'enqueue';
$organizationId = 1;
$adminId = 0;
if (isset($_SESSION['admin_id'])) $adminId = (int)$_SESSION['admin_id'];
elseif (isset($_SESSION['user_id'])) $adminId = (int)$_SESSION['user_id'];
elseif (isset($_SESSION['usuario_id'])) $adminId = (int)$_SESSION['usuario_id'];

$pdo = db();
communication_campaigns_ensure_schema($pdo);
communication_execution_ensure_schema($pdo);
communication_ops_ensure_schema($pdo);

$scopeSql = communication_campaigns_scope_sql($isSuper);
$scopeParams = communication_campaigns_scope_params($organizationId, $adminId, $isSuper);
$stCamp = $pdo->prepare('SELECT * FROM communication_campaigns WHERE id = :id AND ' . $scopeSql . ' LIMIT 1');
$stCamp->execute(array(':id' => $campaignId) + $scopeParams);
$campaign = $stCamp->fetch(PDO::FETCH_ASSOC);

if (!$campaign) {
    http_response_code(404);
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode(array('ok' => false, 'error' => 'Campana no encontrada o sin acceso.'), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if ($mode === 'estimate') {
    $audienceId = isset($campaign['audience_id']) ? (int)$campaign['audience_id'] : 0;
    $audience = communication_campaigns_find_audience($pdo, $organizationId, $adminId, $isSuper, $audienceId);
    if (!$audience) {
        http_response_code(400);
        header('Content-Type: application/json; charset=UTF-8');
        echo json_encode(array('ok' => false, 'error' => 'La campana no tiene una audiencia valida.'), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    $filters = communication_contacts_filters_from_json(isset($audience['filters_json']) ? $audience['filters_json'] : '');
    $contactScope = array(
        'is_super' => $isSuper,
        'admin_id' => $adminId,
    );
    $estimatedRecipients = communication_contacts_count($pdo, $filters, $contactScope);

    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode(array(
        'ok' => true,
        'mode' => 'estimate',
        'campaign_id' => $campaignId,
        'campaign_name' => isset($campaign['name']) ? (string)$campaign['name'] : '',
        'estimated_recipients' => (int)$estimatedRecipients,
    ), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

$enqueue = communication_execution_enqueue_campaign($pdo, $organizationId, $campaignId, $adminId, $isSuper, array());
if (empty($enqueue['ok'])) {
    http_response_code(400);
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode(array('ok' => false, 'error' => isset($enqueue['error']) ? (string)$enqueue['error'] : 'No se pudo encolar la campana.'), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

communication_ops_log($pdo, $organizationId, 'campaigns', 'campaign.dispatch_requested', 'info', 'Campana encolada desde el orquestador AJAX.', array(
    'campaign_id' => $campaignId,
    'command_id' => (int)$enqueue['command_id'],
    'requested_by_admin_id' => $adminId,
), 'campaign.dispatch_requested|' . (int)$campaignId . '|' . (int)$enqueue['command_id']);

header('Content-Type: application/json; charset=UTF-8');
echo json_encode(array(
    'ok' => true,
    'campaign_id' => $campaignId,
    'command_id' => (int)$enqueue['command_id'],
    'request_key' => isset($enqueue['request_key']) ? (string)$enqueue['request_key'] : null,
), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
