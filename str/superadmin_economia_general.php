<?php
require_once __DIR__.'/inc/bootstrap.php';
require_once __DIR__.'/inc/unified_tickets.php';
require_once __DIR__.'/inc/manual_income.php';
require_once __DIR__.'/inc/produccion.php';

require_login();

$cu = current_user();
$rol = isset($cu['tipo_global']) && $cu['tipo_global'] !== ''
  ? $cu['tipo_global']
  : (isset($cu['rol']) ? $cu['rol'] : (isset($_SESSION['tipo_global']) ? $_SESSION['tipo_global'] : ''));

if (!in_array($rol, array('super_admin','superadmin'), true)) {
    http_response_code(403);
    $title = 'Acceso restringido';
    include __DIR__.'/inc/layout_top.php';
    ?>
    <div class="card" style="max-width:640px;margin:32px auto;">
      <h2>Acceso restringido</h2>
      <p>Esta página es solo para superadmin.</p>
      <p style="margin-top:8px;"><a class="btn" href="panel_admin.php">Volver</a></p>
    </div>
    <?php
    include __DIR__.'/inc/layout_bottom.php';
    exit;
}

$pdo = db();

function _table_exists($pdo, $name) {
  try {
    $st = $pdo->prepare("SELECT 1 FROM sqlite_master WHERE (type='table' OR type='view') AND name = :n LIMIT 1");
    $st->execute(array(':n' => $name));
    return (bool)$st->fetchColumn();
  } catch (Exception $e) {
    return false;
  }
}

function _pick_first_existing_col($colsMap, $candidates) {
  foreach ($candidates as $c) {
    if (isset($colsMap[$c])) return $c;
  }
  return null;
}

// ------------------------------------------------------------------
// NOTA (2026-02): Esta economía global del superadmin cuenta únicamente
// las ventas cobradas por el checkout de TotalCoin.
// No se consideran gastos/inversiones/artística/manuales, porque no son
// ingresos que lleguen por TotalCoin.
// ------------------------------------------------------------------
$flashMsg = '';

function _is_totalcoin_payment_row($row)
{
  $candidates = array(
    'gateway','payment_gateway','provider','payment_provider','processor','payment_processor',
    'payment_method','method','medio_pago','checkout_provider','integration','source','pay_source',
    'tc_gateway','totalcoin','totalcoin_gateway'
  );
  foreach ($candidates as $k) {
    if (!isset($row[$k])) continue;
    $v = strtolower(trim((string)$row[$k]));
    if ($v === '') continue;
    if (strpos($v, 'totalcoin') !== false || strpos($v, 'totalcoi') !== false) {
      return true;
    }
  }

  // fallback: buscar en JSON crudo (algunas integraciones guardan payload)
  foreach (array('raw','payload','payment_raw','payment_payload','meta','metadata') as $k) {
    if (!isset($row[$k])) continue;
    $v = strtolower((string)$row[$k]);
    if ($v !== '' && (strpos($v, 'totalcoin') !== false || strpos($v, 'totalcoi') !== false)) {
      return true;
    }
  }
  return false;
}

// ------------------------------------------------------------------
// Eventos (global) + admin creador
// ------------------------------------------------------------------
$eventos = array();
$eventNameById = array();
$eventAdminById = array();
try {
  $evCols = detect_table_columns($pdo, 'eventos');
  $creatorCol = _pick_first_existing_col($evCols, array('creado_por_admin_id','creador_id','admin_id','usuario_admin_id'));
  $hasUA = _table_exists($pdo, 'usuarios_admin');
  $uaCols = $hasUA ? detect_table_columns($pdo, 'usuarios_admin') : array();
  $uaDispCol = _pick_first_existing_col($uaCols, array('email','username','nombre','display_name'));

  if ($creatorCol && $hasUA) {
    $selUA = $uaDispCol ? ("ua.".$uaDispCol." AS admin_display") : "ua.id AS admin_display";
    $sql = "SELECT e.id, e.nombre, " . (isset($evCols['slug']) ? "e.slug" : "'' as slug") . ", e.$creatorCol AS admin_id, $selUA
            FROM eventos e
            LEFT JOIN usuarios_admin ua ON ua.id = e.$creatorCol
            ORDER BY e.id DESC";
    $stEv = $pdo->query($sql);
    $eventos = $stEv ? $stEv->fetchAll(PDO::FETCH_ASSOC) : array();
  } else {
    $stEv = $pdo->query("SELECT id, nombre, " . (isset($evCols['slug']) ? "slug" : "'' as slug") . " FROM eventos ORDER BY id DESC");
    $eventos = $stEv ? $stEv->fetchAll(PDO::FETCH_ASSOC) : array();
  }
} catch (Exception $e) {
  $eventos = array();
}

foreach ($eventos as $ev) {
  if (!isset($ev['id'])) continue;
  $eid = (int)$ev['id'];
  $eventNameById[$eid] = isset($ev['nombre']) ? (string)$ev['nombre'] : ('Evento ' . $eid);
  if (isset($ev['admin_display']) && trim((string)$ev['admin_display']) !== '') {
    $eventAdminById[$eid] = (string)$ev['admin_display'];
  } elseif (isset($ev['admin_id']) && (int)$ev['admin_id'] > 0) {
    $eventAdminById[$eid] = '#' . (int)$ev['admin_id'];
  }
}

// ------------------------------------------------------------------
// Ingresos globales (solo TotalCoin) se calculan desde el log unificado
// ------------------------------------------------------------------
$ingresosTotales = 0;
$entradasVendidas = 0;
$totalesPorEvento = array();

$ventasTickets = 0;
$costoServicio = 0;
$cargoServicio = 0;
$gananciaNeta  = 0;

// ------------------------------------------------------------------
// Ventas (log) - SOLO TotalCoin
// ------------------------------------------------------------------
$ventasQ = isset($_GET['q']) ? trim((string)$_GET['q']) : '';
$ventasEid = isset($_GET['eid']) ? (int)$_GET['eid'] : 0;
$ventasLim = isset($_GET['lim']) ? (int)$_GET['lim'] : 200;
if ($ventasLim < 50) $ventasLim = 50;
if ($ventasLim > 5000) $ventasLim = 5000;
$showKey = isset($_GET['show']) ? trim((string)$_GET['show']) : '';

$slugToEventId = array();
try {
  ensure_bridge_event_map_table($pdo);
  $stMap = $pdo->query("SELECT evento_id, bridge_slug FROM bridge_event_map");
  $rowsMap = $stMap ? $stMap->fetchAll(PDO::FETCH_ASSOC) : array();
  foreach ($rowsMap as $r) {
    if (!empty($r['bridge_slug']) && isset($r['evento_id'])) {
      $slugToEventId[(string)$r['bridge_slug']] = (int)$r['evento_id'];
    }
  }
} catch (Exception $e) {}

try {
  $evCols2 = detect_table_columns($pdo, 'eventos');
  if (isset($evCols2['slug'])) {
    $stSlug = $pdo->query("SELECT id, slug FROM eventos WHERE slug IS NOT NULL AND slug <> ''");
    $rowsSlug = $stSlug ? $stSlug->fetchAll(PDO::FETCH_ASSOC) : array();
    foreach ($rowsSlug as $r) {
      if (!empty($r['slug']) && isset($r['id']) && !isset($slugToEventId[(string)$r['slug']])) {
        $slugToEventId[(string)$r['slug']] = (int)$r['id'];
      }
    }
  }
} catch (Exception $e) {}

$ventas = array();

// Agregados globales (para cards y tabla por evento). Se calculan sin LIMIT.
$aggTotalAmount = 0.0;
$aggTotalCount = 0;
$aggByEvent = array(); // [evento_id => ['recaudado'=>float,'entradas'=>int]]

// Bridge (Tickex/SenForms) - filtrado TotalCoin
try {
  $hasBridgeView = false;
  $hasBridgeTable = false;
  try {
    $stmt = $pdo->query("SELECT 1 FROM sqlite_master WHERE type='view' AND name='v_senforms_bridge_status' LIMIT 1");
    if ($stmt && $stmt->fetch()) $hasBridgeView = true;
  } catch (Exception $e) {}
  try {
    $stmt = $pdo->query("SELECT 1 FROM sqlite_master WHERE type='table' AND name='senforms_bridge_tickets' LIMIT 1");
    if ($stmt && $stmt->fetch()) $hasBridgeTable = true;
  } catch (Exception $e) {}

  if ($hasBridgeView || $hasBridgeTable) {
    $bridgeColsView = $hasBridgeView ? detect_table_columns($pdo, 'v_senforms_bridge_status') : array();
    $bridgeColsTable = $hasBridgeTable ? detect_table_columns($pdo, 'senforms_bridge_tickets') : array();

    $useTable = false;
    if (!empty($bridgeColsTable)) {
      if (isset($bridgeColsTable['selected_type_name']) || isset($bridgeColsTable['selected_type'])) {
        $useTable = true;
      } elseif (!$hasBridgeView) {
        $useTable = true;
      }
    }

    $source = ($useTable || !$hasBridgeView) ? 'senforms_bridge_tickets' : 'v_senforms_bridge_status';
    $bridgeCols = $useTable ? $bridgeColsTable : ($hasBridgeView ? $bridgeColsView : array());

    $bWhereBase = array();
    $bParamsBase = array();
    if (isset($bridgeCols['is_paid'])) {
      $bWhereBase[] = 'is_paid = 1';
    } elseif (isset($bridgeCols['payment_state'])) {
      $bWhereBase[] = "UPPER(payment_state) IN ('SUCCESS','APROBADO','APPROVED','COMPLETED','PAID')";
    } elseif (isset($bridgeCols['payment_status'])) {
      $bWhereBase[] = "UPPER(payment_status) IN ('SUCCESS','APROBADO','APPROVED','COMPLETED','PAID')";
    } elseif (isset($bridgeCols['pago_status'])) {
      $bWhereBase[] = "UPPER(pago_status) IN ('SUCCESS','APROBADO','APPROVED','COMPLETED','PAID')";
    } elseif (isset($bridgeCols['pn_estado'])) {
      $bWhereBase[] = "UPPER(pn_estado) IN ('SUCCESS','APROBADO','APPROVED','COMPLETED','PAID')";
    } elseif (isset($bridgeCols['status'])) {
      $bWhereBase[] = "UPPER(status) IN ('SUCCESS','APROBADO','APPROVED','COMPLETED','PAID')";
    }

    // Filtro TotalCoin a nivel SQL (si existen columnas detectables)
    $tcCols = array();
    foreach (array(
      'gateway','payment_gateway','provider','payment_provider','processor','payment_processor',
      'payment_method','method','medio_pago','checkout_provider','integration','source','pay_source',
      'tc_gateway','totalcoin','totalcoin_gateway'
    ) as $tcC) {
      if (isset($bridgeCols[$tcC])) $tcCols[] = $tcC;
    }
    $hasTcSql = false;
    if (!empty($tcCols)) {
      $parts = array();
      foreach ($tcCols as $tcC) {
        $parts[] = "(LOWER(COALESCE(CAST($tcC AS TEXT),'')) LIKE :tc1 OR LOWER(COALESCE(CAST($tcC AS TEXT),'')) LIKE :tc2)";
      }
      $bWhereBase[] = '(' . implode(' OR ', $parts) . ')';
      $bParamsBase[':tc1'] = '%totalcoin%';
      $bParamsBase[':tc2'] = '%totalcoi%';
      $hasTcSql = true;
    }

    $eventCol = _pick_first_existing_col($bridgeCols, array('evento_id','event_id','id_evento'));
    if ($ventasEid > 0) {
      $mappedSlugs = array();
      try {
        $mappedSlugs = get_mapped_bridge_slugs($pdo, $ventasEid);
        if (empty($mappedSlugs)) {
          try {
            $sstmt = $pdo->prepare('SELECT slug FROM eventos WHERE id = :eid LIMIT 1');
            $sstmt->execute(array(':eid' => $ventasEid));
            $srow = $sstmt->fetch(PDO::FETCH_ASSOC);
            if ($srow && !empty($srow['slug'])) $mappedSlugs = array($srow['slug']);
          } catch (Exception $_e) {}
        }
      } catch (Exception $e) {}

      if (!empty($mappedSlugs) && isset($bridgeCols['event_slug'])) {
        $phs = array();
        foreach ($mappedSlugs as $i => $s) {
          $ph = ':slug' . $i;
          $phs[] = $ph;
          $bParamsBase[$ph] = $s;
        }
        if (!empty($phs)) {
          $bWhereBase[] = 'event_slug IN (' . implode(',', $phs) . ')';
        }
      } elseif ($eventCol) {
        $bWhereBase[] = $eventCol . ' = :eid';
        $bParamsBase[':eid'] = $ventasEid;
      }
    }

    // Copiar base para el listado (agrega búsquedas)
    $bWhere = $bWhereBase;
    $bParams = $bParamsBase;

    if ($ventasQ !== '') {
      $cand = array('buyer_email','email','correo','buyer_name','name','full_name','order_id','ticket_ref','legacy_ticket_id','codigo','reference','ref');
      $wq = array();
      foreach ($cand as $c) {
        if (isset($bridgeCols[$c])) $wq[] = $c . ' LIKE :bq';
      }
      if (!empty($wq)) {
        $bWhere[] = '(' . implode(' OR ', $wq) . ')';
        $bParams[':bq'] = '%' . $ventasQ . '%';
      }
    }

    $bSql = 'SELECT * FROM ' . $source;
    if (!empty($bWhere)) $bSql .= ' WHERE ' . implode(' AND ', $bWhere);

    $orderCol = _pick_first_existing_col($bridgeCols, array('last_updated_at','created_at','id','legacy_ticket_id'));
    if ($orderCol) $bSql .= ' ORDER BY ' . $orderCol . ' DESC';
    $bSql .= ' LIMIT ' . (int)$ventasLim;

    $bStmt = $pdo->prepare($bSql);
    $bStmt->execute($bParams);
    $tickRows = $bStmt->fetchAll(PDO::FETCH_ASSOC);

    // Agregados globales (sin LIMIT). Si no hay columnas para filtrar TC en SQL,
    // se calcula desde las filas del listado como fallback (puede ser parcial).
    $qtyColForCount = _pick_first_existing_col($bridgeCols, array('quantity','cantidad','qty','num_entries'));
    $countExpr = $qtyColForCount ? ('SUM(COALESCE(' . $qtyColForCount . ',1))') : 'COUNT(*)';

    $amountExpr = '0';
    if (isset($bridgeCols['total_amount'])) {
      $amountExpr = 'COALESCE(total_amount,0)';
    } elseif (isset($bridgeCols['total_price'])) {
      $amountExpr = 'COALESCE(total_price,0)';
    } elseif (isset($bridgeCols['total_amount_cents'])) {
      $amountExpr = '(COALESCE(total_amount_cents,0) / 100.0)';
    } elseif (isset($bridgeCols['amount'])) {
      $amountExpr = 'COALESCE(amount,0)';
    } else {
      $qtyCol = _pick_first_existing_col($bridgeCols, array('quantity','cantidad','qty','num_entries'));
      $priceExpr = '0';
      if (isset($bridgeCols['price_cents'])) {
        $priceExpr = '(COALESCE(price_cents,0) / 100.0)';
      } elseif (isset($bridgeCols['price'])) {
        $priceExpr = 'COALESCE(price,0)';
      } elseif (isset($bridgeCols['Price'])) {
        $priceExpr = 'COALESCE(Price,0)';
      }
      $amountExpr = $qtyCol ? "($priceExpr * COALESCE($qtyCol,1))" : $priceExpr;
    }

    if ($hasTcSql) {
      $aggSql = 'SELECT SUM(' . $amountExpr . ') AS s, ' . $countExpr . ' AS c FROM ' . $source;
      if (!empty($bWhereBase)) $aggSql .= ' WHERE ' . implode(' AND ', $bWhereBase);
      $aggStmt = $pdo->prepare($aggSql);
      $aggStmt->execute($bParamsBase);
      $aggRow = $aggStmt->fetch(PDO::FETCH_ASSOC);
      if ($aggRow) {
        $aggTotalAmount = isset($aggRow['s']) ? (float)$aggRow['s'] : 0.0;
        $aggTotalCount = isset($aggRow['c']) ? (int)$aggRow['c'] : 0;
      }

      $groupCol = null;
      if (isset($bridgeCols['event_slug'])) {
        $groupCol = 'event_slug';
      } elseif ($eventCol) {
        $groupCol = $eventCol;
      }
      if ($groupCol) {
        $aggESql = 'SELECT ' . $groupCol . ' AS ek, SUM(' . $amountExpr . ') AS s, ' . $countExpr . ' AS c FROM ' . $source;
        if (!empty($bWhereBase)) $aggESql .= ' WHERE ' . implode(' AND ', $bWhereBase);
        $aggESql .= ' GROUP BY ' . $groupCol;
        $aggEStmt = $pdo->prepare($aggESql);
        $aggEStmt->execute($bParamsBase);
        $aggERows = $aggEStmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($aggERows as $er) {
          $ek = isset($er['ek']) ? (string)$er['ek'] : '';
          if ($ek === '') continue;
          $eid = 0;
          if ($groupCol === 'event_slug') {
            if (isset($slugToEventId[$ek])) $eid = (int)$slugToEventId[$ek];
          } else {
            if (is_numeric($ek)) $eid = (int)$ek;
          }
          if ($eid <= 0) continue;
          if (!isset($aggByEvent[$eid])) $aggByEvent[$eid] = array('recaudado'=>0.0,'entradas'=>0);
          $aggByEvent[$eid]['recaudado'] += isset($er['s']) ? (float)$er['s'] : 0.0;
          $aggByEvent[$eid]['entradas'] += isset($er['c']) ? (int)$er['c'] : 0;
        }
      }
    }

    foreach ($tickRows as $row) {
      // Solo ventas cobradas por TotalCoin.
      if (!$hasTcSql && !_is_totalcoin_payment_row($row)) {
        continue;
      }
      $multiplier = 1;
      foreach (array('quantity','cantidad','qty','num_entries') as $qc) {
        if (isset($row[$qc]) && is_numeric($row[$qc])) {
          $v = (int)$row[$qc];
          if ($v > 1) { $multiplier = $v; break; }
        }
      }

      $ttype = '';
      foreach (array('selected_type_name','selected_type','ticket_type','ticket_name','product_name','entry_type','event_name','ticket_class','category','nombre','name','tipo') as $tc) {
        if (isset($row[$tc]) && trim((string)$row[$tc]) !== '') { $ttype = (string)$row[$tc]; break; }
      }
      $normType = preg_replace('/\s+/', '', strtolower($ttype));
      if (strpos($normType, '2x1') !== false && $multiplier < 2) $multiplier = 2;

      $priceVal = 0.0; $priceDiv = 1; $isTotalField = false;
      foreach (array('total_amount','total_price','total_amount_cents','amount','price','Price','valor','price_cents') as $pf) {
        if (isset($row[$pf]) && is_numeric($row[$pf])) {
          $priceVal = (float)$row[$pf];
          if ($pf === 'price_cents' || $pf === 'total_amount_cents') $priceDiv = 100;
          if ($pf === 'total_amount' || $pf === 'total_price' || $pf === 'total_amount_cents' || $pf === 'amount') $isTotalField = true;
          break;
        }
      }
      if ($priceDiv !== 1) $priceVal = $priceVal / $priceDiv;
      $amount = $isTotalField ? $priceVal : ($priceVal * $multiplier);

      $created = '';
      foreach (array('last_updated_at','created_at','fecha','paid_at','updated_at') as $dc) {
        if (isset($row[$dc]) && trim((string)$row[$dc]) !== '') { $created = (string)$row[$dc]; break; }
      }

      $eventSlug = isset($row['event_slug']) ? (string)$row['event_slug'] : '';
      $eid = 0;
      if ($eventCol && isset($row[$eventCol]) && is_numeric($row[$eventCol])) {
        $eid = (int)$row[$eventCol];
      } elseif ($eventSlug !== '' && isset($slugToEventId[$eventSlug])) {
        $eid = (int)$slugToEventId[$eventSlug];
      }

      $buyerEmail = '';
      foreach (array('buyer_email','email','correo') as $ec) {
        if (isset($row[$ec]) && trim((string)$row[$ec]) !== '') { $buyerEmail = (string)$row[$ec]; break; }
      }
      $buyerName = '';
      foreach (array('buyer_name','full_name','name','nombre') as $nc) {
        if (isset($row[$nc]) && trim((string)$row[$nc]) !== '') { $buyerName = (string)$row[$nc]; break; }
      }

      $ref = '';
      foreach (array('order_id','ticket_ref','legacy_ticket_id','reference','ref','codigo','id') as $rc) {
        if (isset($row[$rc]) && trim((string)$row[$rc]) !== '') { $ref = (string)$row[$rc]; break; }
      }
      $status = '';
      foreach (array('payment_state','payment_status','pago_status','pn_estado','status') as $sc) {
        if (isset($row[$sc]) && trim((string)$row[$sc]) !== '') { $status = (string)$row[$sc]; break; }
      }
      $method = '';
      foreach (array('payment_method','method','medio_pago','gateway','payment_gateway','provider','payment_provider') as $mc) {
        if (isset($row[$mc]) && trim((string)$row[$mc]) !== '') { $method = (string)$row[$mc]; break; }
      }
      $currency = '';
      foreach (array('currency','moneda') as $cc) {
        if (isset($row[$cc]) && trim((string)$row[$cc]) !== '') { $currency = (string)$row[$cc]; break; }
      }

      $idPart = '';
      foreach (array('legacy_ticket_id','id','ticket_id') as $ic) {
        if (isset($row[$ic]) && trim((string)$row[$ic]) !== '') { $idPart = (string)$row[$ic]; break; }
      }
      if ($idPart === '') $idPart = md5(json_encode($row));
      $key = 'TICKEX:' . $idPart;

      $ventas[] = array(
        'key' => $key,
        'source' => 'TICKEX',
        'created_at' => $created,
        'evento_id' => $eid,
        'evento_nombre' => $eid && isset($eventNameById[$eid]) ? $eventNameById[$eid] : ($eventSlug !== '' ? $eventSlug : ($eid ? ('Evento ' . $eid) : '')),
        'admin' => $eid && isset($eventAdminById[$eid]) ? $eventAdminById[$eid] : '',
        'buyer_email' => $buyerEmail,
        'buyer_name' => $buyerName,
        'ref' => $ref,
        'amount' => (float)$amount,
        'status' => $status,
        'method' => $method,
        'currency' => $currency,
        'raw_row' => $row,
      );
    }

    // Fallback si no pudimos filtrar TotalCoin en SQL: escanear sin LIMIT y filtrar TotalCoin en PHP.
    if (!$hasTcSql) {
      $aggTotalAmount = 0.0;
      $aggTotalCount = 0;
      $aggByEvent = array();

      // Seleccionar columnas mínimas útiles si existen (evita SELECT * cuando se puede)
      $want = array(
        'event_slug',
        'quantity','cantidad','qty','num_entries',
        'selected_type_name','selected_type','ticket_type','ticket_name','product_name','entry_type','event_name','ticket_class','category','nombre','name','tipo',
        'total_amount','total_price','total_amount_cents','amount','price','Price','valor','price_cents',
        'gateway','payment_gateway','provider','payment_provider','processor','payment_processor',
        'payment_method','method','medio_pago','checkout_provider','integration','source','pay_source',
        'tc_gateway','totalcoin','totalcoin_gateway',
        'raw','payload','payment_raw','payment_payload','meta','metadata'
      );
      if ($eventCol) $want[] = $eventCol;
      $sel = array();
      foreach ($want as $c) {
        if (isset($bridgeCols[$c]) && !in_array($c, $sel, true)) $sel[] = $c;
      }
      $scanSql = 'SELECT ' . (!empty($sel) ? implode(',', $sel) : '*') . ' FROM ' . $source;
      if (!empty($bWhereBase)) $scanSql .= ' WHERE ' . implode(' AND ', $bWhereBase);
      $scanStmt = $pdo->prepare($scanSql);
      $scanStmt->execute($bParamsBase);
      while ($row = $scanStmt->fetch(PDO::FETCH_ASSOC)) {
        if (!_is_totalcoin_payment_row($row)) continue;

        // Calcular monto (misma heurística que el listado)
        $multiplier = 1;
        foreach (array('quantity','cantidad','qty','num_entries') as $qc) {
          if (isset($row[$qc]) && is_numeric($row[$qc])) {
            $v = (int)$row[$qc];
            if ($v > 1) { $multiplier = $v; break; }
          }
        }
        $ttype = '';
        foreach (array('selected_type_name','selected_type','ticket_type','ticket_name','product_name','entry_type','event_name','ticket_class','category','nombre','name','tipo') as $tc) {
          if (isset($row[$tc]) && trim((string)$row[$tc]) !== '') { $ttype = (string)$row[$tc]; break; }
        }
        $normType = preg_replace('/\s+/', '', strtolower($ttype));
        if (strpos($normType, '2x1') !== false && $multiplier < 2) $multiplier = 2;

        $priceVal = 0.0; $priceDiv = 1; $isTotalField = false;
        foreach (array('total_amount','total_price','total_amount_cents','amount','price','Price','valor','price_cents') as $pf) {
          if (isset($row[$pf]) && is_numeric($row[$pf])) {
            $priceVal = (float)$row[$pf];
            if ($pf === 'price_cents' || $pf === 'total_amount_cents') $priceDiv = 100;
            if ($pf === 'total_amount' || $pf === 'total_price' || $pf === 'total_amount_cents' || $pf === 'amount') $isTotalField = true;
            break;
          }
        }
        if ($priceDiv !== 1) $priceVal = $priceVal / $priceDiv;
        $amount = $isTotalField ? $priceVal : ($priceVal * $multiplier);
        if (!is_numeric($amount)) $amount = 0.0;

        $aggTotalAmount += (float)$amount;
        $aggTotalCount += (int)$multiplier;

        $eventSlug = isset($row['event_slug']) ? (string)$row['event_slug'] : '';
        $eid = 0;
        if ($eventCol && isset($row[$eventCol]) && is_numeric($row[$eventCol])) {
          $eid = (int)$row[$eventCol];
        } elseif ($eventSlug !== '' && isset($slugToEventId[$eventSlug])) {
          $eid = (int)$slugToEventId[$eventSlug];
        }
        if ($eid > 0) {
          if (!isset($aggByEvent[$eid])) $aggByEvent[$eid] = array('recaudado'=>0.0,'entradas'=>0);
          $aggByEvent[$eid]['recaudado'] += (float)$amount;
          $aggByEvent[$eid]['entradas'] += (int)$multiplier;
        }
      }
    }
  }
} catch (Exception $e) {}

usort($ventas, function($a, $b) {
  $da = isset($a['created_at']) ? (string)$a['created_at'] : '';
  $db = isset($b['created_at']) ? (string)$b['created_at'] : '';
  if ($da === $db) {
    $ka = isset($a['key']) ? (string)$a['key'] : '';
    $kb = isset($b['key']) ? (string)$b['key'] : '';
    return strcmp($kb, $ka);
  }
  return strcmp($db, $da);
});
if (count($ventas) > $ventasLim) $ventas = array_slice($ventas, 0, $ventasLim);

$ventasMostradasTotal = 0.0;
foreach ($ventas as $v) {
  $ventasMostradasTotal += isset($v['amount']) ? (float)$v['amount'] : 0;
}

// Totales globales (TotalCoin)
$ventasTickets = $aggTotalAmount;
$ingresosTotales = $ventasTickets;
$costoServicio = $ventasTickets * 0.03;
$cargoServicio = $ventasTickets * 0.06;
$gananciaNeta  = $ventasTickets * 0.03;

// Recaudación por evento (TotalCoin)
$totalesPorEvento = array();
foreach ($aggByEvent as $eid => $st) {
  $totalesPorEvento[] = array(
    'evento_id' => (int)$eid,
    'evento_nombre' => isset($eventNameById[(int)$eid]) ? $eventNameById[(int)$eid] : ('Evento ' . (int)$eid),
    'admin' => isset($eventAdminById[(int)$eid]) ? $eventAdminById[(int)$eid] : '',
    'recaudado' => (float)$st['recaudado'],
    'entradas' => (int)$st['entradas'],
  );
}
usort($totalesPorEvento, function($a, $b) {
  $ra = isset($a['recaudado']) ? (float)$a['recaudado'] : 0;
  $rb = isset($b['recaudado']) ? (float)$b['recaudado'] : 0;
  if ($ra === $rb) return 0;
  return ($ra < $rb) ? 1 : -1;
});

$entradasVendidas = (int)$aggTotalCount;

$title = 'Economía General (TotalCoin)';
include __DIR__.'/inc/layout_top.php';
?>

<?php if ($flashMsg): ?>
  <div class="flash ok" style="margin-top:12px;">
    <?php echo e($flashMsg); ?>
  </div>
<?php endif; ?>

<div class="card" style="margin-top:12px;">
  <div style="display:flex;flex-wrap:wrap;gap:12px;align-items:center;justify-content:space-between;">
    <div>
      <h2 style="margin:0;">Economía general (Superadmin)</h2>
      <div style="color:var(--muted);margin-top:4px;">Vista global: <b>solo ventas pagadas por TotalCoin</b>.</div>
    </div>
    <div style="display:flex;gap:8px;">
      <div class="pill">Entradas vendidas: <?php echo (int)$entradasVendidas; ?></div>
      <div class="pill">Eventos: <?php echo count($totalesPorEvento); ?></div>
    </div>
  </div>
</div>

<div class="card" style="margin-top:12px;display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:10px;">
  <div class="card" style="margin:0;background:var(--panel-2);">
    <div class="muted" style="font-size:12px;">Ventas TotalCoin</div>
    <div style="font-size:26px;font-weight:700;margin-top:4px;">$<?php echo number_format($ventasTickets,2); ?></div>
    <div style="font-size:12px;color:var(--muted);">Base para 3% / 6% / 3%</div>
  </div>
  <div class="card" style="margin:0;background:var(--panel-2);">
    <div class="muted" style="font-size:12px;">Costo servicio (3%)</div>
    <div style="font-size:24px;font-weight:700;margin-top:4px;color:var(--warn);">$<?php echo number_format($costoServicio,2); ?></div>
  </div>
  <div class="card" style="margin:0;background:var(--panel-2);">
    <div class="muted" style="font-size:12px;">Cargo servicio (6%)</div>
    <div style="font-size:24px;font-weight:700;margin-top:4px;color:var(--info);">$<?php echo number_format($cargoServicio,2); ?></div>
    <div style="font-size:12px;color:var(--muted);">3% costo + 3% ganancia</div>
  </div>
  <div class="card" style="margin:0;background:var(--panel-2);">
    <div class="muted" style="font-size:12px;">Ganancia (3%)</div>
    <div style="font-size:24px;font-weight:700;margin-top:4px;color:var(--ok);">$<?php echo number_format($gananciaNeta,2); ?></div>
  </div>
</div>

<div class="card" style="margin-top:12px;">
  <h3 style="margin-top:0;">Recaudación por evento</h3>
  <div style="overflow:auto;">
    <table class="table" style="min-width:760px;">
      <thead>
        <tr>
          <th>Evento</th>
          <th>Admin</th>
          <th style="text-align:right;">Entradas</th>
          <th style="text-align:right;">Ingresos</th>
        </tr>
      </thead>
      <tbody>
        <?php if (empty($totalesPorEvento)): ?>
          <tr><td colspan="4" style="text-align:center;color:var(--muted);">Sin datos</td></tr>
        <?php else: ?>
          <?php foreach ($totalesPorEvento as $te): ?>
            <tr>
              <td>#<?php echo (int)$te['evento_id']; ?> · <?php echo e($te['evento_nombre']); ?></td>
              <td><?php echo $te['admin'] !== '' ? e($te['admin']) : '<span style="color:var(--muted);">—</span>'; ?></td>
              <td style="text-align:right;"><?php echo (int)$te['entradas']; ?></td>
              <td style="text-align:right;">$<?php echo number_format($te['recaudado'],2); ?></td>
            </tr>
          <?php endforeach; ?>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<div class="card" style="margin-top:12px;">
  <div style="display:flex;flex-wrap:wrap;gap:10px;align-items:center;justify-content:space-between;">
    <div>
      <h3 style="margin:0;">Ventas (log)</h3>
      <div style="color:var(--muted);margin-top:4px;">Últimas ventas cobradas por TotalCoin (Tickex/SenForms bridge).</div>
    </div>
    <div class="pill">Mostrando: <?php echo count($ventas); ?> · Total mostrado: $<?php echo number_format($ventasMostradasTotal,2); ?></div>
  </div>

  <form method="get" style="margin-top:10px;display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:10px;align-items:end;">
    <div>
      <label>Buscar</label>
      <input type="text" name="q" value="<?php echo e($ventasQ); ?>" placeholder="email, nombre, código, order_id...">
    </div>
    <div>
      <label>Evento</label>
      <select name="eid">
        <option value="0">— Todos —</option>
        <?php foreach ($eventos as $ev): ?>
          <option value="<?php echo (int)$ev['id']; ?>" <?php echo ($ventasEid === (int)$ev['id'] ? 'selected' : ''); ?>>#<?php echo (int)$ev['id']; ?> · <?php echo e($ev['nombre']); ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div>
      <label>Límite</label>
      <select name="lim">
        <?php foreach (array(100,200,300,500,1000,2000,5000) as $opt): ?>
          <option value="<?php echo (int)$opt; ?>" <?php echo ($ventasLim === (int)$opt ? 'selected' : ''); ?>><?php echo (int)$opt; ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div style="display:flex;gap:8px;justify-content:flex-end;">
      <button class="btn" type="submit">Filtrar</button>
      <a class="btn secondary" href="superadmin_economia_general.php" style="text-decoration:none;">Limpiar</a>
    </div>
  </form>

  <div style="overflow:auto;margin-top:10px;">
    <table class="table" style="min-width:1150px;">
      <thead>
        <tr>
          <th>Fecha</th>
          <th>Evento</th>
          <th>Admin</th>
          <th>Origen</th>
          <th>Comprador</th>
          <th>Ref</th>
          <th style="text-align:right;">Monto</th>
          <th style="text-align:right;">Costo 3%</th>
          <th style="text-align:right;">Cargo 6%</th>
          <th style="text-align:right;">Ganancia 3%</th>
          <th>Pago</th>
          <th style="text-align:center;">Acción</th>
        </tr>
      </thead>
      <tbody>
        <?php if (empty($ventas)): ?>
          <tr><td colspan="12" style="text-align:center;color:var(--muted);">Sin ventas para el filtro</td></tr>
        <?php else: ?>
          <?php foreach ($ventas as $v): ?>
            <?php
              $amt = isset($v['amount']) ? (float)$v['amount'] : 0;
              $c3  = $amt * 0.03;
              $c6  = $amt * 0.06;
              $g3  = $amt * 0.03;
              $buyer = trim((string)$v['buyer_name']);
              if ($buyer === '') $buyer = trim((string)$v['buyer_email']);
              if ($buyer === '') $buyer = '—';
              $pay = '';
              if (!empty($v['method'])) $pay .= $v['method'];
              if (!empty($v['status'])) $pay .= ($pay !== '' ? ' · ' : '') . $v['status'];
              if (!empty($v['currency'])) $pay .= ($pay !== '' ? ' · ' : '') . $v['currency'];
              if ($pay === '') $pay = '—';
            ?>
            <tr style="<?php echo ($showKey !== '' && $showKey === $v['key']) ? 'background:var(--panel-2);' : ''; ?>">
              <td><?php echo e($v['created_at'] !== '' ? $v['created_at'] : ''); ?></td>
              <td><?php echo e($v['evento_nombre'] !== '' ? $v['evento_nombre'] : ($v['evento_id'] ? ('Evento ' . (int)$v['evento_id']) : '—')); ?></td>
              <td><?php echo $v['admin'] !== '' ? e($v['admin']) : '<span style="color:var(--muted);">—</span>'; ?></td>
              <td><span class="pill" style="background:var(--panel-2);border:1px solid var(--line);font-size:12px;"><?php echo e($v['source']); ?></span></td>
              <td><?php echo e($buyer); ?></td>
              <td><?php echo $v['ref'] !== '' ? e($v['ref']) : '<span style="color:var(--muted);">—</span>'; ?></td>
              <td style="text-align:right;font-weight:700;">$<?php echo number_format($amt,2); ?></td>
              <td style="text-align:right;">$<?php echo number_format($c3,2); ?></td>
              <td style="text-align:right;">$<?php echo number_format($c6,2); ?></td>
              <td style="text-align:right;color:var(--ok);">$<?php echo number_format($g3,2); ?></td>
              <td><?php echo e($pay); ?></td>
              <td style="text-align:center;">
                <a class="btn secondary" href="superadmin_economia_general.php?<?php
                  $qs = $_GET;
                  $qs['show'] = $v['key'];
                  echo e(http_build_query($qs));
                ?>" style="padding:4px 10px;font-size:12px;text-decoration:none;">Ver</a>
              </td>
            </tr>
            <?php if ($showKey !== '' && $showKey === $v['key']): ?>
              <tr>
                <td colspan="12" style="padding:0;">
                  <div style="padding:12px;">
                    <div style="display:flex;justify-content:space-between;align-items:center;gap:10px;">
                      <div style="font-weight:700;">Detalle de pago / datos crudos</div>
                      <a class="btn secondary" href="superadmin_economia_general.php?<?php
                        $qs = $_GET;
                        unset($qs['show']);
                        echo e(http_build_query($qs));
                      ?>" style="padding:4px 10px;font-size:12px;text-decoration:none;">Cerrar</a>
                    </div>
                    <pre style="white-space:pre-wrap;word-break:break-word;margin:10px 0 0;background:var(--panel-2);border:1px solid var(--line);padding:10px;border-radius:8px;max-height:320px;overflow:auto;"><?php echo e(json_encode($v['raw_row'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)); ?></pre>
                  </div>
                </td>
              </tr>
            <?php endif; ?>
          <?php endforeach; ?>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<?php include __DIR__.'/inc/layout_bottom.php'; ?>
