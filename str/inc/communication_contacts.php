<?php

if (!function_exists('communication_contacts_add_row')) {
    function communication_contacts_add_row(&$contacts, $email, $source, $data)
    {
        $email = trim((string)$email);
        if ($email === '') return;

        $key = strtolower($email);
        if (!isset($contacts[$key])) {
            $contacts[$key] = array(
                'email' => $email,
                'nombre' => '',
                'rol' => '',
                'registrado' => 'No',
                'fuentes' => array(),
                'ultimo_envio' => '',
                'ultima_entrada' => '',
                'bloqueado' => 0,
                'tickets_count' => 0,
                'event_ids' => array(),
            );
        }

        $contacts[$key]['fuentes'][$source] = true;

        if (!empty($data['nombre']) && $contacts[$key]['nombre'] === '') {
            $contacts[$key]['nombre'] = (string)$data['nombre'];
        }
        if (!empty($data['rol']) && $contacts[$key]['rol'] === '') {
            $contacts[$key]['rol'] = (string)$data['rol'];
        }
        if (!empty($data['registrado']) && $data['registrado'] === 'Si') {
            $contacts[$key]['registrado'] = 'Si';
        }
        if (!empty($data['ultimo_envio'])) {
            $cur = (string)$contacts[$key]['ultimo_envio'];
            $new = (string)$data['ultimo_envio'];
            if ($cur === '' || $new > $cur) {
                $contacts[$key]['ultimo_envio'] = $new;
            }
        }
        if (!empty($data['ultima_entrada'])) {
            $cur = (string)$contacts[$key]['ultima_entrada'];
            $new = (string)$data['ultima_entrada'];
            if ($cur === '' || $new > $cur) {
                $contacts[$key]['ultima_entrada'] = $new;
            }
        }
        if (isset($data['tickets_count'])) {
            $n = (int)$data['tickets_count'];
            if ($n > (int)$contacts[$key]['tickets_count']) {
                $contacts[$key]['tickets_count'] = $n;
            }
        }
        if (!empty($data['event_ids']) && is_array($data['event_ids'])) {
            foreach ($data['event_ids'] as $eid) {
                $eid = (int)$eid;
                if ($eid > 0) {
                    $contacts[$key]['event_ids'][$eid] = true;
                }
            }
        }
    }
}

if (!function_exists('communication_contacts_resolve')) {
    function communication_contacts_resolve($pdo)
    {
        $contacts = array();

        $stUsers = $pdo->query("SELECT email, COALESCE(nombre,'') AS nombre, COALESCE(apellido,'') AS apellido, COALESCE(rol,'') AS rol FROM usuarios");
        while ($r = $stUsers->fetch(PDO::FETCH_ASSOC)) {
            $nom = trim((string)$r['nombre'] . ' ' . (string)$r['apellido']);
            communication_contacts_add_row($contacts, $r['email'], 'usuarios', array(
                'nombre' => $nom,
                'rol' => isset($r['rol']) ? $r['rol'] : '',
                'registrado' => 'Si',
            ));
        }

        $stReg = $pdo->query("SELECT email, COALESCE(nombre,'') AS nombre, COALESCE(apellido,'') AS apellido FROM registro_pendientes");
        while ($r = $stReg->fetch(PDO::FETCH_ASSOC)) {
            $nom = trim((string)$r['nombre'] . ' ' . (string)$r['apellido']);
            communication_contacts_add_row($contacts, $r['email'], 'registro_pendientes', array(
                'nombre' => $nom,
                'registrado' => 'Si',
            ));
        }

        $stEntradas = $pdo->query("SELECT email, MAX(fecha_registro) AS ultima_entrada, MAX(COALESCE(nombre,'')) AS nombre, COUNT(*) AS tickets_count, GROUP_CONCAT(DISTINCT evento_id) AS event_ids FROM entradas WHERE email IS NOT NULL AND email <> '' GROUP BY lower(email)");
        while ($r = $stEntradas->fetch(PDO::FETCH_ASSOC)) {
            $eventIds = array();
            if (!empty($r['event_ids'])) {
                $chunks = explode(',', (string)$r['event_ids']);
                foreach ($chunks as $ch) {
                    $eid = (int)trim($ch);
                    if ($eid > 0) $eventIds[] = $eid;
                }
            }
            communication_contacts_add_row($contacts, $r['email'], 'entradas', array(
                'nombre' => isset($r['nombre']) ? $r['nombre'] : '',
                'ultima_entrada' => isset($r['ultima_entrada']) ? $r['ultima_entrada'] : '',
                'tickets_count' => isset($r['tickets_count']) ? (int)$r['tickets_count'] : 0,
                'event_ids' => $eventIds,
            ));
        }

        $stLogs = $pdo->query("SELECT to_email AS email, MAX(created_at) AS ultimo_envio FROM email_logs WHERE to_email IS NOT NULL AND to_email <> '' GROUP BY lower(to_email)");
        while ($r = $stLogs->fetch(PDO::FETCH_ASSOC)) {
            communication_contacts_add_row($contacts, $r['email'], 'email_logs', array(
                'ultimo_envio' => isset($r['ultimo_envio']) ? $r['ultimo_envio'] : '',
            ));
        }

        $stBlocked = $pdo->query("SELECT lower(email) AS email_key FROM user_blocks WHERE active = 1");
        while ($r = $stBlocked->fetch(PDO::FETCH_ASSOC)) {
            $k = (string)$r['email_key'];
            if (isset($contacts[$k])) {
                $contacts[$k]['bloqueado'] = 1;
            }
        }

        foreach ($contacts as $k => $row) {
            $normalizedEvents = array();
            $rawEvents = isset($row['event_ids']) ? $row['event_ids'] : array();
            if (is_array($rawEvents) && !empty($rawEvents)) {
                foreach ($rawEvents as $eid => $v) {
                    // Puede venir como mapa [id=>true] o lista [id,id]
                    if (is_int($eid) && !is_bool($v)) {
                        $idNum = (int)$v;
                    } else {
                        $idNum = (int)$eid;
                    }
                    if ($idNum > 0) {
                        $normalizedEvents[$idNum] = true;
                    }
                }
            }
            $contacts[$k]['event_ids'] = array_keys($normalizedEvents);
        }

        return $contacts;
    }
}

if (!function_exists('communication_contacts_normalize_filters')) {
    function communication_contacts_normalize_filters($raw)
    {
        $raw = is_array($raw) ? $raw : array();
        $out = array();

        $q = isset($raw['q']) ? trim((string)$raw['q']) : '';
        if ($q !== '') $out['q'] = $q;

        $registered = '';
        if (isset($raw['registered'])) {
            $registered = trim((string)$raw['registered']);
        } elseif (isset($raw['f_registered'])) {
            $registered = trim((string)$raw['f_registered']);
        }
        if ($registered === 'si' || $registered === 'yes') $out['registered'] = 'yes';
        if ($registered === 'no') $out['registered'] = 'no';

        $blocked = '';
        if (isset($raw['blocked'])) {
            $blocked = trim((string)$raw['blocked']);
        } elseif (isset($raw['f_blocked'])) {
            $blocked = trim((string)$raw['f_blocked']);
        }
        if ($blocked === '1' || $blocked === 'yes') $out['blocked'] = 'yes';
        if ($blocked === '0' || $blocked === 'no') $out['blocked'] = 'no';

        $source = '';
        if (isset($raw['source'])) {
            $source = trim((string)$raw['source']);
        } elseif (isset($raw['f_source'])) {
            $source = trim((string)$raw['f_source']);
        }
        $allowedSources = array('usuarios', 'registro_pendientes', 'entradas', 'email_logs');
        if (in_array($source, $allowedSources, true)) {
            $out['source'] = $source;
        }

        $role = isset($raw['role']) ? trim((string)$raw['role']) : '';
        if ($role !== '') $out['role'] = $role;

        $buyer = isset($raw['buyer']) ? trim((string)$raw['buyer']) : '';
        if ($buyer === 'yes' || $buyer === 'no') {
            $out['buyer'] = $buyer;
        }

        $eventId = isset($raw['event_id']) ? (int)$raw['event_id'] : 0;
        if ($eventId > 0) {
            $out['event_id'] = $eventId;
        }

        return $out;
    }
}

if (!function_exists('communication_contacts_apply_filters')) {
    function communication_contacts_apply_filters($rows, $filters)
    {
        $rows = is_array($rows) ? $rows : array();
        $filters = communication_contacts_normalize_filters($filters);

        $result = array();
        foreach ($rows as $r) {
            if (!is_array($r)) continue;

            if (isset($filters['q'])) {
                $needle = strtolower((string)$filters['q']);
                $hay = strtolower((string)(
                    (isset($r['email']) ? $r['email'] : '') . ' ' .
                    (isset($r['nombre']) ? $r['nombre'] : '') . ' ' .
                    (isset($r['rol']) ? $r['rol'] : '')
                ));
                if (strpos($hay, $needle) === false) continue;
            }

            if (isset($filters['registered'])) {
                $isReg = (isset($r['registrado']) && $r['registrado'] === 'Si');
                if ($filters['registered'] === 'yes' && !$isReg) continue;
                if ($filters['registered'] === 'no' && $isReg) continue;
            }

            if (isset($filters['blocked'])) {
                $isBlocked = !empty($r['bloqueado']);
                if ($filters['blocked'] === 'yes' && !$isBlocked) continue;
                if ($filters['blocked'] === 'no' && $isBlocked) continue;
            }

            if (isset($filters['source'])) {
                $src = (string)$filters['source'];
                if (empty($r['fuentes']) || !is_array($r['fuentes']) || !isset($r['fuentes'][$src])) continue;
            }

            if (isset($filters['role'])) {
                $role = strtolower(trim((string)$filters['role']));
                if ($role !== strtolower(trim((string)(isset($r['rol']) ? $r['rol'] : '')))) continue;
            }

            if (isset($filters['buyer'])) {
                $isBuyer = ((int)(isset($r['tickets_count']) ? $r['tickets_count'] : 0) > 0);
                if ($filters['buyer'] === 'yes' && !$isBuyer) continue;
                if ($filters['buyer'] === 'no' && $isBuyer) continue;
            }

            if (isset($filters['event_id'])) {
                $eid = (int)$filters['event_id'];
                $events = (isset($r['event_ids']) && is_array($r['event_ids'])) ? $r['event_ids'] : array();
                $ok = false;
                foreach ($events as $x) {
                    if ((int)$x === $eid) { $ok = true; break; }
                }
                if (!$ok) continue;
            }

            $result[] = $r;
        }

        usort($result, function ($a, $b) {
            return strcmp(
                strtolower((string)(isset($a['email']) ? $a['email'] : '')),
                strtolower((string)(isset($b['email']) ? $b['email'] : ''))
            );
        });

        return $result;
    }
}

if (!function_exists('communication_contacts_count')) {
    function communication_contacts_count($pdo, $filters)
    {
        $all = communication_contacts_resolve($pdo);
        $filtered = communication_contacts_apply_filters(array_values($all), $filters);
        return count($filtered);
    }
}

if (!function_exists('communication_contacts_filters_to_json')) {
    function communication_contacts_filters_to_json($filters)
    {
        $normalized = communication_contacts_normalize_filters($filters);
        if (empty($normalized)) return null;
        return json_encode($normalized, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}

if (!function_exists('communication_contacts_filters_from_json')) {
    function communication_contacts_filters_from_json($json)
    {
        $json = trim((string)$json);
        if ($json === '') return array();
        $decoded = json_decode($json, true);
        if (!is_array($decoded)) return array();
        return communication_contacts_normalize_filters($decoded);
    }
}
