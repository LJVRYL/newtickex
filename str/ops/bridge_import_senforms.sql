PRAGMA journal_mode=WAL;
PRAGMA synchronous=NORMAL;

DROP VIEW IF EXISTS v_senforms_bridge_status;
DROP TABLE IF EXISTS senforms_import;
DROP TABLE IF EXISTS senforms_bridge_tickets;

CREATE TABLE senforms_import (
  legacy_ticket_id TEXT,
  legacy_event_id  TEXT,
  event_slug       TEXT,
  event_name       TEXT,
  event_start      TEXT,
  event_end        TEXT,
  ticket_ref       TEXT,
  payment_token    TEXT,
  operational_uuid TEXT,
  operational_state TEXT,
  payment_state     TEXT,
  pn_estado         TEXT,
  pn_referencia     TEXT,
  pn_fecha_creacion TEXT,
  pn_fecha_confirmacion TEXT,
  pn_fecha_acreditacion TEXT,
  pn_fecha_recibida TEXT,
  pn_metodo_pago    TEXT,
  first_name        TEXT,
  last_name         TEXT,
  email             TEXT,
  identificator     TEXT,
  selected_type     TEXT,
  selected_type_name TEXT,
  price             TEXT,
  created_at         TEXT,
  last_updated_at    TEXT
);

-- IMPORT (TSV)
.mode tabs
.import /root/senforms_tickets.tsv senforms_import

CREATE TABLE senforms_bridge_tickets (
  legacy_ticket_id INTEGER PRIMARY KEY,
  legacy_event_id  INTEGER,
  event_slug       TEXT,
  event_name       TEXT,
  event_start      TEXT,
  event_end        TEXT,
  ticket_ref       TEXT,
  payment_token    TEXT,
  operational_uuid TEXT,
  operational_state TEXT,
  payment_state     TEXT,
  pn_estado         TEXT,
  pn_referencia     TEXT,
  pn_fecha_creacion TEXT,
  pn_fecha_confirmacion TEXT,
  pn_fecha_acreditacion TEXT,
  pn_fecha_recibida TEXT,
  pn_metodo_pago    TEXT,
  first_name        TEXT,
  last_name         TEXT,
  email             TEXT,
  identificator     TEXT,
  selected_type     TEXT,
  selected_type_name TEXT,
  price             REAL,
  created_at        TEXT,
  last_updated_at   TEXT,
  synced_at         TEXT DEFAULT (datetime('now'))
);

-- MERGE idempotente: nos quedamos con la última fila por legacy_ticket_id (MAX(rowid))
INSERT OR REPLACE INTO senforms_bridge_tickets (
  legacy_ticket_id, legacy_event_id, event_slug, event_name, event_start, event_end,
  ticket_ref, payment_token, operational_uuid, operational_state, payment_state,
  pn_estado, pn_referencia, pn_fecha_creacion, pn_fecha_confirmacion, pn_fecha_acreditacion, pn_fecha_recibida, pn_metodo_pago,
  first_name, last_name, email, identificator, selected_type, selected_type_name,
  price, created_at, last_updated_at, synced_at
)
SELECT
  CAST(si.legacy_ticket_id AS INTEGER),
  CAST(si.legacy_event_id  AS INTEGER),
  si.event_slug,
  si.event_name,
  si.event_start,
  si.event_end,
  si.ticket_ref,
  NULLIF(si.payment_token,'NULL'),
  NULLIF(si.operational_uuid,'NULL'),
  NULLIF(si.operational_state,'NULL'),
  NULLIF(si.payment_state,'NULL'),
  NULLIF(si.pn_estado,'NULL'),
  NULLIF(si.pn_referencia,'NULL'),
  NULLIF(si.pn_fecha_creacion,'NULL'),
  NULLIF(si.pn_fecha_confirmacion,'NULL'),
  NULLIF(si.pn_fecha_acreditacion,'NULL'),
  NULLIF(si.pn_fecha_recibida,'NULL'),
  NULLIF(si.pn_metodo_pago,'NULL'),
  NULLIF(si.first_name,'NULL'),
  NULLIF(si.last_name,'NULL'),
  NULLIF(si.email,'NULL'),
  NULLIF(si.identificator,'NULL'),
  NULLIF(si.selected_type,'NULL'),
  NULLIF(si.selected_type_name,'NULL'),
  CAST(si.price AS REAL),
  si.created_at,
  si.last_updated_at,
  datetime('now')
FROM senforms_import si
WHERE si.rowid IN (
  SELECT MAX(si2.rowid)
  FROM senforms_import si2
  GROUP BY si2.legacy_ticket_id
);

CREATE INDEX IF NOT EXISTS idx_senforms_bridge_event ON senforms_bridge_tickets(event_slug);
CREATE INDEX IF NOT EXISTS idx_senforms_bridge_email ON senforms_bridge_tickets(email);
CREATE INDEX IF NOT EXISTS idx_senforms_bridge_ref   ON senforms_bridge_tickets(ticket_ref);

CREATE VIEW v_senforms_bridge_status AS
SELECT
  legacy_ticket_id,
  legacy_event_id,
  event_slug,
  event_name,
  ticket_ref,
  email,
  first_name,
  last_name,
  price,
  payment_state,
  pn_esta
  CASE WHEN payment_state='SUCCESS' OR pn_estado='APROBADO' THEN 1 ELSE 0 END AS is_paid,
  operational_state,
  CASE WHEN oper
  CASE WHEN operational_state='CLOSED' THEN last_updated_at ELSE NU
  last_updated_at
FROM senforms_bridge_tickets;

-- STATS
SELECT 'rows_import', COUNT(*) FROM senforms_import;
SELECT 'rows_bridge', COUNT(*) FROM senforms_bridge_tickets;
SELECT 'paid', SUM(is_paid), 'checked_in', SUM(is_checked_in) FROM v_senforms_bridge_status;
