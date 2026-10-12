#!/usr/bin/env bash
# tests/phase4/mcp_servers.sh start|stop|variant|venv — the two MCP servers on SCRATCH loopback ports from the scratch environment ($CRM_DEV_ENV):
# records :8804, activity :8805 (never the installed application's ports 8843/8844 or its services). Pid files live in $CRM_DEV_STATE. `variant notoken` is a third
# records server on :8809 with no OS_APPLICATION_TOKEN (it cannot ask the kernel: the fail-closed proof); `variant nobridge` has the bridge's port closed, `variant badkey` signs the
# bridge with a key the application does not hold; `variant stop` ends it.
# The Python is the application's own mcp/venv (python3 -m venv mcp/venv && mcp/venv/bin/pip install -r mcp/requirements.txt; deploy/ROOT_STEPS.sh does it for the installed
# copy), else $CRM_MCP_VENV, else a sibling application's venv that already holds FastMCP, asyncpg, httpx and uvicorn — nothing is installed here.
# Under CRM_APP=apache the bridge is the INTERNAL port (:8807: the public name answers 404 under /internal/); under php -S the one server (:8801).
# Run it under the shared lock: flock /tmp/crm-proofs.lock tests/phase4/run.sh — this script never takes it.
set -euo pipefail
ROOT=$(cd "$(dirname "$0")/../.." && pwd)
ENVF=${CRM_DEV_ENV:-/tmp/crm-dev40.env}
STATE=${CRM_DEV_STATE:-/tmp/crm-dev40-state}
mkdir -p "$STATE"
VENV=
for c in "${CRM_MCP_VENV:-}" "$ROOT/mcp/venv" /srv/apps/shareholder_intelligence/mcp/venv /srv/apps/inventory/mcp/venv /srv/apps/knowledge/mcp/venv /srv/apps/doccloud/mcp/venv /srv/apps/spaces/mcp/venv; do
  [ -n "$c" ] && [ -x "$c/bin/python" ] && "$c/bin/python" -c 'import mcp, asyncpg, httpx, uvicorn' 2>/dev/null && { VENV=$c; break; }
done
[ -n "$VENV" ] || { echo "no venv with FastMCP, asyncpg, httpx and uvicorn found" >&2; exit 1; }
PY="$VENV/bin/python"
BRIDGE_PORT=8801; [ "${CRM_APP:-php}" = apache ] && BRIDGE_PORT=8807
stop() {
  for f in "$STATE"/mcp-*.pid; do [ -e "$f" ] && kill "$(cat "$f")" 2>/dev/null || true; rm -f "$f"; done
  for i in $(seq 1 30); do ss -ltn 2>/dev/null | grep -qE ':(8804|8805) ' || break; sleep 0.2; done
  fuser -k -n tcp 8804 >/dev/null 2>&1 || true; fuser -k -n tcp 8805 >/dev/null 2>&1 || true      # by port, never by a command-line pattern
}
case "${1:-}" in
  stop) stop; fuser -k -n tcp 8809 >/dev/null 2>&1 || true ;;
  variant)
    fuser -k -n tcp 8809 >/dev/null 2>&1 || true
    if [ "${2:-}" != stop ]; then
      set -a; . "$ENVF"; set +a
      case "${2:-}" in
        notoken) ( cd "$ROOT/mcp" && exec env -u OS_APPLICATION_TOKEN APP_INTERNAL_PORT=$BRIDGE_PORT MCP_RECORDS_PORT=8809 MCP_ACTIVITY_PORT=8805 "$PY" "$ROOT/mcp/records_server.py" >"$STATE/mcp-variant.log" 2>&1 ) & echo $! > "$STATE/mcp-variant.pid" ;;
        nobridge) ( cd "$ROOT/mcp" && exec env APP_INTERNAL_PORT=8899 MCP_RECORDS_PORT=8809 MCP_ACTIVITY_PORT=8805 "$PY" "$ROOT/mcp/records_server.py" >"$STATE/mcp-variant.log" 2>&1 ) & echo $! > "$STATE/mcp-variant.pid" ;;
        badkey) ( cd "$ROOT/mcp" && exec env APP_INTERNAL_PORT=$BRIDGE_PORT ACTIONS_RELAY_KEY=$(printf 'x%.0s' $(seq 1 40)) MCP_RECORDS_PORT=8809 MCP_ACTIVITY_PORT=8805 "$PY" "$ROOT/mcp/records_server.py" >"$STATE/mcp-variant.log" 2>&1 ) & echo $! > "$STATE/mcp-variant.pid" ;;
        *) echo "variant notoken|nobridge|badkey|stop" >&2; exit 2 ;;
      esac
      for i in $(seq 1 60); do curl -s -o /dev/null -m 1 "http://127.0.0.1:8809/mcp" && break; sleep 0.2; done
    fi ;;
  venv) echo "$VENV" ;;
  start)
    stop
    set -a; . "$ENVF"; set +a
    ( cd "$ROOT/mcp" && exec env APP_INTERNAL_PORT=$BRIDGE_PORT MCP_RECORDS_PORT=8804 MCP_ACTIVITY_PORT=8805 "$PY" "$ROOT/mcp/records_server.py" >"$STATE/mcp-records.log" 2>&1 ) & echo $! > "$STATE/mcp-records.pid"
    ( cd "$ROOT/mcp" && exec env APP_INTERNAL_PORT=$BRIDGE_PORT MCP_RECORDS_PORT=8804 MCP_ACTIVITY_PORT=8805 "$PY" "$ROOT/mcp/activity_server.py" >"$STATE/mcp-activity.log" 2>&1 ) & echo $! > "$STATE/mcp-activity.pid"
    for p in 8804 8805; do
      for i in $(seq 1 60); do curl -s -o /dev/null -m 1 "http://127.0.0.1:$p/mcp" && break; sleep 0.2; done
    done
    echo "mcp servers up (records :8804, activity :8805; python $VENV; bridge :$BRIDGE_PORT)" ;;
  *) echo "usage: $0 start|stop|variant|venv" >&2; exit 2 ;;
esac
