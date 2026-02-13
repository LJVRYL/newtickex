<?php
// Helpers para catálogo SenForms (activación local y cache de tickets)
require_once __DIR__.'/db.php';
require_once __DIR__.'/senforms.php';

function ensure_local_tickettype_state_table(PDO $pdo){
    $pdo->exec("CREATE TABLE IF NOT EXISTS local_tickettype_state (
        event_id INTEGER NOT NULL,
        tickettype_id INTEGER NOT NULL,
        is_active INTEGER NOT NULL DEFAULT 1,
        created_at TEXT DEFAULT (datetime('now')),
        PRIMARY KEY (event_id, tickettype_id)
    )");
}

function get_local_tickettype_states(PDO $pdo, $eventId){
    ensure_local_tickettype_state_table($pdo);
    $st = $pdo->prepare("SELECT tickettype_id, is_active FROM local_tickettype_state WHERE event_id = ?");
    $st->execute(array((int)$eventId));
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    $map = array();
    foreach ($rows as $r) {
        $map[(int)$r['tickettype_id']] = (int)$r['is_active'] === 1;
    }
    return $map;
}

function set_local_tickettype_state(PDO $pdo, $eventId, $ticketTypeId, $isActive){
    ensure_local_tickettype_state_table($pdo);
    $st = $pdo->prepare("INSERT INTO local_tickettype_state (event_id, tickettype_id, is_active, created_at)
        VALUES (:event_id, :tickettype_id, :is_active, datetime('now'))
        ON CONFLICT(event_id, tickettype_id) DO UPDATE SET is_active = excluded.is_active");
    $st->execute(array(
        ':event_id' => (int)$eventId,
        ':tickettype_id' => (int)$ticketTypeId,
        ':is_active' => $isActive ? 1 : 0,
    ));
}

function ensure_sf_tickets_cache_table(PDO $pdo){
    $pdo->exec("CREATE TABLE IF NOT EXISTS senforms_tickets_cache (
        ticket_id INTEGER PRIMARY KEY,
        event_id INTEGER NOT NULL,
        selected_type TEXT,
        selected_type_id INTEGER,
        price REAL,
        payment_state TEXT,
        is_paid INTEGER NOT NULL DEFAULT 0,
        payment_token TEXT,
        email TEXT,
        first_name TEXT,
        last_name TEXT,
        created_at TEXT,
        updated_at TEXT,
        fetched_at TEXT NOT NULL DEFAULT (datetime('now'))
    )");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_sf_cache_event ON senforms_tickets_cache(event_id)");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_sf_cache_event_type ON senforms_tickets_cache(event_id, selected_type_id)");
}

function sf_import_tickets_to_cache(PDO $localPdo, $eventId){
    ensure_sf_tickets_cache_table($localPdo);
    $remote = sf_db();
    $st = $remote->prepare("SELECT Id, EventId, SelectedType, Price, PaymentState, PaymentToken, Email, FirstName, LastName, CreatedAt, LastUpdatedAt FROM Tickets WHERE EventId = ? ORDER BY Id ASC");
    $st->execute(array((int)$eventId));

    $localPdo->beginTransaction();
    $ins = $localPdo->prepare("INSERT OR REPLACE INTO senforms_tickets_cache (
        ticket_id, event_id, selected_type, selected_type_id, price, payment_state, is_paid,
        payment_token, email, first_name, last_name, created_at, updated_at, fetched_at
    ) VALUES (
        :ticket_id, :event_id, :selected_type, :selected_type_id, :price, :payment_state, :is_paid,
        :payment_token, :email, :first_name, :last_name, :created_at, :updated_at, datetime('now')
    )");

    $stats = array('processed' => 0, 'paid' => 0);
    try {
        while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
            $stats['processed']++;
            $selTypeId = is_numeric($row['SelectedType']) ? (int)$row['SelectedType'] : null;
            $isPaid = sf_is_payment_state_paid($row['PaymentState']);
            if ($isPaid) $stats['paid']++;

            $ins->execute(array(
                ':ticket_id' => (int)$row['Id'],
                ':event_id' => (int)$row['EventId'],
                ':selected_type' => $row['SelectedType'],
                ':selected_type_id' => $selTypeId,
                ':price' => isset($row['Price']) ? (float)$row['Price'] : 0,
                ':payment_state' => $row['PaymentState'],
                ':is_paid' => $isPaid ? 1 : 0,
                ':payment_token' => $row['PaymentToken'],
                ':email' => $row['Email'],
                ':first_name' => $row['FirstName'],
                ':last_name' => $row['LastName'],
                ':created_at' => $row['CreatedAt'],
                ':updated_at' => $row['LastUpdatedAt'],
            ));
        }
        $localPdo->commit();
    } catch (Exception $e) {
        try { $localPdo->rollBack(); } catch (Exception $_) {}
        throw $e;
    }

    return $stats;
}

function sf_cache_sales_report(PDO $pdo, $eventId){
    ensure_sf_tickets_cache_table($pdo);
    $st = $pdo->prepare("SELECT 
        COALESCE(selected_type, '0') AS selected_type,
        selected_type_id,
        COUNT(*) AS qty,
        SUM(COALESCE(price,0)) AS total_real
      FROM senforms_tickets_cache
      WHERE event_id = ? AND is_paid = 1
      GROUP BY selected_type, selected_type_id
      ORDER BY qty DESC");
    $st->execute(array((int)$eventId));
    return $st->fetchAll(PDO::FETCH_ASSOC);
}

function sf_cache_summary(PDO $pdo, $eventId){
    ensure_sf_tickets_cache_table($pdo);
    $st = $pdo->prepare("SELECT COUNT(*) AS rows, SUM(CASE WHEN is_paid = 1 THEN 1 ELSE 0 END) AS paid, MAX(fetched_at) AS last_sync FROM senforms_tickets_cache WHERE event_id = ?");
    $st->execute(array((int)$eventId));
    $row = $st->fetch(PDO::FETCH_ASSOC);
    return $row ? $row : array('rows' => 0, 'paid' => 0, 'last_sync' => null);
}

?>
