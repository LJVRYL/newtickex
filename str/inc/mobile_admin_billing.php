<?php
function tickex_mobile_admin_billing_report($pdo, $admin, $filters)
{
    $eventId = isset($filters['eventId']) ? (int)$filters['eventId'] : 0;
    if ($eventId < 0 || ($eventId > 0 && !tickex_mobile_admin_can_event($pdo, $admin, $eventId))) return array('error' => 'forbidden');
    $provider = isset($filters['provider']) ? trim((string)$filters['provider']) : '';
    $status = isset($filters['status']) ? trim((string)$filters['status']) : '';
    $dateFrom = isset($filters['dateFrom']) ? trim((string)$filters['dateFrom']) : '';
    $dateTo = isset($filters['dateTo']) ? trim((string)$filters['dateTo']) : '';
    if ($provider !== '' && !in_array($provider, array('mercadopago', 'totalcoin', 'manual_transfer', 'courtesy'), true)) return array('error' => 'invalid_filter');
    if ($status !== '' && !in_array($status, array('confirmed', 'pending', 'failed'), true)) return array('error' => 'invalid_filter');
    foreach (array($dateFrom, $dateTo) as $date) {
        if ($date !== '' && (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || strtotime($date) === false || date('Y-m-d', strtotime($date)) !== $date)) return array('error' => 'invalid_filter');
    }
    if ($dateFrom !== '' && $dateTo !== '' && strcmp($dateFrom, $dateTo) > 0) return array('error' => 'invalid_filter');
    $report = tickex_billing_report($pdo, (int)$admin['id'], $admin['tipo_global'] === 'super_admin', array(
        'event_id' => $eventId, 'provider' => $provider, 'status' => $status, 'date_from' => $dateFrom, 'date_to' => $dateTo,
    ));
    $recent = array();
    foreach ($report['recent'] as $row) $recent[] = array(
        'orderId' => (string)$row['id'],
        'eventId' => (string)$row['evento_id'],
        'eventName' => (string)$row['evento_nombre'],
        'payment' => (string)$row['normalized_status'],
        'provider' => (string)$row['normalized_provider'],
        'gross' => (float)$row['gross_amount'],
        'ticketSubtotal' => (float)$row['ticket_subtotal_amount'],
        'serviceFee' => (float)$row['service_fee_amount_normalized'],
        'platformFee' => (float)$row['platform_fee_amount'],
        'providerCostEstimate' => (float)$row['provider_cost_amount'],
        'organizerNetEstimate' => (float)$row['organizer_net_amount'],
        'createdAt' => tickex_mobile_datetime($row['created_at']),
    );
    return array('estimateOnly' => true, 'summary' => $report['summary'], 'breakdown' => $report['breakdown'], 'recent' => $recent);
}
