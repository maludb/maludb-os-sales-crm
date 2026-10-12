#!/usr/bin/env bash
# Phase 4 — the two MCP servers, the five shares, app_roles, the gate, the wall, the bridge, the registry: tests/phase4/run.sh
# A fresh SCRATCH database (crm_dev40 — never the installed one), the application on :8801 (php -S, or CRM_APP=apache: a real Apache with the rendered vhost — then the proofs also reach the
# servers through the vhost's own /mcp/records and /mcp/activity lines, and the bridge is the INTERNAL port :8807), a fake kernel (:8802 — run-facts, the chat, the K7 doors), a fake MaluDB (:8803) and
# MaluMail (:8806), and the servers under test on :8804 (records) and :8805 (activity). The fail-closed proofs stop the fake kernel for a moment. NOTHING REAL IS TOUCHED: no live service, Apache,
# unit, database or role password but the three scratch-database roles' (tests/setup_dev.sh gives them a scratch password).
#   RUN IT UNDER THE SHARED LOCK:  flock /tmp/crm-proofs.lock tests/phase4/run.sh        (never flock inside this script: a nested flock deadlocks)
#   CRM_PROOFS="gate wall" tests/phase4/run.sh     runs some, in order (the world is built by the first that needs it).  CRM_KEEP=1 leaves the servers running; CRM_STOP=1 stops them.
# Needs `sudo -n -u postgres`, php, and a Python venv with FastMCP (see mcp_servers.sh). Exit 0 only when every proof passed.
set -uo pipefail
ROOT=$(cd "$(dirname "$0")/../.." && pwd)
export CRM_DEV_DB=${CRM_DEV_DB:-crm_dev40} CRM_DEV_ENV=${CRM_DEV_ENV:-/tmp/crm-dev40.env} CRM_DEV_STATE=${CRM_DEV_STATE:-/tmp/crm-dev40-state}
[ -e "$ROOT/config/.env" ] && grep -q '^DB_PASSWORD=.' "$ROOT/config/.env" && { echo "ABORT: the application looks installed (config/.env has DB_PASSWORD); the proofs are for a scratch clone"; exit 2; }
mkdir -p "$CRM_DEV_STATE"
stop_all() {
  "$ROOT/tests/phase4/mcp_servers.sh" stop >/dev/null 2>&1; "$ROOT/tests/phase2/servers.sh" stop >/dev/null 2>&1
}
if [ "${CRM_STOP:-0}" = 1 ]; then stop_all; echo stopped; exit 0; fi
for port in 8801 8802 8803 8804 8805 8806 8807 8809; do (exec 3<>/dev/tcp/127.0.0.1/$port) 2>/dev/null && { echo "ABORT: port $port is taken"; exit 2; }; done
rm -f "$CRM_DEV_STATE"/world*.json
"$ROOT/tests/setup_dev.sh" >/dev/null || { echo "setup failed"; exit 1; }
sudo -n -u postgres psql -q -c "ALTER DATABASE $CRM_DEV_DB SET pgaudit.log = 'none'" >/dev/null 2>&1       # this scratch database alone — never the server's setting
cleanup() {
  [ "${CRM_KEEP:-0}" = 1 ] && { echo "== left running (CRM_KEEP=1): CRM_STOP=1 bash $0 stops them"; return; }
  stop_all
  sudo -n -u postgres dropdb --if-exists "$CRM_DEV_DB" >/dev/null 2>&1
}
trap cleanup EXIT INT TERM
"$ROOT/tests/phase2/servers.sh" start >/dev/null || { echo "servers failed"; exit 1; }
set -a; . "$CRM_DEV_ENV"; set +a
export FAKE_KERNEL_STATE="$CRM_DEV_STATE/kernel.json" FAKE_MALUDB_LOG="$CRM_DEV_STATE/maludb.log" FAKE_MALUMAIL_LOG="$CRM_DEV_STATE/malumail.log"
cd "$ROOT"
php bin/directory_sync.php --full >/dev/null || { echo "the first sync failed"; exit 1; }
status=0
echo "== world"; php tests/phase4/world.php || status=1
# after servers.sh (its start stops every pid file in the state directory): the MCP servers start on the finished world
"$ROOT/tests/phase4/mcp_servers.sh" start >/dev/null || { echo "mcp servers failed"; exit 1; }
: > "$CRM_DEV_STATE/p4-called.txt"
proofs=${CRM_PROOFS-gate wall reads shares activity registry coverage}
for p in $proofs; do
  echo "== $p"
  php "tests/phase4/$p.php" || status=1
done
logs=$(ls "$CRM_DEV_STATE/app.log" "$CRM_DEV_STATE/apache/apache.err" 2>/dev/null)
if [ -n "$logs" ] && grep -q 'PHP \(Warning\|Notice\|Fatal\|Deprecated\)' $logs; then echo "  FAIL the application logged a PHP warning:"; grep -h -m5 'PHP \(Warning\|Notice\|Fatal\|Deprecated\)' $logs; status=1; else echo "  ok   no PHP warning in the application's log"; fi
if grep -q -i 'traceback' "$CRM_DEV_STATE"/mcp-*.log 2>/dev/null; then echo "  FAIL a server logged a traceback:"; grep -h -i -m3 -B2 -A8 'traceback' "$CRM_DEV_STATE"/mcp-*.log | head -30; status=1; else echo "  ok   no traceback in the servers' logs"; fi
[ $status -eq 0 ] && echo "ALL PHASE 4 PROOFS PASSED" || echo "SOME PHASE 4 PROOFS FAILED"
exit $status
