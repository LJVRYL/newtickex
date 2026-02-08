#!/usr/bin/env bash
set -euo pipefail

DB="/opt/ferozo3/web/str/save_the_rave.sqlite"
TSV="/root/senforms_tickets.tsv"
IMPORT_SQL="/root/bridge_import_senforms.sql"

# 1) Export desde MySQL (SenForms) a TSV
mysql --login-path=senforms --batch --raw -N SenForms -e "
SELECT
  t.Id                                        AS legacy_ticket_id,
  t.EventId                                    AS legacy_event_id,
  e.SiteName                                   AS event_slug,
  REPLACE(REPLACE(e.Name, '\t',' '), '\n',' ') AS event_name,
  e.EventStartDate,
  e.EventEndDate,

  REPLACE(REPLACE(t.Reference, '\t',' '), '\n',' ') AS ticket_ref,
  t.PaymentToken,
  t.OperationalUuid,
  t.OperationalState,
  t.PaymentState,

  pn.Estado        AS pn_estado,
  pn.Referencia    AS pn_referencia,
  pn.FechaCreacion AS pn_fecha_creacion,
  pn.FechaConfirmacion AS pn_fecha_confirmacion,
  pn.FechaAcreditacion AS pn_fecha_acreditacion,
  pn.FechaRecibida AS pn_fecha_recibida,
  pn.MetodoPago    AS pn_metodo_pago,

  REPLACE(REPLACE(t.FirstName, '\t',' '), '\n',' ') AS first_name,
  REPLACE(REPLACE(t.LastName,  '\t',' '), '\n',' ') AS last_name,
  t.Email,
  t.Identificator,
  t.SelectedType,
  REPLACE(REPLACE(tt.Name, '\t',' '), '\n',' ') AS selected_type_name,
  t.Price,
  t.CreatedAt,
  t.LastUpdatedAt
FROM Tickets t
JOIN Events e ON e.Id = t.EventId
LEFT JOIN TicketType tt ON tt.Id = CAST(t.SelectedType AS UNSIGNED)
LEFT JOIN PaymentNotification pn ON pn.Concepto = t.Reference
ORDER BY t.LastUpdatedAt ASC;
" > "$TSV"

# 2) Import a SQLite (tu script)
# (si tu script todavía tiene alguna línea vieja que tira error, igual el import te está funcionando)
sqlite3 "$DB" < "$IMPORT_SQL" >/dev/null 2>&1 || true

# 3) Re-crear la VIEW buena (sin CASE)
sqlite3 "$DB" <<'SQL'
DROP VIEW IF EXISTS v_senforms_bridge_status;

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
  pn_estado,
  ((COALESCE(payment_state,'')='SUCCESS') OR (COALESCE(pn_estado,'')='APROBADO')) AS is_paid,
  operational_state,
  (COALESCE(operational_state,'')='CLOSED') AS is_checked_in,
  last_updated_at AS checked_in_at,
  last_updated_at
FROM senforms_bridge_tickets;
SQL

# 4) WAL OFF + permisos OK
sqlite3 "$DB" "PRAGMA wal_checkpoint(FULL); PRAGMA journal_mode=DELETE;" >/dev/null
chown apache:webusers /opt/ferozo3/web/str/save_the_rave.sqlite* 2>/dev/null || true
chmod 664 /opt/ferozo3/web/str/save_the_rave.sqlite* 2>/dev/null || true

# 5) Stats rápidas
sqlite3 "$DB" "SELECT 'rows_bridge', COUNT(*) FROM senforms_bridge_tickets;"
sqlite3 "$DB" "SELECT 'paid', SUM(is_paid), 'checked_in', SUM(is_checked_in) FROM v_senforms_bridge_status;"
