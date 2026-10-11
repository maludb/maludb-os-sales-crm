#!/usr/bin/env bash
# Slice 9 (the admin area: pipelines, vocabularies, rules, fields, tags, the mailbox, quotas, settings, keys, the purge) — every proof on a fresh SCRATCH database (crm_dev9; never the installed one):
#   tests/phase3/slice9/run.sh            the app under php -S with tests/dev_router.php
#   CRM_APP=apache tests/phase3/slice9/run.sh   the app under a real Apache serving the rendered deploy vhost
# The Phase 2 servers (tests/phase2/servers.sh: the app :8801, the fake kernel :8802, MaluDB :8803, MaluMail :8806). Screenshots in $SHOTS (/tmp/crm-shots/slice9).
# Ends with one line per proof; exit 0 only if all passed. Phase 2's suite (tests/phase2/run.sh) is separate: run it after this one.
set -uo pipefail
ROOT=$(cd "$(dirname "$0")/../../.." && pwd)
export CRM_DEV_DB=${CRM_DEV_DB:-crm_dev9} CRM_DEV_ENV=${CRM_DEV_ENV:-/tmp/crm-dev9.env} CRM_DEV_STATE=${CRM_DEV_STATE:-/tmp/crm-dev9-state} SHOTS=${SHOTS:-/tmp/crm-shots/slice9}
[ -e "$ROOT/config/.env" ] && grep -q '^DB_PASSWORD=.' "$ROOT/config/.env" && { echo "ABORT: the application looks installed (config/.env has DB_PASSWORD); the proofs are for a scratch clone"; exit 2; }
mkdir -p "$SHOTS"
"$ROOT/tests/setup_dev.sh" >/dev/null || { echo "setup failed"; exit 1; }
status=0
cd "$ROOT"
out=$(sudo -n -u postgres psql -v ON_ERROR_STOP=1 -d "$CRM_DEV_DB" -f db/proof/phase0_proof.sql 2>&1); summary=$(echo "$out" | grep -E '[0-9]+ ok, [0-9]+ fail' | head -1 | sed 's/^ *//')
if echo "$summary" | grep -qE ' 0 fail'; then echo "  ok   schema proof: $summary"; else echo "  FAIL schema proof: $summary"; status=1; fi
"$ROOT/tests/phase2/servers.sh" start >/dev/null || { echo "servers failed"; exit 1; }
trap '"$ROOT/tests/phase2/servers.sh" stop' EXIT
set -a; . "$CRM_DEV_ENV"; set +a
export FAKE_KERNEL_STATE="$CRM_DEV_STATE/kernel.json" FAKE_MALUDB_LOG="$CRM_DEV_STATE/maludb.log"
php bin/directory_sync.php --full >/dev/null || { echo "the first sync failed"; exit 1; }
for p in pipelines vocab rules fields mailbox quotas settings tokens purge json; do
  echo "== $p"; php "tests/phase3/slice9/$p.php" || status=1
done
echo "== browser"; node tests/phase3/slice9/browser.mjs || status=1
log="$CRM_DEV_STATE/app.log"; [ -f "$CRM_DEV_STATE/apache/apache.err" ] && log="$log $CRM_DEV_STATE/apache/apache.err"
if cat $log 2>/dev/null | grep -qE 'PHP (Warning|Notice|Deprecated|Fatal)|Uncaught'; then echo "  FAIL the server log holds a PHP warning or error:"; cat $log | grep -E 'PHP (Warning|Notice|Deprecated|Fatal)|Uncaught' | head -5; status=1; else echo "  ok   no PHP warning, notice or uncaught error in the server log"; fi
echo "== registry"; php bin/build_action_registry.php --check >/dev/null 2>&1 && echo "  ok   the registry and maludb-os.json approvals[] match the manifest" || { echo "  FAIL the registry or approvals[] is stale — run bin/build_action_registry.php"; status=1; }
[ $status -eq 0 ] && echo "ALL SLICE 9 PROOFS PASSED" || echo "SOME SLICE 9 PROOFS FAILED"
exit $status
