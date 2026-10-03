<?php

// A hold stays active past the checkout deadline until payment reconciliation
// proves that it cannot be approved. Expiring by the local clock alone can
// strand a buyer whose approved webhook arrives late.
function tickex_capacity_holds_ensure_schema($pdo)
{
    $pdo->exec("CREATE TABLE IF NOT EXISTS checkout_capacity_holds (
        ref TEXT PRIMARY KEY,
        event_id INTEGER NOT NULL,
        quantity INTEGER NOT NULL,
        lines_json TEXT NOT NULL,
        stock_totals_json TEXT NOT NULL,
        status TEXT NOT NULL DEFAULT 'active',
        payment_deadline_at TEXT NOT NULL,
        terminal_reason TEXT,
        last_checked_at TEXT,
        created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
    )");
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_checkout_capacity_holds_event_status ON checkout_capacity_holds(event_id,status)');
}

function tickex_capacity_holds_active_count($pdo, $eventId)
{
    $exists = (bool)$pdo->query("SELECT 1 FROM sqlite_master WHERE type='table' AND name='checkout_capacity_holds' LIMIT 1")->fetchColumn();
    if (!$exists) return 0;
    $st = $pdo->prepare("SELECT COALESCE(SUM(quantity),0) FROM checkout_capacity_holds WHERE event_id=:event AND status='active'");
    $st->execute(array(':event' => (int)$eventId));
    return (int)$st->fetchColumn();
}

function tickex_capacity_holds_active_for_ref($pdo, $eventId, $ref)
{
    $exists = (bool)$pdo->query("SELECT 1 FROM sqlite_master WHERE type='table' AND name='checkout_capacity_holds' LIMIT 1")->fetchColumn();
    if (!$exists) return null;
    $st = $pdo->prepare("SELECT quantity,lines_json FROM checkout_capacity_holds WHERE event_id=:event AND ref=:ref AND status='active' LIMIT 1");
    $st->execute(array(':event' => (int)$eventId, ':ref' => (string)$ref));
    $hold = $st->fetch(PDO::FETCH_ASSOC);
    if (!$hold) return null;
    $lines = json_decode((string)$hold['lines_json'], true);
    return is_array($lines) ? array('quantity' => (int)$hold['quantity'], 'lines' => $lines) : null;
}

function tickex_capacity_holds_lines(array $tickets)
{
    $lines = array();
    foreach ($tickets as $ticket) {
        $typeId = isset($ticket['id']) ? (int)$ticket['id'] : 0;
        $packages = isset($ticket['qty']) ? (int)$ticket['qty'] : 0;
        $qrPerPackage = isset($ticket['qr_quantity']) ? (int)$ticket['qr_quantity'] : 1;
        if ($typeId <= 0 || $packages <= 0 || $qrPerPackage < 1 || $qrPerPackage > 10) throw new InvalidArgumentException('invalid_hold_lines');
        if (!isset($lines[$typeId])) $lines[$typeId] = 0;
        $lines[$typeId] += $packages * $qrPerPackage;
    }
    if (!$lines) throw new InvalidArgumentException('invalid_hold_lines');
    ksort($lines, SORT_NUMERIC);
    return $lines;
}

function tickex_capacity_holds_reserve($pdo, $eventId, $ref, array $tickets, $deadline)
{
    if ($pdo->inTransaction()) throw new LogicException('hold_requires_own_transaction');
    $eventId = (int)$eventId;
    $ref = trim((string)$ref);
    if ($eventId <= 0 || $ref === '' || strtotime((string)$deadline) <= time()) throw new InvalidArgumentException('invalid_hold');
    $lines = tickex_capacity_holds_lines($tickets);
    $quantity = array_sum($lines);
    $linesJson = json_encode($lines);
    tickex_capacity_holds_ensure_schema($pdo);
    $pdo->exec('PRAGMA busy_timeout=3000');
    $pdo->exec('BEGIN IMMEDIATE');
    try {
        $read = $pdo->prepare('SELECT event_id,quantity,lines_json,status FROM checkout_capacity_holds WHERE ref=:ref LIMIT 1');
        $read->execute(array(':ref' => $ref));
        $existing = $read->fetch(PDO::FETCH_ASSOC);
        if ($existing) {
            if ((int)$existing['event_id'] !== $eventId || (int)$existing['quantity'] !== $quantity || (string)$existing['lines_json'] !== $linesJson || (string)$existing['status'] !== 'active') throw new RuntimeException('hold_conflict');
            $pdo->exec('COMMIT');
            return array('result' => 'existing', 'quantity' => $quantity);
        }
        $capacity = tickex_event_capacity_status($pdo, $eventId);
        if ($capacity['limit'] !== null && $quantity > (int)$capacity['available']) throw new RuntimeException('event_full');
        $stockTotals = array();
        foreach ($lines as $typeId => $units) {
            $stockRead = $pdo->prepare('SELECT cantidad_total FROM tipos_entrada WHERE id=:type AND evento_id=:event LIMIT 1');
            $stockRead->execute(array(':type' => $typeId, ':event' => $eventId));
            $stockTotals[$typeId] = $stockRead->fetchColumn();
            $take = $pdo->prepare('UPDATE tipos_entrada SET cantidad_disponible=cantidad_disponible-:qty WHERE id=:type AND evento_id=:event AND cantidad_disponible>=:qty');
            $take->execute(array(':qty' => $units, ':type' => $typeId, ':event' => $eventId));
            if ($take->rowCount() !== 1) throw new RuntimeException('type_unavailable');
        }
        $insert = $pdo->prepare("INSERT INTO checkout_capacity_holds(ref,event_id,quantity,lines_json,stock_totals_json,status,payment_deadline_at) VALUES(:ref,:event,:qty,:lines,:totals,'active',:deadline)");
        $insert->execute(array(':ref' => $ref, ':event' => $eventId, ':qty' => $quantity, ':lines' => $linesJson, ':totals' => json_encode($stockTotals), ':deadline' => (string)$deadline));
        $pdo->exec('COMMIT');
        return array('result' => 'reserved', 'quantity' => $quantity);
    } catch (Exception $error) {
        try { $pdo->exec('ROLLBACK'); } catch (Exception $_rollbackError) {}
        throw $error;
    }
}

// Must run in the same transaction as entry creation; rollback restores the hold.
function tickex_capacity_holds_consume($pdo, $eventId, $ref, array $tickets)
{
    if (!$pdo->inTransaction()) throw new LogicException('consume_requires_issuance_transaction');
    $exists = (bool)$pdo->query("SELECT 1 FROM sqlite_master WHERE type='table' AND name='checkout_capacity_holds' LIMIT 1")->fetchColumn();
    if (!$exists) return false;
    $read = $pdo->prepare("SELECT event_id,lines_json,status FROM checkout_capacity_holds WHERE ref=:ref LIMIT 1");
    $read->execute(array(':ref' => (string)$ref));
    $hold = $read->fetch(PDO::FETCH_ASSOC);
    if (!$hold) return false;
    if ((int)$hold['event_id'] !== (int)$eventId || (string)$hold['lines_json'] !== json_encode(tickex_capacity_holds_lines($tickets)) || (string)$hold['status'] !== 'active') throw new RuntimeException('hold_mismatch');
    $update = $pdo->prepare("UPDATE checkout_capacity_holds SET status='consumed',updated_at=CURRENT_TIMESTAMP WHERE ref=:ref AND status='active'");
    $update->execute(array(':ref' => (string)$ref));
    if ($update->rowCount() !== 1) throw new RuntimeException('hold_race');
    return true;
}

// Caller must provide a verified terminal provider state; the deadline is not proof.
function tickex_capacity_holds_release($pdo, $ref, $terminalReason)
{
    if (!in_array($terminalReason, array('rejected', 'cancelled', 'expired_verified'), true)) throw new InvalidArgumentException('terminal_state_required');
    if ($pdo->inTransaction()) throw new LogicException('release_requires_own_transaction');
    tickex_capacity_holds_ensure_schema($pdo);
    $pdo->exec('PRAGMA busy_timeout=3000');
    $pdo->exec('BEGIN IMMEDIATE');
    try {
        $read = $pdo->prepare('SELECT event_id,lines_json,stock_totals_json,status FROM checkout_capacity_holds WHERE ref=:ref LIMIT 1');
        $read->execute(array(':ref' => (string)$ref));
        $hold = $read->fetch(PDO::FETCH_ASSOC);
        if (!$hold || (string)$hold['status'] === 'released') { $pdo->exec('COMMIT'); return false; }
        if ((string)$hold['status'] !== 'active') throw new RuntimeException('hold_already_consumed');
        $hasOrders = (bool)$pdo->query("SELECT 1 FROM sqlite_master WHERE type='table' AND name='tc_orders' LIMIT 1")->fetchColumn();
        if ($hasOrders) {
            $paid = $pdo->prepare("SELECT 1 FROM tc_orders WHERE ref=:ref AND payment_status='confirmed' LIMIT 1");
            $paid->execute(array(':ref' => (string)$ref));
            if ($paid->fetchColumn()) throw new RuntimeException('paid_hold_cannot_release');
        }
        $lines = json_decode((string)$hold['lines_json'], true);
        if (!is_array($lines) || !$lines) throw new RuntimeException('invalid_hold_lines');
        $stockTotals = json_decode((string)$hold['stock_totals_json'], true);
        if (!is_array($stockTotals)) throw new RuntimeException('invalid_hold_stock');
        foreach ($lines as $typeId => $units) {
            $stockRead = $pdo->prepare('SELECT cantidad_total FROM tipos_entrada WHERE id=:type AND evento_id=:event LIMIT 1');
            $stockRead->execute(array(':type' => (int)$typeId, ':event' => (int)$hold['event_id']));
            $currentTotal = $stockRead->fetchColumn();
            if (!array_key_exists($typeId, $stockTotals) || (string)$currentTotal !== (string)$stockTotals[$typeId]) throw new RuntimeException('hold_stock_changed');
            $restore = $pdo->prepare('UPDATE tipos_entrada SET cantidad_disponible=cantidad_disponible+:qty WHERE id=:type AND evento_id=:event');
            $restore->execute(array(':qty' => (int)$units, ':type' => (int)$typeId, ':event' => (int)$hold['event_id']));
            if ($restore->rowCount() !== 1) throw new RuntimeException('hold_type_missing');
        }
        $update = $pdo->prepare("UPDATE checkout_capacity_holds SET status='released',terminal_reason=:reason,updated_at=CURRENT_TIMESTAMP WHERE ref=:ref AND status='active'");
        $update->execute(array(':reason' => $terminalReason, ':ref' => (string)$ref));
        if ($update->rowCount() !== 1) throw new RuntimeException('hold_race');
        $pdo->exec('COMMIT');
        return true;
    } catch (Exception $error) {
        try { $pdo->exec('ROLLBACK'); } catch (Exception $_rollbackError) {}
        throw $error;
    }
}
