<?php

function tickex_billing_money($value)
{
    return '$' . number_format((float)$value, 2, ',', '.');
}

function tickex_billing_status($row)
{
    $status = strtolower(trim(isset($row['payment_status']) ? (string)$row['payment_status'] : ''));
    $state = strtolower(trim(isset($row['state']) ? (string)$row['state'] : ''));
    if ($status === 'confirmed' || in_array($state, array('success', 'aprobado', 'approved', 'completed', 'paid', 'manual_confirmed'), true)) return 'confirmed';
    if (in_array($status, array('failed', 'rejected', 'cancelled'), true) || in_array($state, array('failed', 'rejected', 'cancelled', 'error'), true)) return 'failed';
    return 'pending';
}

function tickex_billing_provider($row)
{
    $provider = strtolower(trim(isset($row['payment_provider']) ? (string)$row['payment_provider'] : ''));
    return $provider === '' ? 'totalcoin' : $provider;
}

function tickex_billing_provider_label($provider)
{
    $labels = array('mercadopago' => 'Mercado Pago', 'totalcoin' => 'TotalCoin', 'manual_transfer' => 'Transferencia manual', 'courtesy' => 'Cortesía');
    return isset($labels[$provider]) ? $labels[$provider] : ucfirst(str_replace('_', ' ', $provider));
}

function tickex_billing_normalize_order($row)
{
    $provider = tickex_billing_provider($row);
    $gross = max(0, (float)(isset($row['amount']) ? $row['amount'] : 0));
    $serviceFee = max(0, (float)(isset($row['service_fee_amount']) ? $row['service_fee_amount'] : 0));
    $ticketSubtotal = (float)(isset($row['ticket_subtotal']) ? $row['ticket_subtotal'] : 0);
    if ($ticketSubtotal <= 0 && $gross > 0) $ticketSubtotal = max(0, $gross - $serviceFee);
    $platformFee = max(0, (float)(isset($row['marketplace_fee']) ? $row['marketplace_fee'] : 0));
    $providerCost = 0.0;
    if ($provider === 'mercadopago') $providerCost = round($gross * max(0, (float)(isset($row['mp_cost_estimate_percent']) ? $row['mp_cost_estimate_percent'] : 0)) / 100, 2);
    elseif ($provider === 'totalcoin') $providerCost = round($gross * 0.03, 2);
    $row['normalized_status'] = tickex_billing_status($row);
    $row['normalized_provider'] = $provider;
    $row['provider_label'] = tickex_billing_provider_label($provider);
    $row['gross_amount'] = $gross;
    $row['ticket_subtotal_amount'] = $ticketSubtotal;
    $row['service_fee_amount_normalized'] = $serviceFee;
    $row['platform_fee_amount'] = $platformFee;
    $row['provider_cost_amount'] = $providerCost;
    $row['organizer_net_amount'] = max(0, round($gross - $providerCost - $platformFee, 2));
    return $row;
}

function tickex_billing_report(PDO $pdo, $adminId, $isSuper, $filters)
{
    $where = array("COALESCE(e.borrado_en,'') = ''");
    $params = array();
    if (!$isSuper) { $where[] = 'e.creado_por_admin_id = :admin_id'; $params[':admin_id'] = (int)$adminId; }
    $eventId = isset($filters['event_id']) ? (int)$filters['event_id'] : 0;
    if ($eventId > 0) { $where[] = 'e.id = :event_id'; $params[':event_id'] = $eventId; }
    $provider = isset($filters['provider']) ? strtolower((string)$filters['provider']) : '';
    if (in_array($provider, array('mercadopago', 'totalcoin', 'manual_transfer', 'courtesy'), true)) { $where[] = "LOWER(COALESCE(NULLIF(o.payment_provider,''),'totalcoin')) = :provider"; $params[':provider'] = $provider; }
    $dateFrom = isset($filters['date_from']) ? trim((string)$filters['date_from']) : '';
    $dateTo = isset($filters['date_to']) ? trim((string)$filters['date_to']) : '';
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateFrom)) { $where[] = 'date(o.created_at) >= :date_from'; $params[':date_from'] = $dateFrom; }
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateTo)) { $where[] = 'date(o.created_at) <= :date_to'; $params[':date_to'] = $dateTo; }
    $st = $pdo->prepare("SELECT o.*,e.nombre AS evento_nombre,e.creado_por_admin_id AS owner_admin_id FROM tc_orders o INNER JOIN eventos e ON e.id=o.evento_id WHERE " . implode(' AND ', $where) . ' ORDER BY o.id DESC');
    $st->execute($params);
    $statusFilter = isset($filters['status']) ? strtolower((string)$filters['status']) : '';
    $summary = array('gross'=>0.0,'ticket_subtotal'=>0.0,'service_fee'=>0.0,'platform_fee'=>0.0,'provider_cost'=>0.0,'organizer_net'=>0.0,'confirmed'=>0,'pending'=>0,'failed'=>0);
    $breakdown = array(); $recent = array();
    while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
        $row = tickex_billing_normalize_order($row); $status = $row['normalized_status'];
        if (in_array($statusFilter, array('confirmed','pending','failed'), true) && $status !== $statusFilter) continue;
        $summary[$status]++;
        if ($status === 'confirmed') {
            foreach (array('gross'=>'gross_amount','ticket_subtotal'=>'ticket_subtotal_amount','service_fee'=>'service_fee_amount_normalized','platform_fee'=>'platform_fee_amount','provider_cost'=>'provider_cost_amount','organizer_net'=>'organizer_net_amount') as $totalKey=>$rowKey) $summary[$totalKey] += $row[$rowKey];
            $key = $row['normalized_provider'];
            if (!isset($breakdown[$key])) $breakdown[$key] = array('label'=>$row['provider_label'],'count'=>0,'gross'=>0.0,'cost'=>0.0,'net'=>0.0);
            $breakdown[$key]['count']++; $breakdown[$key]['gross'] += $row['gross_amount']; $breakdown[$key]['cost'] += $row['provider_cost_amount'] + $row['platform_fee_amount']; $breakdown[$key]['net'] += $row['organizer_net_amount'];
        }
        if (count($recent) < 25) $recent[] = $row;
    }
    return array('summary'=>$summary,'breakdown'=>array_values($breakdown),'recent'=>$recent);
}

function tickex_billing_events(PDO $pdo, $adminId, $isSuper)
{
    if ($isSuper) return $pdo->query("SELECT id,nombre,creado_por_admin_id FROM eventos WHERE COALESCE(borrado_en,'')='' ORDER BY id DESC")->fetchAll(PDO::FETCH_ASSOC);
    $st = $pdo->prepare("SELECT id,nombre,creado_por_admin_id FROM eventos WHERE COALESCE(borrado_en,'')='' AND creado_por_admin_id=:admin ORDER BY id DESC");
    $st->execute(array(':admin'=>(int)$adminId));
    return $st->fetchAll(PDO::FETCH_ASSOC);
}
