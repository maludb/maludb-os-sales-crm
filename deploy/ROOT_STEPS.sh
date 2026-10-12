#!/usr/bin/env bash
# Sales CRM — what root or the owner does to install it beside the kernel, in order. Written at the end of Phase 0 (2026-10-09);
# later phases ADD steps and mark them. NOTHING HERE HAS BEEN RUN: the proofs use a scratch database, php -S and fixtures, and
# never touch an installed application, Apache, systemd or the MaluDB API.
#   bash deploy/ROOT_STEPS.sh              prints this plan (the default)
#   sudo bash deploy/ROOT_STEPS.sh apply   runs the steps with no decision in them: 0, 0b and 2b (the ports, Apache's modules, the storage directory)
# Steps 1, 2, 3 and 4 are printed and never run: they are the installer's, the owner's, or put a secret somewhere.
set -euo pipefail
APP=/srv/apps/sales_crm
KERNEL=/var/www
MODE=${1:-plan}
STORAGE=${CRM_STORAGE_DIR:-$APP/storage}   # attachments, imports, exports: under the install by default, a mount when the owner names one
run() { echo "+ $*"; if [ "$MODE" = apply ]; then "$@"; fi; }
env_has() { [ -f "$APP/config/.env" ] && grep -q "^$1=." "$APP/config/.env"; }

# ------------------------------------------------------------------------------------------------------------------
# 0. PIN THE PORTS before the installer's first apply (Help Desk's lesson of 2026-10-02). A config/.env holding ONLY the three
#    ports: the installer reads them, fills every other required key itself, and sets fresh role passwords because DB_PASSWORD
#    is absent. Never put anything else in this file by hand before apply — except CRM_STORAGE_DIR when the bytes go on a mount.
echo "== 0. Pin the ports (ROOT) — only if $APP/config/.env does not exist yet"
if [ ! -f "$APP/config/.env" ]; then
  run bash -c "mkdir -p '$APP/config' && printf 'APP_INTERNAL_PORT=8191\nMCP_RECORDS_PORT=8843\nMCP_ACTIVITY_PORT=8844\n' > '$APP/config/.env' && chgrp www-data '$APP/config/.env' && chmod 640 '$APP/config/.env'"
  if [ "$STORAGE" != "$APP/storage" ]; then run bash -c "echo 'CRM_STORAGE_DIR=$STORAGE' >> '$APP/config/.env'"; fi
else
  echo "   (config/.env exists — leaving it alone)"
fi
#    See that it worked:  php $KERNEL/bin/app_install.php plan $APP --domain subello.com | grep -E '^(todo|done) +(ports|vhost)'   -> ports done, no {{...}} unfilled

# 0b. APACHE'S MODULES (ROOT; idempotent; added in Phase 2, 2026-10-09). The vhost rewrites (rewrite), proxies the two MCP servers (proxy,
#     proxy_http) and sets nosniff and the frame option (headers — this host had it disabled, found by the Phase 2 Apache proof; the vhost
#     guards its Header lines with <IfModule> so a missing module never fails the config test, but the headers are wanted).
echo "== 0b. Apache's modules (ROOT): rewrite, proxy, proxy_http, headers"
run a2enmod -q rewrite proxy proxy_http headers

# ------------------------------------------------------------------------------------------------------------------
# 1. Look (read-only, any user): what the installer would do.
echo "== 1. The installer's plan (read-only)"
echo "   php $KERNEL/bin/app_install.php plan $APP --by <super-admin email> --domain subello.com"

# ------------------------------------------------------------------------------------------------------------------
# 2. The installer's apply (ROOT) — PHASE 2 onward, not before the shell exists: database and roles, config/.env, the vhost,
#    the units, the registration (catalog kind ours — the row K32 seeded, db/177 —, /sso and /sso/logout, scope_kind none),
#    the application token, skills imported, approval policies, the installing super-admin's admin grant.
#    --grant-standing-departments gives every standing department the Sales Rep role (key user) — usually NOT wanted for a CRM: grant the
#    sales department instead. --hire-agents hires the expert and the Sales Assistant. Run it twice: the roles are read from app_roles only once the records MCP answers (built in Phase 4: mcp/records_server.py, started by the installer's units).
echo "== 2. apply (ROOT) — from Phase 2"
echo "   sudo php $KERNEL/bin/app_install.php apply $APP --by <super-admin email> --domain subello.com --grant-standing-departments --hire-agents"

# ------------------------------------------------------------------------------------------------------------------
# 2b. THE STORAGE DIRECTORY (ROOT; idempotent; before the first attachment or import). Attachments, import files and exports live
#     under the install ($APP/storage) or on the mount CRM_STORAGE_DIR names. Owned by www-data, mode 750 — never under html/.
echo "== 2b. The storage directory $STORAGE (ROOT)"
for d in attachments imports exports; do run install -d -o www-data -g www-data -m 750 "$STORAGE/$d"; done
run install -d -o www-data -g www-data -m 750 "$STORAGE"

# ------------------------------------------------------------------------------------------------------------------
# 3. MANUAL — THE LOGGING MAILBOX (design §4, D10; the one manual step, as Help Desk's queues have). At MaluMail, on the
#    business's sending domain, create the mailbox crm-<token>@<mail domain> (a token nobody guesses: openssl rand -hex 8) with an
#    inbound webhook to https://crm.<domain>/channels/malumail/mail and a webhook secret. Then:
echo "== 3. MANUAL — the logging mailbox at MaluMail"
echo "   - create crm-\$(openssl rand -hex 8)@<mail domain> at MaluMail; webhook https://crm.<domain>/channels/malumail/mail; note its secret"
echo "   - MAILBOX_CRM_WEBHOOK_SECRET=<secret> into $APP/config/.env (root; never printed, never committed)"
echo "   - the mailbox row: the admin's /admin/mailbox screen (slice 5) or, until then, INSERT INTO mailboxes (address, display_name, provider, webhook_secret_ref) VALUES ('<address>', 'Sales CRM', 'malumail', 'MAILBOX_CRM_WEBHOOK_SECRET'); UPDATE crm_settings SET mailbox_id = (SELECT id FROM mailboxes WHERE address = '<address>')"
echo "   - sellers BCC that address; Reply-To of every CRM-sent email is that address"

# ------------------------------------------------------------------------------------------------------------------
# 4. The owner's keys and decisions (any time after 2):
echo "== 4. The owner's"
echo "   - DNS and TLS for crm.subello.com (until then /etc/hosts maps it to this host)"
echo "   - MaluMail: MALUMAIL_API_KEY, MAIL_FROM, MAIL_FROM_NAME into $APP/config/.env (reminders, the morning note, CRM-sent email) — the installer's mail step writes them from ~/.malumail when present"
echo "   - the Sales Assistant's duty (30 6 * * 1-5) in Agent HR if the hire gave none (the kernel reads agents[].duty since 2026-10-08)"
echo "   - a lead-capture key for the website's form: /admin/tokens (slice 9) → POST https://crm.<domain>/api/v1/leads"
echo "   - the connections a super-admin approves (bin/app_connection.php): the General Ledger, Help Desk, Inventory, Consultant Tracking and Spaces"
echo "     reading accounts_index, contacts_index, deals_won, deals_open_by_account, account_timeline_summary; Sales CRM reading gl.customer_by_crm_account,"
echo "     gl.items_index, inventory.availability_index, helpdesk.tickets_by_organization (each degrades on no_connection)"
echo "   - the worker, the directory sync and the ingest timers are rendered and enabled by apply; by hand:"
echo "       sudo systemctl enable --now sales_crm-worker.timer sales_crm-directory-sync.timer sales_crm-activity-ingest.timer"
