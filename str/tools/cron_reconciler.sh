#!/bin/bash
# Cron wrapper para el reconciliador de órdenes.
# Ejecutar cada 1 minuto via crontab:
#   * * * * * /path/absoluto/str/tools/cron_reconciler.sh >> /path/absoluto/uploads/cron_reconciler.log 2>&1

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
LOCK_FILE="/tmp/tickex_reconciler.lock"
PHP_BIN="${PHP_CLI:-/opt/php7-4/bin/php-cli}"

# Si no existe el binario preferido, intentar con el php del PATH
if [ ! -x "$PHP_BIN" ]; then
    PHP_BIN="$(which php 2>/dev/null)"
fi

if [ -z "$PHP_BIN" ] || [ ! -x "$PHP_BIN" ]; then
    echo "[$(date '+%Y-%m-%d %H:%M:%S')] ERROR: php-cli no encontrado. Configurar PHP_CLI env var." >&2
    exit 1
fi

# Usar flock para evitar ejecuciones solapadas
exec 9>"$LOCK_FILE"
if ! flock -n 9; then
    echo "[$(date '+%Y-%m-%d %H:%M:%S')] SKIP: reconciliador ya en ejecución." 
    exit 0
fi

echo "[$(date '+%Y-%m-%d %H:%M:%S')] START reconciler"
"$PHP_BIN" "$SCRIPT_DIR/order_reconciler.php"
EXIT_CODE=$?
echo "[$(date '+%Y-%m-%d %H:%M:%S')] END reconciler exit=$EXIT_CODE"

exit $EXIT_CODE
