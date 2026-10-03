<?php
require_once __DIR__ . '/checkout_capacity_holds.php';
require_once __DIR__ . '/mercadopago_marketplace.php';

function tickex_capacity_hold_reconciliation_decision(array $hold, array $order, array $preference, array $search, $sellerUserId, $now)
{
    if ((string)$hold['status'] !== 'active') return 'not_active';
    $deadline = strtotime((string)$hold['payment_deadline_at']);
    if (!$deadline || $now < $deadline + 15 * 60) return 'grace_period';
    if ((string)$order['payment_status'] === 'confirmed') return 'paid_pending_issuance';
    if ((string)$order['ref'] !== (string)$hold['ref'] || (int)$order['evento_id'] !== (int)$hold['event_id']) return 'order_mismatch';
    if ((string)$order['payment_provider'] !== 'mercadopago') return 'provider_mismatch';
    if ((string)$order['provider_preference_id'] === '' || (string)$order['provider_preference_id'] !== (string)$preference['id']) return 'preference_mismatch';
    if (isset($preference['external_reference']) && (string)$preference['external_reference'] !== (string)$hold['ref']) return 'preference_mismatch';
    if (isset($preference['collector_id']) && (string)$preference['collector_id'] !== (string)$sellerUserId) return 'seller_mismatch';
    if (empty($preference['expires']) || empty($preference['expiration_date_to']) || strtotime((string)$preference['expiration_date_to']) > $now) return 'preference_not_expired';
    if (!isset($search['results']) || !is_array($search['results']) || !isset($search['paging']['total']) || (int)$search['paging']['total'] > count($search['results'])) return 'payment_search_incomplete';
    foreach ($search['results'] as $payment) {
        if (!is_array($payment) || !isset($payment['external_reference']) || (string)$payment['external_reference'] !== (string)$hold['ref']) return 'payment_search_mismatch';
        if (isset($payment['collector_id']) && (string)$payment['collector_id'] !== (string)$sellerUserId) return 'seller_mismatch';
        $status = isset($payment['status']) ? strtolower((string)$payment['status']) : '';
        if (!in_array($status, array('rejected', 'cancelled'), true)) return 'payment_not_terminal';
    }
    return 'release';
}

function tickex_capacity_holds_reconcile_due($pdo, $limit, $http = null, $now = null)
{
    tickex_capacity_holds_ensure_schema($pdo);
    $now = $now === null ? time() : (int)$now;
    $limit = max(1, min(50, (int)$limit));
    $rows = $pdo->query("SELECT ref,event_id,status,payment_deadline_at FROM checkout_capacity_holds WHERE status='active' ORDER BY COALESCE(last_checked_at,created_at) ASC, ref ASC LIMIT " . $limit)->fetchAll(PDO::FETCH_ASSOC);
    $summary = array('checked' => 0, 'released' => 0, 'retained' => 0, 'errors' => 0, 'reasons' => array());
    foreach ($rows as $hold) {
        if (!$hold['payment_deadline_at'] || $now < strtotime((string)$hold['payment_deadline_at']) + 15 * 60) continue;
        $summary['checked']++;
        try {
            $read = $pdo->prepare("SELECT ref,evento_id,payment_status,payment_provider,provider_preference_id,seller_admin_id FROM tc_orders WHERE ref=:ref AND payment_provider='mercadopago' LIMIT 1");
            $read->execute(array(':ref' => (string)$hold['ref']));
            $order = $read->fetch(PDO::FETCH_ASSOC);
            if (!$order) {
                $sellerId = tickex_mp_event_owner_id($pdo, (int)$hold['event_id']);
                if ($sellerId <= 0) throw new RuntimeException('seller_missing');
                $account = tickex_mp_refresh_account($pdo, $sellerId, false);
                $request = $http === null ? 'tickex_mp_http_json' : $http;
                $headers = array('Authorization: Bearer ' . $account['access_token'], 'Accept: application/json');
                $preferences = call_user_func($request, 'GET', 'https://api.mercadopago.com/checkout/preferences/search?external_reference=' . rawurlencode((string)$hold['ref']) . '&limit=50', $headers);
                $search = call_user_func($request, 'GET', 'https://api.mercadopago.com/v1/payments/search?external_reference=' . rawurlencode((string)$hold['ref']) . '&sort=date_created&criteria=desc&limit=50', $headers);
                $noPreferences = isset($preferences['total']) && (int)$preferences['total'] === 0 && isset($preferences['elements']) && is_array($preferences['elements']) && count($preferences['elements']) === 0;
                $noPayments = isset($search['paging']['total']) && (int)$search['paging']['total'] === 0 && isset($search['results']) && is_array($search['results']) && count($search['results']) === 0;
                $reason = $noPreferences && $noPayments ? 'release' : 'gateway_state_unknown';
                if ($reason === 'release' && tickex_capacity_holds_release($pdo, $hold['ref'], 'expired_verified')) $summary['released']++;
            }
            elseif ((string)$order['payment_status'] === 'confirmed') { $reason = 'paid_pending_issuance'; }
            else {
                $sellerId = (int)$order['seller_admin_id'];
                $account = tickex_mp_refresh_account($pdo, $sellerId, false);
                $request = $http === null ? 'tickex_mp_http_json' : $http;
                $headers = array('Authorization: Bearer ' . $account['access_token'], 'Accept: application/json');
                $preference = call_user_func($request, 'GET', 'https://api.mercadopago.com/checkout/preferences/' . rawurlencode((string)$order['provider_preference_id']), $headers);
                $search = call_user_func($request, 'GET', 'https://api.mercadopago.com/v1/payments/search?external_reference=' . rawurlencode((string)$hold['ref']) . '&sort=date_created&criteria=desc&limit=50', $headers);
                $reason = tickex_capacity_hold_reconciliation_decision($hold, $order, $preference, $search, $account['mp_user_id'], $now);
                if ($reason === 'release') {
                    if (tickex_capacity_holds_release($pdo, $hold['ref'], 'expired_verified')) $summary['released']++;
                    else $reason = 'already_released';
                }
            }
            if ($reason !== 'release') $summary['retained']++;
            if (!isset($summary['reasons'][$reason])) $summary['reasons'][$reason] = 0;
            $summary['reasons'][$reason]++;
        } catch (Exception $error) {
            $summary['errors']++;
            $summary['retained']++;
            $errorReason = $error->getMessage() === 'hold_stock_changed' ? 'stock_changed' : 'provider_error';
            if (!isset($summary['reasons'][$errorReason])) $summary['reasons'][$errorReason] = 0;
            $summary['reasons'][$errorReason]++;
        }
        $mark = $pdo->prepare('UPDATE checkout_capacity_holds SET last_checked_at=CURRENT_TIMESTAMP WHERE ref=:ref');
        $mark->execute(array(':ref' => (string)$hold['ref']));
    }
    return $summary;
}
