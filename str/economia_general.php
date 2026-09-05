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

if (!in_array($rol, array('admin_evento','super_admin','superadmin'), true)) {
    header('Location: /login.php?next=' . urlencode($_SERVER['REQUEST_URI']), true, 302);
    exit;
}

// Separación de vistas: superadmin usa la economía global.
if (in_array($rol, array('super_admin','superadmin'), true)) {
  header('Location: superadmin_economia_general.php', true, 302);
  exit;
}

$pdo = db();

// ------------------------------------------------------------------
// Helpers: seguridad/compatibilidad de esquema
// ------------------------------------------------------------------
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
// Tabla de movimientos económicos globales
// ------------------------------------------------------------------
function ensure_econ_table($pdo) {
  try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS econ_movimientos (
      id INTEGER PRIMARY KEY AUTOINCREMENT,
      tipo TEXT NOT NULL,              -- gasto | presupuesto | inversion
      concepto TEXT NOT NULL,
      categoria TEXT,
      monto REAL NOT NULL,
      estado TEXT,
      evento_id INTEGER,
      notas TEXT,
      creado_por INTEGER,
      incluye_en_totales INTEGER NOT NULL DEFAULT 1,
      created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    )");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_econ_tipo ON econ_movimientos(tipo)");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_econ_evento ON econ_movimientos(evento_id)");

    // agregar columna incluye_en_totales si faltara (bases viejas)
    $cols = array();
    $stc = $pdo->query("PRAGMA table_info(econ_movimientos)");
    if ($stc) {
      $colsInfo = $stc->fetchAll(PDO::FETCH_ASSOC);
      foreach ($colsInfo as $ci) { $cols[$ci['name']] = true; }
    }
    if (!isset($cols['incluye_en_totales'])) {
      $pdo->exec("ALTER TABLE econ_movimientos ADD COLUMN incluye_en_totales INTEGER NOT NULL DEFAULT 1");
    }
  } catch (Exception $e) {
    // ignorar
  }
}

function add_econ_mov($pdo, $data) {
    ensure_econ_table($pdo);
    try {
        $stmt = $pdo->prepare("INSERT INTO econ_movimientos (tipo, concepto, categoria, monto, estado, evento_id, notas, creado_por) VALUES (:t,:c,:cat,:m,:e,:eid,:n,:uid)");
        $stmt->execute(array(
            ':t'   => $data['tipo'],
            ':c'   => $data['concepto'],
            ':cat' => $data['categoria'],
            ':m'   => $data['monto'],
            ':e'   => $data['estado'],
            ':eid' => $data['evento_id'],
            ':n'   => $data['notas'],
            ':uid' => $data['creado_por'],
        ));
        return true;
    } catch (Exception $e) {
        return false;
    }
}

ensure_econ_table($pdo);

// Costo acumulado de staff (toda la base)
function get_staff_cost_total($pdo) {
  try {
    $cols = array();
    $st = $pdo->query("PRAGMA table_info(usuarios_admin)");
    if ($st) {
      foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $ci) { $cols[$ci['name']] = true; }
    }
    if (!isset($cols['costo_servicio'])) return 0;
    $stmt = $pdo->query("SELECT SUM(COALESCE(costo_servicio,0)) FROM usuarios_admin WHERE tipo_global='staff_evento'");
    $val = $stmt ? $stmt->fetchColumn() : 0;
    return $val ? (float)$val : 0;
  } catch (Exception $e) {
    return 0;
  }
}

// ------------------------------------------------------------------
// POST: agregar movimiento
// ------------------------------------------------------------------
$flashMsg = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'add_mov') {
    $tipo = isset($_POST['tipo']) ? trim($_POST['tipo']) : '';
    $concepto = isset($_POST['concepto']) ? trim($_POST['concepto']) : '';
    $categoria = isset($_POST['categoria']) ? trim($_POST['categoria']) : '';
    $monto = isset($_POST['monto']) ? (float)$_POST['monto'] : 0;
    $estado = isset($_POST['estado']) ? trim($_POST['estado']) : '';
    $evento_id = isset($_POST['evento_id']) ? (int)$_POST['evento_id'] : null;
    $notas = isset($_POST['notas']) ? trim($_POST['notas']) : '';

    if (!in_array($tipo, array('gasto','presupuesto','inversion'), true)) {
        $flashMsg = 'Tipo inválido';
    } elseif ($concepto === '' || $monto <= 0) {
        $flashMsg = 'Concepto y monto son obligatorios';
    } else {
      // Seguridad: si se asocia a un evento, validar que sea del admin.
      if ($evento_id) {
        try {
          $evCols = detect_table_columns($pdo, 'eventos');
          $creatorCol = _pick_first_existing_col($evCols, array('creado_por_admin_id','creador_id','admin_id','usuario_admin_id'));
          if (!$creatorCol || !isset($cu['id'])) {
            $flashMsg = 'No se pudo validar el evento seleccionado';
            $evento_id = null;
          } else {
            $st = $pdo->prepare("SELECT 1 FROM eventos WHERE id = :eid AND $creatorCol = :uid LIMIT 1");
            $st->execute(array(':eid' => (int)$evento_id, ':uid' => (int)$cu['id']));
            if (!$st->fetchColumn()) {
              $flashMsg = 'Evento inválido para tu usuario';
              $evento_id = null;
            }
          }
        } catch (Exception $e) {
          $flashMsg = 'No se pudo validar el evento seleccionado';
          $evento_id = null;
        }
      }

      if ($flashMsg !== '') {
        // no guardar
      } else {
        $ok = add_econ_mov($pdo, array(
            'tipo' => $tipo,
            'concepto' => $concepto,
            'categoria' => $categoria,
            'monto' => $monto,
            'estado' => $estado,
            'evento_id' => $evento_id ? $evento_id : null,
            'notas' => $notas,
            'creado_por' => isset($cu['id']) ? (int)$cu['id'] : null,
        ));
        $flashMsg = $ok ? 'Movimiento agregado' : 'Error al guardar el movimiento';
      }
    }
}

// POST: eliminar movimiento
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'del_mov') {
  $id = isset($_POST['id']) ? (int)$_POST['id'] : 0;
  if ($id > 0) {
    $where = 'id = :id';
    $params = array(':id' => $id);
    if ($rol === 'admin_evento' && isset($cu['id'])) {
      $where .= ' AND creado_por = :uid';
      $params[':uid'] = (int)$cu['id'];
    }
    $stmtDel = $pdo->prepare("DELETE FROM econ_movimientos WHERE $where");
    $stmtDel->execute($params);
    $flashMsg = ($stmtDel->rowCount() > 0) ? 'Movimiento eliminado' : 'No autorizado o movimiento inexistente';
  }
}

// POST: toggle incluye_en_totales
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'toggle_mov') {
  $id = isset($_POST['id']) ? (int)$_POST['id'] : 0;
  $val = isset($_POST['val']) ? (int)$_POST['val'] : 0;
  if ($id > 0) {
    $where = 'id = :id';
    $params = array(':v' => $val, ':id' => $id);
    if ($rol === 'admin_evento' && isset($cu['id'])) {
      $where .= ' AND creado_por = :uid';
      $params[':uid'] = (int)$cu['id'];
    }
    $stmtT = $pdo->prepare("UPDATE econ_movimientos SET incluye_en_totales = :v WHERE $where");
    $stmtT->execute($params);
    $flashMsg = ($stmtT->rowCount() > 0) ? ($val ? 'Movimiento activado' : 'Movimiento excluido de totales') : 'No autorizado o movimiento inexistente';
  }
}

// ------------------------------------------------------------------
// Eventos disponibles (para filtrar y asociar movimientos)
// ------------------------------------------------------------------
$eventos = array();
$ownershipUnknown = false;
try {
  $evCols = detect_table_columns($pdo, 'eventos');
  $creatorCol = _pick_first_existing_col($evCols, array('creado_por_admin_id','creador_id','admin_id','usuario_admin_id'));

  // Si es admin_evento y podemos filtrar por creador, limitar eventos a los suyos.
  if ($rol === 'admin_evento' && $creatorCol && isset($cu['id'])) {
    $stEv = $pdo->prepare("SELECT id, nombre FROM eventos WHERE $creatorCol = :aid ORDER BY id DESC");
    $stEv->execute(array(':aid' => (int)$cu['id']));
    $eventos = $stEv ? $stEv->fetchAll(PDO::FETCH_ASSOC) : array();
  } elseif ($rol === 'admin_evento') {
    // Seguridad: si no podemos filtrar por el creador, no mostramos eventos de terceros.
    $eventos = array();
    $ownershipUnknown = true;
  } else {
    $stEv = $pdo->query("SELECT id, nombre FROM eventos ORDER BY id DESC");
    $eventos = $stEv ? $stEv->fetchAll(PDO::FETCH_ASSOC) : array();
  }
} catch (Exception $e) {
    $eventos = array();
}

// Lista de movimientos recientes
$movimientos = array();
try {
  if ($rol === 'admin_evento' && isset($cu['id'])) {
    $stMov = $pdo->prepare("SELECT * FROM econ_movimientos WHERE creado_por = :uid ORDER BY created_at DESC, id DESC LIMIT 50");
    $stMov->execute(array(':uid' => (int)$cu['id']));
    $movimientos = $stMov ? $stMov->fetchAll(PDO::FETCH_ASSOC) : array();
  } else {
    $stMov = $pdo->query("SELECT * FROM econ_movimientos ORDER BY created_at DESC, id DESC LIMIT 50");
    $movimientos = $stMov ? $stMov->fetchAll(PDO::FETCH_ASSOC) : array();
  }
} catch (Exception $e) {
    $movimientos = array();
}

// Totales de movimientos
$totalGastos = 0; $totalInv = 0; $totalPresu = 0;
foreach ($movimientos as $m) {
    $mt = isset($m['monto']) ? (float)$m['monto'] : 0;
  $incluye = isset($m['incluye_en_totales']) ? ((int)$m['incluye_en_totales'] === 1) : true;
  if (!$incluye) continue;
  if ($m['tipo'] === 'gasto') $totalGastos += $mt;
  elseif ($m['tipo'] === 'inversion') $totalInv += $mt;
  elseif ($m['tipo'] === 'presupuesto') $totalPresu += $mt;
}

// ------------------------------------------------------------------
// Ingresos totales (todas las ventas + ingresos manuales)
// ------------------------------------------------------------------
$ingresosTotales = 0;
$ingresosManual  = 0;
$egresosManual = 0;
$costosProcesamiento = 0;
$entradasVendidas = 0;
$totalesPorEvento = array();
$ventasCheckout = 0.0; // Tickex/SenForms (checkout)
$ventasStr      = 0.0; // STR (puerta/otros)

// recoger ids de eventos
$eventIds = array();
foreach ($eventos as $ev) {
  if (isset($ev['id'])) $eventIds[] = (int)$ev['id'];
}

// Nota: la economía global (superadmin) vive en superadmin_economia_general.php

$eventIds = array_values(array_unique(array_filter($eventIds)));

foreach ($eventIds as $eid) {
    $stats = get_economic_stats($pdo, $eid);
    $ingresosTotales += $stats['total_recaudado'];
    $ingresosManual  += $stats['manual_income'];
    $egresosManual += abs((float)($stats['manual_income_egresos'] ?? 0));
    $costosProcesamiento += (float)($stats['payment_processing_cost'] ?? 0);
    $entradasVendidas += $stats['entradas_vendidas'];

    // Desglose ventas por origen (para aplicar cargo de servicio SOLO al checkout)
    if (!empty($stats['por_tipo']) && is_array($stats['por_tipo'])) {
      foreach ($stats['por_tipo'] as $pt) {
        if (!is_array($pt)) continue;
        $m = isset($pt['monto']) ? (float)$pt['monto'] : 0.0;
        $o = isset($pt['origen']) ? (string)$pt['origen'] : '';
        if ($o === 'TICKEX') $ventasCheckout += $m;
        elseif ($o === 'STR') $ventasStr += $m;
      }
    }

    $totalesPorEvento[] = array(
        'evento_id' => $eid,
        'recaudado' => $stats['total_recaudado'],
        'manual'    => $stats['manual_income'],
        'entradas'  => $stats['entradas_vendidas'],
    );
}

$staffCost = 0;
$artistCostTotal = 0;
// En esta vista (admin), evitamos sumar costos globales.
// La vista global del superadmin sí los contempla.
$saldoProyecto = ($ingresosTotales + $totalPresu) - ($costosProcesamiento + $egresosManual + $totalGastos + $totalInv + $staffCost + $artistCostTotal);

// ------------------------------------------------------------------
// Cargo de servicio (solo ventas por checkout)
// - Este admin en particular: 3%
// - Otros admins: 6%
// - Ventas STR (puerta/otras): no se les aplica cargo aquí
// ------------------------------------------------------------------
$serviceFeeDefault = 0.06;
$serviceFeeSpecial = 0.03;
$serviceFeeRate = $serviceFeeDefault;

// Nota: ajustar este ID si el admin especial no es #1
if (isset($cu['id']) && (int)$cu['id'] === 1) {
  $serviceFeeRate = $serviceFeeSpecial;
}

if (!is_numeric($ventasCheckout)) $ventasCheckout = 0.0;
$cargoServicio = $ventasCheckout * $serviceFeeRate;
$serviceFeePctLabel = (int)round($serviceFeeRate * 100);

// ------------------------------------------------------------------
// Ventas (log): últimas ventas unificadas STR + Tickex/SenForms
// ------------------------------------------------------------------
$ventasQ = isset($_GET['q']) ? trim((string)$_GET['q']) : '';
$ventasEid = isset($_GET['eid']) ? (int)$_GET['eid'] : 0;
$ventasLim = isset($_GET['lim']) ? (int)$_GET['lim'] : 200;
if ($ventasLim < 50) $ventasLim = 50;
if ($ventasLim > 5000) $ventasLim = 5000;
$showKey = isset($_GET['show']) ? trim((string)$_GET['show']) : '';

$eventNameById = array();
foreach ($eventos as $ev) {
  if (isset($ev['id'])) {
    $eventNameById[(int)$ev['id']] = isset($ev['nombre']) ? (string)$ev['nombre'] : ('Evento ' . (int)$ev['id']);
  }
}
$allowedEventIdSet = array();
foreach ($eventIds as $eid) { $allowedEventIdSet[(int)$eid] = true; }

// Mapa slug -> evento_id (bridge_event_map y/o eventos.slug)
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

// ===== Ventas STR (entradas con monto_pagado > 0) =====
try {
  $entrCols = detect_table_columns($pdo, 'entradas');
  $dateCol = _pick_first_existing_col($entrCols, array('created_at','fecha_registro','fecha'));
  $where = array("monto_pagado > 0");
  $params = array();

  if ($ventasEid > 0) {
    $where[] = "evento_id = :eid";
    $params[':eid'] = $ventasEid;
  } elseif ($rol === 'admin_evento') {
    // limitar al set permitido si podemos
    if (!empty($eventIds)) {
      $in = array();
      foreach ($eventIds as $i => $idv) {
        $ph = ':ae' . $i;
        $in[] = $ph;
        $params[$ph] = (int)$idv;
      }
      if (!empty($in)) {
        $where[] = 'evento_id IN (' . implode(',', $in) . ')';
      }
    }
  }

  if ($ventasQ !== '') {
    // búsqueda simple (siempre existen estos campos en la práctica; si faltaran, no filtrar)
    $wq = array();
    if (isset($entrCols['nombre'])) $wq[] = 'nombre LIKE :q';
    if (isset($entrCols['email'])) $wq[] = 'email LIKE :q';
    if (isset($entrCols['codigo'])) $wq[] = 'codigo LIKE :q';
    if (!empty($wq)) {
      $where[] = '(' . implode(' OR ', $wq) . ')';
      $params[':q'] = '%' . $ventasQ . '%';
    }
  }

  $selectCols = array('id','evento_id','nombre','email','codigo','tipo','monto_pagado');
  if ($dateCol) $selectCols[] = $dateCol . ' AS created_at';
  $sql = 'SELECT ' . implode(',', $selectCols) . ' FROM entradas';
  if (!empty($where)) $sql .= ' WHERE ' . implode(' AND ', $where);
  $sql .= ' ORDER BY id DESC LIMIT ' . (int)$ventasLim;
  $st = $pdo->prepare($sql);
  $st->execute($params);
  $rows = $st->fetchAll(PDO::FETCH_ASSOC);
  foreach ($rows as $r) {
    $eid = isset($r['evento_id']) ? (int)$r['evento_id'] : 0;
    $amount = isset($r['monto_pagado']) ? (float)$r['monto_pagado'] : 0.0;
    $key = 'STR:' . (isset($r['id']) ? (int)$r['id'] : 0);
    $ventas[] = array(
      'key' => $key,
      'source' => 'STR',
      'created_at' => isset($r['created_at']) ? (string)$r['created_at'] : '',
      'evento_id' => $eid,
      'evento_nombre' => $eid && isset($eventNameById[$eid]) ? $eventNameById[$eid] : ($eid ? ('Evento ' . $eid) : ''),
      'buyer_email' => isset($r['email']) ? (string)$r['email'] : '',
      'buyer_name' => isset($r['nombre']) ? (string)$r['nombre'] : '',
      'ref' => isset($r['codigo']) ? (string)$r['codigo'] : '',
      'amount' => $amount,
      'status' => isset($r['tipo']) ? (string)$r['tipo'] : '',
      'method' => '',
      'currency' => '',
      'raw_row' => $r,
    );
  }
} catch (Exception $e) {
  // ignorar
}

// ===== Ventas Tickex/SenForms (bridge, pagas) =====
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

    $bWhere = array();
    $bParams = array();

    // pagas
    if (isset($bridgeCols['is_paid'])) {
      $bWhere[] = 'is_paid = 1';
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

    // filtro por evento (si aplica)
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
          $bParams[$ph] = $s;
        }
        if (!empty($phs)) $bWhere[] = 'event_slug IN (' . implode(',', $phs) . ')';
      } elseif ($eventCol) {
        $bWhere[] = $eventCol . ' = :eid';
        $bParams[':eid'] = $ventasEid;
      }
    }

    // búsqueda libre
    if ($ventasQ !== '') {
      $cand = array('buyer_email','email','correo','buyer_name','name','full_name','order_id','ticket_ref','legacy_ticket_id','codigo','reference','ref');
      $wq = array();
      foreach ($cand as $c) {
        if (isset($bridgeCols[$c])) {
          $wq[] = $c . ' LIKE :bq';
        }
      }
      if (!empty($wq)) {
        $bWhere[] = '(' . implode(' OR ', $wq) . ')';
        $bParams[':bq'] = '%' . $ventasQ . '%';
      }
    }

    $bSql = 'SELECT * FROM ' . $source;
    if (!empty($bWhere)) $bSql .= ' WHERE ' . implode(' AND ', $bWhere);

    // ORDER BY
    $orderCol = _pick_first_existing_col($bridgeCols, array('last_updated_at','created_at','id','legacy_ticket_id'));
    if ($orderCol) $bSql .= ' ORDER BY ' . $orderCol . ' DESC';
    $bSql .= ' LIMIT ' . (int)$ventasLim;

    $bStmt = $pdo->prepare($bSql);
    $bStmt->execute($bParams);
    $tickRows = $bStmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($tickRows as $row) {
      // cantidad
      $multiplier = 1;
      foreach (array('quantity','cantidad','qty','num_entries') as $qc) {
        if (isset($row[$qc]) && is_numeric($row[$qc])) {
          $v = (int)$row[$qc];
          if ($v > 1) { $multiplier = $v; break; }
        }
      }

      // 2x1 por tipo/nombre
      $ttype = '';
      foreach (array('selected_type_name','selected_type','ticket_type','ticket_name','product_name','entry_type','event_name','ticket_class','category','nombre','name','tipo') as $tc) {
        if (isset($row[$tc]) && trim((string)$row[$tc]) !== '') { $ttype = (string)$row[$tc]; break; }
      }
      $normType = preg_replace('/\s+/', '', strtolower($ttype));
      if (strpos($normType, '2x1') !== false && $multiplier < 2) $multiplier = 2;

      // monto
      $priceVal = 0.0; $priceDiv = 1;
      foreach (array('price','Price','amount','total_price','total_amount','valor','price_cents') as $pf) {
        if (isset($row[$pf]) && is_numeric($row[$pf])) {
          $priceVal = (float)$row[$pf];
          if ($pf === 'price_cents') $priceDiv = 100;
          break;
        }
      }
      if ($priceDiv !== 1) $priceVal = $priceVal / $priceDiv;
      $amount = $priceVal * $multiplier;

      // fecha
      $created = '';
      foreach (array('last_updated_at','created_at','fecha','paid_at','updated_at') as $dc) {
        if (isset($row[$dc]) && trim((string)$row[$dc]) !== '') { $created = (string)$row[$dc]; break; }
      }

      // evento
      $eventSlug = isset($row['event_slug']) ? (string)$row['event_slug'] : '';
      $eid = 0;
      if ($eventCol && isset($row[$eventCol]) && is_numeric($row[$eventCol])) {
        $eid = (int)$row[$eventCol];
      } elseif ($eventSlug !== '' && isset($slugToEventId[$eventSlug])) {
        $eid = (int)$slugToEventId[$eventSlug];
      }

      // limitar por permisos si admin_evento
      if ($rol === 'admin_evento') {
        // si no podemos mapear el evento, mejor excluir por seguridad
        if (!$eid) continue;
        if (!isset($allowedEventIdSet[$eid])) continue;
      }

      // buyer
      $buyerEmail = '';
      foreach (array('buyer_email','email','correo') as $ec) {
        if (isset($row[$ec]) && trim((string)$row[$ec]) !== '') { $buyerEmail = (string)$row[$ec]; break; }
      }
      $buyerName = '';
      foreach (array('buyer_name','full_name','name','nombre') as $nc) {
        if (isset($row[$nc]) && trim((string)$row[$nc]) !== '') { $buyerName = (string)$row[$nc]; break; }
      }

      // refs / status / method / currency
      $ref = '';
      foreach (array('order_id','ticket_ref','legacy_ticket_id','reference','ref','codigo','id') as $rc) {
        if (isset($row[$rc]) && trim((string)$row[$rc]) !== '') { $ref = (string)$row[$rc]; break; }
      }
      $status = '';
      foreach (array('payment_state','payment_status','pago_status','pn_estado','status') as $sc) {
        if (isset($row[$sc]) && trim((string)$row[$sc]) !== '') { $status = (string)$row[$sc]; break; }
      }
      $method = '';
      foreach (array('payment_method','method','medio_pago','gateway') as $mc) {
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
  }
} catch (Exception $e) {
  // ignorar
}

// ordenar por fecha (desc) y luego por key
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

// limitar combinado
if (count($ventas) > $ventasLim) {
  $ventas = array_slice($ventas, 0, $ventasLim);
}

$ventasMostradasTotal = 0.0;
foreach ($ventas as $v) {
  $ventasMostradasTotal += isset($v['amount']) ? (float)$v['amount'] : 0;
}

$title = 'Economía General';
include __DIR__.'/inc/layout_top.php';
?>

<?php if ($flashMsg): ?>
  <div class="flash ok" style="margin-top:12px;">
    <?php echo e($flashMsg); ?>
  </div>
<?php endif; ?>

<?php if (!empty($ownershipUnknown)): ?>
  <div class="card" style="margin-top:12px;border:1px solid var(--warn);">
    <b>Atención:</b> no se pudo determinar qué eventos pertenecen a tu usuario (falta columna de creador en <code>eventos</code>). Por seguridad, no se muestran eventos/ventas.
  </div>
<?php endif; ?>

<div class="card" style="margin-top:12px;">
  <div style="display:flex;flex-wrap:wrap;gap:12px;align-items:center;justify-content:space-between;">
    <div>
      <h2 style="margin:0;">Economía del proyecto</h2>
      <div style="color:var(--muted);margin-top:4px;">Vista general de ingresos, gastos y presupuesto.</div>
    </div>
    <div style="display:flex;gap:8px;">
      <div class="pill">Entradas vendidas: <?php echo (int)$entradasVendidas; ?></div>
      <div class="pill">Eventos: <?php echo count($eventIds); ?></div>
    </div>
  </div>
  <div style="display:flex;gap:8px;flex-wrap:wrap;margin-top:14px;">
    <a class="btn" href="economia_general.php">Resumen económico</a>
    <a class="btn secondary" href="produccion.php">Producción y costos</a>
  </div>
</div>

<div class="card" style="margin-top:12px;display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:10px;">
  <div class="card" style="margin:0;background:var(--panel-2);">
    <div class="muted" style="font-size:12px;">Ingresos totales</div>
    <div style="font-size:28px;font-weight:700;margin-top:4px;color:var(--ok);">$<?php echo number_format($ingresosTotales,2); ?></div>
    <div style="font-size:12px;color:var(--muted);">Incluye ventas + ingresos manuales, sin descontar costos</div>
  </div>
  <div class="card" style="margin:0;background:var(--panel-2);">
    <div class="muted" style="font-size:12px;">Manual (otros/varios)</div>
    <div style="font-size:24px;font-weight:700;margin-top:4px;color:<?php echo ($ingresosManual >= 0 ? 'var(--info)' : 'var(--warn)'); ?>;">$<?php echo number_format($ingresosManual,2); ?></div>
  </div>
  <div class="card" style="margin:0;background:var(--panel-2);">
    <div class="muted" style="font-size:12px;">Procesamiento de pagos</div>
    <div style="font-size:24px;font-weight:700;margin-top:4px;color:var(--warn);">$<?php echo number_format($costosProcesamiento,2); ?></div>
  </div>
  <div class="card" style="margin:0;background:var(--panel-2);">
    <div class="muted" style="font-size:12px;">Gastos registrados</div>
    <div style="font-size:24px;font-weight:700;margin-top:4px;color:var(--warn);">$<?php echo number_format($totalGastos,2); ?></div>
  </div>
  <div class="card" style="margin:0;background:var(--panel-2);">
    <div class="muted" style="font-size:12px;">Inversiones</div>
    <div style="font-size:24px;font-weight:700;margin-top:4px;color:var(--warn);">$<?php echo number_format($totalInv,2); ?></div>
  </div>
  <div class="card" style="margin:0;background:var(--panel-2);">
    <div class="muted" style="font-size:12px;">Costo staff</div>
    <div style="font-size:24px;font-weight:700;margin-top:4px;">$<?php echo number_format($staffCost,2); ?></div>
  </div>
  <div class="card" style="margin:0;background:var(--panel-2);">
    <div class="muted" style="font-size:12px;">Artística</div>
    <div style="font-size:24px;font-weight:700;margin-top:4px;">$<?php echo number_format($artistCostTotal,2); ?></div>
  </div>
  <div class="card" style="margin:0;background:var(--panel-2);">
    <div class="muted" style="font-size:12px;">Presupuesto disponible</div>
    <div style="font-size:28px;font-weight:700;margin-top:4px;color:<?php echo $saldoProyecto>=0?'var(--ok)':'var(--warn)'; ?>;">$<?php echo number_format($saldoProyecto,2); ?></div>
    <div style="font-size:12px;color:var(--muted);">Presupuesto + ingresos − gastos − inversiones</div>
  </div>
</div>

<div class="card" style="margin-top:12px;display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:10px;">
  <div class="card" style="margin:0;background:var(--panel-2);">
    <div class="muted" style="font-size:12px;">Ventas por checkout</div>
    <div style="font-size:26px;font-weight:700;margin-top:4px;">$<?php echo number_format($ventasCheckout,2); ?></div>
    <div style="font-size:12px;color:var(--muted);">Tickex/SenForms (no incluye puerta/STR)</div>
  </div>
  <div class="card" style="margin:0;background:var(--panel-2);">
    <div class="muted" style="font-size:12px;">Cargo servicio (<?php echo (int)$serviceFeePctLabel; ?>%)</div>
    <div style="font-size:24px;font-weight:700;margin-top:4px;color:var(--info);">$<?php echo number_format($cargoServicio,2); ?></div>
    <div style="font-size:12px;color:var(--muted);">Aplicado solo a ventas por checkout</div>
  </div>
</div>

<div class="card" style="margin-top:12px;">
  <h3 style="margin-top:0;">Agregar movimiento</h3>
  <form method="post" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:10px;">
    <input type="hidden" name="action" value="add_mov">
    <div>
      <label>Tipo</label>
      <select name="tipo" required>
        <option value="gasto">Gasto</option>
        <option value="presupuesto">Presupuesto / fondo</option>
        <option value="inversion">Inversión</option>
      </select>
    </div>
    <div>
      <label>Concepto</label>
      <input type="text" name="concepto" required placeholder="Ej: Compra de luces">
    </div>
    <div>
      <label>Categoría</label>
      <input type="text" name="categoria" placeholder="Producción, Marketing...">
    </div>
    <div>
      <label>Monto</label>
      <input type="number" step="0.01" name="monto" required>
    </div>
    <div>
      <label>Estado</label>
      <input type="text" name="estado" placeholder="Pendiente, Pagado, Cotización">
    </div>
    <div>
      <label>Evento asociado (opcional)</label>
      <select name="evento_id">
        <option value="">— Global —</option>
        <?php foreach ($eventos as $ev): ?>
          <option value="<?php echo (int)$ev['id']; ?>"><?php echo e($ev['nombre']); ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div style="grid-column:1/-1;">
      <label>Notas</label>
      <textarea name="notas" rows="2" placeholder="Detalles, links de cotización, etc."></textarea>
    </div>
    <div style="grid-column:1/-1;display:flex;justify-content:flex-end;">
      <button class="btn" type="submit">Guardar</button>
    </div>
  </form>
</div>

<div class="card" style="margin-top:12px;">
  <h3 style="margin-top:0;">Últimos movimientos</h3>
  <div style="overflow:auto;">
    <table class="table" style="min-width:760px;">
      <thead>
        <tr>
          <th>Fecha</th>
          <th>Tipo</th>
          <th>Concepto</th>
          <th>Categoría</th>
          <th style="text-align:right;">Monto</th>
          <th>Estado</th>
          <th>Evento</th>
          <th style="text-align:center;">Totales</th>
          <th style="text-align:center;">Acciones</th>
        </tr>
      </thead>
      <tbody>
        <?php if (empty($movimientos)): ?>
          <tr><td colspan="9" style="text-align:center;color:var(--muted);">Sin movimientos</td></tr>
        <?php else: ?>
          <?php foreach ($movimientos as $m): ?>
            <tr>
              <td><?php echo e(isset($m['created_at']) ? $m['created_at'] : ''); ?></td>
              <td>
                <span class="pill" style="background:var(--panel-2);border:1px solid var(--line);font-size:12px;">
                  <?php echo e($m['tipo']); ?>
                </span>
              </td>
              <td><?php echo e($m['concepto']); ?></td>
              <td><?php echo $m['categoria'] !== '' ? e($m['categoria']) : '<span style="color:var(--muted);">—</span>'; ?></td>
              <td style="text-align:right;font-weight:700;">$<?php echo number_format((float)$m['monto'],2); ?></td>
              <td><?php echo $m['estado'] !== '' ? e($m['estado']) : '<span style="color:var(--muted);">—</span>'; ?></td>
              <td>
                <?php
                  $eid = isset($m['evento_id']) ? (int)$m['evento_id'] : 0;
                  if ($eid && !empty($eventos)) {
                      $found = null;
                      foreach ($eventos as $ev) {
                          if ((int)$ev['id'] === $eid) { $found = $ev['nombre']; break; }
                      }
                      echo $found ? e($found) : ('Evento ' . $eid);
                  } else {
                      echo '<span style="color:var(--muted);">—</span>';
                  }
                ?>
              </td>
              <td style="text-align:center;">
                <?php $incl = isset($m['incluye_en_totales']) ? (int)$m['incluye_en_totales'] : 1; ?>
                <form method="post" style="margin:0;">
                  <input type="hidden" name="action" value="toggle_mov">
                  <input type="hidden" name="id" value="<?php echo (int)$m['id']; ?>">
                  <input type="hidden" name="val" value="<?php echo $incl ? 0 : 1; ?>">
                  <button class="btn secondary" type="submit" style="padding:4px 10px;font-size:12px;">
                    <?php echo $incl ? 'Ocultar' : 'Mostrar'; ?>
                  </button>
                </form>
              </td>
              <td style="text-align:center;">
                <form method="post" style="margin:0;" onsubmit="return confirm('¿Eliminar movimiento?');">
                  <input type="hidden" name="action" value="del_mov">
                  <input type="hidden" name="id" value="<?php echo (int)$m['id']; ?>">
                  <button class="btn secondary" type="submit" style="padding:4px 10px;font-size:12px;background:var(--panel-2);color:var(--warn);border:1px solid var(--line);">Eliminar</button>
                </form>
              </td>
            </tr>
          <?php endforeach; ?>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<div class="card" style="margin-top:12px;">
  <h3 style="margin-top:0;">Recaudación por evento</h3>
  <div style="overflow:auto;">
    <table class="table" style="min-width:600px;">
      <thead>
        <tr>
          <th>Evento</th>
          <th style="text-align:right;">Entradas</th>
          <th style="text-align:right;">Ingresos</th>
          <th style="text-align:right;">Manuales</th>
        </tr>
      </thead>
      <tbody>
        <?php if (empty($totalesPorEvento)): ?>
          <tr><td colspan="4" style="text-align:center;color:var(--muted);">Sin datos</td></tr>
        <?php else: ?>
          <?php foreach ($totalesPorEvento as $te): ?>
            <tr>
              <td>#<?php echo (int)$te['evento_id']; ?></td>
              <td style="text-align:right;"><?php echo (int)$te['entradas']; ?></td>
              <td style="text-align:right;">$<?php echo number_format($te['recaudado'],2); ?></td>
              <td style="text-align:right;">$<?php echo number_format($te['manual'],2); ?></td>
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
      <div style="color:var(--muted);margin-top:4px;">Últimas ventas unificadas (STR + Tickex/SenForms) con detalles de pago disponibles.</div>
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
      <a class="btn secondary" href="economia_general.php" style="text-decoration:none;">Limpiar</a>
    </div>
  </form>

  <div style="overflow:auto;margin-top:10px;">
    <table class="table" style="min-width:980px;">
      <thead>
        <tr>
          <th>Fecha</th>
          <th>Evento</th>
          <th>Origen</th>
          <th>Comprador</th>
          <th>Ref</th>
          <th style="text-align:right;">Monto</th>
          <th style="text-align:right;">Cargo servicio</th>
          <th>Pago</th>
          <th style="text-align:center;">Acción</th>
        </tr>
      </thead>
      <tbody>
        <?php if (empty($ventas)): ?>
          <tr><td colspan="9" style="text-align:center;color:var(--muted);">Sin ventas para el filtro</td></tr>
        <?php else: ?>
          <?php foreach ($ventas as $v): ?>
            <?php
              $amt = isset($v['amount']) ? (float)$v['amount'] : 0;
              $isCheckout = (isset($v['source']) && (string)$v['source'] === 'TICKEX');
              $fee = $isCheckout ? ($amt * $serviceFeeRate) : 0.0;
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
              <td><span class="pill" style="background:var(--panel-2);border:1px solid var(--line);font-size:12px;"><?php echo e($v['source']); ?></span></td>
              <td><?php echo e($buyer); ?></td>
              <td><?php echo $v['ref'] !== '' ? e($v['ref']) : '<span style="color:var(--muted);">—</span>'; ?></td>
              <td style="text-align:right;font-weight:700;">$<?php echo number_format($amt,2); ?></td>
              <td style="text-align:right;<?php echo $isCheckout ? '' : 'color:var(--muted);'; ?>">$<?php echo number_format($fee,2); ?></td>
              <td><?php echo e($pay); ?></td>
              <td style="text-align:center;">
                <a class="btn secondary" href="economia_general.php?<?php
                  $qs = $_GET;
                  $qs['show'] = $v['key'];
                  echo e(http_build_query($qs));
                ?>" style="padding:4px 10px;font-size:12px;text-decoration:none;">Ver</a>
              </td>
            </tr>
            <?php if ($showKey !== '' && $showKey === $v['key']): ?>
              <tr>
                <td colspan="9" style="padding:0;">
                  <div style="padding:12px;">
                    <div style="display:flex;justify-content:space-between;align-items:center;gap:10px;">
                      <div style="font-weight:700;">Detalle de pago / datos crudos</div>
                      <a class="btn secondary" href="economia_general.php?<?php
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
