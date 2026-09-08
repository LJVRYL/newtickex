#!/usr/bin/env bash
set -u

failed=0

report_failure() {
  printf '%s [health] %s\n' "$(date -Iseconds)" "$1"
  failed=1
}

if ! /opt/ferozo/bin/apachectl -t >/dev/null 2>&1; then
  report_failure "la configuracion de Apache no es valida"
fi

for port in 80 443; do
  if ! ss -lnt | grep -Eq ":${port}[[:space:]]"; then
    report_failure "el puerto ${port} no esta escuchando"
  fi
done

check_page() {
  url="$1"
  marker="$2"
  body="$(curl -fsS --max-time 15 "$url" 2>/dev/null)" || {
    report_failure "${url} no responde correctamente"
    return
  }
  printf '%s' "$body" | grep -Fqi "$marker" || report_failure "${url} respondio sin el contenido esperado"
}

check_page "https://www.tickex.com.ar/" "Todo tu evento bajo control"
check_page "https://str.tickex.com.ar/login.php" "<title>Tickex"

db_file="/opt/ferozo3/web/str/save_the_rave.sqlite"
if [ ! -r "$db_file" ]; then
  report_failure "la base de datos productiva no se puede leer"
fi

free_kb="$(df -Pk /opt/ferozo3/web | awk 'NR==2 {print $4}')"
if [ -z "$free_kb" ] || [ "$free_kb" -lt 1048576 ]; then
  report_failure "queda menos de 1 GiB libre en el disco de Tickex"
fi

exit "$failed"
