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
 * Obtiene la columna de check-in de entradas
 */
function get_checkin_column($pdo) {
    $cols = detect_table_columns($pdo, 'entradas');
    if (isset($cols['checkin'])) return 'checkin';
    if (isset($cols['checked_in'])) return 'checked_in';
    return 'checked_in'; // fallback
}

/**
 * Asegura que exista la tabla de mapeo entre evento STR y slug del bridge
 */
function ensure_bridge_event_map_table($pdo) {
    try {
        $sql = "CREATE TABLE IF NOT EXISTS bridge_event_map (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            evento_id INTEGER NOT NULL,
            bridge_slug TEXT NOT NULL,
            created_at DATETIME DEFAULT (datetime('now'))
        )";
        $pdo->exec($sql);
    } catch (Exception $e) {
        // no bloquear flujo si falla
    }
}

/**
 * Obtener slugs mapeados para un evento STR
 * @return array lista de slugs (strings) o array vacío
 */
function get_mapped_bridge_slugs($pdo, $evento_id) {
    ensure_bridge_event_map_table($pdo);
    try {
        $stmt = $pdo->prepare("SELECT bridge_slug FROM bridge_event_map WHERE evento_id = :eid ORDER BY id ASC");
        $stmt->execute(array(':eid' => $evento_id));
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
            } elseif (isset($bridgeCols['pago_status'])) {
                $bWhere[] = "pago_status IN ('SUCCESS', 'APROBADO')";
            } elseif (isset($bridgeCols['status'])) {
                $bWhere[] = "status IN ('SUCCESS', 'APROBADO')";
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
                // fallback: payment_state SUCCESS -> paid
                if (!$isPaid && isset($row['payment_state'])) {
                    $ps = strtoupper(trim($row['payment_state']));
                    if ($ps === 'SUCCESS' || $ps === 'APROBADO' || $ps === 'COMPLETED') $isPaid = true;
                }

                // si no está pago, omitir la entrada
                if (!$isPaid) {
                    continue;
                }

                $isCheckedIn = false;
                if (isset($row['is_checked_in'])) $isCheckedIn = ((int)$row['is_checked_in'] === 1);
                elseif (isset($row['checked_in'])) $isCheckedIn = ((int)$row['checked_in'] === 1);

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
                        'tipo'        => $ttype,
                        'is_paid'     => $isPaid,
                        'is_checked_in' => $isCheckedIn,
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
        $stmtT = $pdo->prepare("SELECT COUNT(*) FROM entradas WHERE evento_id = ?");
        $stmtT->execute(array($evento_id));
        $strTotal = (int)$stmtT->fetchColumn();
        $stats['total'] += $strTotal;
        $stats['paid'] += $strTotal; // STR siempre pagadas por defecto
        
        $stmtC = $pdo->prepare("SELECT COUNT(*) FROM entradas WHERE evento_id = ? AND $colCheck = 1");
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
            return $stats; // sin bridge
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
            return $stats; // sin slug mapping
        }
        
        // Contar total de bridge entries (SOLO PAGADAS - consistente con get_unified_entries)
        $bWhere = array();
        $bParams = array();
        
        // Filtrar por pago (igual que en get_unified_entries)
        if (isset($bridgeCols['is_paid'])) {
            $bWhere[] = "is_paid = 1";
        } elseif (isset($bridgeCols['payment_state'])) {
            $bWhere[] = "UPPER(payment_state) IN ('SUCCESS','APROBADO','APPROVED','COMPLETED','PAID')";
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
    
    // Actualizar stock_total: inicial (tipos_entrada) + todas las entradas cargadas (STR + TICKEX + manuales)
    if ($stats['stock_total'] !== null) {
        $stats['stock_total'] = $stockTotalInicial + $stats['total'];
    }
    
    return $stats;
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
        'por_tipo' => array(), // array( tipo => array('cantidad' => X, 'monto' => Y), ... )
        'manual_income' => 0
    );
    
    try {
        // ==== STR: entradas pagadas (monto_pagado > 0) ====
        $sqlStr = "SELECT COUNT(*) as qty, SUM(COALESCE(monto_pagado, 0)) as monto, tipo
                   FROM entradas 
                   WHERE evento_id = :eid AND monto_pagado > 0
                   GROUP BY tipo
                   ORDER BY tipo";
        $stmtStr = $pdo->prepare($sqlStr);
        $stmtStr->execute(array(':eid' => $evento_id));
        
        while ($row = $stmtStr->fetch(PDO::FETCH_ASSOC)) {
            $tipo = isset($row['tipo']) ? trim((string)$row['tipo']) : 'Desconocido';
            $qty = isset($row['qty']) ? (int)$row['qty'] : 0;
            $monto = isset($row['monto']) ? (float)$row['monto'] : 0;
            
            $stats['entradas_vendidas'] += $qty;
            $stats['total_recaudado'] += $monto;
            
            if (!isset($stats['por_tipo'][$tipo])) {
                $stats['por_tipo'][$tipo] = array('cantidad' => 0, 'monto' => 0, 'origen' => 'STR');
            }
            $stats['por_tipo'][$tipo]['cantidad'] += $qty;
            $stats['por_tipo'][$tipo]['monto'] += $monto;
        }
        
        // ==== TICKEX: entradas pagadas (is_paid = 1) ====
        try {
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

                if (!empty($mappedSlugs)) {
                    // Construir WHERE para bridge (filtrar solo ventas pagadas)
                    $bWhere = array();
                    $bParams = array();

                    if (isset($bridgeCols['is_paid'])) {
                        $bWhere[] = "is_paid = 1";
                    } elseif (isset($bridgeCols['payment_state']) || isset($bridgeCols['payment_status'])) {
                        // handled later if needed
                    }

                    if (isset($bridgeCols['event_slug'])) {
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
                    $tipoCols = array('ticket_type','ticket_name','product_name','entry_type','event_name','ticket_class','category','nombre');
                    $tipoCol = null;
                    foreach ($tipoCols as $tc) {
                        if (isset($bridgeCols[$tc])) { $tipoCol = $tc; break; }
                    }

                    // Construir SQL dinámico: usar priceCol si existe, sino monto 0
                    $montoExpr = $priceCol ? ("SUM(COALESCE(" . $priceCol . ",0))" . ($priceDiv !== 1 ? "/$priceDiv" : "")) : "SUM(0)";
                    $tipoSelect = $tipoCol ? $tipoCol : "NULL";

                    $sqlBridge = "SELECT COUNT(*) as qty, " . $montoExpr . " as monto, " . $tipoSelect . " as tipo FROM $source";
                    if (!empty($bWhere)) $sqlBridge .= " WHERE " . implode(" AND ", $bWhere);
                    $sqlBridge .= " GROUP BY " . ($tipoCol ? $tipoCol : '1') . " ORDER BY tipo";

                    $stmtBridge = $pdo->prepare($sqlBridge);
                    $stmtBridge->execute($bParams);

                    while ($row = $stmtBridge->fetch(PDO::FETCH_ASSOC)) {
                        $tipo = isset($row['tipo']) && $row['tipo'] ? trim((string)$row['tipo']) : 'Tickex';
                        $qty = isset($row['qty']) ? (int)$row['qty'] : 0;
                        $monto = isset($row['monto']) ? (float)$row['monto'] : 0;

                        $stats['entradas_vendidas'] += $qty;
                        $stats['total_recaudado'] += $monto;

                        if (!isset($stats['por_tipo'][$tipo])) {
                            $stats['por_tipo'][$tipo] = array('cantidad' => 0, 'monto' => 0, 'origen' => 'TICKEX');
                        }
                        $stats['por_tipo'][$tipo]['cantidad'] += $qty;
                        $stats['por_tipo'][$tipo]['monto'] += $monto;
                        $stats['por_tipo'][$tipo]['origen'] = 'TICKEX';
                    }
                }
            }
        } catch (Exception $e) {
            // Ignorar error bridge
        }
        
        // ==== Ingresos manuales ====
        try {
            ensure_manual_income_table($pdo);
            $stats['manual_income'] = get_total_manual_income($pdo, $evento_id);
            $stats['total_recaudado'] += $stats['manual_income'];
        } catch (Exception $e) {
            // Ignorar error ingresos manuales
        }
        
    } catch (Exception $e) {
        // Log o ignore
    }
    
    // Ordenar por_tipo por cantidad descendente
    if (!empty($stats['por_tipo'])) {
        usort($stats['por_tipo'], function($a, $b) {
            return $b['cantidad'] - $a['cantidad'];
        });
    }
    
    return $stats;
}?>
