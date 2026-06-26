#!/usr/bin/env bash
# Modes:
#   serve         : start apache (default; web UI on :80)
#   scan FILE...  : run CLI plantpan-scan
#   bash / sh     : drop into a shell
set -euo pipefail

CMD="${1:-serve}"
shift || true

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
