#!/usr/bin/env bash
# Phase 0 proof, repeatable: the schema (db/proof/phase0_proof.sql, the schema's checks — skipped with a line when the file is
# not there yet) and the kit without a kernel (testing-without-a-kernel.md §4) — the directory fixture applied, the roles from
# access[], a hand-off minted the kernel's way and presented, its replay / expiry / another audience / an unknown member / a
# tampered signature / a member with no grant refused, every refusal logged, health answering, CSRF, the activity funnel, a
# person's key and the rate rule, the sign-out notice ending sessions, a grant the kernel revoked shutting the door, the worker's
# empty pass, the ingest bridge against a fake MaluDB. Scratch database only (crm_dev0); the installed application (if any) is never
# touched. Ports 8802 (the fake kernel), 8803 (the fake MaluDB + MaluMail), 8807 (the app). Prints ok/FAIL per check and a
# count; exits non-zero on any failure. Needs passwordless `sudo -n -u postgres`, php 8.3, curl, openssl, and for the ingest check
# a python with asyncpg + httpx (/var/www/mcp/venv/bin/python, else $CRM_PY).
set -uo pipefail
ROOT=$(cd "$(dirname "$0")/../.." && pwd)
export CRM_DEV_DB=${CRM_DEV_DB:-crm_dev0}
export CRM_DEV_ENV=${CRM_DEV_ENV:-/tmp/dc-dev0.env}
PY=${CRM_PY:-/var/www/mcp/venv/bin/python}
PORT=8807; P_KERNEL=8802; P_FAKE=8803
TMP=$(mktemp -d /tmp/dc-kit.XXXXXX)
pass=0; fail=0; PIDS=()
ok()   { echo "ok   $1"; pass=$((pass+1)); }
bad()  { echo "FAIL $1"; fail=$((fail+1)); }
check(){ if [ "$1" = "$2" ]; then ok "$3"; else bad "$3 (got: $1, wanted: $2)"; fi; }
has()  { if grep -q -- "$2" <<<"$1"; then ok "$3"; else bad "$3 (missing: $2)"; fi; }
hasnt(){ if grep -q -- "$2" <<<"$1"; then bad "$3 (found: $2)"; else ok "$3"; fi; }
sql()  { sudo -n -u postgres psql -d "$CRM_DEV_DB" -Atc "$1"; }
as()   { sql "select set_config('app.member_id', '$1', false); select $2" | tail -1; }
cleanup() { local rc=$?; for p in "${PIDS[@]:-}"; do [ -n "$p" ] && kill "$p" 2>/dev/null; done; rm -rf "$TMP"; exit $rc; }
trap cleanup EXIT INT TERM
for port in $PORT $P_KERNEL $P_FAKE; do (exec 3<>/dev/tcp/127.0.0.1/$port) 2>/dev/null && { echo "ABORT: port $port is taken"; exit 2; }; done

bash "$ROOT/tests/setup_dev.sh" >/dev/null || { echo "scratch database failed"; exit 1; }
set -a; . "$CRM_DEV_ENV"; set +a
export APP_URL="http://127.0.0.1:$PORT" OS_INTERNAL_URL="http://127.0.0.1:$P_KERNEL" MALUDB_API_URL="http://127.0.0.1:$P_FAKE" MALUMAIL_API_URL="http://127.0.0.1:$P_FAKE"
export FAKE_KERNEL_STATE="$TMP/kernel.json" FAKE_MALUDB_LOG="$TMP/maludb.log" FAKE_MALUMAIL_LOG="$TMP/malumail.log"
cd "$ROOT"
APP="http://127.0.0.1:$PORT"

echo "== the schema (db/proof/phase0_proof.sql)"
if [ -f "$ROOT/db/proof/phase0_proof.sql" ]; then
  out=$(sudo -n -u postgres psql -v ON_ERROR_STOP=1 -d "$CRM_DEV_DB" -f "$ROOT/db/proof/phase0_proof.sql" 2>&1)
  echo "$out" | grep -E '^ ?FAIL' || true
  summary=$(echo "$out" | grep -E '[0-9]+ ok, [0-9]+ failed' | head -1 | sed 's/^ *//')
  failed=$(echo "$summary" | sed -E 's/.* ([0-9]+) failed.*/\1/')
  check "$failed" "0" "schema proof: $summary"
else
  echo "skip db/proof/phase0_proof.sql is not written yet (the schema builder's) — the kit's checks run on db/001–005 alone"
fi

echo "== the contract tables (db/001–005)"
check "$(sql "select string_agg(role_key, ',' order by sort_order) from mcp_app_roles")" "viewer,user,marketing,manager,admin" "the five roles, in order (os.app-roles/1)"
check "$(sql "select string_agg(role_key, ',') from crm_roles where is_admin")" "admin" "exactly one admin role"
check "$(sql "select count(*) from crm_rights")" "41" "forty-one rights"
check "$(sql "select cardinality(rights) from mcp_app_roles where role_key = 'viewer'")" "2" "a Viewer holds two rights: records.read, calendar.read"
check "$(sudo -n -u postgres psql -d "$CRM_DEV_DB" -v ON_ERROR_STOP=1 -qAt -c "begin; insert into activity_log (source, action, after) values ('portal', 'link.open', '{\"label\":\"SMOKE link\"}'); select source from activity_log; rollback;" 2>&1 | head -1)" "portal" "activity_log admits source portal (a link holder)"
check "$(sql "select string_agg(column_name, ',' order by ordinal_position) from information_schema.columns where table_name = 'activity_log' and column_name in ('lead_id','account_id','contact_id','deal_id','token_id')")" "lead_id,account_id,contact_id,deal_id,token_id" "the five audit keys appended to activity_log"
check "$(sql "select currency || ':' || default_remind_minutes || ':' || tombstone_days || ':' || key_rate_per_minute || ':' || capture_rate_per_day || ':' || import_max_rows from crm_settings")" "USD:30:30:60:600:50000" "the settings row is seeded with the design's defaults (USD, 30-min reminders, 30-day tombstones, 60/min, 600 captures/day, 50,000 rows)"

echo "== the mirror (the fixture through directory_sync --from-file)"
php bin/directory_sync.php --from-file bin/dev_directory.json >/dev/null 2>&1; check "$?" "0" "the directory fixture applies"
check "$(sql "select count(*) || ' of ' || count(*) filter (where capability is not null) from members")" "10 of 9" "ten members mirrored, nine admitted (the Watcher holds no grant)"
check "$(sql "select roles::text from members where id = 27")" "{manager,user}" "roles map from the feed's access[]: Marco holds manager and user"
check "$(sql "select is_agent::int || ',' || roles::text from members where id = 42")" "1,{user}" "the Assistant is an agent (is_agent generated) holding user"
check "$(sql "select is_external::int || ',' || roles::text from members where id = 29")" "1,{viewer}" "Ann is external and a Viewer"
check "$(sql "select crm_member_roles(28)::text || ' ' || crm_member_roles(1)::text || ' ' || crm_member_roles(41)::text")" "{user} {admin} {}" "crm_member_roles: a write member with no roles yet is user; the super-admin admin; an unadmitted agent nothing"

echo "== roles and rights (crm_has_right, crm_is_admin, crm_is_manager, crm_is_viewer)"
check "$(as 1 "crm_is_admin() and crm_is_manager() and crm_has_right('settings.manage') and crm_has_right('records.purge') and crm_has_right('deals.write') and not crm_is_viewer()")" "t" "the super-admin runs Sales CRM: every right, a manager, not a viewer"
check "$(as 26 "crm_has_right('deals.write') and crm_has_right('activities.write') and crm_has_right('email.send') and crm_has_right('imports.own') and not crm_has_right('deals.any') and not crm_is_manager() and not crm_is_admin()")" "t" "a Sales Rep writes deals and activities, sends email, imports; is no manager"
check "$(as 27 "crm_is_manager() and crm_has_right('deals.any') and crm_has_right('records.merge') and crm_has_right('pipelines.manage') and crm_has_right('reports.team') and not crm_has_right('settings.manage') and not crm_has_right('records.purge')")" "t" "a Sales Manager runs the team, merges, keeps pipelines; runs nothing of the application"
check "$(as 29 "crm_is_viewer() and crm_has_right('records.read') and not crm_has_right('deals.write') and crm_is_member_here()")" "t" "a Viewer reads everything, changes nothing, belongs here"
check "$(as 31 "crm_has_right('deals.write') and not crm_has_right('deals.any')")" "t" "a member whose roles have not arrived is read from the capability (write = Sales Rep)"
check "$(as 40 "crm_has_right('deals.write') and app_member_kind() = 'agent' and not crm_is_viewer() and crm_is_member_here()")" "t" "an agent granted Sales Rep has a Rep's rights and belongs here"
check "$(as 41 "not app_is_active_member() and not crm_has_right('records.read')")" "t" "an agent not yet admitted holds nothing"
check "$(as '' "crm_has_right('records.read') or crm_is_member_here()")" "f" "nobody (no app.member_id) holds nothing"

echo "== sign-on"
php -r '$f = json_decode(file_get_contents("bin/dev_directory.json"), true); file_put_contents(getenv("FAKE_KERNEL_STATE"), json_encode(["feed" => $f["feed"]]));'
php -S 127.0.0.1:$P_KERNEL tests/fake_kernel.php >"$TMP/kernel.log" 2>&1 & PIDS+=($!)
php -S 127.0.0.1:$P_FAKE tests/fake_maludb.php >"$TMP/fake.log" 2>&1 & PIDS+=($!)
PHP_CLI_SERVER_WORKERS=4 php -S 127.0.0.1:$PORT -t html tests/dev_router.php >"$TMP/app.log" 2>&1 & PIDS+=($!)
sleep 1.2
check "$(curl -s -o /dev/null -w '%{http_code}' $APP/api/v1/health)" "200" "health answers 200"
check "$(curl -s $APP/api/v1/health | php -r 'echo json_decode(stream_get_contents(STDIN), true)["application"] ?? "?";')" "sales_crm" "…naming the application sales_crm"
url27=$(php bin/dev_handoff.php 27)
check "$(curl -s -o /dev/null -w '%{http_code} %{redirect_url}' -c "$TMP/jar27" "$url27")" "302 $APP/" "a signed hand-off token opens a session (302 to /)"
home27=$(curl -s -b "$TMP/jar27" "$APP/")
has "$home27" 'id="home-member">SMOKE Marco<' "the home placeholder names the signed-in member"
has "$home27" 'Sales Manager' "…and the roles the kernel sent"
check "$(curl -s -o /dev/null -w '%{http_code}' "$url27")" "403" "the same token a second time is refused (replay)"
check "$(curl -s -o /dev/null -w '%{http_code}' "$(HANDOFF_TTL=-120 php bin/dev_handoff.php 26)")" "403" "an expired token is refused"
bad_url=$(APP_KEY=other php bin/dev_handoff.php 26 | sed "s#$APP##")
check "$(curl -s -o /dev/null -w '%{http_code}' "$APP$bad_url")" "403" "a token minted for another application is refused (audience)"
check "$(curl -s -o /dev/null -w '%{http_code}' "$(php bin/dev_handoff.php 999)")" "403" "an unknown member is refused"
check "$(sql "select count(*) from members where id = 999")" "0" "…and is never created in the mirror"
check "$(curl -s -o /dev/null -w '%{http_code}' "$(HANDOFF_NO_GRANT=1 php bin/dev_handoff.php 30)")" "403" "a hand-off whose claims carry no grant is refused (capability)"
pyld=$(echo "$url27" | sed -E 's/.*token=([^.]+\.[^.]+\.[^.]+\.[^.]+)\.[a-f0-9]+.*/\1/')
check "$(curl -s -o /dev/null -w '%{http_code}' "$APP/sso?token=$pyld.$(printf '0%.0s' $(seq 1 64))&claims=x.y")" "403" "a tampered signature is refused"
check "$(sql "select string_agg(after->>'reason', ',' order by id) from activity_log where action = 'member.sign_on.refused'")" "replay,token,token,capability,capability,token" "every refusal is logged with its reason (an unknown member's claims carry no grant: capability); none is told to the visitor"
check "$(curl -s -o /dev/null -w '%{http_code}' "$APP/")" "302" "an anonymous visit to / is sent to the launcher (never a login form)"
check "$(sql "select count(*) from member_sessions where member_id = 27 and ended_at is null")" "1" "the session is listed for the kernel's sign-out notice"
check "$(sql "select (after->'roles')::text from activity_log where action = 'member.sign_on' and entity_id = 27")" '["manager", "user"]' "member.sign_on logged with the roles"
code29=$(curl -s -o /dev/null -w '%{http_code}' -c "$TMP/jar29" "$(php bin/dev_handoff.php 29)"); check "$code29" "302" "a Viewer (external holding read) is let in to the shell"

echo "== CSRF"
tok=$(curl -s -b "$TMP/jar27" "$APP/" | sed -n 's/.*name="csrf_token" value="\([a-f0-9]*\)".*/\1/p' | head -1)
check "$(curl -s -o /dev/null -w '%{http_code}' -b "$TMP/jar27" -X POST "$APP/logout.php")" "403" "a POST without a CSRF token is refused"
check "$(curl -s -o /dev/null -w '%{http_code}' -b "$TMP/jar27" -X POST -d 'csrf_token=deadbeef' "$APP/logout.php")" "403" "a POST with a bad CSRF token is refused"
check "$(curl -s -o /dev/null -w '%{http_code} %{redirect_url}' -X POST -d 'csrf_token=x' "$APP/")" "302 https://app.example.invalid/launcher?app=sales_crm" "an anonymous POST to a screen goes to the launcher (there is no login form here)"
check "$(curl -s -o /dev/null -w '%{http_code}' -H 'Accept: application/json' -X POST "$APP/")" "401" "…and answers a JSON caller 401, never a bare 403"

echo "== the activity funnel"
out=$(php tests/phase0/probe.php log 2>/dev/null)
check "$(sql "select account_id || ':' || deal_id || ':' || contact_id || ':' || source from activity_log where action = 'probe.event'")" "3:7:11:cron" "log_activity writes account_id, deal_id and contact_id (source cron under the CLI)"
check "$(sql "select contact_id from activity_log where action = 'probe.contact'")" "11" "…and takes the contact from the entity when not given"
check "$(echo "$out" | tr '\n' ' ')" "logged survived " "log_activity never throws on a bad actor, an unencodable payload or a bad key"

echo "== keys: a person's own, rate-limited"
RAW="mcp_$(openssl rand -hex 24)"; HASH=$(printf '%s' "$RAW" | openssl dgst -sha256 | sed 's/.* //')
sql "insert into mcp_access_tokens (member_id, label, token_hash, scope) values (26, 'SMOKE key', '$HASH', 'api')" >/dev/null
TOK=$(sql "select id from mcp_access_tokens where token_hash = '$HASH'")
check "$(sql "select rate_per_minute || '/' || rate_per_day from mcp_access_tokens where id = $TOK")" "60/5000" "a key's limits default from the settings (the trigger)"
check "$(sql "select member_id || ':' || label from crm_resolve_key('$HASH')")" "26:SMOKE key" "a key resolves to its person and label (crm_resolve_key)"
r1=$(php tests/phase0/probe.php apikey "$RAW" 2>&1 | tr '\n' ' ')
has "$r1" "authenticated:26:key=yes:label=SMOKE key" "the token API authenticates a key as its person"
check "$(sql "select token_id || ':' || source || ':' || (after->>'label') from activity_log where action = 'probe.api'")" "$TOK:api:SMOKE key" "…every row under a key carries token_id, source api and the key's label, never the key"
MCP="mcp_$(openssl rand -hex 24)"; MH=$(printf '%s' "$MCP" | openssl dgst -sha256 | sed 's/.* //')
sql "insert into mcp_access_tokens (member_id, label, token_hash, scope) values (26, 'SMOKE mcp', '$MH', 'mcp')" >/dev/null
has "$(php tests/phase0/probe.php apikey "$MCP" 2>&1 | tr '\n' ' ')" '"code":"unauthorized"' "a person's mcp token is not a door to the token API"
rl=$(sql "select string_agg(ok::text, ',' order by n) from (select n, (crm_rate_ok($TOK)).ok from generate_series(1, 60) n) s" | tr ',' '\n' | sort | uniq -c | tr -s ' ' | tr '\n' ';')
check "$rl" " 1 false; 59 true;" "the rate rule counts 60 calls a minute (the key's API call above was the first) and refuses the 61st (crm_rate_ok)"
NEW=$(sql "select crm_key_rotate($TOK, '$(openssl rand -hex 32)')"); check "$(sql "select rotated_from = $TOK and rate_per_minute = 60 from mcp_access_tokens where id = $NEW") $(sql "select expires_at <= now() + interval '25 hours' from mcp_access_tokens where id = $TOK")" "t t" "rotation mints a key of the same person and limits and gives the old one 24 hours"

echo "== the kernel's word shuts the doors"
notice="27.$(date +%s).sales_crm"; sig=$(printf 'sso-logout:%s' "$notice" | openssl dgst -sha256 -hmac "$ACTION_TOKEN_KEY" | sed 's/.* //')
check "$(curl -s -o /dev/null -w '%{http_code}' -X POST --data-urlencode "notice=$notice.$sig" "$APP/sso/logout")" "204" "the kernel's sign-out notice answers 204"
check "$(sql "select ended_by from member_sessions where member_id = 27 order by created_at desc limit 1")" "kernel" "…and ends the member's session"
check "$(curl -s -o /dev/null -w '%{http_code}' -b "$TMP/jar27" "$APP/")" "302" "…so the next request goes to the launcher"
check "$(curl -s -o /dev/null -w '%{http_code}' -X POST --data-urlencode "notice=27.1.sales_crm.deadbeef" "$APP/sso/logout")" "204" "a bad notice also answers 204 and changes nothing"
check "$(curl -s -o /dev/null -w '%{http_code}' -b "$TMP/jar29" "$APP/")" "200" "Ann, signed on earlier, is let in while her grant stands"
php -r '$s = json_decode(file_get_contents(getenv("FAKE_KERNEL_STATE")), true); $s["incremental"] = ["schema" => "os.directory-changes/1", "since" => "2026-01-01T00:00:00.000000Z", "next" => "2026-01-02T00:00:00.000000Z", "full" => false, "members" => [], "departments" => [], "memberships" => [], "deleted_departments" => [], "scopes" => [], "access" => [["member_id" => 29, "capability" => null, "roles" => [], "scopes" => []]]]; file_put_contents(getenv("FAKE_KERNEL_STATE"), json_encode($s));'
php bin/directory_sync.php >/dev/null 2>&1; check "$?" "0" "the change feed applies through the kernel's internal API (the fake kernel)"
check "$(curl -s -o /dev/null -w '%{http_code}' -b "$TMP/jar29" "$APP/")" "302" "a grant the kernel revoked shuts the door within one sync"
check "$(sql "select ended_by from member_sessions where member_id = 29 order by created_at desc limit 1") $(sql "select capability is null from members where id = 29")" "directory t" "…the session ended by the directory and the mirror holds no grant"
check "$(curl -s -o /dev/null -w '%{http_code}' "$(HANDOFF_NO_GRANT=1 php bin/dev_handoff.php 29)")" "403" "…and a hand-off whose claims now carry no grant is refused (the kernel's claims are the authority)"
tok26=$(curl -s -c "$TMP/jar26" -b "$TMP/jar26" -o /dev/null "$(php bin/dev_handoff.php 26)"; curl -s -b "$TMP/jar26" "$APP/" | sed -n 's/.*name="csrf_token" value="\([a-f0-9]*\)".*/\1/p' | head -1)
check "$(curl -s -o /dev/null -w '%{http_code} %{redirect_url}' -b "$TMP/jar26" -X POST -d "csrf_token=$tok26" "$APP/logout.php")" "302 https://app.example.invalid/launcher" "Sales CRM's own sign-out (POST + CSRF) goes back to the launcher"
check "$(curl -s -o /dev/null -w '%{http_code}' -b "$TMP/jar26" "$APP/")" "302" "…and the session is over"

echo "== the worker, the ingest bridge, mail"
php bin/worker.php >/dev/null 2>&1; check "$?:$(sql "select count(*) from activity_log where action = 'worker.pass' and source = 'cron'")" "0:1" "the worker runs a pass of stubs, logs worker.pass (source cron) and exits 0"
if [ -x "$PY" ] && "$PY" -c 'import asyncpg, httpx' 2>/dev/null; then
  "$PY" mcp/activity_ingest.py >"$TMP/ingest.out" 2>&1; check "$?" "0" "the ingest bridge ships the log to MaluDB (fake) and advances its checkpoint"
  check "$(php -r '$first = json_decode(file(getenv("FAKE_MALUDB_LOG"))[0], true); echo array_key_first($first["payload"]) . "=" . $first["payload"]["application"] . ":" . $first["kind"];')" "application=sales_crm:activity" "the payload names the application first (memory.md)"
  check "$(php -r 'foreach (file(getenv("FAKE_MALUDB_LOG")) as $l) { $b = json_decode($l, true); if (($b["payload"]["action"] ?? "") === "probe.event") echo $b["payload"]["account_id"] . ":" . $b["payload"]["deal_id"] . ":" . $b["payload"]["contact_id"]; }')" "3:7:11" "…and carries account_id, deal_id and contact_id"
  check "$(sql "select last_id = (select max(id) from activity_log) from activity_ingest_state")" "t" "…the checkpoint stands at the last row"
else
  bad "the ingest bridge needs a python with asyncpg and httpx (CRM_PY)"; bad "the payload names the application first"; bad "payload carries the audit keys"; bad "the checkpoint"
fi
check "$(php tests/phase0/probe.php mail 2>&1)" "sent:200" "MaluMail sends through the (fake) API when a key is set"
check "$(MALUMAIL_API_KEY= php tests/phase0/probe.php mail 2>&1 | cut -d: -f1)" "mail-threw" "with no key the send raises a catchable error (mail is optional)"

echo "== $pass ok, $fail failed"
[ "$fail" -eq 0 ]
