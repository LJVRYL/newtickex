<?php
require_once __DIR__ . '/totalcoin.php';
require_once __DIR__ . '/order_events.php';

if (!function_exists('tickex_totalcoin_confirm_from_status')) {
    function tickex_totalcoin_confirm_from_status($pdo, $order, $statusFetcher = null)
    {
        $reference = isset($order['ref']) ? trim((string)$order['ref']) : '';
        if ($reference === '') return array('confirmed' => false, 'result' => 'missing_reference');

        $remote = is_callable($statusFetcher)
            ? call_user_func($statusFetcher, $reference)
            : tc_checkout_status($reference);
        if (empty($remote['found']) || !is_array($remote['data'])) {
            return array('confirmed' => false, 'result' => 'not_found');
        }

        $data = $remote['data'];
        $remoteState = strtoupper(trim((string)(isset($data['Estado']) ? $data['Estado'] : '')));
        $remoteConcept = trim((string)(isset($data['Concepto']) ? $data['Concepto'] : ''));
        $remoteAmount = isset($data['Monto']) && is_numeric($data['Monto']) ? (float)$data['Monto'] : null;

        if ($remoteConcept !== $reference) return array('confirmed' => false, 'result' => 'concept_mismatch', 'estado' => $remoteState);
        if ($remoteAmount === null || abs((float)$order['amount'] - $remoteAmount) > 0.01) {
            return array('confirmed' => false, 'result' => 'amount_mismatch', 'estado' => $remoteState);
        }
        if ($remoteState !== 'APROBADO') return array('confirmed' => false, 'result' => 'not_approved', 'estado' => $remoteState);

        $st = $pdo->prepare("UPDATE tc_orders SET payment_status = 'confirmed', payment_confirmed_at = COALESCE(payment_confirmed_at, CURRENT_TIMESTAMP), state = 'success', updated_at = CURRENT_TIMESTAMP WHERE id = :id AND (payment_status IS NULL OR payment_status IN ('pending','created','confirmed'))");
        $st->execute(array(':id' => (int)$order['id']));
        log_order_event($pdo, (int)$order['id'], (string)$order['request_id'], 'totalcoin_status_confirmed', array('ref' => $reference, 'estado' => $remoteState));

        return array('confirmed' => true, 'result' => 'confirmed', 'estado' => $remoteState, 'data' => $data);
    }
}
