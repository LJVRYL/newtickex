#!/usr/bin/env bash
# deploy.sh — Tickex deploy al VPS Ferozo
# Uso: bash str/ops/deploy.sh [--skip-cron]
set -euo pipefail

DEPLOY_DIR="${DEPLOY_DIR:-/opt/ferozo3/web}"
PHP_BIN="${PHP_CLI:-/opt/php7-4/bin/php-cli}"
STR_DIR="$DEPLOY_DIR/str"
DB_FILE="$STR_DIR/save_the_rave.sqlite"
UPLOADS_DIR="$STR_DIR/../uploads"
LOG="$UPLOADS_DIR/deploy.log"
SKIP_CRON="${1:-}"

log() { echo "[$(date '+%Y-%m-%d %H:%M:%S')] $*" | tee -a "$LOG"; }

# ── 0. Verificar entorno ──────────────────────────────────────────────
log "=== DEPLOY START ==="
[ -d "$DEPLOY_DIR" ] || { log "ERROR: DEPLOY_DIR no existe: $DEPLOY_DIR"; exit 1; }
[ -x "$PHP_BIN" ]    || { log "ERROR: php-cli no encontrado: $PHP_BIN"; exit 1; }
mkdir -p "$UPLOADS_DIR"
chmod 755 "$UPLOADS_DIR"

# ── 1. Git pull ───────────────────────────────────────────────────────
log "git pull..."
cd "$DEPLOY_DIR"
git fetch origin
git status
git pull --ff-only origin main 2>&1 | tee -a "$LOG"

# ── 2. Permisos ───────────────────────────────────────────────────────
log "Ajustando permisos..."
find "$STR_DIR/tools" -name "*.sh" -exec chmod +x {} \;
chmod +x "$STR_DIR/ops/deploy.sh" || true
[ -f "$DB_FILE" ] && chmod 664 "$DB_FILE" && log "DB: $DB_FILE OK"

# ── 3. Migración de schema (auto vía db.php al primer request) ────────
log "Forzando migración de schema..."
"$PHP_BIN" -r "
define('CLI_MIGRATE', true);
chdir('$STR_DIR');
require_once '$STR_DIR/inc/db.php';
\$pdo = db();
// Verificar columnas críticas
\$cols = \$pdo->query('PRAGMA table_info(tc_orders)')->fetchAll(PDO::FETCH_COLUMN, 1);
\$ok = in_array('processed_at', \$cols) && in_array('request_id', \$cols);
echo \$ok ? 'schema OK' . PHP_EOL : 'schema FAIL' . PHP_EOL;
\$colsE = \$pdo->query('PRAGMA table_info(entradas)')->fetchAll(PDO::FETCH_COLUMN, 1);
echo in_array('tc_order_request_id', \$colsE) ? 'entradas.tc_order_request_id OK' . PHP_EOL : 'entradas.tc_order_request_id MISSING - migrando...' . PHP_EOL;
\$oe = \$pdo->query(\"SELECT 1 FROM sqlite_master WHERE type='table' AND name='order_events' LIMIT 1\")->fetchColumn();
echo \$oe ? 'order_events OK' . PHP_EOL : 'order_events MISSING' . PHP_EOL;
" 2>&1 | tee -a "$LOG"

# ── 4. Verificar variables de entorno requeridas ──────────────────────
log "Verificando env vars..."
MISSING_ENV=0
for VAR in TICKEX_SITE_URL TOTALCOIN_CALLBACK_BASE; do
    VAL=$(printenv "$VAR" 2>/dev/null || true)
    if [ -z "$VAL" ]; then
        log "  WARN: $VAR no configurada — verificar .htaccess o pool PHP-FPM"
        MISSING_ENV=$((MISSING_ENV + 1))
    else
        log "  OK: $VAR=$VAL"
    fi
done
[ $MISSING_ENV -gt 0 ] && log "WARN: $MISSING_ENV env vars sin configurar. Ver sección ENV al final."

# ── 5. Cron ───────────────────────────────────────────────────────────
if [ "$SKIP_CRON" != "--skip-cron" ]; then
    log "Instalando cron (reconciliador cada 1 min)..."
    CRON_CMD="* * * * * $STR_DIR/tools/cron_reconciler.sh >> $UPLOADS_DIR/cron_reconciler.log 2>&1"
    TMPFILE=$(mktemp)
    crontab -l 2>/dev/null | grep -v "cron_reconciler" > "$TMPFILE" || true
    echo "$CRON_CMD" >> "$TMPFILE"
    crontab "$TMPFILE"
    rm -f "$TMPFILE"
    log "  Cron instalado: $CRON_CMD"
    log "  Verificando: $(crontab -l | grep cron_reconciler || echo 'ERROR: no encontrado')"
fi

# ── 6. Smoke test HTTP ────────────────────────────────────────────────
SITE_URL="${TICKEX_SITE_URL:-https://str.tickex.com.ar}"
log "Smoke test HTTP: $SITE_URL/ping.php"
HTTP_CODE=$(curl -s -o /dev/null -w "%{http_code}" --max-time 10 "$SITE_URL/ping.php" 2>/dev/null || echo "000")
if [ "$HTTP_CODE" = "200" ]; then
    log "  ping.php: HTTP $HTTP_CODE OK"
else
    log "  WARN: ping.php devolvió HTTP $HTTP_CODE"
fi

log "=== DEPLOY DONE ==="
echo ""
echo "──────────────────────────────────────────────────────────────────"
echo " Env vars requeridas (.htaccess o PHP-FPM pool config):"
echo ""
echo "   SetEnv TICKEX_SITE_URL          https://str.tickex.com.ar"
echo "   SetEnv TOTALCOIN_CALLBACK_BASE  https://str.tickex.com.ar/str"
echo "   SetEnv TOTALCOIN_SEND_CALLBACKS 1"
echo "   SetEnv TICKEX_MAIL_FROM_EMAIL   no-reply@tickex.com.ar"
echo "   SetEnv TICKEX_MAIL_FROM_NAME    Tickex"
echo "   SetEnv TICKEX_MAIL_ENVELOPE_FROM no-reply@tickex.com.ar"
echo "──────────────────────────────────────────────────────────────────"
echo " Cron instalado: * * * * * .../cron_reconciler.sh"
echo " Log de deploy:  $LOG"
echo "──────────────────────────────────────────────────────────────────"
