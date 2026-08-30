<?php

if (!function_exists('communication_contacts_add_row')) {
    function communication_contacts_add_row(&$contacts, $email, $source, $data)
    {
        $email = trim((string)$email);
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) return;

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
                'imported_batches' => array(),
                'imported_files' => array(),
                'imported_at' => '',
                'source' => '',
                'import_batch' => '',
                'import_file' => '',
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

        if (!empty($data['imported_batches']) && is_array($data['imported_batches'])) {
            foreach ($data['imported_batches'] as $batch) {
                $batch = trim((string)$batch);
                if ($batch !== '') {
                    $contacts[$key]['imported_batches'][$batch] = true;
                }
            }
        }

        if (!empty($data['imported_files']) && is_array($data['imported_files'])) {
            foreach ($data['imported_files'] as $file) {
                $file = trim((string)$file);
                if ($file !== '') {
                    $contacts[$key]['imported_files'][$file] = true;
                }
            }
        }

        if (!empty($data['imported_at'])) {
            $cur = (string)$contacts[$key]['imported_at'];
            $new = (string)$data['imported_at'];
            if ($cur === '' || $new > $cur) {
                $contacts[$key]['imported_at'] = $new;
            }
        }
    }
}

if (!function_exists('communication_contacts_imports_ensure_schema')) {
    function communication_contacts_imports_ensure_schema($pdo)
    {
        try {
            $pdo->exec("CREATE TABLE IF NOT EXISTS communication_contacts_imports (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                organization_id INTEGER NOT NULL DEFAULT 1,
                created_by_admin_id INTEGER,
                source TEXT,
                import_batch TEXT,
                import_file TEXT,
                imported_at TEXT,
                batch_label TEXT,
                email TEXT NOT NULL,
                nombre TEXT,
                rol TEXT,
                created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
            )");
            $pdo->exec("CREATE INDEX IF NOT EXISTS idx_comm_contacts_imports_email ON communication_contacts_imports(email COLLATE NOCASE)");
            $pdo->exec("CREATE INDEX IF NOT EXISTS idx_comm_contacts_imports_batch ON communication_contacts_imports(batch_label)");
            $pdo->exec("CREATE INDEX IF NOT EXISTS idx_comm_contacts_imports_admin ON communication_contacts_imports(created_by_admin_id)");
            $pdo->exec("CREATE INDEX IF NOT EXISTS idx_comm_contacts_imports_created_at ON communication_contacts_imports(created_at)");

            $hasSource = communication_contacts_table_has_column($pdo, 'communication_contacts_imports', 'source');
            if (!$hasSource) {
                $pdo->exec("ALTER TABLE communication_contacts_imports ADD COLUMN source TEXT");
            }
            $hasImportBatch = communication_contacts_table_has_column($pdo, 'communication_contacts_imports', 'import_batch');
            if (!$hasImportBatch) {
                $pdo->exec("ALTER TABLE communication_contacts_imports ADD COLUMN import_batch TEXT");
            }
            $hasImportFile = communication_contacts_table_has_column($pdo, 'communication_contacts_imports', 'import_file');
            if (!$hasImportFile) {
                $pdo->exec("ALTER TABLE communication_contacts_imports ADD COLUMN import_file TEXT");
            }
            $hasImportedAt = communication_contacts_table_has_column($pdo, 'communication_contacts_imports', 'imported_at');
            if (!$hasImportedAt) {
                $pdo->exec("ALTER TABLE communication_contacts_imports ADD COLUMN imported_at TEXT");
            }

            try {
                $pdo->exec("UPDATE communication_contacts_imports SET source = 'import_csv' WHERE source IS NULL OR source = ''");
                $pdo->exec("UPDATE communication_contacts_imports SET import_batch = batch_label WHERE (import_batch IS NULL OR import_batch = '') AND batch_label IS NOT NULL");
                $pdo->exec("UPDATE communication_contacts_imports SET imported_at = created_at WHERE imported_at IS NULL OR imported_at = ''");
            } catch (Exception $e) {
                // ignore
            }
        } catch (Exception $e) {
            // ignore
        }
    }
}

if (!function_exists('communication_contacts_table_has_column')) {
    function communication_contacts_table_has_column($pdo, $table, $column)
    {
        try {
            $st = $pdo->query("PRAGMA table_info(" . $table . ")");
            while ($r = $st->fetch(PDO::FETCH_ASSOC)) {
                if (!empty($r['name']) && (string)$r['name'] === (string)$column) {
                    return true;
                }
            }
        } catch (Exception $e) {
            // ignore
        }
        return false;
    }
}

if (!function_exists('communication_contacts_sql_in_ints')) {
    function communication_contacts_sql_in_ints($values)
    {
        $values = is_array($values) ? $values : array();
        $out = array();
        foreach ($values as $v) {
            $n = (int)$v;
            if ($n > 0) {
                $out[$n] = $n;
            }
        }
        if (empty($out)) {
            return '0';
        }
        return implode(',', array_values($out));
    }
}

if (!function_exists('communication_contacts_event_ids_for_admin')) {
    function communication_contacts_event_ids_for_admin($pdo, $adminId)
    {
        $adminId = (int)$adminId;
        if ($adminId <= 0) return array();

        $eventIds = array();
        try {
            if (!communication_contacts_table_has_column($pdo, 'eventos', 'creado_por_admin_id')) {
                return array();
            }
            $st = $pdo->prepare('SELECT id FROM eventos WHERE creado_por_admin_id = :aid');
            $st->execute(array(':aid' => $adminId));
            while ($r = $st->fetch(PDO::FETCH_ASSOC)) {
                $eid = isset($r['id']) ? (int)$r['id'] : 0;
                if ($eid > 0) $eventIds[$eid] = $eid;
            }
        } catch (Exception $e) {
            // ignore
        }

        return array_values($eventIds);
    }
}

if (!function_exists('communication_contacts_enrich_existing')) {
    function communication_contacts_enrich_existing($pdo, &$contacts)
    {
        if (!is_array($contacts) || empty($contacts)) return;

        try {
            $stUsers = $pdo->query("SELECT email, COALESCE(nombre,'') AS nombre, COALESCE(apellido,'') AS apellido, COALESCE(rol,'') AS rol FROM usuarios");
            while ($r = $stUsers->fetch(PDO::FETCH_ASSOC)) {
                $email = isset($r['email']) ? trim((string)$r['email']) : '';
                if ($email === '') continue;
                $k = strtolower($email);
                if (!isset($contacts[$k])) continue;

                $nom = trim((string)$r['nombre'] . ' ' . (string)$r['apellido']);
                if ($nom !== '' && $contacts[$k]['nombre'] === '') {
                    $contacts[$k]['nombre'] = $nom;
                }
                if (!empty($r['rol']) && $contacts[$k]['rol'] === '') {
                    $contacts[$k]['rol'] = (string)$r['rol'];
                }
                $contacts[$k]['registrado'] = 'Si';
                $contacts[$k]['fuentes']['usuarios'] = true;
            }
        } catch (Exception $e) {
            // ignore
        }

        try {
            $stReg = $pdo->query("SELECT email, COALESCE(nombre,'') AS nombre, COALESCE(apellido,'') AS apellido FROM registro_pendientes");
            while ($r = $stReg->fetch(PDO::FETCH_ASSOC)) {
                $email = isset($r['email']) ? trim((string)$r['email']) : '';
                if ($email === '') continue;
                $k = strtolower($email);
                if (!isset($contacts[$k])) continue;

                $nom = trim((string)$r['nombre'] . ' ' . (string)$r['apellido']);
                if ($nom !== '' && $contacts[$k]['nombre'] === '') {
                    $contacts[$k]['nombre'] = $nom;
                }
                $contacts[$k]['registrado'] = 'Si';
                $contacts[$k]['fuentes']['registro_pendientes'] = true;
            }
        } catch (Exception $e) {
            // ignore
        }
    }
}

if (!function_exists('communication_contacts_resolve')) {
    function communication_contacts_resolve($pdo, $scope)
    {
        $scope = is_array($scope) ? $scope : array();
        $isSuper = !empty($scope['is_super']);
        $adminId = isset($scope['admin_id']) ? (int)$scope['admin_id'] : 0;
        $contacts = array();

        communication_contacts_imports_ensure_schema($pdo);

        if ($isSuper || $adminId <= 0) {
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

            try {
                $stImported = $pdo->query("SELECT email, MAX(COALESCE(nombre,'')) AS nombre, MAX(COALESCE(rol,'')) AS rol, GROUP_CONCAT(DISTINCT COALESCE(import_batch,'')) AS batches, GROUP_CONCAT(DISTINCT COALESCE(import_file,'')) AS files, MAX(COALESCE(imported_at,'')) AS imported_at FROM communication_contacts_imports WHERE email IS NOT NULL AND email <> '' GROUP BY lower(email)");
                while ($r = $stImported->fetch(PDO::FETCH_ASSOC)) {
                    $batches = array();
                    if (!empty($r['batches'])) {
                        $chunks = explode(',', (string)$r['batches']);
                        foreach ($chunks as $ch) {
                            $b = trim((string)$ch);
                            if ($b !== '') $batches[] = $b;
                        }
                    }
                    $files = array();
                    if (!empty($r['files'])) {
                        $chunks = explode(',', (string)$r['files']);
                        foreach ($chunks as $ch) {
                            $f = trim((string)$ch);
                            if ($f !== '') $files[] = $f;
                        }
                    }
                    communication_contacts_add_row($contacts, $r['email'], 'import_csv', array(
                        'nombre' => isset($r['nombre']) ? $r['nombre'] : '',
                        'rol' => isset($r['rol']) ? $r['rol'] : '',
                        'imported_batches' => $batches,
                        'imported_files' => $files,
                        'imported_at' => isset($r['imported_at']) ? $r['imported_at'] : '',
                    ));
                }
            } catch (Exception $e) {
                // ignore
            }

            $stBlocked = $pdo->query("SELECT lower(email) AS email_key FROM user_blocks WHERE active = 1");
            while ($r = $stBlocked->fetch(PDO::FETCH_ASSOC)) {
                $k = (string)$r['email_key'];
                if (isset($contacts[$k])) {
                    $contacts[$k]['bloqueado'] = 1;
                }
            }
        } else {
            $eventIds = communication_contacts_event_ids_for_admin($pdo, $adminId);
            $inEvents = communication_contacts_sql_in_ints($eventIds);

            // 1) Contactos que compraron/recibieron entradas en eventos del admin
            try {
                $sqlEntradas = "SELECT email, MAX(fecha_registro) AS ultima_entrada, MAX(COALESCE(nombre,'')) AS nombre, COUNT(*) AS tickets_count, GROUP_CONCAT(DISTINCT evento_id) AS event_ids
                                FROM entradas
                                WHERE email IS NOT NULL AND email <> '' AND evento_id IN (" . $inEvents . ")
                                GROUP BY lower(email)";
                $stEntradas = $pdo->query($sqlEntradas);
                while ($r = $stEntradas->fetch(PDO::FETCH_ASSOC)) {
                    $eventList = array();
                    if (!empty($r['event_ids'])) {
                        $chunks = explode(',', (string)$r['event_ids']);
                        foreach ($chunks as $ch) {
                            $eid = (int)trim($ch);
                            if ($eid > 0) $eventList[] = $eid;
                        }
                    }
                    communication_contacts_add_row($contacts, $r['email'], 'entradas', array(
                        'nombre' => isset($r['nombre']) ? $r['nombre'] : '',
                        'ultima_entrada' => isset($r['ultima_entrada']) ? $r['ultima_entrada'] : '',
                        'tickets_count' => isset($r['tickets_count']) ? (int)$r['tickets_count'] : 0,
                        'event_ids' => $eventList,
                    ));
                }
            } catch (Exception $e) {
                // ignore
            }

            // 2) Emails enviados por este admin en el flujo de Tickex vinculado a entradas
            try {
                $sqlLogs = "SELECT l.to_email AS email, MAX(l.created_at) AS ultimo_envio
                            FROM email_logs l
                            JOIN entradas e ON e.id = l.related_id
                            WHERE l.related_table = 'entradas'
                              AND l.to_email IS NOT NULL AND l.to_email <> ''
                              AND e.evento_id IN (" . $inEvents . ")
                            GROUP BY lower(l.to_email)";
                $stLogs = $pdo->query($sqlLogs);
                while ($r = $stLogs->fetch(PDO::FETCH_ASSOC)) {
                    communication_contacts_add_row($contacts, $r['email'], 'email_logs', array(
                        'ultimo_envio' => isset($r['ultimo_envio']) ? $r['ultimo_envio'] : '',
                    ));
                }
            } catch (Exception $e) {
                // ignore
            }

            try {
                $stImported = $pdo->prepare("SELECT email, MAX(COALESCE(nombre,'')) AS nombre, MAX(COALESCE(rol,'')) AS rol, GROUP_CONCAT(DISTINCT COALESCE(import_batch,'')) AS batches, GROUP_CONCAT(DISTINCT COALESCE(import_file,'')) AS files, MAX(COALESCE(imported_at,'')) AS imported_at
                                             FROM communication_contacts_imports
                                             WHERE created_by_admin_id = :aid AND email IS NOT NULL AND email <> ''
                                             GROUP BY lower(email)");
                $stImported->execute(array(':aid' => $adminId));
                while ($r = $stImported->fetch(PDO::FETCH_ASSOC)) {
                    $batches = array();
                    if (!empty($r['batches'])) {
                        $chunks = explode(',', (string)$r['batches']);
                        foreach ($chunks as $ch) {
                            $b = trim((string)$ch);
                            if ($b !== '') $batches[] = $b;
                        }
                    }
                    $files = array();
                    if (!empty($r['files'])) {
                        $chunks = explode(',', (string)$r['files']);
                        foreach ($chunks as $ch) {
                            $f = trim((string)$ch);
                            if ($f !== '') $files[] = $f;
                        }
                    }
                    communication_contacts_add_row($contacts, $r['email'], 'import_csv', array(
                        'nombre' => isset($r['nombre']) ? $r['nombre'] : '',
                        'rol' => isset($r['rol']) ? $r['rol'] : '',
                        'imported_batches' => $batches,
                        'imported_files' => $files,
                        'imported_at' => isset($r['imported_at']) ? $r['imported_at'] : '',
                    ));
                }
            } catch (Exception $e) {
                // ignore
            }

            // 3) Contactos bloqueados por este admin (visibles para poder gestionarlos)
            try {
                $stBlocked = $pdo->prepare("SELECT lower(email) AS email_key, email FROM user_blocks WHERE active = 1 AND blocked_by_admin_id = :aid");
                $stBlocked->execute(array(':aid' => $adminId));
                while ($r = $stBlocked->fetch(PDO::FETCH_ASSOC)) {
                    $email = isset($r['email']) ? (string)$r['email'] : '';
                    if ($email === '') {
                        $email = isset($r['email_key']) ? (string)$r['email_key'] : '';
                    }
                    if ($email !== '') {
                        communication_contacts_add_row($contacts, $email, 'user_blocks', array());
                    }
                }
            } catch (Exception $e) {
                // ignore
            }

            // Enriquecer solo los contactos del alcance encontrado
            communication_contacts_enrich_existing($pdo, $contacts);

            // Marcar bloqueados activos dentro del subconjunto
            try {
                $stBlockedAll = $pdo->query("SELECT lower(email) AS email_key FROM user_blocks WHERE active = 1");
                while ($r = $stBlockedAll->fetch(PDO::FETCH_ASSOC)) {
                    $k = (string)$r['email_key'];
                    if (isset($contacts[$k])) {
                        $contacts[$k]['bloqueado'] = 1;
                    }
                }
            } catch (Exception $e) {
                // ignore
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

            $normalizedBatches = array();
            $rawBatches = isset($row['imported_batches']) ? $row['imported_batches'] : array();
            if (is_array($rawBatches) && !empty($rawBatches)) {
                foreach ($rawBatches as $batch => $v) {
                    if (is_int($batch) && !is_bool($v)) {
                        $label = trim((string)$v);
                    } else {
                        $label = trim((string)$batch);
                    }
                    if ($label !== '') {
                        $normalizedBatches[$label] = true;
                    }
                }
            }
            $contacts[$k]['imported_batches'] = array_keys($normalizedBatches);

            $normalizedFiles = array();
            $rawFiles = isset($row['imported_files']) ? $row['imported_files'] : array();
            if (is_array($rawFiles) && !empty($rawFiles)) {
                foreach ($rawFiles as $file => $v) {
                    if (is_int($file) && !is_bool($v)) {
                        $label = trim((string)$v);
                    } else {
                        $label = trim((string)$file);
                    }
                    if ($label !== '') {
                        $normalizedFiles[$label] = true;
                    }
                }
            }
            $contacts[$k]['imported_files'] = array_keys($normalizedFiles);

            $sources = isset($row['fuentes']) && is_array($row['fuentes']) ? array_keys($row['fuentes']) : array();
            $contacts[$k]['source'] = implode(', ', $sources);
            $contacts[$k]['import_batch'] = !empty($contacts[$k]['imported_batches']) ? (string)$contacts[$k]['imported_batches'][0] : '';
            $contacts[$k]['import_file'] = !empty($contacts[$k]['imported_files']) ? (string)$contacts[$k]['imported_files'][0] : '';
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
        $allowedSources = array('usuarios', 'registro_pendientes', 'entradas', 'email_logs', 'import_csv');
        if (in_array($source, $allowedSources, true)) {
            $out['source'] = $source;
        }

        $importBatch = '';
        if (isset($raw['import_batch'])) {
            $importBatch = trim((string)$raw['import_batch']);
        } elseif (isset($raw['f_import_batch'])) {
            $importBatch = trim((string)$raw['f_import_batch']);
        }
        if ($importBatch !== '') {
            $out['import_batch'] = $importBatch;
        }

        $importFile = '';
        if (isset($raw['import_file'])) {
            $importFile = trim((string)$raw['import_file']);
        } elseif (isset($raw['f_import_file'])) {
            $importFile = trim((string)$raw['f_import_file']);
        }
        if ($importFile !== '') {
            $out['import_file'] = $importFile;
        }

        $importedFrom = isset($raw['imported_from']) ? trim((string)$raw['imported_from']) : '';
        if ($importedFrom !== '') {
            $out['imported_from'] = $importedFrom;
        }

        $importedTo = isset($raw['imported_to']) ? trim((string)$raw['imported_to']) : '';
        if ($importedTo !== '') {
            $out['imported_to'] = $importedTo;
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

            if (isset($filters['import_batch'])) {
                $batch = trim((string)$filters['import_batch']);
                $batches = isset($r['imported_batches']) && is_array($r['imported_batches']) ? $r['imported_batches'] : array();
                $has = false;
                foreach ($batches as $b) {
                    if ((string)$b === $batch) {
                        $has = true;
                        break;
                    }
                }
                if (!$has) continue;
            }

            if (isset($filters['import_file'])) {
                $fileNeedle = trim((string)$filters['import_file']);
                $files = isset($r['imported_files']) && is_array($r['imported_files']) ? $r['imported_files'] : array();
                $hasFile = false;
                foreach ($files as $f) {
                    if ((string)$f === $fileNeedle) {
                        $hasFile = true;
                        break;
                    }
                }
                if (!$hasFile) continue;
            }

            if (isset($filters['imported_from'])) {
                $from = trim((string)$filters['imported_from']);
                $importedAt = isset($r['imported_at']) ? trim((string)$r['imported_at']) : '';
                if ($importedAt === '' || substr($importedAt, 0, 10) < $from) continue;
            }

            if (isset($filters['imported_to'])) {
                $to = trim((string)$filters['imported_to']);
                $importedAt = isset($r['imported_at']) ? trim((string)$r['imported_at']) : '';
                if ($importedAt === '' || substr($importedAt, 0, 10) > $to) continue;
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

        usort($result, 'communication_contacts_compare_by_email');

        return $result;
    }
}

if (!function_exists('communication_contacts_compare_by_email')) {
    function communication_contacts_compare_by_email($a, $b)
    {
        return strcmp(
            strtolower((string)(isset($a['email']) ? $a['email'] : '')),
            strtolower((string)(isset($b['email']) ? $b['email'] : ''))
        );
    }
}

if (!function_exists('communication_contacts_count')) {
    function communication_contacts_count($pdo, $filters, $scope)
    {
        $all = communication_contacts_resolve($pdo, $scope);
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
