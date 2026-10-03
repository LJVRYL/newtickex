<?php
// Mobile API v1. Uses Tickex's existing SQLite data, without loading the mutating web bootstrap.
date_default_timezone_set('America/Argentina/Buenos_Aires');
require_once __DIR__ . '/../../../inc/mobile_events.php';
require_once __DIR__ . '/../../../inc/mobile_auth.php';
require_once __DIR__ . '/../../../inc/mobile_tickets.php';
require_once __DIR__ . '/../../../inc/mobile_orders.php';
require_once __DIR__ . '/../../../inc/mobile_staff.php';
require_once __DIR__ . '/../../../inc/mobile_staff_gate.php';
require_once __DIR__ . '/../../../inc/mobile_staff_invitations.php';
require_once __DIR__ . '/../../../inc/mobile_staff_invite_admin.php';
require_once __DIR__ . '/../../../inc/mobile_admin.php';
require_once __DIR__ . '/../../../inc/mobile_admin_free_checkout.php';
require_once __DIR__ . '/../../../inc/mobile_staff_operations.php';
require_once __DIR__ . '/../../../inc/mobile_event_presentation.php';
require_once __DIR__ . '/../../../inc/mobile_inventory.php';
require_once __DIR__ . '/../../../inc/mobile_communication_catalog.php';
require_once __DIR__ . '/../../../inc/mobile_communication_drafts.php';
require_once __DIR__ . '/../../../inc/mobile_communication_audiences.php';
require_once __DIR__ . '/../../../inc/mobile_communication_templates.php';
require_once __DIR__ . '/../../../inc/mobile_communication_history.php';
require_once __DIR__ . '/../../../inc/mobile_communication_actions.php';
require_once __DIR__ . '/../../../inc/mobile_admin_sales_detail.php';
require_once __DIR__ . '/../../../inc/billing_financials.php';
require_once __DIR__ . '/../../../inc/mobile_admin_billing.php';
require_once __DIR__ . '/../../../inc/unified_tickets.php';
require_once __DIR__ . '/../../../inc/mobile_admin_clients.php';
require_once __DIR__ . '/../../../inc/mobile_admin_economy.php';
require_once __DIR__ . '/../../../inc/mobile_admin_site.php';
require_once __DIR__ . '/../../../inc/mobile_admin_support.php';
require_once __DIR__ . '/../../../inc/mobile_admin_account.php';
require_once __DIR__ . '/../../../inc/mobile_admin_payments.php';
require_once __DIR__ . '/../../../inc/mobile_checkout_intents.php';
require_once __DIR__ . '/../../../inc/mobile_door_checkout.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
$requestId = '';
try {
    $requestBytes = false;
    if (function_exists('random_bytes')) $requestBytes = random_bytes(8);
    elseif (function_exists('openssl_random_pseudo_bytes')) $requestBytes = openssl_random_pseudo_bytes(8);
    if ($requestBytes !== false && strlen($requestBytes) === 8) {
        $requestId = bin2hex($requestBytes);
        header('X-Request-ID: ' . $requestId);
    }
} catch (Exception $ignored) {}

function tickex_mobile_response($code, $payload)
{
    http_response_code($code);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function tickex_mobile_body()
{
    $contentType = isset($_SERVER['CONTENT_TYPE']) ? (string)$_SERVER['CONTENT_TYPE'] : '';
    if (stripos($contentType, 'application/json') !== 0) tickex_mobile_response(415, array('error' => 'json_required'));
    $raw = file_get_contents('php://input', false, null, 0, 8193);
    if (strlen($raw) > 8192) tickex_mobile_response(413, array('error' => 'request_too_large'));
    $data = json_decode($raw, true);
    if (!is_array($data)) tickex_mobile_response(400, array('error' => 'invalid_json'));
    return $data;
}

$path = parse_url(isset($_SERVER['REQUEST_URI']) ? $_SERVER['REQUEST_URI'] : '', PHP_URL_PATH);
$prefix = '/api/mobile/v1';
if (strpos((string)$path, $prefix . '/') !== 0) tickex_mobile_response(404, array('error' => 'not_found'));
$route = substr($path, strlen($prefix));
$method = isset($_SERVER['REQUEST_METHOD']) ? $_SERVER['REQUEST_METHOD'] : 'GET';
$publicEvents = $method === 'GET' && ($route === '/events' || preg_match('~^/events/[1-9][0-9]*/?$~', $route));
$login = $method === 'POST' && $route === '/auth/login';
$adminLogin = $method === 'POST' && $route === '/auth/admin-login';
$adminRoute = strpos($route, '/admin/') === 0;
$privateRoute = in_array($route, array('/auth/logout', '/me', '/me/tickets', '/me/orders', '/me/staff-invitations', '/staff/events', '/checkout-intents'), true) || preg_match('~^/me/tickets/[1-9][0-9]*/?$~', $route) || preg_match('~^/me/staff-invitations/[1-9][0-9]*/decision/?$~', $route) || preg_match('~^/orders/[1-9][0-9]*/?$~', $route) || preg_match('~^/checkout-intents/mobile-[a-f0-9]{32}/?$~', $route) || preg_match('~^/staff/events/[1-9][0-9]*/(stats|search|activity|door-types|door-checkouts)/?$~', $route) || preg_match('~^/staff/events/[1-9][0-9]*/door-checkouts/door-[a-f0-9]{32}/?$~', $route) || in_array($route, array('/staff/check-in', '/staff/check-in/undo'), true);
if (!$publicEvents && !$login && !$adminLogin && !$adminRoute && !$privateRoute) tickex_mobile_response(404, array('error' => 'not_found'));

$dbFile = getenv('TICKEX_DB_FILE');
if (!is_string($dbFile) || trim($dbFile) === '') $dbFile = __DIR__ . '/../../../save_the_rave.sqlite';
if (!is_file($dbFile)) tickex_mobile_response(503, array('error' => 'temporarily_unavailable'));

try {
    $pdo = new PDO('sqlite:' . $dbFile);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec('PRAGMA busy_timeout = 5000');
    if ($publicEvents) {
        $pdo->exec('PRAGMA query_only = ON');
        $id = preg_match('~^/events/([1-9][0-9]*)/?$~', $route, $match) ? (int)$match[1] : null;
        $events = tickex_mobile_public_events($pdo, $id);
        if ($id !== null && count($events) === 0) tickex_mobile_response(404, array('error' => 'not_found'));
        $data = array();
        foreach ($events as $event) {
            $globalAvailable = tickex_mobile_global_available($pdo, $event);
            $projected = tickex_mobile_event($event, tickex_mobile_ticket_types($pdo, (int)$event['id'], $globalAvailable), tickex_mobile_free_offer($pdo, (int)$event['id'], $globalAvailable));
            if ($id === null) unset($projected['ticketTypes'], $projected['description']);
            $data[] = $projected;
        }
        tickex_mobile_response(200, $id === null ? $data : $data[0]);
    }
    if ($login) {
        $body = tickex_mobile_body();
        $session = tickex_mobile_login($pdo, isset($body['email']) ? $body['email'] : '', isset($body['password']) ? $body['password'] : '', isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : '');
        if (!$session) { error_log('mobile_api auth_failed request_id=' . $requestId); tickex_mobile_response(401, array('error' => 'invalid_credentials')); }
        tickex_mobile_response(200, $session);
    }
    if ($adminLogin) {
        $body = tickex_mobile_body();
        $session = tickex_mobile_admin_login($pdo, isset($body['email']) ? $body['email'] : '', isset($body['password']) ? $body['password'] : '', isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : '');
        if (!$session) { error_log('mobile_api admin_auth_failed request_id=' . $requestId); tickex_mobile_response(401, array('error' => 'invalid_credentials')); }
        tickex_mobile_response(200, $session);
    }
    if ($adminRoute) {
        $adminToken = tickex_mobile_bearer();
        $admin = tickex_mobile_authenticated_admin($pdo, $adminToken);
        if (!$admin) tickex_mobile_response(401, array('error' => 'unauthorized'));
        if ($route === '/admin/me' && $method === 'GET') tickex_mobile_response(200, tickex_mobile_admin_profile($admin));
        if ($route === '/admin/logout' && $method === 'POST') { tickex_mobile_admin_logout($pdo, $adminToken); tickex_mobile_response(200, array('ok' => true)); }
        if ($route === '/admin/account') {
            if ($method === 'GET') $result = tickex_mobile_admin_account($pdo, $admin);
            elseif ($method === 'PATCH') $result = tickex_mobile_admin_account_save($pdo, $admin, tickex_mobile_body());
            else tickex_mobile_response(405, array('error'=>'method_not_allowed'));
            if (isset($result['error'])) tickex_mobile_response($result['error'] === 'forbidden' ? 403 : ($result['error'] === 'not_found' ? 404 : (in_array($result['error'], array('conflict','email_taken','tickex_id_taken'), true) ? 409 : 422)), $result);
            tickex_mobile_response(200, $result);
        }
        if ($route === '/admin/plan') {
            if ($method !== 'GET') tickex_mobile_response(405, array('error'=>'method_not_allowed'));
            $result = tickex_mobile_admin_plan($pdo, $admin);
            if (isset($result['error'])) tickex_mobile_response(403, $result);
            tickex_mobile_response(200, $result);
        }
        if ($route === '/admin/payment-settings') {
            if ($method !== 'GET') tickex_mobile_response(405, array('error'=>'method_not_allowed'));
            $result = tickex_mobile_admin_payment_settings($pdo, $admin);
            if (isset($result['error'])) tickex_mobile_response(403, $result);
            tickex_mobile_response(200, $result);
        }
        if ($route === '/admin/payment-settings/connect') {
            if ($method !== 'POST') tickex_mobile_response(405, array('error'=>'method_not_allowed'));
            $result = tickex_mobile_admin_payment_connect($pdo, $admin);
            if (isset($result['error'])) tickex_mobile_response($result['error'] === 'forbidden' ? 403 : 503, $result);
            tickex_mobile_response(200, $result);
        }
        if ($route === '/admin/payment-settings/disconnect') {
            if ($method !== 'POST') tickex_mobile_response(405, array('error'=>'method_not_allowed'));
            $body = tickex_mobile_body();
            $result = tickex_mobile_admin_payment_disconnect($pdo, $admin, isset($body['confirmed']) ? $body['confirmed'] : false);
            if (isset($result['error'])) tickex_mobile_response($result['error'] === 'forbidden' ? 403 : ($result['error'] === 'confirmation_required' ? 422 : ($result['error'] === 'pending_payments' ? 409 : 503)), $result);
            tickex_mobile_response(200, $result);
        }
        if ($route === '/admin/site') {
            if ($method === 'GET') $result = tickex_mobile_admin_site($pdo, $admin);
            elseif ($method === 'PATCH') $result = tickex_mobile_admin_site_save($pdo, $admin, tickex_mobile_body());
            else tickex_mobile_response(405, array('error'=>'method_not_allowed'));
            if (isset($result['error'])) tickex_mobile_response($result['error'] === 'forbidden' ? 403 : (in_array($result['error'], array('conflict','slug_taken'), true) ? 409 : 422), $result);
            tickex_mobile_response(200, $result);
        }
        if ($route === '/admin/support/tickets') {
            if ($method === 'GET') $result = tickex_mobile_support_list($pdo, $admin, $_GET);
            elseif ($method === 'POST') $result = tickex_mobile_support_create($pdo, $admin, tickex_mobile_body());
            else tickex_mobile_response(405, array('error'=>'method_not_allowed'));
            if (isset($result['error'])) tickex_mobile_response($result['error'] === 'forbidden' ? 403 : 422, $result);
            tickex_mobile_response($method === 'POST' ? 201 : 200, $result);
        }
        if (preg_match('~^/admin/support/tickets/([1-9][0-9]*)/?$~', $route, $supportTicket)) {
            if ($method !== 'GET') tickex_mobile_response(405, array('error'=>'method_not_allowed'));
            $result = tickex_mobile_support_detail($pdo, $admin, (int)$supportTicket[1]);
            if (isset($result['error'])) tickex_mobile_response($result['error'] === 'not_found' ? 404 : 403, $result);
            tickex_mobile_response(200, $result);
        }
        if (preg_match('~^/admin/support/tickets/([1-9][0-9]*)/(reply|reopen)/?$~', $route, $supportAction)) {
            if ($method !== 'POST') tickex_mobile_response(405, array('error'=>'method_not_allowed'));
            $result = $supportAction[2] === 'reply' ? tickex_mobile_support_reply($pdo, $admin, (int)$supportAction[1], tickex_mobile_body()) : tickex_mobile_support_reopen($pdo, $admin, (int)$supportAction[1]);
            if (isset($result['error'])) tickex_mobile_response($result['error'] === 'not_found' ? 404 : ($result['error'] === 'forbidden' ? 403 : 422), $result);
            tickex_mobile_response(200, $result);
        }
        if (preg_match('~^/admin/communication/(audiences|templates|campaigns)/?$~', $route, $communicationMatch)) {
            if ($communicationMatch[1] === 'audiences' && $method === 'POST') {
                $audience = tickex_mobile_communication_audience_save($pdo, $admin, tickex_mobile_body(), 0);
                if (isset($audience['error'])) tickex_mobile_response($audience['error'] === 'forbidden' || $audience['error'] === 'event_forbidden' ? 403 : 422, $audience);
                tickex_mobile_response(201, $audience);
            }
            if ($communicationMatch[1] === 'templates' && $method === 'POST') {
                $template = tickex_mobile_communication_template_save($pdo, $admin, tickex_mobile_body(), 0);
                if (isset($template['error'])) tickex_mobile_response($template['error'] === 'forbidden' ? 403 : 422, $template);
                tickex_mobile_response(201, $template);
            }
            if ($method !== 'GET') tickex_mobile_response(405, array('error' => 'method_not_allowed'));
            $catalog = tickex_mobile_communication_catalog($pdo, $admin, $communicationMatch[1]);
            if (isset($catalog['error'])) tickex_mobile_response(403, $catalog);
            tickex_mobile_response(200, $catalog);
        }
        if (preg_match('~^/admin/communication/templates/([1-9][0-9]*)/?$~', $route, $templateEdit)) {
            if ($method !== 'PATCH') tickex_mobile_response(405, array('error' => 'method_not_allowed'));
            $template = tickex_mobile_communication_template_save($pdo, $admin, tickex_mobile_body(), (int)$templateEdit[1]);
            if (isset($template['error'])) tickex_mobile_response($template['error'] === 'not_found' ? 404 : ($template['error'] === 'forbidden' ? 403 : ($template['error'] === 'system_locked' ? 409 : 422)), $template);
            tickex_mobile_response(200, $template);
        }
        if (preg_match('~^/admin/communication/audiences/([1-9][0-9]*)/?$~', $route, $audienceEdit)) {
            if ($method !== 'PATCH') tickex_mobile_response(405, array('error' => 'method_not_allowed'));
            $audience = tickex_mobile_communication_audience_save($pdo, $admin, tickex_mobile_body(), (int)$audienceEdit[1]);
            if (isset($audience['error'])) tickex_mobile_response($audience['error'] === 'not_found' ? 404 : ($audience['error'] === 'forbidden' || $audience['error'] === 'event_forbidden' ? 403 : 422), $audience);
            tickex_mobile_response(200, $audience);
        }
        if (preg_match('~^/admin/communication/audiences/([1-9][0-9]*)/estimate/?$~', $route, $audienceEstimate)) {
            if ($method !== 'GET') tickex_mobile_response(405, array('error' => 'method_not_allowed'));
            $estimate = tickex_mobile_communication_audience_estimate($pdo, $admin, (int)$audienceEstimate[1]);
            if (isset($estimate['error'])) tickex_mobile_response($estimate['error'] === 'not_found' ? 404 : 403, $estimate);
            tickex_mobile_response(200, $estimate);
        }
        if ($route === '/admin/communication/campaigns/draft' && $method === 'POST') {
            $draft = tickex_mobile_communication_draft($pdo, $admin, tickex_mobile_body(), 0);
            if (isset($draft['error'])) tickex_mobile_response($draft['error'] === 'forbidden' ? 403 : 422, $draft);
            tickex_mobile_response(201, $draft);
        }
        if (preg_match('~^/admin/communication/campaigns/([1-9][0-9]*)/draft/?$~', $route, $campaignEdit)) {
            if ($method !== 'PATCH') tickex_mobile_response(405, array('error' => 'method_not_allowed'));
            $draft = tickex_mobile_communication_draft($pdo, $admin, tickex_mobile_body(), (int)$campaignEdit[1]);
            if (isset($draft['error'])) tickex_mobile_response($draft['error'] === 'forbidden' ? 403 : ($draft['error'] === 'not_found' ? 404 : ($draft['error'] === 'campaign_locked' ? 409 : 422)), $draft);
            tickex_mobile_response(200, $draft);
        }
        if (preg_match('~^/admin/communication/campaigns/([1-9][0-9]*)/preview/?$~', $route, $campaignPreview)) {
            if ($method !== 'GET') tickex_mobile_response(405, array('error' => 'method_not_allowed'));
            $preview = tickex_mobile_communication_preview($pdo, $admin, (int)$campaignPreview[1]);
            if (isset($preview['error'])) tickex_mobile_response($preview['error'] === 'forbidden' ? 403 : 404, $preview);
            tickex_mobile_response(200, $preview);
        }
        if (preg_match('~^/admin/communication/campaigns/([1-9][0-9]*)/history/?$~', $route, $campaignHistory)) {
            if ($method !== 'GET') tickex_mobile_response(405, array('error' => 'method_not_allowed'));
            $history = tickex_mobile_communication_history($pdo, $admin, (int)$campaignHistory[1]);
            if (isset($history['error'])) tickex_mobile_response($history['error'] === 'not_found' ? 404 : 403, $history);
            tickex_mobile_response(200, $history);
        }
        if (preg_match('~^/admin/communication/runs/([1-9][0-9]*)/?$~', $route, $communicationRun)) {
            if ($method !== 'GET') tickex_mobile_response(405, array('error' => 'method_not_allowed'));
            $limit = isset($_GET['limit']) && ctype_digit((string)$_GET['limit']) ? (int)$_GET['limit'] : 50;
            $offset = isset($_GET['offset']) && ctype_digit((string)$_GET['offset']) ? (int)$_GET['offset'] : 0;
            $detail = tickex_mobile_communication_run_detail($pdo, $admin, (int)$communicationRun[1], $limit, $offset);
            if (isset($detail['error'])) tickex_mobile_response($detail['error'] === 'not_found' ? 404 : 403, $detail);
            tickex_mobile_response(200, $detail);
        }
        if ($route === '/admin/communication/engine') {
            if ($method !== 'GET') tickex_mobile_response(405, array('error' => 'method_not_allowed'));
            $state = tickex_mobile_communication_engine_state($pdo, $admin);
            if (isset($state['error'])) tickex_mobile_response(403, $state);
            tickex_mobile_response(200, $state);
        }
        if (preg_match('~^/admin/communication/campaigns/([1-9][0-9]*)/queue/?$~', $route, $communicationQueue)) {
            if ($method !== 'POST') tickex_mobile_response(405, array('error' => 'method_not_allowed'));
            $body = tickex_mobile_body();
            $queued = tickex_mobile_communication_queue($pdo, $admin, (int)$communicationQueue[1], isset($body['confirmed']) ? $body['confirmed'] : false, isset($_SERVER['HTTP_IDEMPOTENCY_KEY']) ? $_SERVER['HTTP_IDEMPOTENCY_KEY'] : '');
            if (isset($queued['error'])) tickex_mobile_response($queued['error'] === 'forbidden' ? 403 : ($queued['error'] === 'not_found' ? 404 : (in_array($queued['error'], array('campaign_locked', 'queue_unavailable'), true) ? 409 : 422)), $queued);
            tickex_mobile_response(202, $queued);
        }
        if (preg_match('~^/admin/communication/runs/([1-9][0-9]*)/cancel/?$~', $route, $communicationCancel)) {
            if ($method !== 'POST') tickex_mobile_response(405, array('error' => 'method_not_allowed'));
            $body = tickex_mobile_body();
            $cancelled = tickex_mobile_communication_cancel_run($pdo, $admin, (int)$communicationCancel[1], isset($body['confirmed']) ? $body['confirmed'] : false);
            if (isset($cancelled['error'])) tickex_mobile_response($cancelled['error'] === 'forbidden' ? 403 : ($cancelled['error'] === 'not_found' ? 404 : (in_array($cancelled['error'], array('run_finalized', 'cancel_unavailable'), true) ? 409 : 422)), $cancelled);
            tickex_mobile_response(200, $cancelled);
        }
        if ($route === '/admin/inventory') {
            if ($method === 'GET') $inventory = tickex_mobile_inventory_list($pdo, $admin, isset($_GET['q']) ? $_GET['q'] : '', isset($_GET['status']) ? $_GET['status'] : '');
            elseif ($method === 'POST') $inventory = tickex_mobile_inventory_create($pdo, $admin, tickex_mobile_body());
            else tickex_mobile_response(405, array('error' => 'method_not_allowed'));
            if (isset($inventory['error'])) tickex_mobile_response($inventory['error'] === 'forbidden' ? 403 : 422, $inventory);
            tickex_mobile_response($method === 'POST' ? 201 : 200, $inventory);
        }
        if (preg_match('~^/admin/inventory/([1-9][0-9]*)/?$~', $route, $inventoryItem)) {
            if ($method !== 'GET') tickex_mobile_response(405, array('error' => 'method_not_allowed'));
            $inventory = tickex_mobile_inventory_detail($pdo, $admin, (int)$inventoryItem[1]);
            if (isset($inventory['error'])) tickex_mobile_response($inventory['error'] === 'forbidden' ? 403 : 404, $inventory);
            tickex_mobile_response(200, $inventory);
        }
        if (preg_match('~^/admin/inventory/([1-9][0-9]*)/logs/?$~', $route, $inventoryLog)) {
            if ($method !== 'POST') tickex_mobile_response(405, array('error' => 'method_not_allowed'));
            $inventory = tickex_mobile_inventory_add_log($pdo, $admin, (int)$inventoryLog[1], tickex_mobile_body());
            if (isset($inventory['error'])) tickex_mobile_response($inventory['error'] === 'forbidden' ? 403 : ($inventory['error'] === 'not_found' ? 404 : 422), $inventory);
            tickex_mobile_response(200, $inventory);
        }
        if ($route === '/admin/events' && $method === 'GET') tickex_mobile_response(200, tickex_mobile_admin_events($pdo, $admin));
        if ($route === '/admin/billing/report') {
            if ($method !== 'GET') tickex_mobile_response(405, array('error' => 'method_not_allowed'));
            $result = tickex_mobile_admin_billing_report($pdo, $admin, $_GET);
            if (isset($result['error'])) tickex_mobile_response($result['error'] === 'forbidden' ? 403 : 422, $result);
            tickex_mobile_response(200, $result);
        }
        if ($route === '/admin/clients') {
            if ($method !== 'GET') tickex_mobile_response(405, array('error' => 'method_not_allowed'));
            $result = tickex_mobile_admin_clients($pdo, $admin, $_GET);
            if (isset($result['error'])) tickex_mobile_response($result['error'] === 'forbidden' ? 403 : 422, $result);
            tickex_mobile_response(200, $result);
        }
        if ($route === '/admin/economy/movements') {
            if ($method === 'GET') {
                $result = tickex_mobile_economy_list($pdo, $admin, $_GET);
                if (isset($result['error'])) tickex_mobile_response($result['error'] === 'forbidden' ? 403 : 422, $result);
                tickex_mobile_response(200, $result);
            }
            if ($method !== 'POST') tickex_mobile_response(405, array('error' => 'method_not_allowed'));
            $result = tickex_mobile_economy_create($pdo, $admin, tickex_mobile_body());
            if (isset($result['error'])) tickex_mobile_response($result['error'] === 'forbidden' ? 403 : ($result['error'] === 'request_conflict' ? 409 : ($result['error'] === 'storage_error' ? 500 : 422)), $result);
            tickex_mobile_response(201, $result);
        }
        if (preg_match('~^/admin/economy/movements/([1-9][0-9]*)/include/?$~', $route, $economyInclude)) {
            if ($method !== 'PATCH') tickex_mobile_response(405, array('error' => 'method_not_allowed'));
            $body = tickex_mobile_body();
            $result = tickex_mobile_economy_set_included($pdo, $admin, (int)$economyInclude[1], isset($body['expectedIncluded']) ? $body['expectedIncluded'] : null, isset($body['included']) ? $body['included'] : null);
            if (isset($result['error'])) tickex_mobile_response($result['error'] === 'not_found' ? 404 : ($result['error'] === 'conflict' ? 409 : 422), $result);
            tickex_mobile_response(200, $result);
        }
        if (preg_match('~^/admin/economy/movements/([1-9][0-9]*)/?$~', $route, $economyDelete)) {
            if ($method !== 'DELETE') tickex_mobile_response(405, array('error' => 'method_not_allowed'));
            $body = tickex_mobile_body();
            $result = tickex_mobile_economy_delete($pdo, $admin, (int)$economyDelete[1], isset($body['confirmed']) ? $body['confirmed'] : null);
            if (isset($result['error'])) tickex_mobile_response($result['error'] === 'not_found' ? 404 : 422, $result);
            tickex_mobile_response(200, $result);
        }
        if (preg_match('~^/admin/events/([1-9][0-9]*)/sales/page/?$~', $route, $salesPage)) {
            if ($method !== 'GET') tickex_mobile_response(405, array('error' => 'method_not_allowed'));
            $result = tickex_mobile_admin_sales_page($pdo, $admin, (int)$salesPage[1], isset($_GET['beforeId']) ? $_GET['beforeId'] : 0, isset($_GET['limit']) ? $_GET['limit'] : 20, isset($_GET['status']) ? $_GET['status'] : 'all');
            if (isset($result['error'])) tickex_mobile_response($result['error'] === 'forbidden' ? 403 : 422, $result);
            tickex_mobile_response(200, $result);
        }
        if (preg_match('~^/admin/events/([1-9][0-9]*)/sales/orders/([1-9][0-9]*)/?$~', $route, $saleDetail)) {
            if ($method !== 'GET') tickex_mobile_response(405, array('error' => 'method_not_allowed'));
            $result = tickex_mobile_admin_sale_detail($pdo, $admin, (int)$saleDetail[1], (int)$saleDetail[2]);
            if (isset($result['error'])) tickex_mobile_response($result['error'] === 'forbidden' ? 403 : 404, $result);
            tickex_mobile_response(200, $result);
        }
        if ($route === '/admin/events' && $method === 'POST') {
            $created = tickex_mobile_admin_create_event($pdo, $admin, tickex_mobile_body());
            if (isset($created['error'])) tickex_mobile_response($created['error'] === 'slug_in_use' ? 409 : 422, $created);
            error_log('mobile_api admin_event_created request_id=' . $requestId . ' event_id=' . (int)$created['id']);
            tickex_mobile_response(201, $created);
        }
        if ($method === 'GET' && preg_match('~^/admin/events/([1-9][0-9]*)/?$~', $route, $adminRead)) {
            $detail = tickex_mobile_admin_event_detail($pdo, $admin, (int)$adminRead[1]);
            if ($detail === null) tickex_mobile_response(404, array('error' => 'not_found'));
            tickex_mobile_response(200, $detail);
        }
        if ($method === 'PATCH' && preg_match('~^/admin/events/([1-9][0-9]*)/?$~', $route, $adminEdit)) {
            $eventId = (int)$adminEdit[1];
            if (!tickex_mobile_admin_can_event($pdo, $admin, $eventId)) tickex_mobile_response(403, array('error' => 'forbidden'));
            $edit = tickex_mobile_body();
            if (count($edit) === 1 && isset($edit['venue'])) {
                $result = tickex_mobile_admin_update_venue($pdo, $admin, $eventId, $edit['venue']);
                if ($result === null) tickex_mobile_response(422, array('error' => 'invalid_venue'));
            } else {
                $result = tickex_mobile_admin_edit_event($pdo, $admin, $eventId, $edit);
                if (isset($result['error'])) tickex_mobile_response($result['error'] === 'not_found' ? 404 : 422, $result);
            }
            error_log('mobile_api admin_event_updated request_id=' . $requestId . ' event_id=' . $eventId);
            tickex_mobile_response(200, $result);
        }
        if ($method === 'POST' && preg_match('~^/admin/events/([1-9][0-9]*)/publication/?$~', $route, $publication)) {
            $eventId = (int)$publication[1];
            $body = tickex_mobile_body();
            $result = tickex_mobile_admin_set_publication($pdo, $admin, $eventId, isset($body['published']) ? $body['published'] : null);
            if (isset($result['error'])) tickex_mobile_response($result['error'] === 'forbidden' ? 403 : 422, $result);
            error_log('mobile_api admin_publication_updated request_id=' . $requestId . ' event_id=' . $eventId . ' published=' . ($result['published'] ? '1' : '0'));
            tickex_mobile_response(200, $result);
        }
        if (preg_match('~^/admin/events/([1-9][0-9]*)/presentation/?$~', $route, $presentation)) {
            $eventId = (int)$presentation[1];
            if ($method === 'GET') $result = tickex_mobile_admin_event_presentation($pdo, $admin, $eventId);
            elseif ($method === 'PATCH') $result = tickex_mobile_admin_update_presentation($pdo, $admin, $eventId, tickex_mobile_body());
            else tickex_mobile_response(405, array('error' => 'method_not_allowed'));
            if (isset($result['error'])) tickex_mobile_response($result['error'] === 'forbidden' ? 403 : ($result['error'] === 'capacity_below_issued' ? 409 : 422), $result);
            tickex_mobile_response(200, $result);
        }
        if (preg_match('~^/admin/events/([1-9][0-9]*)/(dashboard|sales|ticket-types|attendees)/?$~', $route, $adminMatch)) {
            $eventId = (int)$adminMatch[1];
            if (!tickex_mobile_admin_can_event($pdo, $admin, $eventId)) tickex_mobile_response(403, array('error' => 'forbidden'));
            if ($adminMatch[2] === 'attendees' && $method === 'POST') {
                $search = tickex_mobile_body();
                $query = isset($search['query']) ? (string)$search['query'] : '';
                if (strlen(trim($query)) < 2 || strlen($query) > 80) tickex_mobile_response(422, array('error' => 'invalid_search'));
                tickex_mobile_response(200, tickex_mobile_admin_attendees($pdo, $eventId, $query));
            }
            if ($method === 'GET') {
                if ($adminMatch[2] === 'dashboard') tickex_mobile_response(200, tickex_mobile_admin_dashboard($pdo, $eventId));
                if ($adminMatch[2] === 'sales') tickex_mobile_response(200, tickex_mobile_admin_sales($pdo, $eventId));
                if ($adminMatch[2] === 'ticket-types') tickex_mobile_response(200, tickex_mobile_admin_ticket_types($pdo, $eventId));
            }
            if ($adminMatch[2] === 'ticket-types' && $method === 'POST') {
                $result = tickex_mobile_admin_create_ticket_type($pdo, $admin, $eventId, tickex_mobile_body());
                if (isset($result['error'])) tickex_mobile_response($result['error'] === 'forbidden' ? 403 : 422, $result);
                error_log('mobile_api admin_type_created request_id=' . $requestId . ' event_id=' . $eventId);
                tickex_mobile_response(201, $result);
            }
        }
        if ($method === 'PATCH' && preg_match('~^/admin/events/([1-9][0-9]*)/ticket-types/([1-9][0-9]*)/?$~', $route, $typeEdit)) {
            $eventId = (int)$typeEdit[1];
            $result = tickex_mobile_admin_update_ticket_type($pdo, $admin, $eventId, (int)$typeEdit[2], tickex_mobile_body());
            if (isset($result['error'])) tickex_mobile_response($result['error'] === 'forbidden' ? 403 : ($result['error'] === 'stock_changed' ? 409 : ($result['error'] === 'not_found' ? 404 : 422)), $result);
            error_log('mobile_api admin_stock_updated request_id=' . $requestId . ' event_id=' . $eventId . ' type_id=' . (int)$typeEdit[2]);
            tickex_mobile_response(200, $result);
        }
        if (preg_match('~^/admin/events/([1-9][0-9]*)/free-checkout/?$~', $route, $freeCheckout)) {
            $eventId = (int)$freeCheckout[1];
            if ($method === 'GET') $result = tickex_mobile_admin_free_checkout($pdo, $admin, $eventId);
            elseif ($method === 'PATCH') $result = tickex_mobile_admin_save_free_checkout($pdo, $admin, $eventId, tickex_mobile_body());
            else tickex_mobile_response(405, array('error' => 'method_not_allowed'));
            if (isset($result['error'])) tickex_mobile_response($result['error'] === 'forbidden' ? 403 : 422, $result);
            tickex_mobile_response(200, $result);
        }
        if (preg_match('~^/admin/events/([1-9][0-9]*)/staff/?$~', $route, $adminStaff)) {
            $eventId = (int)$adminStaff[1];
            if (!tickex_mobile_admin_can_event($pdo, $admin, $eventId)) tickex_mobile_response(403, array('error' => 'forbidden'));
            if ($method === 'GET') tickex_mobile_response(200, tickex_mobile_admin_staff($pdo, $admin, $eventId));
            if ($method === 'POST') {
                $body = tickex_mobile_body();
                $clientId = isset($body['clientId']) ? (int)$body['clientId'] : 0;
                $roleCode = isset($body['roleCode']) ? trim((string)$body['roleCode']) : '';
                $assigned = isset($body['assigned']) ? $body['assigned'] : null;
                $result = tickex_mobile_admin_assign_staff($pdo, $admin, $eventId, $clientId, $roleCode, $assigned);
                if (isset($result['error'])) tickex_mobile_response($result['error'] === 'staff_not_found' ? 404 : 422, $result);
                error_log('mobile_api admin_staff_assignment request_id=' . $requestId . ' event_id=' . $eventId . ' client_id=' . $clientId);
                tickex_mobile_response(200, $result);
            }
        }
        if (preg_match('~^/admin/events/([1-9][0-9]*)/staff/roles/?$~', $route, $adminRoles)) {
            $eventId = (int)$adminRoles[1];
            if ($method === 'GET') $result = tickex_mobile_admin_roles($pdo, $admin, $eventId);
            elseif ($method === 'POST') $result = tickex_mobile_admin_create_role($pdo, $admin, $eventId, tickex_mobile_body());
            else tickex_mobile_response(405, array('error' => 'method_not_allowed'));
            if (isset($result['error'])) tickex_mobile_response($result['error'] === 'forbidden' ? 403 : ($result['error'] === 'role_exists' ? 409 : 422), $result);
            tickex_mobile_response(200, $result);
        }
        if (preg_match('~^/admin/events/([1-9][0-9]*)/staff/roles/([1-9][0-9]*)/?$~', $route, $adminRole)) {
            $eventId = (int)$adminRole[1];
            $roleId = (int)$adminRole[2];
            if ($method === 'PATCH') $result = tickex_mobile_admin_update_role($pdo, $admin, $eventId, $roleId, tickex_mobile_body());
            elseif ($method === 'DELETE') $result = tickex_mobile_admin_delete_role($pdo, $admin, $eventId, $roleId);
            else tickex_mobile_response(405, array('error' => 'method_not_allowed'));
            if (isset($result['error'])) tickex_mobile_response($result['error'] === 'forbidden' ? 403 : ($result['error'] === 'not_found' ? 404 : ($result['error'] === 'role_in_use' ? 409 : 422)), $result);
            tickex_mobile_response(200, $result);
        }
        if (preg_match('~^/admin/events/([1-9][0-9]*)/staff/invitations/?$~', $route, $adminInvite)) {
            if ($method !== 'POST') tickex_mobile_response(405, array('error' => 'method_not_allowed'));
            $invite = tickex_mobile_admin_invite_staff($pdo, $admin, (int)$adminInvite[1], tickex_mobile_body());
            if (isset($invite['error'])) tickex_mobile_response($invite['error'] === 'forbidden' ? 403 : ($invite['error'] === 'already_pending' ? 409 : 422), $invite);
            tickex_mobile_response(201, $invite);
        }
        if (preg_match('~^/admin/events/([1-9][0-9]*)/staff/operations/?$~', $route, $adminOperations)) {
            if ($method === 'GET') $result = tickex_mobile_admin_staff_operations($pdo, $admin, (int)$adminOperations[1]);
            elseif ($method === 'POST') $result = tickex_mobile_admin_staff_action($pdo, $admin, (int)$adminOperations[1], tickex_mobile_body());
            else tickex_mobile_response(405, array('error' => 'method_not_allowed'));
            if (isset($result['error'])) tickex_mobile_response($result['error'] === 'forbidden' ? 403 : ($result['error'] === 'staff_not_assigned' || $result['error'] === 'task_not_found' ? 404 : 422), $result);
            tickex_mobile_response(200, $result);
        }
        if (preg_match('~^/admin/events/([1-9][0-9]*)/sales-control/?$~', $route, $salesControl)) {
            $eventId = (int)$salesControl[1];
            if (!tickex_mobile_admin_can_event($pdo, $admin, $eventId)) tickex_mobile_response(403, array('error' => 'forbidden'));
            if ($method === 'GET') tickex_mobile_response(200, array('eventId' => (string)$eventId, 'paused' => tickex_event_sales_paused($pdo, $eventId)));
            if ($method === 'POST') {
                $body = tickex_mobile_body();
                if (!isset($body['paused']) || !is_bool($body['paused'])) tickex_mobile_response(422, array('error' => 'invalid_state'));
                $reason = isset($body['reason']) ? trim((string)$body['reason']) : '';
                if ($body['paused'] && $reason === '') tickex_mobile_response(422, array('error' => 'reason_required'));
                $result = tickex_event_sales_set_paused($pdo, $eventId, (int)$admin['id'], $body['paused'], $reason);
                error_log('mobile_api sales_control_updated request_id=' . $requestId . ' event_id=' . $eventId . ' paused=' . ($body['paused'] ? '1' : '0'));
                tickex_mobile_response(200, $result);
            }
        }
        if ($method === 'POST' && preg_match('~^/admin/events/([1-9][0-9]*)/send-tickex/?$~', $route, $adminSend)) {
            $eventId = (int)$adminSend[1];
            if (!tickex_mobile_admin_can_event($pdo, $admin, $eventId)) tickex_mobile_response(403, array('error' => 'forbidden'));
            $sent = tickex_mobile_admin_send_ticket($pdo, $admin, $eventId, tickex_mobile_body(), isset($_SERVER['HTTP_IDEMPOTENCY_KEY']) ? $_SERVER['HTTP_IDEMPOTENCY_KEY'] : '');
            if (isset($sent['error'])) tickex_mobile_response($sent['error'] === 'forbidden' ? 403 : ($sent['error'] === 'issuance_failed' ? 409 : 422), $sent);
            error_log('mobile_api admin_ticket_sent request_id=' . $requestId . ' event_id=' . $eventId . ' order_id=' . $sent['orderId']);
            tickex_mobile_response(200, $sent);
        }
        tickex_mobile_response(405, array('error' => 'method_not_allowed'));
    }
    $token = tickex_mobile_bearer();
    $client = tickex_mobile_authenticated_client($pdo, $token);
    if (!$client) tickex_mobile_response(401, array('error' => 'unauthorized'));
    if ($route === '/auth/logout' && $method === 'POST') {
        tickex_mobile_logout($pdo, $token);
        tickex_mobile_response(200, array('ok' => true));
    }
    if ($route === '/me' && $method === 'GET') tickex_mobile_response(200, tickex_mobile_profile($client));
    if ($route === '/me' && $method === 'PATCH') {
        $body = tickex_mobile_body();
        $profile = tickex_mobile_update_profile($pdo, $client['id'], isset($body['firstName']) ? $body['firstName'] : '', isset($body['lastName']) ? $body['lastName'] : '');
        if (!$profile) tickex_mobile_response(422, array('error' => 'invalid_profile'));
        error_log('mobile_api profile_updated request_id=' . $requestId);
        tickex_mobile_response(200, $profile);
    }
    if ($route === '/me/staff-invitations') {
        if ($method !== 'GET') tickex_mobile_response(405, array('error' => 'method_not_allowed'));
        tickex_mobile_response(200, tickex_mobile_staff_invitations($pdo, $client));
    }
    if (preg_match('~^/me/staff-invitations/([1-9][0-9]*)/decision/?$~', $route, $invitationMatch)) {
        if ($method !== 'POST') tickex_mobile_response(405, array('error' => 'method_not_allowed'));
        $body = tickex_mobile_body();
        $result = tickex_mobile_staff_invitation_decide($pdo, $client, (int)$invitationMatch[1], isset($body['decision']) ? $body['decision'] : '');
        if (isset($result['error'])) tickex_mobile_response($result['error'] === 'forbidden' ? 403 : ($result['error'] === 'not_found' ? 404 : 422), $result);
        tickex_mobile_response(200, $result);
    }
    if ($route === '/me/tickets' && $method === 'GET') {
        $tickets = tickex_mobile_tickets($pdo, $client['email'], null);
        foreach ($tickets as &$ticket) unset($ticket['qrPayload']);
        unset($ticket);
        tickex_mobile_response(200, $tickets);
    }
    if ($route === '/me/orders' && $method === 'GET') tickex_mobile_response(200, tickex_mobile_orders_for_client($pdo, $client['email']));
    if ($route === '/staff/events' && $method === 'GET') tickex_mobile_response(200, tickex_mobile_staff_events($pdo, $client['id']));
    if (preg_match('~^/staff/events/([1-9][0-9]*)/door-types/?$~', $route, $doorMatch)) {
        if ($method !== 'GET') tickex_mobile_response(405, array('error' => 'method_not_allowed'));
        if (!tickex_mobile_gate_permission($pdo, $client['id'], (int)$doorMatch[1], 'sales_view')) tickex_mobile_response(403, array('error' => 'forbidden'));
        tickex_mobile_response(200, tickex_mobile_door_types($pdo, (int)$doorMatch[1]));
    }
    if (preg_match('~^/staff/events/([1-9][0-9]*)/door-checkouts/?$~', $route, $doorMatch)) {
        if (!tickex_mobile_gate_permission($pdo, $client['id'], (int)$doorMatch[1], 'sales_view')) tickex_mobile_response(403, array('error' => 'forbidden'));
        if ($method === 'GET') tickex_mobile_response(200, tickex_mobile_door_recent($pdo, (int)$doorMatch[1]));
        if ($method !== 'POST') tickex_mobile_response(405, array('error' => 'method_not_allowed'));
        $body = tickex_mobile_body();
        $key = isset($_SERVER['HTTP_IDEMPOTENCY_KEY']) ? (string)$_SERVER['HTTP_IDEMPOTENCY_KEY'] : '';
        $result = tickex_mobile_door_create($pdo, $client['id'], (int)$doorMatch[1], $body, $key);
        if (isset($result['error'])) tickex_mobile_response(in_array($result['error'], array('sales_paused', 'type_unavailable', 'event_full', 'idempotency_conflict', 'order_pending'), true) ? 409 : 422, $result);
        tickex_mobile_response(201, $result);
    }
    if (preg_match('~^/staff/events/([1-9][0-9]*)/door-checkouts/(door-[a-f0-9]{32})/?$~', $route, $doorMatch)) {
        if ($method !== 'GET') tickex_mobile_response(405, array('error' => 'method_not_allowed'));
        if (!tickex_mobile_gate_permission($pdo, $client['id'], (int)$doorMatch[1], 'sales_view')) tickex_mobile_response(403, array('error' => 'forbidden'));
        $order = tickex_mobile_door_order($pdo, $doorMatch[2]);
        if (!$order || (int)$order['evento_id'] !== (int)$doorMatch[1]) tickex_mobile_response(404, array('error' => 'not_found'));
        tickex_mobile_response(200, tickex_mobile_door_projection($pdo, $order));
    }
    if (in_array($method, array('GET', 'POST'), true) && preg_match('~^/staff/events/([1-9][0-9]*)/(stats|search|activity)/?$~', $route, $staffMatch)) {
        $eventId = (int)$staffMatch[1];
        $permission = $staffMatch[2] === 'stats' ? 'dashboard_view' : ($staffMatch[2] === 'activity' ? 'reports_view' : 'tickets_validate');
        if (!tickex_mobile_gate_permission($pdo, $client['id'], $eventId, $permission)) tickex_mobile_response(403, array('error' => 'forbidden'));
        if ($staffMatch[2] === 'stats') {
            if ($method !== 'GET') tickex_mobile_response(405, array('error' => 'method_not_allowed'));
            tickex_mobile_response(200, tickex_mobile_gate_stats($pdo, $eventId));
        }
        if ($staffMatch[2] === 'activity') {
            if ($method !== 'GET') tickex_mobile_response(405, array('error' => 'method_not_allowed'));
            tickex_mobile_response(200, tickex_mobile_gate_activity($pdo, $eventId));
        }
        if ($method !== 'POST') tickex_mobile_response(405, array('error' => 'method_not_allowed'));
        $searchBody = tickex_mobile_body();
        $query = isset($searchBody['query']) ? (string)$searchBody['query'] : '';
        if (strlen(trim($query)) < 2 || strlen($query) > 80) tickex_mobile_response(422, array('error' => 'invalid_search'));
        tickex_mobile_response(200, tickex_mobile_gate_search($pdo, $eventId, $query));
    }
    if ($route === '/staff/check-in' && $method === 'POST') {
        $body = tickex_mobile_body();
        $eventId = isset($body['eventId']) ? (int)$body['eventId'] : 0;
        if (!tickex_mobile_gate_permission($pdo, $client['id'], $eventId, 'checkin_scan')) tickex_mobile_response(403, array('error' => 'forbidden'));
        $result = tickex_mobile_gate_checkin($pdo, $client['id'], $eventId, isset($body['ticketId']) ? (int)$body['ticketId'] : 0, isset($body['code']) ? $body['code'] : '');
        if (isset($result['error'])) tickex_mobile_response($result['error'] === 'already_checked_in' ? 409 : ($result['error'] === 'not_found' ? 404 : 422), $result);
        error_log('mobile_api staff_checkin request_id=' . $requestId . ' event_id=' . $eventId);
        tickex_mobile_response(200, $result);
    }
    if ($route === '/staff/check-in/undo' && $method === 'POST') {
        $body = tickex_mobile_body();
        $eventId = isset($body['eventId']) ? (int)$body['eventId'] : 0;
        if (!tickex_mobile_gate_permission($pdo, $client['id'], $eventId, 'checkin_scan')) tickex_mobile_response(403, array('error' => 'forbidden'));
        $result = tickex_mobile_gate_undo($pdo, $client['id'], $eventId, isset($body['ticketId']) ? (int)$body['ticketId'] : 0);
        if (isset($result['error'])) tickex_mobile_response($result['error'] === 'not_found' ? 404 : 409, $result);
        error_log('mobile_api staff_undo request_id=' . $requestId . ' event_id=' . $eventId);
        tickex_mobile_response(200, $result);
    }
    if ($route === '/checkout-intents' && $method === 'POST') {
        $body = tickex_mobile_body();
        require_once __DIR__ . '/../../../inc/event_sales_control.php';
        if (tickex_event_sales_paused($pdo, isset($body['eventId']) ? $body['eventId'] : 0)) tickex_mobile_response(409, array('error' => 'sales_paused'));
        $key = isset($_SERVER['HTTP_IDEMPOTENCY_KEY']) ? (string)$_SERVER['HTTP_IDEMPOTENCY_KEY'] : '';
        $intent = tickex_mobile_create_checkout_intent($pdo, $client, isset($body['eventId']) ? $body['eventId'] : 0, $key);
        if (isset($intent['error'])) tickex_mobile_response($intent['error'] === 'idempotency_conflict' || $intent['error'] === 'checkout_intent_expired' ? 409 : 422, $intent);
        error_log('mobile_api checkout_intent_created request_id=' . $requestId . ' event_id=' . (int)$intent['eventId']);
        tickex_mobile_response(201, $intent);
    }
    if ($method === 'GET' && preg_match('~^/checkout-intents/(mobile-[a-f0-9]{32})/?$~', $route, $match)) {
        $intent = tickex_mobile_checkout_intent_status($pdo, $client, $match[1]);
        if ($intent === null) tickex_mobile_response(404, array('error' => 'not_found'));
        tickex_mobile_response(200, $intent);
    }
    if ($method === 'GET' && preg_match('~^/me/tickets/([1-9][0-9]*)/?$~', $route, $match)) {
        $tickets = tickex_mobile_tickets($pdo, $client['email'], (int)$match[1]);
        if (count($tickets) === 0) tickex_mobile_response(404, array('error' => 'not_found'));
        tickex_mobile_response(200, $tickets[0]);
    }
    if ($method === 'GET' && preg_match('~^/orders/([1-9][0-9]*)/?$~', $route, $match)) {
        $order = tickex_mobile_order_for_client($pdo, (int)$match[1], $client['email']);
        if ($order === null) tickex_mobile_response(404, array('error' => 'not_found'));
        tickex_mobile_response(200, $order);
    }
    header('Allow: GET, POST, PATCH');
    tickex_mobile_response(405, array('error' => 'method_not_allowed'));
} catch (Exception $error) {
    error_log('mobile_api failure request_id=' . $requestId . ' class=' . get_class($error));
    tickex_mobile_response(503, array('error' => 'temporarily_unavailable'));
}
