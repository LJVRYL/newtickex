# Copilot Instructions

**Overview**
- PHP 7+ app, no frameworks; primary data in SQLite [save_the_rave.sqlite](save_the_rave.sqlite) (ignored) plus optional MySQL SenForms bridge.
- Main UI [panel_evento.php](panel_evento.php) unifies STR entradas with Tickex/SenForms bridge data and manual incomes; most pages include [inc/bootstrap.php](inc/bootstrap.php) for shared helpers (`db()`, `e()`, layout wrappers).
- Paths are `__DIR__`-relative throughout; keep that for portability.

**Run & Verify**
- Start server from repo root: `php -S 127.0.0.1:8080 -t .`; visit http://127.0.0.1:8080/panel_evento.php?evento_id=1.
- Run [check_setup.php](check_setup.php) first; confirms SQLite presence, bridge view/table, and ability to create `manual_income`.
- Diagnostics helpers: [diag_db_structure.php](diag_db_structure.php), [diag_check.php](diag_check.php), [diag.py](diag.py); quick docs in [LEEME_PRIMERO.txt](LEEME_PRIMERO.txt), [GUIA_RAPIDA.txt](GUIA_RAPIDA.txt), [CAMBIOS_RESUMEN.txt](CAMBIOS_RESUMEN.txt), [README_REFACTOR.md](README_REFACTOR.md), [REFACTOR_PANEL_EVENTO.md](REFACTOR_PANEL_EVENTO.md), [TEST_INSTRUCTIONS.sh](TEST_INSTRUCTIONS.sh).

**Auth & Roles**
- Login enforced via [inc/auth.php](inc/auth.php) `require_login()`; sessions may expose `tipo_global`, `rol`, `rol_evento`.
- Admin-only actions gate on `admin_evento` or `super_admin` (`superadmin` also allowed). Keep these checks on new endpoints and POST handlers.

**Data Access**
- SQLite via [inc/db.php](inc/db.php) `db()` pointing to `save_the_rave.sqlite`; die fast if missing. Never commit the DB file.
- SenForms MySQL via [inc/senforms.php](inc/senforms.php) `sf_*` helpers; credentials default in code but prefer overriding `SENFORMS_DB_*` env vars.

**Ticket Unification**
- Core in [inc/unified_tickets.php](inc/unified_tickets.php):
  - `detect_table_columns()` + `get_checkin_column()` tolerate schema drift (checkin vs checked_in, event_id vs event_slug, etc.).
  - `get_unified_entries($pdo,$evento_id,$filters)` merges STR `entradas` with Tickex data from view `v_senforms_bridge_status` or table `senforms_bridge_tickets`; filters paid rows based on available status columns, normalizes fields, and sorts newest-first by timestamps.
  - `get_unified_stats()` computes totals/stock across both sources; STR assumed paid.
- STR-only paths still work if bridge artifacts are missing.

**Bridge Mapping**
- Mapping table `bridge_event_map` auto-created; `get_mapped_bridge_slugs()`/`set_bridge_mapping()` (and [set_bridge_mapping.php](set_bridge_mapping.php)) control mapping from STR event id to Tickex slug; fallback to `eventos.slug` when absent.

**Manual Income Module**
- [inc/manual_income.php](inc/manual_income.php) ensures table `manual_income`, provides CRUD and totals; [add_manual_income.php](add_manual_income.php) and [delete_manual_income.php](delete_manual_income.php) respond JSON `{success|error}` and enforce roles.
- UI lives in [panel_evento.php](panel_evento.php); uses AJAX and reload for totals.

**UI / Patterns**
- Layout uses [inc/layout_top.php](inc/layout_top.php) / bottom; escape user data with `e()` before rendering.
- [panel_evento.php](panel_evento.php) also lists events (cards), handles ownership via `creado_por_admin_id` when present, and dynamically detects columns (stock, fechas). Preserve detection logic instead of hardcoding.

**Safety / Conventions**
- Prefer prepared statements; keep tolerant fallbacks rather than failing when optional tables/columns are missing.
- Stick to relative paths; avoid leaking MySQL creds—override via env locally.
