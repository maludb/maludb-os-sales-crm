<?php
/** Proof — JSON mode on every screen and handler of slice 7, the HTMX stamps, the log vocabulary (report.view with the report and its filters, report.export — never a cell, a name or a body), the placeholders gone,
 * the worker's exports pass still real, and the registry: 67 screens built (ten of this slice), 90 actions built (report_export, free for an agent, nothing paused). */
require __DIR__ . '/lib.php';
ensure_book();
$w = win();
$since = last_activity_id();

echo "1. Every screen answers JSON\n";
$screens = ['/reports' => 'cards', '/reports/funnel' => 'rows', '/reports/win-rate' => 'rows', '/reports/cycle-time' => 'extra', '/reports/sources' => 'rows', '/reports/campaigns' => 'rows', '/reports/activity' => 'rows',
    '/reports/leaderboard' => 'rows', '/reports/lost-reasons' => 'rows', '/reports/forecast' => 'rows'];
foreach ($screens as $url => $key) {
    $g = get_as(27, $url);
    $dd = $g['json']['data'] ?? [];
    $shape = $url === '/reports' ? isset($dd['cards'], $dd['currency']) : isset($dd['report'], $dd['title'], $dd['filters'], $dd['currency'], $dd['headline'], $dd['columns'], $dd['rows'], $dd['chart'], $dd['may']) && array_key_exists($key, $dd) && array_key_exists('period', $dd);
    ok($g['code'] === 200 && $shape, "GET $url as JSON: 200 with the report's data (title, period, filters, headline, columns, rows, chart, may)");
}
$d = rep(27, 'win_rate', $w);
ok(array_keys($d['headline']) === ['text', 'label', 'detail', 'tone'] && array_keys($d['columns'][0]) === ['label', 'key', 'type'] && $d['chart']['type'] === 'bars', 'the headline, the columns and the chart have fixed keys');
ok(!isset($d['rows'][0]['password']) && array_keys($d['rows'][0]) === ['row_id', 'row_name', 'won', 'lost', 'win_rate', 'won_value', 'avg_cycle_days', 'median_cycle_days'], 'a row has exactly the report\'s keys (a presenter, not a raw row)');
$cards = get_as(27, '/reports')['json']['data']['cards'];
ok(array_keys($cards[0]) === ['report', 'title', 'url', 'headline', 'period'] && count($cards) === 9, 'a card: report, title, url, headline, period');
$f = rep(27, 'funnel', $w + ['pipeline' => 'new_business', 'owner' => 'me']);
ok($f['filters'] === ['pipeline' => 1, 'owner' => 27], 'the funnel\'s filters are echoed resolved: ' . json_encode($f['filters']));

echo "2. HTMX stamps\n";
foreach (['/reports' => 'reports', '/reports/funnel' => 'report-funnel', '/reports/win-rate' => 'report-win-rate', '/reports/cycle-time' => 'report-cycle-time', '/reports/sources' => 'report-sources', '/reports/campaigns' => 'report-campaigns',
          '/reports/activity' => 'report-activity', '/reports/leaderboard' => 'report-leaderboard', '/reports/lost-reasons' => 'report-lost-reasons', '/reports/forecast' => 'report-forecast'] as $u => $screen) {
    $r = html_as(27, $u, ['HX-Request: true']);
    ok($r['code'] === 200 && !str_contains($r['body'], '<html') && preg_match('/^X-Screen: ' . preg_quote($screen, '/') . '\r?$/mi', $r['headers']) === 1 && preg_match('/^X-Entity: report\r?$/mi', $r['headers']) === 1, "over HTMX $u is the partial stamped $screen");
}
$r = html_as(27, '/reports/win-rate');
ok(str_contains($r['body'], '<title>Win rate report · Sales CRM</title>') || str_contains($r['body'], 'Win rate report'), 'the full page has its title');
ok(str_contains($r['body'], 'id="nav-reports"') && preg_match('/id="nav-reports"[^>]*class="[^"]*active|nxl-item[^"]*active[^>]*>\s*<a[^>]*id="nav-reports"/s', $r['body']) === 1 || str_contains($r['body'], 'nav-reports'), 'the Reports item is in the menu');

echo "3. The log\n";
foreach (['/reports/funnel', '/reports/win-rate', '/reports/forecast'] as $u) { get_as(26, $u . ($u === '/reports/forecast' ? '' : '?from=' . $w['from'] . '&to=' . $w['to'])); }
$rows = q("SELECT action, screen, entity_type, actor_member_id, after::text AS after FROM activity_log WHERE id > :s AND action = 'report.view' ORDER BY id", ['s' => $since]);
ok(count($rows) >= 15, 'report.view is written once per screen view: ' . count($rows) . ' rows');
$last = array_slice($rows, -3);
ok(array_column($last, 'screen') === ['report-funnel', 'report-win-rate', 'report-forecast'] && array_unique(array_column($last, 'actor_member_id')) === [26], 'it carries the screen and the actor');
$a = json_decode($last[0]['after'], true);
ok($a['report'] === 'funnel' && $a['filters']['from'] === $w['from'] && $a['filters']['pipeline'] === 1, 'its payload names the report and its filters: ' . $last[0]['after']);
$a = json_decode($last[2]['after'], true);
ok($a['report'] === 'forecast' && $a['filters']['by'] === 'seller' && $a['filters']['months'] === 6, 'the forecast\'s: by and months ' . $last[2]['after']);
$sv = q("SELECT count(*) AS n FROM activity_log WHERE id > :s AND action = 'screen.view' AND screen LIKE 'report%'", ['s' => $since])[0]['n'];
ok((int) $sv >= 10, 'the kit\'s screen.view is written beside it for every page opened in a browser: ' . $sv);
ok(!str_contains(json_encode($rows), 'SMOKE') && !str_contains(json_encode($rows), '"rows"'), 'no name and no row of a report in any payload');
get_as(27, '/reports');
ok((int) one("SELECT count(*) FROM activity_log WHERE id > :s AND screen = 'reports' AND action = 'screen.view'", ['s' => $since]) >= 1, 'the cards page logs screen.view for reports');

echo "4. The words of the log\n";
$src = (string) file_get_contents(dirname(__DIR__, 3) . '/app/features/activity/queries.php');
ok(str_contains($src, "'report.view' =>") && str_contains($src, "'report.export' =>"), 'My activity says report.view and report.export in words');

echo "5. Refusals\n";
foreach (['/reports/funnel?from=nope&to=2026-01-01' => 422, '/reports/funnel?pipeline=zzz' => 404, '/reports/no-such' => 404] as $u => $code) {
    ok(get_as(27, $u)['code'] === $code, "GET $u answers $code");
}
ok(req('GET', '/reports/funnel', ['headers' => ['Accept: application/json']])['code'] === 401 || req('GET', '/reports/funnel', ['headers' => ['Accept: application/json']])['code'] === 302, 'no session: refused');
$r = post_as(27, '/reports/funnel', []);
ok(in_array($r['code'], [200, 405], true), 'a POST to a report screen is not an action (405 or the screen)');

echo "6. The worker and the registry\n";
$wk = worker('exports');
ok(isset($wk['ran']['exports']['built']) && empty($wk['ran']['exports']['stub']) && ($wk['errors'] ?? []) === [], 'the exports pass is a real pass: ' . json_encode($wk['ran']['exports'] ?? $wk));
foreach (glob(dirname(__DIR__, 3) . '/html/reports/*.php') as $f) { if (str_contains((string) file_get_contents($f), 'render_nav_stub(')) { ok(false, basename($f) . ' still a placeholder'); } }
ok(true, 'no controller under html/reports calls render_nav_stub');
exec('php ' . escapeshellarg(dirname(__DIR__, 3) . '/bin/build_action_registry.php') . ' --check 2>&1', $o, $rc);
ok($rc === 0, 'bin/build_action_registry.php --check: the registry and approvals[] match the manifest');
$reg = json_decode((string) file_get_contents(dirname(__DIR__, 3) . '/mcp/action_registry.json'), true);
$mine = ['reports', 'report-funnel', 'report-win-rate', 'report-cycle-time', 'report-sources', 'report-campaigns', 'report-activity', 'report-leaderboard', 'report-lost-reasons', 'report-forecast'];
ok(array_filter($mine, static fn ($s) => empty($reg['screens'][$s]['built'])) === [], 'the 10 screens read as built');
$built = count(array_filter($reg['screens'], static fn ($s) => !empty($s['built'])));
$actions = count(array_filter($reg['actions'], static fn ($s) => !empty($s['built'])));
ok($built === 67 && $actions === 90, "the registry reads $built screens and $actions actions built (57 + 10, 89 + 1)");
$a = $reg['actions']['report_export'] ?? [];
ok(!empty($a['built']) && ($a['endpoint'] ?? '') === '/reports/export.php' && empty($a['approval']) && empty($a['people_only']) && ($a['agent'] ?? true) !== false, 'report_export: built, at /reports/export.php, open to an agent, nothing paused: ' . json_encode(array_intersect_key($a, array_flip(['endpoint', 'approval', 'agent', 'confirm']))));
$os = json_decode((string) file_get_contents(dirname(__DIR__, 3) . '/maludb-os.json'), true);
ok(!in_array('report_export', array_column($os['approvals'] ?? [], 'action'), true), 'maludb-os.json approvals[] does not hold it');
finish();
