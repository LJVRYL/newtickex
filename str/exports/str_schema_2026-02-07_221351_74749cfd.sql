CREATE TABLE entradas (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            nombre TEXT NOT NULL,
            email TEXT NOT NULL,
            fecha_registro TEXT NOT NULL,
            codigo TEXT NOT NULL UNIQUE,
            checked_in INTEGER NOT NULL DEFAULT 0
        , checked_in_at TEXT, tipo TEXT NOT NULL DEFAULT 'desconocido', monto_pagado INTEGER NOT NULL DEFAULT 0, evento_id INTEGER);
CREATE TABLE entradas_eliminadas (
  id INTEGER,
  nombre TEXT,
  email TEXT,
  fecha_registro TEXT,
  codigo TEXT,
  checked_in INTEGER,
  checked_in_at TEXT,
  tipo TEXT,
  monto_pagado INTEGER,
  deleted_at TEXT
);
CREATE TABLE usuarios_admin (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  username TEXT NOT NULL UNIQUE,
  password TEXT NOT NULL,
  rol TEXT NOT NULL,
  activo INTEGER NOT NULL DEFAULT 1
, tipo_global TEXT NOT NULL DEFAULT 'admin_evento', rol_evento TEXT DEFAULT NULL, nombre TEXT, email TEXT, dni TEXT, cbu TEXT, avatar_filename TEXT, creado_por_admin_id INTEGER, evento_id INTEGER);
CREATE TABLE eventos (
  id                 INTEGER PRIMARY KEY AUTOINCREMENT,
  nombre             TEXT NOT NULL,
  slug               TEXT NOT NULL UNIQUE,   -- ej: "str", "retro"
  descripcion        TEXT,
  flyer_filename     TEXT,                   -- nombre de archivo en disco
  fecha_desde        TEXT,                   -- ISO (YYYY-MM-DD o YYYY-MM-DD HH:MM)
  fecha_hasta        TEXT,
  creado_por_admin_id INTEGER,
  creado_en          TEXT NOT NULL,
  actualizado_en     TEXT
, publicado_site INTEGER DEFAULT 0, buy_url TEXT);
CREATE TABLE tipos_entrada (
  id                   INTEGER PRIMARY KEY AUTOINCREMENT,
  evento_id            INTEGER NOT NULL,
  nombre               TEXT NOT NULL,  -- ej: "General FREE", "Early Bird"
  tipo                 TEXT NOT NULL,  -- 'free' o 'paga'
  precio               INTEGER NOT NULL DEFAULT 0,  -- en tu unidad (ej: pesos)
  cantidad_total       INTEGER NOT NULL DEFAULT 0,
  cantidad_disponible  INTEGER NOT NULL DEFAULT 0,
  hora_limite          TEXT,        -- texto libre: "hasta las 02:00", etc.
  reglas_precio        TEXT, categoria TEXT, tipo_venta TEXT, descripcion TEXT,        -- JSON / descripción de tandas, a futuro
  FOREIGN KEY(evento_id) REFERENCES eventos(id)
);
CREATE VIEW usuarios AS
SELECT
  id,
  COALESCE(NULLIF(nombre, ''), username)      AS nombre,
  ''                                          AS apellido,
  email,
  password,
  '$2y$10$aC0hWyiirkltF/gE8wp9Q.LutJ3iHmlHTrK65msZv25OUdofF4H32' AS password_hash,
  rol,
  1                                           AS email_confirmado,
  NULL                                        AS token_confirmacion,
  datetime('now')                             AS creado_en,
  datetime('now')                             AS fecha_registro,
  NULL                                        AS token_verificacion,
  1                                           AS verificado,
  tipo_global,
  dni,
  cbu,
  avatar_filename,
  creado_por_admin_id,
  evento_id
FROM usuarios_admin
WHERE activo = 1;
CREATE TABLE plantillas_entrada (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  admin_id INTEGER,
  creado_por_admin_id INTEGER,
  categoria TEXT,
  nombre TEXT NOT NULL,
  tipo TEXT NOT NULL,
  precio_default INTEGER NOT NULL DEFAULT 0,
  cantidad_default INTEGER NOT NULL DEFAULT 0,
  hora_limite_default TEXT,
  descripcion TEXT,
  activo INTEGER NOT NULL DEFAULT 1
, reglas_default TEXT, creado_en TEXT, actualizado_en TEXT);
CREATE TABLE clientes_sites (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    admin_id INTEGER NOT NULL,
    slug_publico TEXT NOT NULL,
    nombre_publico TEXT NOT NULL,
    texto_hero TEXT,
    texto_intro TEXT,
    visible INTEGER DEFAULT 0,
    created_at TEXT,
    updated_at TEXT
);
CREATE UNIQUE INDEX idx_clientes_sites_slug ON clientes_sites(slug_publico);
CREATE TABLE tickex_event_map (
  str_event_id     INTEGER PRIMARY KEY,
  legacy_event_id  INTEGER NOT NULL,
  event_slug       TEXT NOT NULL,
  event_public_id  TEXT NOT NULL,
  created_at       TEXT NOT NULL DEFAULT (datetime('now')),
  updated_at       TEXT
);
CREATE UNIQUE INDEX idx_tickex_event_map_legacy  ON tickex_event_map(legacy_event_id);
CREATE UNIQUE INDEX idx_tickex_event_map_public  ON tickex_event_map(event_public_id);
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
CREATE INDEX idx_senforms_bridge_event ON senforms_bridge_tickets(event_slug);
CREATE INDEX idx_senforms_bridge_email ON senforms_bridge_tickets(email);
CREATE INDEX idx_senforms_bridge_ref   ON senforms_bridge_tickets(ticket_ref);
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
