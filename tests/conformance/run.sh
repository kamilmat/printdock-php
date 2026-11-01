#!/bin/sh
# Runs the PAP/1 server conformance suite against examples/standalone (php -S + SQLite).
# Used by `make conformance` (inside the dev image) and by CI.
set -eu

cd "$(dirname "$0")/../.."
PORT="${PORT:-8099}"
DB="$(pwd)/var/conformance.sqlite"
mkdir -p var
rm -f "$DB" "$DB-wal" "$DB-shm"
export PRINTDOCK_DB="$DB"

php -S "127.0.0.1:$PORT" examples/standalone/public/index.php >var/conformance-server.log 2>&1 &
SERVER_PID=$!
trap 'kill "$SERVER_PID" 2>/dev/null || true' EXIT

tries=0
until python3 -c "import urllib.request,sys; urllib.request.urlopen('http://127.0.0.1:$PORT/admin/health', timeout=1)" 2>/dev/null; do
    tries=$((tries + 1))
    if [ "$tries" -gt 50 ]; then
        echo "example server did not start" >&2
        cat var/conformance-server.log >&2
        exit 1
    fi
    sleep 0.2
done

echo "== conformance with the admin API (all checks) =="
python3 tests/conformance/server_conformance.py --admin-url "http://127.0.0.1:$PORT/admin"

echo
echo "== conformance as a black box (--url --key --enqueue-cmd) =="
CREATED="$(python3 - "$PORT" <<'PY'
import json, sys, urllib.request
port = sys.argv[1]
body = json.dumps({"name": "blackbox", "lease_s": 3, "poll_interval_s": 1,
                   "printers": [{"printer_id": "p1", "role": "label", "transport": "socket",
                                 "address": "127.0.0.1:9100", "language": "zpl", "dpi": 203, "media": "100x150mm"}]}).encode()
req = urllib.request.Request("http://127.0.0.1:%s/admin/agents" % port, data=body, method="POST",
                             headers={"Content-Type": "application/json"})
created = json.load(urllib.request.urlopen(req))
print(created["key"], created["agent_id"])
PY
)"
KEY="${CREATED% *}"
export PRINTDOCK_AGENT_ID="${CREATED#* }"
python3 tests/conformance/server_conformance.py --url "http://127.0.0.1:$PORT/pap/v1" --key "$KEY" \
    --printer-id p1 --lease-s 3 \
    --enqueue-cmd 'php examples/standalone/bin/enqueue.php {printer_id} {file}'
