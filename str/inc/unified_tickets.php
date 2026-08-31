<?php
/**
 * inc/unified_tickets.php
 * Funciones para unificar entradas de STR y Tickex/SenForms en panel_evento.php
 */

/**
 * Detecta qué columnas existen en una tabla
 * @return array Mapa de columnas disponibles
 */
function detect_table_columns($pdo, $table_name) {
    static $cache = array();
    
    if (isset($cache[$table_name])) {
        return $cache[$table_name];
    }
    
    $cols = array();
    try {
        $stmt = $pdo->query("PRAGMA table_info($table_name)");
        if ($stmt) {
            $colsInfo = $stmt->fetchAll(PDO::FETCH_ASSOC);
            foreach ($colsInfo as $col) {
                $cols[$col['name']] = true;
            }
        }
    } catch (Exception $e) {
        // tabla no existe
    }
    
    $cache[$table_name] = $cols;
    return $cols;
}

/**
 * Construye la cláusula SQL para excluir entradas ocultas no escaneadas.
 *
 * Actualmente deshabilitado para que todas las ventas se muestren en el panel.
 */
function build_hidden_entries_where_clause($pdo, $colCheck = null) {
    return '';
}

/**
 * Obtiene la columna de check-in de entradas
 */
function get_checkin_column($pdo) {
    $cols = detect_table_columns($pdo, 'entradas');
    if (isset($cols['checkin'])) return 'checkin';
    if (isset($cols['checked_in'])) return 'checked_in';
    return 'checked_in'; // fallback
}

function has_table($pdo, $table_name) {
    try {
        $stmt = $pdo->prepare("SELECT 1 FROM sqlite_master WHERE type='table' AND name = :name LIMIT 1");
        $stmt->execute(array(':name' => $table_name));
        return (bool)$stmt->fetchColumn();
    } catch (Exception $e) {
        return false;
    }
}

function get_bridge_mapping_table_name($pdo) {
    if (has_table($pdo, 'bridge_event_map')) {
        return 'bridge_event_map';
    }
    if (has_table($pdo, 'tickex_event_map')) {
        return 'tickex_event_map';
    }
    return null;
}

function ensure_bridge_checkin_usage_table($pdo) {
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS bridge_checkin_uses (
            bridge_ticket_id INTEGER PRIMARY KEY,
            used_count INTEGER NOT NULL DEFAULT 0,
            updated_at DATETIME DEFAULT (datetime('now'))
        )");
    } catch (Exception $e) {
        // ignore
    }
}

function get_bridge_checkin_used_counts($pdo, $ticketIds) {
    $out = array();
    if (!is_array($ticketIds) || empty($ticketIds)) return $out;
    ensure_bridge_checkin_usage_table($pdo);
    $ids = array();
    foreach ($ticketIds as $id) {
        $v = (int)$id;
        if ($v > 0 && !in_array($v, $ids, true)) $ids[] = $v;
    }
    if (empty($ids)) return $out;
    try {
        $ph = array();
        $params = array();
        foreach ($ids as $i => $idv) {
            $k = ':i' . $i;
            $ph[] = $k;
            $params[$k] = $idv;
        }
        $st = $pdo->prepare("SELECT bridge_ticket_id, used_count FROM bridge_checkin_uses WHERE bridge_ticket_id IN (" . implode(',', $ph) . ")");
        $st->execute($params);
        while ($r = $st->fetch(PDO::FETCH_ASSOC)) {
            $out[(int)$r['bridge_ticket_id']] = max(0, (int)$r['used_count']);
        }
    } catch (Exception $e) {
        // ignore
    }
    return $out;
}

function increment_bridge_checkin_use($pdo, $bridgeTicketId, $maxUses) {
    $result = array('ok' => false, 'used' => 0, 'max' => max(1, (int)$maxUses), 'full' => false, 'changed' => false);
    $bridgeTicketId = (int)$bridgeTicketId;
    if ($bridgeTicketId <= 0) return $result;
    ensure_bridge_checkin_usage_table($pdo);
    try {
        $pdo->beginTransaction();
        $st = $pdo->prepare("SELECT used_count FROM bridge_checkin_uses WHERE bridge_ticket_id = :id LIMIT 1");
        $st->execute(array(':id' => $bridgeTicketId));
        $used = (int)$st->fetchColumn();
        $max = max(1, (int)$maxUses);
        $newUsed = $used;
        if ($used < $max) {
            $newUsed = $used + 1;
            if ($used > 0 || $st->rowCount() > 0) {
                $u = $pdo->prepare("UPDATE bridge_checkin_uses SET used_count = :u, updated_at = datetime('now') WHERE bridge_ticket_id = :id");
                $u->execute(array(':u' => $newUsed, ':id' => $bridgeTicketId));
            } else {
                $i = $pdo->prepare("INSERT INTO bridge_checkin_uses (bridge_ticket_id, used_count, updated_at) VALUES (:id, :u, datetime('now'))");
                $i->execute(array(':id' => $bridgeTicketId, ':u' => $newUsed));
            }
        }
        $pdo->commit();
        $result['ok'] = true;
        $result['used'] = $newUsed;
        $result['max'] = $max;
        $result['full'] = ($newUsed >= $max);
        $result['changed'] = ($newUsed > $used);
    } catch (Exception $e) {
        try { $pdo->rollBack(); } catch (Exception $_) {}
    }
    return $result;
}

function ensure_checkin_audit_log_table($pdo) {
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS checkin_audit_log (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            created_at DATETIME DEFAULT (datetime('now')),
            actor_user_id INTEGER,
            evento_id INTEGER,
            source TEXT,
            source_ticket_id INTEGER,
            ticket_ref TEXT,
            attendee_name TEXT,
            action TEXT,
            result TEXT,
            detail TEXT
        )");
    } catch (Exception $e) {
        // ignore
    }
}

function log_checkin_audit($pdo, $data = array()) {
    if (!is_array($data)) return false;
    ensure_checkin_audit_log_table($pdo);
    try {
        $st = $pdo->prepare("INSERT INTO checkin_audit_log (
            actor_user_id, evento_id, source, source_ticket_id, ticket_ref, attendee_name, action, result, detail
        ) VALUES (
            :actor_user_id, :evento_id, :source, :source_ticket_id, :ticket_ref, :attendee_name, :action, :result, :detail
        )");
        $st->execute(array(
            ':actor_user_id' => isset($data['actor_user_id']) ? (int)$data['actor_user_id'] : null,
            ':evento_id' => isset($data['evento_id']) ? (int)$data['evento_id'] : null,
            ':source' => isset($data['source']) ? (string)$data['source'] : '',
            ':source_ticket_id' => isset($data['source_ticket_id']) ? (int)$data['source_ticket_id'] : null,
            ':ticket_ref' => isset($data['ticket_ref']) ? (string)$data['ticket_ref'] : '',
            ':attendee_name' => isset($data['attendee_name']) ? (string)$data['attendee_name'] : '',
            ':action' => isset($data['action']) ? (string)$data['action'] : 'checkin',
            ':result' => isset($data['result']) ? (string)$data['result'] : '',
            ':detail' => isset($data['detail']) ? (string)$data['detail'] : '',
        ));
        return true;
    } catch (Exception $e) {
        return false;
    }
}

/**
 * Asegura que exista la tabla de mapeo entre evento STR y slug del bridge
 */
function ensure_bridge_event_map_table($pdo) {
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS bridge_event_map (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            evento_id INTEGER NOT NULL,
            bridge_slug TEXT NOT NULL,
            created_at DATETIME DEFAULT (datetime('now'))
        )");

        if (has_table($pdo, 'tickex_event_map') && has_table($pdo, 'bridge_event_map')) {
            $count = (int)$pdo->query("SELECT COUNT(*) FROM bridge_event_map")->fetchColumn();
            if ($count === 0) {
                $pdo->exec("INSERT OR IGNORE INTO bridge_event_map (evento_id, bridge_slug, created_at)
                    SELECT str_event_id, event_slug, datetime('now')
                    FROM tickex_event_map
                    WHERE event_slug IS NOT NULL AND event_slug <> ''");
            }
        }
    } catch (Exception $e) {
        // no bloquear flujo si falla
    }
}

/**
 * Obtener slugs mapeados para un evento STR
 * @return array lista de slugs (strings) o array vacío
 */
function get_mapped_bridge_slugs($pdo, $evento_id) {
    $table = get_bridge_mapping_table_name($pdo);
    if (!$table) {
        return array();
    }

    try {
        if ($table === 'bridge_event_map') {
            $stmt = $pdo->prepare("SELECT bridge_slug AS bridge_slug FROM bridge_event_map WHERE evento_id = :eid ORDER BY id ASC");
            $stmt->execute(array(':eid' => $evento_id));
        } else {
            $stmt = $pdo->prepare("SELECT event_slug AS bridge_slug FROM tickex_event_map WHERE str_event_id = :eid ORDER BY id ASC");
            $stmt->execute(array(':eid' => $evento_id));
        }

        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $slugs = array();
        foreach ($rows as $r) {
            if (!empty($r['bridge_slug'])) $slugs[] = $r['bridge_slug'];
        }
        return $slugs;
    } catch (Exception $e) {
        return array();
    }
}

/**
 * Setear (reemplazar) la mapping slug para un evento
 */
function set_bridge_mapping($pdo, $evento_id, $bridge_slug) {
    if (empty($bridge_slug)) return false;
    ensure_bridge_event_map_table($pdo);
    try {
        // eliminar mappings previos para este evento y agregar nuevo
        $pdo->beginTransaction();
        $d = $pdo->prepare("DELETE FROM bridge_event_map WHERE evento_id = :eid");
        $d->execute(array(':eid' => $evento_id));
        $i = $pdo->prepare("INSERT INTO bridge_event_map (evento_id, bridge_slug) VALUES (:eid, :slug)");
        $i->execute(array(':eid' => $evento_id, ':slug' => $bridge_slug));
        $pdo->commit();
        return true;
    } catch (Exception $e) {
        try { $pdo->rollBack(); } catch (Exception $_) {}
        return false;
    }
}

/**
 * Obtiene todas las entradas de ambas fuentes, unificadas
 * @param $pdo PDO connection
 * @param $evento_id int ID del evento
 * @param $filters array Filtros opcionales (q, tipo, estado)
 * @return array Lista unificada de entradas normalizadas
 */
function get_unified_entries($pdo, $evento_id, $filters = array()) {
    $entries = array();
    $colCheck = get_checkin_column($pdo);
    
    // ===== ENTRADAS STR =====
    $where = array("evento_id = :eid");
    $params = array(':eid' => $evento_id);
    
    if (!empty($filters['q'])) {
        $where[] = "(nombre LIKE :q OR email LIKE :q OR codigo LIKE :q)";
        $params[':q'] = '%' . $filters['q'] . '%';
    }
    if (!empty($filters['tipo'])) {
        $where[] = "tipo = :tipo";
        $params[':tipo'] = $filters['tipo'];
    }
    if (isset($filters['estado']) && $filters['estado'] === 'checkin_ok') {
        $where[] = "$colCheck = 1";
    } elseif (isset($filters['estado']) && $filters['estado'] === 'pendiente') {
        $where[] = "$colCheck = 0";
    }

    $hiddenClause = build_hidden_entries_where_clause($pdo, $colCheck);
    if ($hiddenClause !== '') {
        $where[] = substr($hiddenClause, 5); // remove leading ' AND '
    }
    
    $sql = "SELECT * FROM entradas WHERE " . implode(" AND ", $where) . " ORDER BY id DESC";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $strEntries = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    foreach ($strEntries as $row) {
        $tipo = isset($row['tipo']) ? trim((string)$row['tipo']) : '';
        $tipoUp = strtoupper($tipo);
        $monto = isset($row['monto_pagado']) ? (float)$row['monto_pagado'] : 0;

        // Clasificación de pago para STR
        // PAGA / PREPAGA => pagas
        // FREE / GRATIS => gratis
        // PUERTA => paga si monto > 0, gratis si 0
        // Otros => paga si monto > 0, si no, gratis
        $isPaid = false;
        if ($tipoUp === 'PAGA' || $tipoUp === 'PREPAGA') {
            $isPaid = true;
        } elseif ($tipoUp === 'FREE' || $tipoUp === 'GRATIS') {
            $isPaid = false;
        } elseif ($tipoUp === 'PUERTA') {
            $isPaid = ($monto > 0);
        } else {
            $isPaid = ($monto > 0);
        }

        $entries[] = array(
            'source'      => 'STR',
            'ticket_id'   => isset($row['id']) ? (int)$row['id'] : 0,
            'ticket_ref'  => isset($row['codigo']) ? $row['codigo'] : '',
            'nombre'      => isset($row['nombre']) ? $row['nombre'] : '',
            'email'       => isset($row['email']) ? $row['email'] : '',
            'tipo'        => $tipo,
            'price'       => $monto,
            'is_paid'     => $isPaid,
            'is_checked_in' => isset($row[$colCheck]) ? (int)$row[$colCheck] === 1 : false,
            'checked_in_at' => isset($row['checked_in_at']) ? $row['checked_in_at'] : null,
            'created_at'  => isset($row['created_at']) ? $row['created_at'] : (isset($row['fecha']) ? $row['fecha'] : null),
            'raw_row'     => $row,  // guardar fila completa para checkear/acciones
        );
    }
    
    // ===== ENTRADAS TICKEX/SENFORMS (si existen) =====
    // Detectar si existe la vista o tabla de bridge
    $hasBridgeView = false;
    $hasBridgeTable = false;
    $mappedSlugs = array();
    
    try {
        $stmt = $pdo->query("SELECT 1 FROM sqlite_master WHERE type='view' AND name='v_senforms_bridge_status' LIMIT 1");
        if ($stmt && $stmt->fetch()) {
            $hasBridgeView = true;
        }
    } catch (Exception $e) {
        // no existe la view
    }

    try {
        $stmt = $pdo->query("SELECT 1 FROM sqlite_master WHERE type='table' AND name='senforms_bridge_tickets' LIMIT 1");
        if ($stmt && $stmt->fetch()) {
            $hasBridgeTable = true;
        }
    } catch (Exception $e) {
        // no existe la tabla
    }
    
    // intentar obtener slugs mapeados para este evento (independiente de si es view o table)
    try {
        $mappedSlugs = get_mapped_bridge_slugs($pdo, $evento_id);
        if (empty($mappedSlugs)) {
            // intentar obtener slug desde la tabla de eventos STR (si existe)
            try {
                $sstmt = $pdo->prepare("SELECT slug FROM eventos WHERE id = :eid LIMIT 1");
                $sstmt->execute(array(':eid' => $evento_id));
                $srow = $sstmt->fetch(PDO::FETCH_ASSOC);
                if ($srow && !empty($srow['slug'])) {
                    $mappedSlugs = array($srow['slug']);
                }
            } catch (Exception $_e) {
                // tabla eventos no encontrada o error, seguir sin slug
            }
        }
    } catch (Exception $e) {
        // error al obtener mapping
    }
    
    if ($hasBridgeView || $hasBridgeTable) {
        // Detectar columnas de cada candidato para elegir el mejor (prefiere tabla si trae selected_type_name)
        $bridgeColsView = array();
        $bridgeColsTable = array();
        if ($hasBridgeView) {
            $bridgeColsView = detect_table_columns($pdo, 'v_senforms_bridge_status');
        }
        if ($hasBridgeTable) {
            $bridgeColsTable = detect_table_columns($pdo, 'senforms_bridge_tickets');
        }

        $useTable = false;
        if (!empty($bridgeColsTable)) {
            if (isset($bridgeColsTable['selected_type_name']) || isset($bridgeColsTable['selected_type'])) {
                $useTable = true; // tabla tiene info de tipo
            } elseif (!$hasBridgeView) {
                $useTable = true; // no hay view
            }
        }

        $source = ($useTable || !$hasBridgeView) ? 'senforms_bridge_tickets' : 'v_senforms_bridge_status';
        // Detectar columnas del bridge elegido
        $bridgeCols = $useTable ? $bridgeColsTable : ($hasBridgeView ? $bridgeColsView : array());
        
        // Detectar si hay columna de evento
        $eventCol = null;
        if (isset($bridgeCols['evento_id'])) {
            $eventCol = 'evento_id';
        } elseif (isset($bridgeCols['event_id'])) {
            $eventCol = 'event_id';
        } elseif (isset($bridgeCols['id_evento'])) {
            $eventCol = 'id_evento';
        }
        
        try {
            // Construir query para bridge
            $bWhere = array();
            $bParams = array();
            
            // Filtrar por estado pagado
            if (isset($bridgeCols['is_paid'])) {
                $bWhere[] = "is_paid = 1";
            } elseif (isset($bridgeCols['payment_state'])) {
                $bWhere[] = "UPPER(payment_state) IN ('SUCCESS','APROBADO','APPROVED','COMPLETED','PAID')";
            } elseif (isset($bridgeCols['payment_status'])) {
                $bWhere[] = "UPPER(payment_status) IN ('SUCCESS','APROBADO','APPROVED','COMPLETED','PAID')";
            } elseif (isset($bridgeCols['pago_status'])) {
                $bWhere[] = "UPPER(pago_status) IN ('SUCCESS','APROBADO','APPROVED','COMPLETED','PAID')";
            } elseif (isset($bridgeCols['pn_estado'])) {
                $bWhere[] = "UPPER(pn_estado) IN ('SUCCESS','APROBADO','APPROVED','COMPLETED','PAID')";
            } elseif (isset($bridgeCols['status'])) {
                $bWhere[] = "UPPER(status) IN ('SUCCESS','APROBADO','APPROVED','COMPLETED','PAID')";
            }
            
            // Filtrar por evento: preferir slugs mapeados -> event_slug
            if (!empty($mappedSlugs) && isset($bridgeCols['event_slug'])) {
                $placeholders = array();
                foreach ($mappedSlugs as $i => $s) {
                    $ph = ':slug' . $i;
                    $placeholders[] = $ph;
                    $bParams[$ph] = $s;
                }
                if (!empty($placeholders)) {
                    $bWhere[] = "event_slug IN (" . implode(',', $placeholders) . ")";
                }
            } elseif ($eventCol) {
                $bWhere[] = "$eventCol = :eid";
                $bParams[':eid'] = $evento_id;
            }
            
            $bSql = "SELECT * FROM $source";
            if (!empty($bWhere)) {
                $bSql .= " WHERE " . implode(" AND ", $bWhere);
            }
            // Determinar columna para ORDER BY según columnas disponibles en el source
            $orderBy = null;
            if (isset($bridgeCols['id'])) {
                $orderBy = 'id DESC';
            } elseif (isset($bridgeCols['last_updated_at'])) {
                $orderBy = 'last_updated_at DESC';
            } elseif (isset($bridgeCols['legacy_ticket_id'])) {
                $orderBy = 'legacy_ticket_id DESC';
            }
            if ($orderBy) {
                $bSql .= " ORDER BY " . $orderBy;
            }
            
            $bStmt = $pdo->prepare($bSql);
            $bStmt->execute($bParams);
            $tickexEntries = $bStmt->fetchAll(PDO::FETCH_ASSOC);

            $bridgeIds = array();
            foreach ($tickexEntries as $r0) {
                $tid0 = 0;
                if (isset($r0['id'])) $tid0 = (int)$r0['id'];
                elseif (isset($r0['legacy_ticket_id'])) $tid0 = (int)$r0['legacy_ticket_id'];
                if ($tid0 > 0) $bridgeIds[] = $tid0;
            }
            $usedMap = get_bridge_checkin_used_counts($pdo, $bridgeIds);
            
            // detectar columna de tipo para duplicar 2x1
            $tipoCols = array('selected_type_name','selected_type','ticket_type','ticket_name','product_name','entry_type','event_name','ticket_class','category','nombre','name','tipo');
            $tipoColFound = null;
            foreach ($tipoCols as $tc) {
                if (isset($bridgeCols[$tc])) { $tipoColFound = $tc; break; }
            }

            foreach ($tickexEntries as $row) {
                $ttype = '';
                foreach ($tipoCols as $tc) {
                    if (isset($row[$tc]) && trim((string)$row[$tc]) !== '') {
                        $ttype = (string)$row[$tc];
                        break;
                    }
                }
                if ($ttype === '') {
                    // última instancia: buscar cualquier campo string que contenga 2x1
                    foreach ($row as $v) {
                        if (is_string($v) && preg_match('/2\s*x\s*1/i', $v)) {
                            $ttype = $v;
                            break;
                        }
                    }
                }

                // cantidad explícita si la fuente trae quantity/cantidad
                $qtyCols = array('quantity','cantidad','qty','num_entries');
                $multiplier = 1;
                foreach ($qtyCols as $qc) {
                    if (isset($row[$qc]) && is_numeric($row[$qc])) {
                        $val = (int)$row[$qc];
                        if ($val > 1) { $multiplier = $val; break; }
                    }
                }

                $normType = preg_replace('/\s+/', '', strtolower($ttype));
                if (strpos($normType, '2x1') !== false && $multiplier < 2) {
                    $multiplier = 2;
                }

                // Precio real cobrado (bridge.price)
                $priceVal = 0.0; $priceDiv = 1;
                $priceFields = array('price','Price','amount','total_price','total_amount','valor','price_cents');
                foreach ($priceFields as $pf) {
                    if (isset($row[$pf]) && is_numeric($row[$pf])) {
                        $priceVal = (float)$row[$pf];
                        if ($pf === 'price_cents') { $priceDiv = 100; }
                        break;
                    }
                }
                if ($priceDiv !== 1) {
                    $priceVal = $priceVal / $priceDiv;
                }

                // Label limpio: quitar sufijo "- $xxxxx" y rearmar con precio real
                $ttypeDisplay = $ttype;
                if ($ttype !== '') {
                    $ttypeClean = trim(preg_replace('/-\s*\$[0-9.,]+$/', '', $ttype));
                    if ($priceVal > 0) {
                        $ttypeDisplay = $ttypeClean !== '' ? ($ttypeClean . ' - $' . number_format($priceVal, 0, ',', '.')) : ('$' . number_format($priceVal, 0, ',', '.'));
                    } else {
                        $ttypeDisplay = $ttypeClean !== '' ? $ttypeClean : $ttype;
                    }
                }

                // Detectar campos disponibles y normalizar para el bridge (v_senforms_bridge_status)
                $ticketId = 0;
                if (isset($row['id'])) $ticketId = (int)$row['id'];
                elseif (isset($row['legacy_ticket_id'])) $ticketId = (int)$row['legacy_ticket_id'];

                // ticket_ref puede venir como ticket_ref, order_id o codigo
                if (isset($row['ticket_ref'])) {
                    $ticketRef = $row['ticket_ref'];
                } elseif (isset($row['order_id'])) {
                    $ticketRef = $row['order_id'];
                } elseif (isset($row['codigo'])) {
                    $ticketRef = $row['codigo'];
                } else {
                    $ticketRef = '';
                }

                // Nombre: preferir first_name + last_name, luego buyer_name o nombre
                $nombre = '';
                if (isset($row['first_name']) || isset($row['last_name'])) {
                    $fn = isset($row['first_name']) ? trim($row['first_name']) : '';
                    $ln = isset($row['last_name']) ? trim($row['last_name']) : '';
                    $nombre = trim($fn . ' ' . $ln);
                }
                if ($nombre === '') {
                    if (isset($row['buyer_name'])) $nombre = $row['buyer_name'];
                    elseif (isset($row['nombre'])) $nombre = $row['nombre'];
                }

                // Email
                if (isset($row['buyer_email'])) $email = $row['buyer_email'];
                elseif (isset($row['email'])) $email = $row['email'];
                else $email = '';

                // Pago / checkin
                $isPaid = isset($row['is_paid']) ? ((int)$row['is_paid'] === 1) : false;
                // Fallbacks de pago: algunas vistas traen payment_state/status/pago_status/pn_estado
                if (!$isPaid) {
                    if (isset($row['payment_state'])) {
                        $state = strtoupper(trim((string)$row['payment_state']));
                        if (in_array($state, array('SUCCESS','APROBADO','APPROVED','COMPLETED','PAID'), true)) {
                            $isPaid = true;
                        }
                    } elseif (isset($row['payment_status'])) {
                        $state = strtoupper(trim((string)$row['payment_status']));
                        if (in_array($state, array('SUCCESS','APROBADO','APPROVED','COMPLETED','PAID'), true)) {
                            $isPaid = true;
                        }
                    } elseif (isset($row['pago_status'])) {
                        $state = strtoupper(trim((string)$row['pago_status']));
                        if (in_array($state, array('SUCCESS','APROBADO','APPROVED','COMPLETED','PAID'), true)) {
                            $isPaid = true;
                        }
                    } elseif (isset($row['pn_estado'])) {
                        $state = strtoupper(trim((string)$row['pn_estado']));
                        if (in_array($state, array('SUCCESS','APROBADO','APPROVED','COMPLETED','PAID'), true)) {
                            $isPaid = true;
                        }
                    } elseif (isset($row['status'])) {
                        $state = strtoupper(trim((string)$row['status']));
                        if (in_array($state, array('SUCCESS','APROBADO','APPROVED','COMPLETED','PAID'), true)) {
                            $isPaid = true;
                        }
                    }
                }

                // si no está pago, omitir la entrada (solo Success/Aprobado)
                if (!$isPaid) {
                    continue;
                }

                // Asegurar que el slug de ticket coincide con mapping actual
                if (!empty($mappedSlugs) && isset($row['event_slug'])) {
                    $slugRow = (string)$row['event_slug'];
                    $match = false;
                    foreach ($mappedSlugs as $s) {
                        if ($slugRow === $s) { $match = true; break; }
                    }
                    if (!$match) {
                        continue; // slug no mapea al evento
                    }
                }

                $isCheckedIn = false;
                if (isset($row['is_checked_in'])) $isCheckedIn = ((int)$row['is_checked_in'] === 1);
                elseif (isset($row['checked_in'])) $isCheckedIn = ((int)$row['checked_in'] === 1);

                $usedCount = isset($usedMap[$ticketId]) ? (int)$usedMap[$ticketId] : ($isCheckedIn ? $multiplier : 0);
                if ($usedCount < 0) $usedCount = 0;
                if ($usedCount > $multiplier) $usedCount = $multiplier;

                $checkedInAt = isset($row['checked_in_at']) ? $row['checked_in_at'] : null;

                // Fecha: preferir last_updated_at, luego created_at o fecha
                if (isset($row['last_updated_at'])) $createdAt = $row['last_updated_at'];
                elseif (isset($row['created_at'])) $createdAt = $row['created_at'];
                elseif (isset($row['fecha'])) $createdAt = $row['fecha'];
                else $createdAt = null;

                for ($i = 0; $i < $multiplier; $i++) {
                    $entries[] = array(
                        'source'      => 'TICKEX',
                        'ticket_id'   => $ticketId,
                        'ticket_ref'  => $multiplier > 1 ? ($ticketRef . '-'.($i+1)) : $ticketRef,
                        'nombre'      => $nombre,
                        'email'       => $email,
                        'tipo'        => $ttypeDisplay,
                        'price'       => $priceVal,
                        'is_paid'     => $isPaid,
                        'is_checked_in' => ($i < $usedCount),
                        'checked_in_at' => $checkedInAt,
                        'created_at'  => $createdAt,
                        'raw_row'     => $row,  // guardar para referencia
                    );
                }
            }
        } catch (Exception $e) {
            // Si falla el query del bridge, simplemente no incluimos esas entradas
            // (el panel sigue funcionando con STR)
        }
    }
    
    // Ordenar por fecha desc (newer first)
    usort($entries, function($a, $b) {
        $aTime = $a['created_at'] ? strtotime($a['created_at']) ?: 0 : 0;
        $bTime = $b['created_at'] ? strtotime($b['created_at']) ?: 0 : 0;
        return $bTime - $aTime;
    });
    
    return $entries;
}

/**
 * Cuenta estadísticas directamente desde la BD (sin cargar todas las filas)
 * Similar a stats_evento() pero unificando STR + TICKEX
 */
function get_unified_stats($pdo, $evento_id) {
    $stats = array(
        'total' => 0,           // entradas vendidas (STR + TICKEX)
        'checkins' => 0,        // entradas escaneadas
        'paid' => 0,            // entradas pagadas
        'pendiente' => 0,       // entradas sin escanear
        'disponibles' => null,  // cantidad disponible de tipos_entrada
        'stock_total' => null,  // cantidad total de tipos_entrada
    );
    
    // ===== OBTENER STOCK (tipos_entrada) =====
    try {
        $st = $pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name='tipos_entrada' LIMIT 1");
        if ($st && $st->fetch()) {
            // Obtener disponibles y stock total
            $stStock = $pdo->prepare("SELECT SUM(cantidad_disponible) as disp, SUM(cantidad_total) as tot FROM tipos_entrada WHERE evento_id = ?");
            $stStock->execute(array($evento_id));
            $row = $stStock->fetch(PDO::FETCH_ASSOC);
            if ($row) {
                if ($row['disp'] !== null) $stats['disponibles'] = (int)$row['disp'];
                if ($row['tot'] !== null) $stats['stock_total'] = (int)$row['tot'];
            }
        }
    } catch (Exception $e) {
        // tipos_entrada no existe, ignorar
    }
    
    // Guardar stock_total inicial antes de sumar entradas
    $stockTotalInicial = $stats['stock_total'] !== null ? $stats['stock_total'] : 0;
    
    // ===== CONTAR STR =====
    try {
        $colCheck = get_checkin_column($pdo);
        // Las métricas globales cuentan todo QR emitido, incluso los ocultos.
        // La ocultación sólo afecta el listado operativo hasta el check-in.
        $sqlStr = "SELECT tipo, monto_pagado FROM entradas WHERE evento_id = ?";
        $stmtStr = $pdo->prepare($sqlStr);
        $stmtStr->execute(array($evento_id));
        $strRows = $stmtStr->fetchAll(PDO::FETCH_ASSOC);
        
        $strTotal = count($strRows);
        $strPaid = 0;
        
        foreach ($strRows as $row) {
            $tipoUp = strtoupper(trim($row['tipo'] ?? ''));
            $monto = isset($row['monto_pagado']) ? (float)$row['monto_pagado'] : 0;
            
            $isPaid = false;
            if ($tipoUp === 'PAGA' || $tipoUp === 'PREPAGA') {
                $isPaid = true;
            } elseif ($tipoUp === 'FREE' || $tipoUp === 'GRATIS') {
                $isPaid = false;
            } elseif ($tipoUp === 'PUERTA') {
                $isPaid = ($monto > 0);
            } else {
                $isPaid = ($monto > 0);
            }
            
            if ($isPaid) {
                $strPaid++;
            }
        }
        
        $stats['total'] += $strTotal;
        $stats['paid'] += $strPaid;
        
        $sqlCheck = "SELECT COUNT(*) FROM entradas WHERE evento_id = ? AND $colCheck = 1";
        $stmtC = $pdo->prepare($sqlCheck);
        $stmtC->execute(array($evento_id));
        $stats['checkins'] += (int)$stmtC->fetchColumn();

    } catch (Exception $e) {
        // si falla, continuar sin STR
    }
    
    // ===== CONTAR TICKEX/BRIDGE =====
    try {
        // Detectar bridge (view y tabla)
        $hasBridgeView = false;
        $hasBridgeTable = false;
        try {
            $stmt = $pdo->query("SELECT 1 FROM sqlite_master WHERE type='view' AND name='v_senforms_bridge_status' LIMIT 1");
            if ($stmt && $stmt->fetch()) {
                $hasBridgeView = true;
            }
        } catch (Exception $_e) {}
        try {
            $stmt = $pdo->query("SELECT 1 FROM sqlite_master WHERE type='table' AND name='senforms_bridge_tickets' LIMIT 1");
            if ($stmt && $stmt->fetch()) {
                $hasBridgeTable = true;
            }
        } catch (Exception $_e) {}
        
        if (!$hasBridgeView && !$hasBridgeTable) {
            throw new Exception('Sin bridge');
        }

        // Elegir tabla si aporta selected_type_name/selected_type
        $bridgeColsView = $hasBridgeView ? detect_table_columns($pdo, 'v_senforms_bridge_status') : array();
        $bridgeColsTable = $hasBridgeTable ? detect_table_columns($pdo, 'senforms_bridge_tickets') : array();

        $useTable = false;
        if ($hasBridgeTable && (isset($bridgeColsTable['selected_type_name']) || isset($bridgeColsTable['selected_type']))) {
            $useTable = true;
        } elseif (!$hasBridgeView && $hasBridgeTable) {
            $useTable = true;
        }

        $source = ($useTable || !$hasBridgeView) ? 'senforms_bridge_tickets' : 'v_senforms_bridge_status';
        $bridgeCols = $useTable ? $bridgeColsTable : $bridgeColsView;
        
        // Obtener mapped slugs
        $mappedSlugs = get_mapped_bridge_slugs($pdo, $evento_id);
        if (empty($mappedSlugs)) {
            try {
                $sstmt = $pdo->prepare("SELECT slug FROM eventos WHERE id = :eid LIMIT 1");
                $sstmt->execute(array(':eid' => $evento_id));
                $srow = $sstmt->fetch(PDO::FETCH_ASSOC);
                if ($srow && !empty($srow['slug'])) {
                    $mappedSlugs = array($srow['slug']);
                }
            } catch (Exception $_e) {}
        }
        
        if (empty($mappedSlugs)) {
            throw new Exception('Sin slug mapping');
        }
        
        // Contar total de bridge entries (SOLO PAGADAS - consistente con get_unified_entries)
        $bWhere = array();
        $bParams = array();
        
        // Filtrar por pago (igual que en get_unified_entries)
        if (isset($bridgeCols['is_paid'])) {
            $bWhere[] = "is_paid = 1";
        } elseif (isset($bridgeCols['payment_state'])) {
            $bWhere[] = "UPPER(payment_state) IN ('SUCCESS','APROBADO','APPROVED','COMPLETED','PAID')";
        } elseif (isset($bridgeCols['payment_status'])) {
            $bWhere[] = "UPPER(payment_status) IN ('SUCCESS','APROBADO','APPROVED','COMPLETED','PAID')";
        } elseif (isset($bridgeCols['pago_status'])) {
            $bWhere[] = "UPPER(pago_status) IN ('SUCCESS','APROBADO','APPROVED','COMPLETED','PAID')";
        } elseif (isset($bridgeCols['pn_estado'])) {
            $bWhere[] = "UPPER(pn_estado) IN ('SUCCESS','APROBADO','APPROVED','COMPLETED','PAID')";
        } elseif (isset($bridgeCols['status'])) {
            $bWhere[] = "UPPER(status) IN ('SUCCESS','APROBADO','APPROVED','COMPLETED','PAID')";
        }
        
        // Filtrar por evento slug
        if (!empty($mappedSlugs) && isset($bridgeCols['event_slug'])) {
            $placeholders = array();
            foreach ($mappedSlugs as $i => $s) {
                $ph = ':slug' . $i;
                $placeholders[] = $ph;
                $bParams[$ph] = $s;
            }
            if (!empty($placeholders)) {
                $bWhere[] = "event_slug IN (" . implode(',', $placeholders) . ")";
            }
        }
        
        // Columna de tipo para factor 2x1
        $tipoCols = array('selected_type_name','selected_type','ticket_type','ticket_name','product_name','entry_type','event_name','ticket_class','category','nombre','name','tipo');
        $qtyCols = array('quantity','cantidad','qty','num_entries');
        $normConds = array();
        foreach ($tipoCols as $tc) {
            if (isset($bridgeCols[$tc])) {
                $normConds[] = "REPLACE(LOWER(".$tc."),' ','') LIKE '%2x1%'";
            }
        }

        // preferir cantidad explícita si existe
        $qtyExpr = null;
        foreach ($qtyCols as $qc) {
            if (isset($bridgeCols[$qc])) { $qtyExpr = $qc; break; }
        }

        $factorExpr = '1';
        if ($qtyExpr) {
            $textCond = !empty($normConds) ? ("(" . implode(' OR ', $normConds) . ")") : '0';
            $factorExpr = "CASE WHEN $qtyExpr >= 2 THEN $qtyExpr WHEN $textCond THEN 2 ELSE 1 END";
        } elseif (!empty($normConds)) {
            $factorExpr = "CASE WHEN (" . implode(' OR ', $normConds) . ") THEN 2 ELSE 1 END";
        }

        $bSql = "SELECT SUM(".$factorExpr.") FROM $source";
        if (!empty($bWhere)) {
            $bSql .= " WHERE " . implode(" AND ", $bWhere);
        }
        $bStmt = $pdo->prepare($bSql);
        $bStmt->execute($bParams);
        $stats['total'] += (int)$bStmt->fetchColumn();

        // Pagadas (is_paid = 1) ajustadas por 2x1
        if (isset($bridgeCols['is_paid'])) {
            $bSqlPaid = "SELECT SUM(".$factorExpr.") FROM $source WHERE is_paid = 1";
            if (!empty($bWhere)) {
                $bSqlPaid .= " AND " . implode(" AND ", $bWhere);
            }
            $bStmtPaid = $pdo->prepare($bSqlPaid);
            $bStmtPaid->execute($bParams);
            $stats['paid'] += (int)$bStmtPaid->fetchColumn();
        }

        // Checkins (is_checked_in = 1) ajustados por 2x1
        if (isset($bridgeCols['is_checked_in'])) {
            $bSqlChk = "SELECT SUM(".$factorExpr.") FROM $source WHERE is_checked_in = 1";
            if (!empty($bWhere)) {
                $bSqlChk .= " AND " . implode(" AND ", $bWhere);
            }
            $bStmtChk = $pdo->prepare($bSqlChk);
            $bStmtChk->execute($bParams);
            $stats['checkins'] += (int)$bStmtChk->fetchColumn();
        }
        
    } catch (Exception $e) {
        // si falla bridge, continuar sin él
    }
    
    $stats['pendiente'] = $stats['total'] - $stats['checkins'];
    
    // cantidad_total ya representa la capacidad inicial. No se le vuelven a
    // sumar las entradas emitidas porque eso infla artificialmente el stock.
    if ($stats['stock_total'] !== null) $stats['stock_total'] = $stockTotalInicial;
    
    return $stats;
}

if (!function_exists('tickex_paid_package_breakdown')) {
    function tickex_paid_package_breakdown($pdo, $eventoId)
    {
        $result = array();
        $requests = array();
        $columns = detect_table_columns($pdo, 'entradas');
        $requestSelect = isset($columns['tc_order_request_id']) ? 'tc_order_request_id' : "'' AS tc_order_request_id";
        $st = $pdo->prepare("SELECT tipo, COALESCE(monto_pagado,0) AS monto_pagado, $requestSelect FROM entradas WHERE evento_id=:eid AND monto_pagado>0");
        $st->execute(array(':eid' => (int)$eventoId));
        while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
            $type = trim((string)$row['tipo']);
            if ($type === '') $type = 'Desconocido';
            if (!isset($result[$type])) $result[$type] = array('cantidad' => 0, 'monto' => 0.0);
            $result[$type]['monto'] += (float)$row['monto_pagado'];
            $requestId = trim((string)$row['tc_order_request_id']);
            if ($requestId === '') {
                $result[$type]['cantidad']++;
            } else {
                if (!isset($requests[$requestId])) $requests[$requestId] = array();
                $requests[$requestId][$type] = true;
            }
        }

        if (!empty($requests) && has_table($pdo, 'tc_orders')) {
            $stOrder = $pdo->prepare('SELECT selected_tickets_json FROM tc_orders WHERE request_id=:rid LIMIT 1');
            foreach ($requests as $requestId => $fallbackTypes) {
                $stOrder->execute(array(':rid' => $requestId));
                $json = $stOrder->fetchColumn();
                $tickets = is_string($json) ? json_decode($json, true) : null;
                $counted = false;
                if (is_array($tickets)) {
                    foreach ($tickets as $ticket) {
                        $quantity = max(0, (int)(isset($ticket['qty']) ? $ticket['qty'] : 0));
                        if ($quantity <= 0) continue;
                        $type = trim((string)(isset($ticket['name']) ? $ticket['name'] : ''));
                        if ($type === '') $type = key($fallbackTypes);
                        if (!isset($result[$type])) $result[$type] = array('cantidad' => 0, 'monto' => 0.0);
                        $result[$type]['cantidad'] += $quantity;
                        $counted = true;
                    }
                }
                if (!$counted) {
                    foreach ($fallbackTypes as $type => $_true) {
                        if (!isset($result[$type])) $result[$type] = array('cantidad' => 0, 'monto' => 0.0);
                        $result[$type]['cantidad']++;
                    }
                }
            }
        }

        return $result;
    }
}

/**
 * Cuenta estadísticas de entradas unificadas
 */
function count_unified_entries($entries) {
    $total = count($entries);
    $checkins = 0;
    $paid = 0;
    foreach ($entries as $e) {
        if ($e['is_checked_in']) $checkins++;
        if ($e['is_paid']) $paid++;
    }
    
    return array(
        'total' => $total,
        'checkins' => $checkins,
        'paid' => $paid,
        'pendiente' => $total - $checkins,
    );
}
/**
 * Obtiene estadísticas económicas unificadas (STR + TICKEX)
 * Retorna: array con entradas_vendidas, total_recaudado, y breakdown por tipo
 */
function get_economic_stats($pdo, $evento_id) {
    $stats = array(
        'entradas_vendidas' => 0,
        'total_recaudado' => 0,
        'bridge_gross' => 0,
        'bridge_fee_3pct' => 0,
        'bridge_net' => 0,
        'totalcoin_checkout_gross' => 0,
        'totalcoin_checkout_fee_3pct' => 0,
        'totalcoin_gross' => 0,
        'totalcoin_fee_3pct' => 0,
        'payment_processing_cost' => 0,
        'service_fee_charged' => 0,
        'por_tipo' => array(), // array( tipo => array('cantidad' => X, 'monto' => Y), ... )
        'manual_income' => 0,
        'manual_income_ingresos' => 0,
        'manual_income_egresos' => 0,
    );
    
    try {
        // ==== STR: paquetes/ventas pagadas y recaudación ====
        // Varios QR de un mismo paquete cuentan como una venta, pero sus
        // importes distribuidos se suman para conservar la recaudación exacta.
        $paidPackages = tickex_paid_package_breakdown($pdo, $evento_id);
        foreach ($paidPackages as $tipo => $packageRow) {
            $qty = isset($packageRow['cantidad']) ? (int)$packageRow['cantidad'] : 0;
            $monto = isset($packageRow['monto']) ? (float)$packageRow['monto'] : 0;
            
            $stats['entradas_vendidas'] += $qty;
            $stats['total_recaudado'] += $monto;
            
            if (!isset($stats['por_tipo'][$tipo])) {
                $stats['por_tipo'][$tipo] = array('cantidad' => 0, 'monto' => 0, 'origen' => 'STR');
            }
            $stats['por_tipo'][$tipo]['cantidad'] += $qty;
            $stats['por_tipo'][$tipo]['monto'] += $monto;
        }
        
        // ==== TICKEX: entradas pagadas (is_paid = 1) con precio real del bridge ====
        try {
            $bridgeGross = 0.0;
            // Detectar bridge (tabla o view)
            $candidates = array('v_senforms_bridge_status', 'senforms_bridge_tickets');
            $source = null;
            foreach ($candidates as $t) {
                try {
                    $stmt = $pdo->query("SELECT name FROM sqlite_master WHERE (type='table' OR type='view') AND name='" . $t . "' LIMIT 1");
                    if ($stmt && $stmt->fetch(PDO::FETCH_ASSOC)) {
                        $source = $t;
                        break;
                    }
                } catch (Exception $_e) {}
            }

            if ($source) {
                $bridgeCols = detect_table_columns($pdo, $source);
                $mappedSlugs = get_mapped_bridge_slugs($pdo, $evento_id);

                // detectar columna de evento para fallback
                $eventCol = null;
                if (isset($bridgeCols['evento_id'])) $eventCol = 'evento_id';
                elseif (isset($bridgeCols['event_id'])) $eventCol = 'event_id';
                elseif (isset($bridgeCols['id_evento'])) $eventCol = 'id_evento';

                // si no hay forma de filtrar por evento, evitamos mezclar datos
                if (empty($mappedSlugs) && !$eventCol) {
                    throw new Exception('Sin filtro de evento para bridge');
                }

                // Construir WHERE para bridge (solo ventas pagadas del evento)
                $bWhere = array();
                $bParams = array();

                if (isset($bridgeCols['is_paid'])) {
                    $bWhere[] = "is_paid = 1";
                } elseif (isset($bridgeCols['payment_state'])) {
                    $bWhere[] = "UPPER(payment_state) IN ('SUCCESS','APROBADO','APPROVED','COMPLETED','PAID')";
                } elseif (isset($bridgeCols['payment_status'])) {
                    $bWhere[] = "UPPER(payment_status) IN ('SUCCESS','APROBADO','APPROVED','COMPLETED','PAID')";
                }

                if (!empty($mappedSlugs) && isset($bridgeCols['event_slug'])) {
                    $placeholders = array();
                    foreach ($mappedSlugs as $i => $s) {
                        $ph = ':slug' . $i;
                        $placeholders[] = $ph;
                        $bParams[$ph] = $s;
                    }
                    if (!empty($placeholders)) {
                        $bWhere[] = "event_slug IN (" . implode(',', $placeholders) . ")";
                    }
                } elseif ($eventCol) {
                    $bWhere[] = "$eventCol = :eid";
                    $bParams[':eid'] = $evento_id;
                }

                // Detectar columna de precio disponible
                $priceCols = array('price','amount','total_price','total_amount','valor','price_cents');
                $priceCol = null; $priceDiv = 1;
                foreach ($priceCols as $pc) {
                    if (isset($bridgeCols[$pc])) {
                        $priceCol = $pc;
                        if ($pc === 'price_cents') $priceDiv = 100;
                        break;
                    }
                }

                // Detectar columna para tipo/nombre de ticket
                $tipoCols = array('selected_type_name','selected_type','ticket_type','ticket_name','product_name','entry_type','event_name','ticket_class','category','nombre','name','tipo');
                $tipoCol = null;
                foreach ($tipoCols as $tc) {
                    if (isset($bridgeCols[$tc])) { $tipoCol = $tc; break; }
                }

                // Factor por cantidad o 2x1
                $qtyCols = array('quantity','cantidad','qty','num_entries');
                $qtyExpr = null;
                foreach ($qtyCols as $qc) {
                    if (isset($bridgeCols[$qc])) { $qtyExpr = $qc; break; }
                }
                $normConds = array();
                foreach ($tipoCols as $tc) {
                    if (isset($bridgeCols[$tc])) {
                        $normConds[] = "REPLACE(LOWER(".$tc."),' ','') LIKE '%2x1%'";
                    }
                }
                $factorExpr = '1';
                if ($qtyExpr) {
                    $textCond = !empty($normConds) ? ("(" . implode(' OR ', $normConds) . ")") : '0';
                    $factorExpr = "CASE WHEN $qtyExpr >= 2 THEN $qtyExpr WHEN $textCond THEN 2 ELSE 1 END";
                } elseif (!empty($normConds)) {
                    $factorExpr = "CASE WHEN (" . implode(' OR ', $normConds) . ") THEN 2 ELSE 1 END";
                }

                // Construir SQL dinámico: usar priceCol * factor si existe, sino monto 0
                $tipoSelect = $tipoCol ? $tipoCol : "NULL";
                $montoExpr = $priceCol ? ("SUM((COALESCE(" . $priceCol . ",0)" . ($priceDiv !== 1 ? "/$priceDiv" : "") . ") * (".$factorExpr."))") : "SUM(0)";
                $qtyExprSql = "SUM(".$factorExpr.") as qty";

                $sqlBridge = "SELECT " . $qtyExprSql . ", " . $montoExpr . " as monto, " . $tipoSelect . " as tipo FROM $source";
                if (!empty($bWhere)) $sqlBridge .= " WHERE " . implode(" AND ", $bWhere);
                $sqlBridge .= " GROUP BY " . ($tipoCol ? $tipoCol : '1') . " ORDER BY tipo";

                $stmtBridge = $pdo->prepare($sqlBridge);
                $stmtBridge->execute($bParams);

                while ($row = $stmtBridge->fetch(PDO::FETCH_ASSOC)) {
                    $tipo = isset($row['tipo']) && $row['tipo'] ? trim((string)$row['tipo']) : 'Tickex';
                    $qty = isset($row['qty']) ? (int)$row['qty'] : 0;
                    $monto = isset($row['monto']) ? (float)$row['monto'] : 0;
                    $bridgeGross += $monto;

                    $stats['entradas_vendidas'] += $qty;
                    $stats['total_recaudado'] += $monto;

                    if (!isset($stats['por_tipo'][$tipo])) {
                        $stats['por_tipo'][$tipo] = array('cantidad' => 0, 'monto' => 0, 'origen' => 'TICKEX');
                    }
                    $stats['por_tipo'][$tipo]['cantidad'] += $qty;
                    $stats['por_tipo'][$tipo]['monto'] += $monto;
                    $stats['por_tipo'][$tipo]['origen'] = 'TICKEX';
                }

                $bridgeFee = round($bridgeGross * 0.03, 2);
                $stats['bridge_gross'] = $bridgeGross;
                $stats['bridge_fee_3pct'] = $bridgeFee;
                $stats['bridge_net'] = $bridgeGross - $bridgeFee;
                $stats['total_recaudado'] -= $bridgeFee;
            }
        } catch (Exception $e) {
            // Ignorar error bridge
        }

        // ==== Costos reales del proveedor en órdenes del checkout moderno ====
        // TotalCoin cobra 3% y ese costo lo absorbe la organización: no se suma
        // al importe que paga el comprador. Las órdenes históricas sin proveedor
        // explícito se consideran TotalCoin; las sincronizadas por bridge se
        // excluyen porque su 3% ya fue calculado arriba.
        try {
            if (has_table($pdo, 'tc_orders')) {
                $orderCols = detect_table_columns($pdo, 'tc_orders');
                if (isset($orderCols['evento_id']) && isset($orderCols['amount'])) {
                    $confirmedWhere = isset($orderCols['payment_status'])
                        ? "LOWER(COALESCE(payment_status,'')) = 'confirmed'"
                        : "UPPER(COALESCE(state,'')) IN ('SUCCESS','APROBADO','APPROVED','COMPLETED','PAID')";
                    $providerWhere = isset($orderCols['payment_provider'])
                        ? "LOWER(COALESCE(NULLIF(payment_provider,''),'totalcoin')) = 'totalcoin'"
                        : "1 = 1";
                    $bridgeWhere = isset($orderCols['request_id'])
                        ? "COALESCE(request_id,'') NOT LIKE 'bridge-%'"
                        : "1 = 1";

                    $stTcCost = $pdo->prepare("SELECT COALESCE(SUM(amount),0) FROM tc_orders WHERE evento_id = :eid AND $confirmedWhere AND $providerWhere AND $bridgeWhere");
                    $stTcCost->execute(array(':eid' => $evento_id));
                    $checkoutGross = max(0, (float)$stTcCost->fetchColumn());
                    $checkoutFee = round($checkoutGross * 0.03, 2);
                    $stats['totalcoin_checkout_gross'] = $checkoutGross;
                    $stats['totalcoin_checkout_fee_3pct'] = $checkoutFee;
                    $stats['total_recaudado'] -= $checkoutFee;

                    if (isset($orderCols['service_fee_amount'])) {
                        $stService = $pdo->prepare("SELECT COALESCE(SUM(service_fee_amount),0) FROM tc_orders WHERE evento_id = :eid AND $confirmedWhere");
                        $stService->execute(array(':eid' => $evento_id));
                        $stats['service_fee_charged'] = max(0, (float)$stService->fetchColumn());
                    }
                }
            }
        } catch (Exception $e) {
            // Mantener disponible el panel aunque una instalación antigua no tenga tc_orders completo.
        }

        $stats['totalcoin_gross'] = (float)$stats['bridge_gross'] + (float)$stats['totalcoin_checkout_gross'];
        $stats['totalcoin_fee_3pct'] = (float)$stats['bridge_fee_3pct'] + (float)$stats['totalcoin_checkout_fee_3pct'];
        $stats['payment_processing_cost'] = $stats['totalcoin_fee_3pct'];
        
        // ==== Ingresos/Egresos manuales (otros/varios) ====
        try {
            ensure_manual_income_table($pdo);
            if (function_exists('get_manual_income_breakdown')) {
                $manual = get_manual_income_breakdown($pdo, $evento_id);
                $stats['manual_income'] = $manual['neto'];
                $stats['manual_income_ingresos'] = $manual['ingresos']['total'];
                $stats['manual_income_egresos'] = $manual['egresos']['total'];

                if ($manual['ingresos']['total'] > 0) {
                    $stats['por_tipo'][] = array(
                        'tipo'     => 'Otros / Varios (ingreso)',
                        'cantidad' => $manual['ingresos']['count'],
                        'monto'    => $manual['ingresos']['total'],
                        'origen'   => 'MANUAL',
                    );
                }
                if ($manual['egresos']['total'] < 0) {
                    $stats['por_tipo'][] = array(
                        'tipo'     => 'Otros / Varios (egreso)',
                        'cantidad' => $manual['egresos']['count'],
                        'monto'    => $manual['egresos']['total'],
                        'origen'   => 'MANUAL',
                    );
                }
            } else {
                $stats['manual_income'] = get_total_manual_income($pdo, $evento_id);
            }

            $stats['total_recaudado'] += $stats['manual_income'];
        } catch (Exception $e) {
            // Ignorar error ingresos manuales
        }
        
    } catch (Exception $e) {
        // Log o ignore
    }
    
    // Ordenar por_tipo por cantidad descendente
    if (!empty($stats['por_tipo'])) {
        $normalized = array();
        foreach ($stats['por_tipo'] as $tipoKey => $datos) {
            if (!is_array($datos)) continue;
            $row = $datos;
            if (!isset($row['tipo'])) {
                $row['tipo'] = is_string($tipoKey) ? $tipoKey : 'Sin tipo';
            }
            $normalized[] = $row;
        }
        usort($normalized, function($a, $b) {
            return ($b['monto'] <=> $a['monto']);
        });
        $stats['por_tipo'] = $normalized;
    }
    
    return $stats;
}?>
