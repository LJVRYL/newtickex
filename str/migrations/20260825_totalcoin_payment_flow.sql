-- TotalCoin payment flow migration, SQLite 3.28-compatible.
-- Apply with run_20260825_totalcoin_payment_flow.php; it checks columns first.

CREATE TABLE IF NOT EXISTS payment_notifications (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    idempotency_key TEXT NOT NULL UNIQUE,
    concepto TEXT NOT NULL,
    referencia TEXT,
    estado TEXT NOT NULL,
    amount REAL,
    tc_order_id INTEGER,
    payload_hash TEXT NOT NULL,
    payload_json TEXT,
    received_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    processed_at TEXT,
    result TEXT,
    error TEXT
);

CREATE INDEX IF NOT EXISTS idx_payment_notifications_concepto ON payment_notifications(concepto);
CREATE INDEX IF NOT EXISTS idx_payment_notifications_order ON payment_notifications(tc_order_id);
CREATE INDEX IF NOT EXISTS idx_payment_notifications_estado ON payment_notifications(estado);
CREATE INDEX IF NOT EXISTS idx_payment_notifications_received ON payment_notifications(received_at);

-- Added to tc_orders by the versioned runner:
-- payment_status, payment_confirmed_at, processing_status,
-- processing_started_at, email_status, email_attempts,
-- email_sent_at, email_last_error.

-- Added to entradas by the versioned runner:
-- issuance_key.
-- A UNIQUE index prevents duplicate issuance units.
