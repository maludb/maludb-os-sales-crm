#!/usr/bin/env bash
# tests/phase2/servers.sh start|stop — the proofs' servers on loopback, all from the scratch environment ($CRM_DEV_ENV):
#   the application on :8801, a fake kernel on :8802, a fake MaluDB on :8803, a fake MaluMail on :8806. The application is `php -S` with
#   tests/dev_router.php by default; with CRM_APP=apache it is a REAL Apache (as your user, mod_php) serving deploy/apache-sales_crm.conf
#   rendered the way the kernel's installer renders it (the public vhost moved to :8801, the internal one on :8807) — the proofs then
#   exercise the vhost's own rewrites (/sso, the canonical URLs, the board's family, /api blocked, the MCP proxies). State: $CRM_DEV_STATE
#   (default /tmp/crm-dev-state) holds the pid files, the fake kernel's state file and the fakes' logs.
set -euo pipefail
ROOT=$(cd "$(dirname "$0")/../.." && pwd)
ENVF=${CRM_DEV_ENV:-/tmp/crm-dev.env}
STATE=${CRM_DEV_STATE:-/tmp/crm-dev-state}
mkdir -p "$STATE"
stop() {
  for f in "$STATE"/*.pid; do [ -e "$f" ] && kill "$(cat "$f")" 2>/dev/null || true; rm -f "$f"; done
  pkill -f 'php .*-S 127.0.0.1:880[1236]' 2>/dev/null || true       # the built-in server's forked workers outlive their parent
  [ -f "$STATE/apache/main.conf" ] && apache2 -f "$STATE/apache/main.conf" -k stop 2>/dev/null || true
  sleep 0.3
}
case "${1:-}" in
  stop) stop ;;
  start)
    stop
    set -a; . "$ENVF"; set +a
    export FAKE_KERNEL_STATE="$STATE/kernel.json" FAKE_MALUDB_LOG="$STATE/maludb.log" FAKE_MALUMAIL_LOG="$STATE/malumail.log"
    : > "$FAKE_MALUDB_LOG"; : > "$FAKE_MALUMAIL_LOG"; rm -f "$FAKE_KERNEL_STATE.chat" "$FAKE_KERNEL_STATE.apps"
    php -r '$f=json_decode(file_get_contents($argv[1]),true); file_put_contents($argv[2], json_encode(["feed"=>$f["feed"]]));' "$ROOT/bin/dev_directory.json" "$FAKE_KERNEL_STATE"
    php -S 127.0.0.1:8802 "$ROOT/tests/fake_kernel.php" >"$STATE/kernel.log" 2>&1 & echo $! > "$STATE/kernel.pid"
    php -S 127.0.0.1:8803 "$ROOT/tests/fake_maludb.php" >"$STATE/fake.log" 2>&1 & echo $! > "$STATE/fake.pid"
    php -S 127.0.0.1:8806 "$ROOT/tests/fake_malumail.php" >"$STATE/malumail.log.srv" 2>&1 & echo $! > "$STATE/malumail.pid"
    if [ "${CRM_APP:-php}" = "apache" ]; then
      mkdir -p "$STATE/apache"
      python3 - "$ROOT" "$STATE/apache" <<'PY'
import os, sys
root, out = sys.argv[1], sys.argv[2]
v = open(root + '/deploy/apache-sales_crm.conf').read()
for k, val in {'APP_FQDN': 'crm.subello.com', 'APP_DIR': root, 'APP_INTERNAL_PORT': '8807', 'MCP_RECORDS_PORT': '8804', 'MCP_ACTIVITY_PORT': '8805'}.items():
    v = v.replace('{{%s}}' % k, val)
assert '{{' not in v, 'unfilled placeholder in the vhost template'
v = v.replace('<VirtualHost *:80>', '<VirtualHost *:8801>')
open(out + '/site.conf', 'w').write(v)
open(out + '/main.conf', 'w').write('ServerRoot "/etc/apache2"\nDefaultRuntimeDir %s\nPidFile %s/apache.pid\nErrorLog %s/apache.err\nServerName localhost\nLogLevel warn\n'
    'Include /etc/apache2/mods-enabled/*.load\nInclude /etc/apache2/mods-enabled/*.conf\n%sDefine APACHE_LOG_DIR %s\nListen 8801\nInclude %s/site.conf\n'
    % (out, out, out, ''.join('LoadModule %s_module /usr/lib/apache2/modules/mod_%s.so\n' % (m, m) for m in ('headers',) if not os.path.exists('/etc/apache2/mods-enabled/%s.load' % m)), out, out))
PY
      # mod_php reads the application's environment from the process: the scratch .env is exported above, so the vhost needs no SetEnv.
      apache2 -f "$STATE/apache/main.conf" -k start
    else
      ( cd "$ROOT" && PHP_CLI_SERVER_WORKERS=4 php -d upload_max_filesize=50M -d post_max_size=60M -S 127.0.0.1:8801 -t html tests/dev_router.php >"$STATE/app.log" 2>&1 & echo $! > "$STATE/app.pid" )
    fi
    for i in $(seq 1 30); do curl -s -o /dev/null http://127.0.0.1:8801/api/v1/health && break; sleep 0.2; done
    echo "servers up (app :8801 ${CRM_APP:-php}, fake kernel :8802, fake MaluDB :8803, fake MaluMail :8806)" ;;
  *) echo "usage: $0 start|stop" >&2; exit 2 ;;
esac
