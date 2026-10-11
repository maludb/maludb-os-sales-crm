<?php
/**
 * Proof — quotas (admin-settings.md "Proof"): the grid of sellers × the twelve months of a year, saved per cell (only the cells that changed are written and logged), blank removing, a single-cell call as an agent would make it, the refusals
 * naming the field, who counts as a seller, and the reports that read them.
 */
require __DIR__ . '/lib.php';
$x = run_tag();
$since = last_activity_id();
$year = 2031 + (hexdec(substr($x, 0, 2)) % 40);           // a year no other proof touches
pg_run("DELETE FROM quotas WHERE month >= '$year-01-01' AND month < '" . ($year + 1) . "-01-01'");

echo "1. The grid\n";
$g = screen(1, "/admin/quotas?year=$year");
$ids = array_column($g['sellers'], 'member_id');
ok($g['year'] === $year && count($g['months']) === 12 && $g['months'][0] === "$year-01" && $g['months'][11] === "$year-12" && $g['currency'] === 'USD', "the twelve months of $year, in the business's currency");
ok(in_array(1, $ids, true) && in_array(26, $ids, true) && in_array(27, $ids, true) && !in_array(29, $ids, true) && !in_array(40, $ids, true) && !in_array(42, $ids, true), 'the sellers are people who can own a deal — the admin, the Reps, the Sales Manager; not the Viewer and not the agents: ' . implode(',', $ids));
ok(array_filter($g['sellers'][0]['cells'], static fn ($v) => $v !== null) === [], 'every cell empty on a year with no quota');
$page = html_as(1, "/admin/quotas?year=$year")['body'];
ok(str_contains($page, 'id="quota-grid"') && str_contains($page, 'id="quota-26-' . $year . '-03"') && str_contains($page, 'name="cells[26][' . $year . '-03]"') && str_contains($page, 'id="quotas-prev"') && str_contains($page, 'id="quotas-save-btn"'), 'the page: the grid with an input per seller and month (id quota-{member}-{month}), the year links, Save');
$today = get_as(1, '/admin/quotas')['json']['data']['year'];
ok($today === (int) date('Y'), 'with no year the grid is this year\'s');
ok(get_as(1, '/admin/quotas?year=1850')['json']['data']['year'] === (int) date('Y') && get_as(1, '/admin/quotas?year=abc')['json']['data']['year'] === (int) date('Y'), 'a year that is nonsense is ignored');

echo "2. Saving cells\n";
$cells = ['26' => ["$year-01" => '10000', "$year-02" => '12,500.50', "$year-03" => ''], '27' => ["$year-01" => '20000'], '1' => ["$year-12" => '0']];
$r = adm('/admin/quotas/save.php', ['cells' => $cells]);
$rows = q('SELECT member_id, to_char(month, \'YYYY-MM\') AS ym, amount FROM quotas WHERE month >= :a AND month < :b ORDER BY member_id, month', ['a' => "$year-01-01", 'b' => ($year + 1) . '-01-01']);
ok($r['code'] === 200 && count($rows) === 4 && (float) $rows[2]['amount'] === 12500.5 && (float) $rows[0]['amount'] === 0.0 && ($r['json']['changed'] ?? 0) === 4, 'quota_set per changed cell: four quotas stored (a thousands comma tolerated, 0 is a quota, a blank is none): ' . ($r['json']['did'] ?? $r['body']));
ok((int) one("SELECT count(*) FROM activity_log WHERE action = 'quota.set' AND id > :s", ['s' => $since]) === 4, 'four quota.set rows, one per cell');
$since2 = last_activity_id();
$r = adm('/admin/quotas/save.php', ['cells' => $cells]);
ok($r['code'] === 200 && ($r['json']['did'] ?? '') === 'Nothing to change' && last_activity_id() === $since2, 'the same grid again changes nothing and logs nothing');
$cells['26']["$year-01"] = '11000'; $cells['27']["$year-01"] = '';
$r = adm('/admin/quotas/save.php', ['cells' => $cells]);
$have = q('SELECT member_id, to_char(month, \'YYYY-MM\') AS ym, amount FROM quotas WHERE month >= :a AND month < :b ORDER BY member_id, month', ['a' => "$year-01-01", 'b' => ($year + 1) . '-01-01']);
ok($r['code'] === 200 && ($r['json']['changed'] ?? 0) === 2 && count($have) === 3 && (float) $have[1]['amount'] === 11000.0, 'one raised, one blanked: only those two are written (the blank removes the quota)');
$la = q("SELECT after::text AS a FROM activity_log WHERE action = 'quota.set' AND id > :s ORDER BY id", ['s' => $since2]);
ok(count($la) === 2 && str_contains($la[0]['a'], '"before"') && str_contains($la[0]['a'], 'member_id') && str_contains($la[0]['a'], "$year-01"), 'quota.set carries the seller, the month and the amount before and after: ' . $la[0]['a']);
$g = screen(1, "/admin/quotas?year=$year");
$priya = array_values(array_filter($g['sellers'], static fn ($s) => $s['member_id'] === 26))[0];
ok((float) $priya["cells"]["$year-01"] === 11000.0 && (float) $priya["cells"]["$year-02"] === 12500.5 && $priya['cells']["$year-03"] === null, 'the grid reads them back');
ok(str_contains(html_as(1, "/admin/quotas?year=$year")['body'], 'value="12500.5"') && str_contains(html_as(1, "/admin/quotas?year=$year")['body'], 'id="quota-total-26"'), 'and so does the page, with each seller\'s year total');

echo "3. One cell — an agent's call\n";
$r = adm('/admin/quotas/save.php', ['member' => 27, 'month' => "$year-06", 'amount' => '33000']);
ok($r['code'] === 200 && (float) one('SELECT amount FROM quotas WHERE member_id = 27 AND month = :m', ['m' => "$year-06-01"]) === 33000.0, 'member, month (2031-06) and amount: one quota');
$r = adm('/admin/quotas/save.php', ['member' => 27, 'month' => "$year-06-17", 'amount' => '']);
ok($r['code'] === 200 && one('SELECT amount FROM quotas WHERE member_id = 27 AND month = :m', ['m' => "$year-06-01"]) === false, 'a day of the month names the month; a blank amount removes it');
$ag = agent_post(40, 9501, '/admin/quotas/save.php', ['member' => 27, 'month' => "$year-07", 'amount' => '1']);
ok($ag['code'] === 403, 'an agent without quotas.manage is refused (the Expert is a Rep)');

echo "4. Refusals\n";
$r = adm('/admin/quotas/save.php', ['member' => 27, 'month' => "$year-08", 'amount' => '-5']);
ok($r['code'] === 422 && field_of($r) === 'amount' && str_contains(says($r), '0 or more'), 'a negative amount names its field: ' . message_of($r));
$r = adm('/admin/quotas/save.php', ['member' => 27, 'month' => "$year-08", 'amount' => 'lots']);
ok($r['code'] === 422 && field_of($r) === 'amount', 'a word for an amount');
$r = adm('/admin/quotas/save.php', ['member' => 27, 'month' => 'next spring', 'amount' => '5']);
ok($r['code'] === 422 && field_of($r) === 'month', 'a month that is no month names its field');
$r = adm('/admin/quotas/save.php', ['month' => "$year-08", 'amount' => '5']);
ok($r['code'] === 422 && field_of($r) === 'member', 'no member names the field');
$r = adm('/admin/quotas/save.php', ['member' => 29, 'month' => "$year-08", 'amount' => '5']);
ok($r['code'] === 422 && field_of($r) === 'member' && str_contains(says($r), 'for a seller'), 'a Viewer is no seller: ' . message_of($r));
$r = adm('/admin/quotas/save.php', ['member' => 40, 'month' => "$year-08", 'amount' => '5']);
ok($r['code'] === 422 && field_of($r) === 'member', 'nor an agent');
$r = adm('/admin/quotas/save.php', ['cells' => ['26' => ["$year-09" => '100', "$year-10" => 'x']]]);
ok($r['code'] === 422 && (float) one('SELECT count(*) FROM quotas WHERE member_id = 26 AND month = :m', ['m' => "$year-09-01"]) === 0.0, 'one bad cell refuses the grid: the good cell beside it was not saved either');
$r = adm('/admin/quotas/save.php', ['amount' => '5']);
ok($r['code'] === 422, 'no member or cells at all: 422');

echo "5. What reads them, and who may\n";
ok(get_as(26, '/admin/quotas')['code'] === 403 && post_as(26, '/admin/quotas/save.php', ['member' => 26, 'month' => "$year-01", 'amount' => '999999'])['code'] === 403 && (float) one('SELECT amount FROM quotas WHERE member_id = 26 AND month = :m', ['m' => "$year-01-01"]) === 11000.0, 'a Rep may not set (even her own) quota');
ok(get_as(27, "/admin/quotas?year=$year")['code'] === 200 && post_as(27, '/admin/quotas/save.php', ['member' => 26, 'month' => "$year-04", 'amount' => '7000'])['code'] === 200, 'the Sales Manager (quotas.manage) may');
$mkt = role_actor(31, 'marketing');
ok(get_role($mkt, '/admin/quotas')['code'] === 403, 'Marketing may not');
as_member(26);
$seen = q("SELECT DISTINCT member_id FROM mcp_quotas WHERE month >= :a AND month < :b", ['a' => "$year-01-01", 'b' => ($year + 1) . '-01-01']);
ok(array_column($seen, 'member_id') === [26], 'mcp_quotas still shows a Rep only her own lines (the wall is the view\'s)');
as_member(27);
ok(count(q("SELECT DISTINCT member_id FROM mcp_quotas WHERE month >= :a AND month < :b", ['a' => "$year-01-01", 'b' => ($year + 1) . '-01-01'])) >= 2, '…and the Sales Manager everyone\'s');
finish();
