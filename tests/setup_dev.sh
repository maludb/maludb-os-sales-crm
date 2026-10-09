#!/usr/bin/env bash
# Builds the SCRATCH database the proofs run against — never the installed application's database.
#   tests/setup_dev.sh            (needs `sudo -n -u postgres`; recreates $CRM_DEV_DB, default crm_dev0)
# 1. drops and creates the scratch database; 2. applies db/0*.sql in order as postgres (the same files the installer applies —
#    db/001–005 alone are enough for the kit's proof; the schema's files follow as they are written); 3. leaves the cluster roles'
#    passwords ALONE when the app is installed (reads them from config/.env); else gives them a scratch password (the kernel's
#    installer sets fresh ones at `apply`); 4. writes the proofs' environment to $CRM_DEV_ENV (default /tmp/crm-dev0.env, mode 600) —
#    app/bootstrap.php reads that file instead of config/.env when CRM_DEV_ENV is set (never in production). Nothing here is
#    committed or installed. 
set -euo pipefail
ROOT=$(cd "$(dirname "$0")/.." && pwd)
DB=${CRM_DEV_DB:-crm_dev0}
ENVF=${CRM_DEV_ENV:-/tmp/crm-dev0.env}
PW=$(openssl rand -hex 16)
PSQL="sudo -n -u postgres psql -v ON_ERROR_STOP=1 -q"
$PSQL -c "DROP DATABASE IF EXISTS $DB" -c "CREATE DATABASE $DB"
UPTO=${CRM_DEV_UPTO:-999}      # CRM_DEV_UPTO=005 applies db/001–005 only (the kit's own proof while later files are being written)
for f in "$ROOT"/db/0*.sql; do n=$(basename "$f" | cut -c1-3); [ "$n" -le "$UPTO" ] 2>/dev/null || continue; $PSQL -d "$DB" -f "$f" >/dev/null || { echo "FAILED: $f"; exit 1; }; done
LIVEENV="$ROOT/config/.env"
lv() { grep -m1 "^$1=" "$LIVEENV" | cut -d= -f2-; }
if [ -f "$LIVEENV" ] && [ -n "$(lv DB_PASSWORD)" ] && [ -n "$(lv MCP_RECORDS_DB_PASSWORD)" ] && [ -n "$(lv MCP_ACTIVITY_DB_PASSWORD)" ]; then
  PW_RW=$(lv DB_PASSWORD); PW_REC=$(lv MCP_RECORDS_DB_PASSWORD); PW_ACT=$(lv MCP_ACTIVITY_DB_PASSWORD)
else
  PW_RW=$PW; PW_REC=$PW; PW_ACT=$PW
  for r in sales_crm_rw sales_crm_records_ro sales_crm_activity_ro; do $PSQL -c "ALTER ROLE $r WITH LOGIN PASSWORD '$PW'"; done
fi
umask 077
cat > "$ENVF" <<ENV
APP_ENV=dev
APP_DEBUG=1
APP_NAME="Sales CRM"
APP_KEY=sales_crm
APP_URL=http://127.0.0.1:8801
DB_HOST=127.0.0.1
DB_PORT=5432
DB_NAME=$DB
DB_USER=sales_crm_rw
DB_PASSWORD=$PW_RW
MCP_RECORDS_DB_USER=sales_crm_records_ro
MCP_RECORDS_DB_PASSWORD=$PW_REC
MCP_ACTIVITY_DB_USER=sales_crm_activity_ro
MCP_ACTIVITY_DB_PASSWORD=$PW_ACT
APP_INTERNAL_PORT=8801
MCP_RECORDS_PORT=8804
MCP_ACTIVITY_PORT=8805
ACTION_TOKEN_KEY=$(openssl rand -hex 32)
ACTIONS_RELAY_KEY=$(openssl rand -hex 32)
OS_LAUNCHER_URL=https://app.example.invalid/
OS_INTERNAL_URL=http://127.0.0.1:8802
OS_APPLICATION_TOKEN=osapp_dev_$(openssl rand -hex 12)
MALUDB_API_URL=http://127.0.0.1:8803
MALUDB_API_TOKEN=dev-maludb-$(openssl rand -hex 8)
MALUMAIL_API_URL=http://127.0.0.1:8806
MALUMAIL_API_KEY=dev-malumail-$(openssl rand -hex 8)
MAIL_FROM=crm@example.invalid
MAIL_FROM_NAME="Sales CRM"
CRM_STORAGE_DIR=
CRM_MAX_DOCUMENT_BYTES=
CRM_PDFTOTEXT=
CRM_PDFTOPPM=
MALUDB_DC_API_URL=
MALUDB_DC_API_TOKEN=
ENV
echo "scratch database $DB ready; environment in $ENVF"
