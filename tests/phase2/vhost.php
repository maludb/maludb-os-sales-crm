<?php
/*
 * Proof: the deploy vhost's URL rules (deploy/apache-sales_crm.conf; the routing form of docs/phase1-consistency.md decision 11). Meaningful under
 * CRM_APP=apache tests/phase2/run.sh, where a real Apache serves the template rendered as the installer renders it; under php -S the same checks
 * run against tests/dev_router.php, which mirrors the rewrites. The receivers at their extension-less names, the canonical URLs and the
 * placeholders, the board's family by a pipeline's key or id, an email by direction, the unsubscribe door, the attachment gate, a stage form
 * under its pipeline, /api/v1/* (404 until slices 4 and 8 but for health), /pick/, the MCP proxies 502 (under Apache), the manifest and the
 * assets with their types, nothing outside html/, storage/ unreachable by path.
 */
require __DIR__ . '/lib.php';
[$j, ] = sign_on(27);
echo "URL rules\n";
foreach (['/sso' => 403, '/sso/logout' => 405] as $path => $want) {
    ok(req('GET', $path)['code'] === $want, "GET $path → $want (the receivers answer at their extension-less names)");
}
ok(page($j, '/settings/tokens/')['code'] === 200 && page($j, '/settings/tokens')['code'] === 200, '/settings/tokens/ and /settings/tokens both reach settings/tokens/index.php');
ok(page($j, '/settings/')['code'] === 200 && page($j, '/settings')['code'] === 200, '/settings/ and /settings both reach settings/index.php');
foreach (['leads', 'accounts', 'contacts', 'calendar', 'activities', 'campaigns', 'lists', 'reports', 'admin'] as $d) {
    ok(page($j, "/$d")['code'] === 200 && page($j, "/$d/")['code'] === 200, "/$d and /$d/ both reach $d/index.php (the placeholder)");
}
ok(page($j, '/search')['code'] === 200 && page($j, '/activity')['code'] === 200 && page($j, '/notifications')['code'] === 200, '/search, /activity and /notifications resolve to their own files');
ok(page($j, '/email/unmatched')['code'] === 200, '/email/unmatched reaches email/unmatched.php (the placeholder)');
$pid = (int) one("SELECT id FROM pipelines WHERE key = 'new_business'");
ok(page($j, '/pipeline/new_business/board')['code'] === 200 && page($j, "/pipeline/$pid/board")['code'] === 200, 'the board by the pipeline\'s key and by its id reach pipeline/board.php');
ok(page($j, '/pipeline/new_business/list')['code'] === 404 && page($j, '/pipeline/new_business/forecast')['code'] === 404 && page($j, '/pipeline/new_business/columns')['code'] === 404, '…list, forecast and columns are rewritten to their files, which slice 3 builds: 404 until then');
ok(page($j, '/pipeline/../board')['code'] === 404 && page($j, '/pipeline/new_business/nonsense')['code'] === 404, 'a malformed pipeline path is 404');
ok(page($j, '/deals/1')['code'] === 404 && page($j, '/deals/new')['code'] === 404 && page($j, '/deals/1/edit')['code'] === 404 && page($j, '/deals/1/won')['code'] === 404, '/deals/{id}, /deals/new, /deals/{id}/edit and /deals/{id}/won are rewritten to view.php, form.php and won.php: 404 until slice 3');
ok(page($j, '/accounts/1')['code'] === 404 && page($j, '/leads/1/convert')['code'] === 404 && page($j, '/import/1/status')['code'] === 404, 'a record page and a page of a record: 404 until their slice');
ok(page($j, '/email/in/1')['code'] === 404 && page($j, '/email/out/1')['code'] === 404 && page($j, '/email/sideways/1')['code'] === 404, '/email/{in|out}/{id} is rewritten to email/view.php: 404 until slice 5');
ok(req('GET', '/u/' . str_repeat('a', 32))['code'] === 404 && req('GET', '/u/short')['code'] === 404, '/u/{token} is rewritten to u/index.php: 404 until slice 5; a malformed token 404');
ok(page($j, '/attachments/1')['code'] === 404 && page($j, '/admin/pipelines/1/stages/new')['code'] === 404, '/attachments/{id} and /admin/pipelines/{id}/stages/new reach their files: 404 until slices 1 and 9');
ok(page($j, '/log/call')['code'] === 404 && page($j, '/calendar/grid')['code'] === 404, '/log/call and /calendar/grid: 404 until slice 4');
ok(req('GET', '/nosuchscreen', ['jar' => $j])['code'] === 404, 'an unknown path: 404');
$h = req('GET', '/api/v1/health');
$hb = json_decode($h['body'], true) ?: [];
ok($h['code'] === 200 && ($hb['application'] ?? '') === 'sales_crm' && ($hb['storage'] ?? '') === 'ok', '/api/v1/health answers, with storage ok (the scratch storage directory)');
ok(req('GET', '/api/v1/health.php')['code'] === 200, '/api/v1/health.php answers too');
ok(req('GET', '/api/v1/calendar.ics?token=x')['code'] === 404 && req('POST', '/api/v1/leads')['code'] === 404 && req('GET', '/api/v1/deals/1')['code'] === 404, 'the ICS feed and the records API are rewritten to files slices 4 and 8 build: 404 until then');
ok(req('GET', '/api/v1/other')['code'] === 404 && req('GET', '/api/')['code'] === 404, 'anything else under /api is 404');
ok(req('POST', '/channels/malumail/mail')['code'] === 404, 'the mailbox webhook is rewritten to the file slice 5 builds: 404 until then');
$p = req('GET', '/pick/?source=x');
ok($p['code'] === 302 && $p['location'] === LAUNCHER, '/pick/ without a session goes to the launcher');
if (getenv('CRM_APP') === 'apache') {
    ok(in_array(req('GET', '/mcp/records')['code'], [502, 503], true) && in_array(req('GET', '/mcp/activity')['code'], [502, 503], true), 'the MCP proxies are in the vhost and answer 502 until Phase 4 starts the servers');
    $i = req('GET', 'http://127.0.0.1:8807/api/v1/health');
    ok($i['code'] === 200 && json_decode($i['body'], true)['application'] === 'sales_crm', 'the internal vhost (the kernel\'s actions server\'s door) answers health on its loopback port');
    $conf = (string) file_get_contents(getenv('CRM_DEV_STATE') . '/apache/site.conf');
    ok(str_contains($conf, 'LimitRequestBody 67108864') && str_contains($conf, 'upload_max_filesize 50M') && str_contains($conf, 'post_max_size 60M'), 'the rendered vhost carries LimitRequestBody 64 MB and the PHP upload limits deploy says');
    $hd = req('GET', '/api/v1/health')['headers'];
    ok(preg_match('/^X-Content-Type-Options: nosniff/mi', $hd) === 1 && preg_match('/^X-Frame-Options: SAMEORIGIN/mi', $hd) === 1, 'Apache sends nosniff and the frame option (mod_headers, guarded by <IfModule> in the vhost; ROOT_STEPS 0b enables it)');
    ok(!str_contains($conf, '/d/index.php') && !str_contains($conf, '/f/index.php') && str_contains($conf, '/pipeline/$2.php?pipeline=$1'), 'the vhost carries the CRM\'s routing form and none of a sibling\'s doors');
}
$m = req('GET', '/manifest.webmanifest');
ok($m['code'] === 200 && preg_match('/^Content-Type: application\/(manifest\+)?json/mi', $m['headers']) === 1 && json_decode($m['body'], true)['name'] === 'Sales CRM', '/manifest.webmanifest is served as JSON and names Sales CRM');
$s = req('GET', '/assets/css/theme.min.css');
ok($s['code'] === 200 && preg_match('/^Content-Type: text\/css/mi', $s['headers']) === 1, 'static assets are served with their type');
$sj = req('GET', '/assets/vendors/sortablejs/Sortable.min.js');
ok($sj['code'] === 200, 'SortableJS (the board\'s enhancement, slice 3) is served from the application, not a CDN');
foreach (['/config/.env', '/db/001_roles_and_identity.sql', '/app/bootstrap.php', '/bin/directory_sync.php', '/mcp/db.py', '/maludb-os.json', '/.git/config', '/tests/phase2/lib.php', '/deploy/ROOT_STEPS.sh', '/storage/attachments/x'] as $p) {
    $r = req('GET', $p);
    ok(in_array($r['code'], [403, 404], true) && !str_contains($r['body'], 'ACTION_TOKEN_KEY') && !str_contains($r['body'], 'CREATE TABLE'), "$p is not served ({$r['code']})");
}
finish();
