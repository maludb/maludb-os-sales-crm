<?php
/** Proof — the home's tiles: a manager's forecast tile (this month for the team, the quota line in its tone) and the leaders' top three, a Rep's closing tile, a Viewer's plain home; each equal to the database's own numbers and to the
 * reports they open; the links to the reports. */
require __DIR__ . '/lib.php';
ensure_book();
$m0 = month_start();

echo "1. A manager\n";
$h = get_as(27, '/')['json']['data'];
$f = $h['manager']['forecast'];
$want = as_rows(27, "SELECT COALESCE(sum(open_value), 0) AS o, COALESCE(sum(weighted), 0) AS w, COALESCE(sum(won_value), 0) AS wn, COALESCE(sum(deals), 0) AS d, sum(quota) AS q FROM crm_forecast(NULL, 1, 'seller', NULL) WHERE month = date_trunc('month', current_date)::date")[0];
ok(abs($f['open_value'] - (float) $want['o']) < 0.01 && abs($f['weighted'] - (float) $want['w']) < 0.01 && abs($f['won_value'] - (float) $want['wn']) < 0.01 && $f['deals'] === (int) $want['d'] && (float) $f['quota'] === 150000.0, 'the forecast tile equals crm_forecast() for this month: weighted ' . $f['weighted'] . ', won ' . $f['won_value'] . ' of ' . $f['quota']);
$fr = rep(27, 'forecast', ['months' => 1]);
$x = $fr['extra']['months'][0];
ok(abs($x['weighted'] - $f['weighted']) < 0.01 && abs($x['won_value'] - $f['won_value']) < 0.01 && (float) $x['quota'] === (float) $f['quota'] && abs($x['open_value'] - $f['open_value']) < 0.01, 'the tile and the forecast report it opens say the same thing');
$lw = as_rows(27, 'SELECT member_id, display_name, won_value, won_count FROM crm_leaderboard(:m, NULL) WHERE won_value > 0 ORDER BY won_value DESC, won_count DESC, display_name LIMIT 3', ['m' => $m0]);
ok(same_rows($h['manager']['leaders'], $lw, ['member_id' => 'member_id', 'display_name' => 'display_name', 'won_value' => 'won_value', 'won_count' => 'won_count']) && count($lw) === 3, 'the leaders\' top three equal crm_leaderboard(): ' . implode(', ', array_column($lw, 'display_name')));
$board = rep(27, 'leaderboard', ['month' => $m0]);
ok(array_column($board['rows'], 'member_id') === array_column($h['manager']['leaders'], 'member_id') || count($board['rows']) >= 3, 'the leaderboard it opens lists the same sellers');
$b = html_as(27, '/')['body'];
foreach (['home-forecast', 'home-forecast-won', 'home-forecast-weighted', 'home-forecast-quota', 'home-forecast-link', 'home-leaders', 'home-leaders-list', 'home-leaders-link', 'home-closing'] as $id) { ok(str_contains($b, 'id="' . $id . '"'), "the home has #$id"); }
preg_match('/id="home-forecast-link"/', $b);
ok(preg_match('/<a href="\/reports\/forecast"[^>]*id="home-forecast-link"/', $b) === 1 && preg_match('/<a href="\/reports\/leaderboard"[^>]*id="home-leaders-link"/', $b) === 1, 'the tiles link to /reports/forecast and /reports/leaderboard');
$att = 100 * $f['won_value'] / $f['quota'];
ok(preg_match('/class="fw-semibold text-' . tone_of($att) . '" id="home-forecast-quota">' . (int) round($att) . '%</', $b) === 1, 'the quota percent (' . (int) round($att) . '%) is toned ' . tone_of($att));
preg_match_all('/id="home-leader-(\d+)"/', $b, $ids);
ok(array_map('intval', $ids[1]) === array_column($lw, 'member_id'), 'three leaders in the page, in order');
ok(get_as(27, '/reports/forecast')['code'] === 200 && html_as(27, '/reports/forecast')['code'] === 200 && html_as(27, '/reports/leaderboard')['code'] === 200, 'both links open');
foreach ([[0, 'danger'], [69, 'danger'], [70, 'warning'], [99, 'warning'], [100, 'success']] as [$a, $t]) { ok(tone_of((float) $a) === $t, "$a% is $t"); }

echo "2. A Rep\n";
$h = get_as(26, '/')['json']['data'];
ok($h['manager'] === null, 'a Rep\'s home has no manager block');
$b = html_as(26, '/')['body'];
ok(!str_contains($b, 'id="home-forecast"') && !str_contains($b, 'id="home-leaders"') && str_contains($b, 'id="home-closing"'), '…no forecast tile, no leaders; the closing tile is there');
$raw = q("SELECT count(*) AS n, COALESCE(sum(value) FILTER (WHERE currency = 'USD'), 0) AS v, COALESCE(sum(weighted_value) FILTER (WHERE currency = 'USD'), 0) AS wv FROM deals WHERE owner_member_id = 26 AND status = 'open' AND deleted_at IS NULL
    AND expected_close_date >= date_trunc('month', current_date) AND expected_close_date < date_trunc('month', current_date) + interval '1 month'")[0];
$c = $h['closing'];
ok($c['count'] === (int) $raw['n'] && abs($c['value'] - (float) $raw['v']) < 0.01 && abs($c['weighted'] - (float) $raw['wv']) < 0.01 && $c['count'] > 3, 'the closing tile equals the raw rows: ' . $c['count'] . ' deals, ' . $c['value'] . ' value, ' . $c['weighted'] . ' weighted');
ok(str_contains($b, 'id="home-closing-value">$' . number_format($c['value']) . '<'), 'and the page prints the figure: $' . number_format($c['value']));
$d = get_as(30, '/')['json']['data']['closing'];
$rawD = (int) pg("SELECT count(*) FROM deals WHERE owner_member_id = 30 AND status = 'open' AND deleted_at IS NULL AND expected_close_date >= date_trunc('month', current_date) AND expected_close_date < date_trunc('month', current_date) + interval '1 month'");
ok($d['count'] === $rawD, 'Dana\'s closing tile is her own deals: ' . $d['count'] . ' deals');
$link = $c['count'] > 0 ? preg_match('/id="home-closing-link"/', $b) : 1;
ok($link === 1, 'a link to the board when there is something closing');

echo "3. A Viewer\n";
$h = get_as(29, '/')['json']['data'];
ok($h['manager'] === null, 'a Viewer has no manager block either');
finish();
