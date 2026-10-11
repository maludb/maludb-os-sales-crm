<?php
/** Proof — the wall on the team reports, through the screens and the JSON, not only in SQL: a Rep's activity summary and forecast by seller hold their own rows; the leaderboard is a manager's; a record
 * restricted to Priya is in no one else's numbers (the manager's included? — the schema decides: the proof reads what crm_wall() answers); a Viewer reads and cannot export. Every report equals the function AS THAT MEMBER. */
require __DIR__ . '/lib.php';
ensure_book();
$w = win();
$m0 = month_start();

echo "1. The leaderboard is a manager's\n";
foreach ([26, 30, 29] as $m) {
    $d = rep($m, 'leaderboard', ['month' => $m0]);
    ok($d['rows'] === [] && str_contains($d['note'], 'managers') && $d['headline']['text'] === '—', "member $m: no rows, the note \"{$d['note']}\", the headline —");
}
$h = rep_html(26, 'leaderboard')['body'];
ok(str_contains($h, "The leaderboard is the team managers'.") || str_contains($h, 'The leaderboard is the team managers&#039;.'), 'a Rep\'s page says so in words');
$lb = rep(27, 'leaderboard', ['month' => $m0])['rows'];
ok(count($lb) === 5 && array_values(array_intersect([26, 27, 30], array_column($lb, 'member_id'))) === [26, 27, 30], 'the manager\'s holds the five people who sell, the three with deals among them');
ok(rep(1, 'leaderboard', ['month' => $m0])['rows'] !== [], 'the CRM admin reads it too (reports.team)');

echo "2. The activity summary: a seller\'s own rows\n";
foreach ([26 => 'SMOKE Priya', 30 => 'SMOKE Dana'] as $m => $name) {
    $d = rep($m, 'activity', $w);
    $want = as_rows($m, 'SELECT * FROM crm_activity_summary(:a, :b, NULL)', ['a' => $w['from'], 'b' => $w['to']]);
    ok($d['rows'] !== [] && array_unique(array_column($d['rows'], 'member_id')) === [$m] && same_rows($d['rows'], $want, ['member_id' => 'member_id', 'kind' => 'kind', 'done' => 'done']), "$name sees " . count($d['rows']) . ' rows, all hers, equal to the function as her');
    ok(array_unique($d['chart']['categories']) === [$name], '…and her chart has one bar group: ' . $name);
}
$d = rep(29, 'activity', $w);
ok($d['rows'] === [], 'a Viewer, who sells nothing, sees no rows');
$mg = rep(27, 'activity', $w);
ok(count(array_unique(array_column($mg['rows'], 'member_id'))) === 3, 'the manager sees all three');
$h = rep_html(26, 'activity', $w)['body'];
ok(!str_contains($h, 'SMOKE Dana') && !str_contains($h, 'SMOKE Marco') && str_contains($h, 'SMOKE Priya'), 'in the page too: no one else\'s name');

echo "3. The forecast by seller\n";
$d = rep(26, 'forecast', ['by' => 'seller', 'months' => 6]);
ok(array_unique(array_column($d['rows'], 'row_id')) === [26], 'a Rep sees only their own line');
ok(!str_contains(json_encode($d), 'SMOKE Marco') && !str_contains(json_encode($d), 'SMOKE Dana'), '…and no other seller\'s name anywhere in the JSON');
$own = array_filter($d['rows'], static fn ($r) => $r['quota'] !== null);
ok(count($own) === 6 && (float) $d['extra']['months'][0]['quota'] === 60000.0, 'her quota (60,000 a month) is visible to her, and the quota line is hers alone');
$mgr = rep(27, 'forecast', ['by' => 'seller', 'months' => 6]);
ok((float) $mgr['extra']['months'][0]['quota'] === 150000.0 && $mgr['extra']['months'][0]['weighted'] > $d['extra']['months'][0]['weighted'], 'the manager\'s line is the team\'s: quota 150,000, more weighted than any one seller');
$sumOwn = 0.0; foreach (as_rows(27, "SELECT * FROM crm_forecast(NULL, 1, 'seller', NULL)") as $r) { if ((int) $r['row_id'] === 26) { $sumOwn += (float) $r['weighted']; } }
ok(abs($d['extra']['months'][0]['weighted'] - $sumOwn) < 0.01, 'her month total is her row of the function, not the team\'s');
$st = rep(26, 'forecast', ['by' => 'stage', 'months' => 2]);
ok($st['extra']['months'][0]['quota'] === null && array_unique(array_column($st['rows'], 'quota')) === [null], 'by stage a Rep sees no quota at all');
$h = rep_html(26, 'forecast')['body'];
ok(!str_contains($h, 'SMOKE Marco') && !str_contains($h, 'SMOKE Dana'), 'the Rep\'s forecast page names no other seller');
$vw = rep(29, 'forecast', ['by' => 'seller']);
ok($vw['rows'] === [] && $vw['headline']['text'] === '—', 'a Viewer sells nothing: no rows');

echo "4. What a record restricted to Priya does to the numbers\n";
$p = rep(26, 'win_rate', $w);
$m = rep(27, 'win_rate', $w);
$dn = rep(30, 'win_rate', $w);
$mine = as_rows(26, "SELECT count(*) AS n FROM mcp_deals WHERE title LIKE 'RPT%' AND status = 'won'")[0]['n'];
$mgrSees = as_rows(27, "SELECT count(*) AS n FROM mcp_deals WHERE title LIKE 'RPT%' AND status = 'won'")[0]['n'];
$danaSees = as_rows(30, "SELECT count(*) AS n FROM mcp_deals WHERE title LIKE 'RPT%' AND status = 'won'")[0]['n'];
ok(array_sum(array_column($p['rows'], 'won')) === (int) $mine && array_sum(array_column($m['rows'], 'won')) === (int) $mgrSees && array_sum(array_column($dn['rows'], 'won')) === (int) $danaSees, "won deals counted: Priya $mine, Marco $mgrSees, Dana $danaSees — each equal to what the database lets that member see");
ok((int) $danaSees === 74 && (int) $mine === 80, 'six of the eighty are restricted to Priya: Dana\'s numbers leave them out');
$fp = rep(26, 'funnel', $w);
$fd = rep(30, 'funnel', $w);
ok(array_sum(array_column($fd['rows'], 'entered')) < array_sum(array_column($fp['rows'], 'entered')), 'the funnel too: Dana\'s stages hold fewer deals than Priya\'s');
foreach ([30 => 'sources', 29 => 'lost_reasons'] as $mm => $name) {
    $d = rep($mm, $name, $w);
    ok(($d['_code'] ?? 200) === 200, "member $mm reads $name");
}
$wd = rep(30, 'sources', $w);
$wantD = array_values(array_filter(as_rows(30, 'SELECT * FROM crm_source_performance(:a, :b)', ['a' => $w['from'], 'b' => $w['to']]), static fn ($r) => (int) $r['leads'] + (int) $r['deals'] + (int) $r['won'] > 0));
ok(same_rows($wd['rows'], $wantD, ['source_id' => 'source_id', 'won' => 'won', 'won_value' => 'won_value', 'deals' => 'deals']), 'Dana\'s sources equal the function as her');

echo "5. Every report equals the function as the member asking\n";
$fns = [
    'funnel' => ['SELECT * FROM crm_funnel(1, :a, :b, NULL)', ['stage_id' => 'stage_id', 'entered' => 'entered', 'moved_on' => 'moved_on']],
    'win_rate' => ["SELECT * FROM crm_win_rate(:a, :b, 'seller')", ['row_id' => 'row_id', 'won' => 'won', 'lost' => 'lost']],
    'lost_reasons' => ['SELECT * FROM crm_lost_reasons_summary(:a, :b, false) WHERE deals > 0', ['lost_reason_id' => 'lost_reason_id', 'deals' => 'deals']],
];
foreach ([26, 30, 29, 27, 1] as $mm) {
    $bad = [];
    foreach ($fns as $name => [$sql, $keys]) {
        $d = rep($mm, $name, $w);
        if (!same_rows($d['rows'], as_rows($mm, $sql, ['a' => $w['from'], 'b' => $w['to']]), $keys)) { $bad[] = $name; }
    }
    ok($bad === [], "member $mm: funnel, win rate and lost reasons equal their functions" . ($bad ? ' — differ: ' . implode(', ', $bad) : ''));
}

echo "6. A Viewer reads, and cannot export\n";
foreach (['funnel', 'win_rate', 'sources', 'campaigns', 'forecast'] as $name) {
    ok(rep_html(29, $name)['code'] === 200, "a Viewer opens $name");
}
ok(get_as(29, '/reports')['code'] === 200, '…and the cards');
ok(post_as(29, '/reports/export.php', ['report' => 'sources'])['code'] === 403, '…but export is refused');
$none = get_as(29, '/reports/funnel')['json']['data']['may'] ?? null;
ok($none === ['export' => false, 'team' => false], 'the JSON says what they may: ' . json_encode($none));
ok((get_as(26, '/reports/funnel')['json']['data']['may'] ?? null) === ['export' => true, 'team' => false] && (get_as(27, '/reports/funnel')['json']['data']['may'] ?? null) === ['export' => true, 'team' => true], 'a Rep may export and is not the team; the manager is both');
finish();
