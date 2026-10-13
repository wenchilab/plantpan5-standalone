#!/usr/bin/env bash
# Modes:
#   serve         : start apache (default; web UI on :80)
#   scan FILE...  : run CLI plantpan-scan
#   bash / sh     : drop into a shell
set -euo pipefail

CMD="${1:-serve}"
shift || true

# Optional override of the PHP memory ceiling, e.g.
#   docker run -e PP_MEMORY_LIMIT=8G ...
# The analysis holds one job's hits in memory, so this is what bounds how much
# sequence a single submission can carry (see "Limits" in README.md).
if [ -n "${PP_MEMORY_LIMIT:-}" ]; then
    echo "[plantpan5-offline] php memory_limit = ${PP_MEMORY_LIMIT}"
    sed -i "s/^memory_limit = .*/memory_limit = ${PP_MEMORY_LIMIT}/" \
        /usr/local/etc/php/conf.d/plantpan.ini
fi

case "$CMD" in
    serve)
        echo "[plantpan5-offline] starting Apache on :80"
        echo "[plantpan5-offline] open http://localhost:8080/ in your browser"
        echo "                    (or whatever port you mapped with -p)"
        exec apache2-foreground
        ;;
    scan) exec plantpan-scan "$@" ;;
    sh|bash) exec /bin/bash "$@" ;;
    *) exec "$CMD" "$@" ;;
esac
