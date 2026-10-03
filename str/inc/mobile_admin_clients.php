<?php
function tickex_mobile_admin_clients($pdo, $admin, $filters)
{
    $eventId = isset($filters['eventId']) ? (int)$filters['eventId'] : 0;
    $limit = isset($filters['limit']) ? (int)$filters['limit'] : 30;
    $offset = isset($filters['offset']) ? (int)$filters['offset'] : 0;
    $q = isset($filters['q']) ? trim((string)$filters['q']) : '';
    $sort = isset($filters['sort']) ? (string)$filters['sort'] : 'recent';
    $group = isset($filters['group']) ? (string)$filters['group'] : 'all';
    $contact = isset($filters['contact']) ? (string)$filters['contact'] : 'all';
    if ($eventId < 0 || $limit < 1 || $limit > 50 || $offset < 0 || $offset > 100000 || strlen($q) > 100 || !in_array($sort, array('recent', 'az'), true) || !in_array($group, array('all', 'registered', 'guests'), true) || !in_array($contact, array('all', 'email', 'whatsapp'), true)) return array('error' => 'invalid_filter');
    if ($eventId > 0 && !tickex_mobile_admin_can_event($pdo, $admin, $eventId)) return array('error' => 'forbidden');
    $events = tickex_billing_events($pdo, (int)$admin['id'], $admin['tipo_global'] === 'super_admin');
    $registered = array();
    foreach ($pdo->query('SELECT email FROM usuarios WHERE email IS NOT NULL') as $user) $registered[strtolower(trim((string)$user['email']))] = true;
    $clients = array(); $anon = 0;
    foreach ($events as $event) {
        $eid = (int)$event['id'];
        if ($eventId > 0 && $eid !== $eventId) continue;
        foreach (get_unified_entries($pdo, $eid) as $entry) {
            $email = isset($entry['email']) ? trim((string)$entry['email']) : '';
            $name = isset($entry['nombre']) ? trim((string)$entry['nombre']) : '';
            $phone = '';
            $raw = isset($entry['raw_row']) && is_array($entry['raw_row']) ? $entry['raw_row'] : array();
            foreach (array('telefono', 'tel', 'phone', 'whatsapp', 'celular', 'mobile') as $phoneKey) {
                if (isset($raw[$phoneKey]) && trim((string)$raw[$phoneKey]) !== '') { $phone = trim((string)$raw[$phoneKey]); break; }
            }
            $lastAt = isset($entry['created_at']) ? trim((string)$entry['created_at']) : '';
            if ($lastAt === '') foreach (array('fecha_registro', 'fecha', 'created_at', 'creado_en') as $dateKey) {
                if (isset($raw[$dateKey]) && trim((string)$raw[$dateKey]) !== '') { $lastAt = trim((string)$raw[$dateKey]); break; }
            }
            $key = $email !== '' ? 'email:' . strtolower($email) : ($name !== '' ? 'name:' . strtolower($name) : 'anon:' . (++$anon));
            if (!isset($clients[$key])) $clients[$key] = array('display' => $name !== '' ? $name : ($email !== '' ? $email : 'Cliente sin datos'), 'name' => $name, 'email' => $email, 'phone' => $phone, 'registered' => $email !== '' && isset($registered[strtolower($email)]), 'ticketCount' => 0, 'events' => array(), 'sources' => array(), 'lastAt' => null);
            $clients[$key]['ticketCount']++;
            if ($clients[$key]['phone'] === '' && $phone !== '') $clients[$key]['phone'] = $phone;
            $eventName = (string)$event['nombre'];
            if (!in_array($eventName, $clients[$key]['events'], true)) $clients[$key]['events'][] = $eventName;
            $source = isset($entry['source']) ? (string)$entry['source'] : 'STR';
            if (!in_array($source, $clients[$key]['sources'], true)) $clients[$key]['sources'][] = $source;
            if ($lastAt !== '' && ($clients[$key]['lastAt'] === null || strtotime($lastAt) > strtotime($clients[$key]['lastAt']))) $clients[$key]['lastAt'] = $lastAt;
        }
    }
    $items = array_values($clients);
    $items = array_values(array_filter($items, function($client) use ($q, $group, $contact) {
        if ($group === 'registered' && !$client['registered']) return false;
        if ($group === 'guests' && $client['registered']) return false;
        if ($contact === 'email' && $client['email'] === '') return false;
        if ($contact === 'whatsapp' && $client['phone'] === '') return false;
        if ($q === '') return true;
        foreach (array($client['display'], $client['email'], $client['phone'], implode(' ', $client['events'])) as $value) {
            if (function_exists('mb_stripos') ? mb_stripos($value, $q, 0, 'UTF-8') !== false : stripos($value, $q) !== false) return true;
        }
        return false;
    }));
    usort($items, function($a, $b) use ($sort) {
        if ($sort === 'az') return strcasecmp($a['display'], $b['display']);
        $at = $a['lastAt'] ? strtotime($a['lastAt']) : 0;
        $bt = $b['lastAt'] ? strtotime($b['lastAt']) : 0;
        if ($at === $bt) return strcasecmp($a['display'], $b['display']);
        return $at > $bt ? -1 : 1;
    });
    $total = count($items);
    return array('total' => $total, 'items' => array_slice($items, $offset, $limit), 'nextOffset' => $offset + $limit < $total ? $offset + $limit : null);
}
