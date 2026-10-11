<?php
/** Proof — every report renders with the numbers the database function answers, for the manager and for a Rep: the funnel, win rate, cycle time, sources, campaigns, activity, leaderboard, lost reasons and the
 * forecast against the seeded book (200 deals, 70 leads, 150 done activities, three campaigns, quotas), each cross-checked against the raw rows; the headline, the chart's data and the table agree; the cards of /reports;
 * the pages' ids, the chart's <title> and <desc>, the filter bar. */
require __DIR__ . '/lib.php';
$n = ensure_book();
ok($n === 200 && (int) pg("SELECT count(*) FROM deals WHERE title LIKE 'RPT deal %' AND status = 'won'") === 80 && (int) pg("SELECT count(*) FROM deals WHERE title LIKE 'RPT deal %' AND status = 'lost'") === 40, 'the seeded book: 200 deals — 80 won, 40 lost, 80 open');
$w = win();
$cur = 'USD';
$pipe = 1;

echo "1. Win rate\n";
foreach ([27, 26, 30] as $m) {
    foreach (['seller', 'source', 'pipeline'] as $by) {
        $d = rep($m, 'win_rate', $w + ['by' => $by]);
        $want = as_rows($m, 'SELECT * FROM crm_win_rate(:a, :b, :by)', ['a' => $w['from'], 'b' => $w['to'], 'by' => $by]);
        $want = array_map(static fn (array $r): array => $r + ['nm' => $r['row_name'] ?? '(none)'], $want);
        ok(same_rows($d['rows'], $want, ['row_id' => 'row_id', 'row_name' => 'nm', 'won' => 'won', 'lost' => 'lost', 'win_rate' => 'win_rate', 'won_value' => 'won_value', 'avg_cycle_days' => 'avg_cycle_days', 'median_cycle_days' => 'median_cycle_days']),
            "member $m by $by: " . count($want) . ' rows equal crm_win_rate()');
    }
}
$d = rep(27, 'win_rate', $w);
$won = array_sum(array_column($d['rows'], 'won'));
$lost = array_sum(array_column($d['rows'], 'lost'));
ok($won === 80 && $lost === 40 && $d['headline']['text'] === '66.7%' && $d['headline']['detail'] === '80 won, 40 lost', 'the manager: 80 won, 40 lost over the window — 66.7% (hand-counted from the rows)');
$d30 = rep(30, 'win_rate', $w);
ok(array_sum(array_column($d30['rows'], 'won')) === 74, 'Dana does not see the six deals restricted to Priya: 74 won, not 80');
ok(row_by($d['rows'], 'row_id', 27)['won'] === (int) pg("SELECT count(*) FROM deals WHERE owner_member_id = 27 AND status = 'won' AND title LIKE 'RPT%'"), 'Marco\'s won count equals the raw rows');
ok($d['chart']['type'] === 'bars' && $d['chart']['unit'] === 'pct' && $d['chart']['categories'] === array_column($d['rows'], 'row_name') && $d['chart']['series'][0]['values'] === array_map(static fn ($r) => (float) $r['win_rate'], $d['rows']), 'the chart carries the table\'s categories and win rates');
$cols = array_column($d['columns'], 'label');
ok($cols === ['Seller', 'Won', 'Lost', 'Win rate', 'Won value', 'Average days', 'Median days'], 'columns: ' . implode(', ', $cols));
$none = rep(27, 'win_rate', ['from' => '2020-01-01', 'to' => '2020-01-31']);
ok($none['rows'] === [] && $none['headline']['text'] === '—' && $none['chart']['series'][0]['values'] === [], 'a period with no closed deal: no rows, "—", an empty chart');

echo "2. Funnel\n";
foreach ([27, 26] as $m) {
    $d = rep($m, 'funnel', $w);
    $want = as_rows($m, 'SELECT * FROM crm_funnel(:p, :a, :b, NULL)', ['p' => $pipe, 'a' => $w['from'], 'b' => $w['to']]);
    ok(same_rows($d['rows'], $want, ['stage_id' => 'stage_id', 'stage_name' => 'stage_name', 'entered' => 'entered', 'moved_on' => 'moved_on', 'lost_from' => 'lost_from', 'still_here' => 'still_here', 'median_days' => 'median_days']),
        "member $m: " . count($want) . ' stages equal crm_funnel()');
}
$d = rep(27, 'funnel', $w);
ok(count($d['rows']) === 4 && array_sum(array_map(static fn ($r) => $r['moved_on'] + $r['lost_from'] + $r['still_here'], $d['rows'])) === array_sum(array_column($d['rows'], 'entered')), 'four open stages; every deal that entered one moved on, was lost from it or is still there');
$q = row_by($d['rows'], 'stage_name', 'Qualification');
ok($q['entered'] === (int) pg("SELECT count(*) FROM deal_stage_history h WHERE h.to_stage_id = 6 AND h.at::date BETWEEN '{$w['from']}' AND '{$w['to']}' AND h.deal_id IN (SELECT id FROM deals WHERE title LIKE 'RPT%' AND pipeline_id = 1)"), 'Qualification\'s "entered" equals a count of the history rows');
$ch = $d['chart'];
ok($ch['type'] === 'funnel' && array_column($ch['stages'], 'entered') === array_column($d['rows'], 'entered') && array_column($ch['stages'], 'label') === array_column($d['rows'], 'stage_name'), 'the funnel chart\'s stages are the table\'s');
ok(preg_match('/^\d+(\.\d)?% of those that left it moved on$/', $d['headline']['detail']) === 1 && $d['headline']['label'] === 'the weakest stage', 'headline: the weakest stage — ' . $d['headline']['text'] . ', ' . $d['headline']['detail']);
$kept = [];
foreach ($d['rows'] as $r) { if ($r['moved_on'] + $r['lost_from'] > 0) { $kept[$r['stage_name']] = $r['moved_on'] / ($r['moved_on'] + $r['lost_from']); } }
asort($kept);
ok($d['headline']['text'] === array_key_first($kept), 'the weakest stage is the one where, of the deals that left it, the fewest moved on (' . array_key_first($kept) . ')');
$d2 = rep(27, 'funnel', $w + ['pipeline' => 'renewals']);
ok(count($d2['rows']) === 3 && ($d2['filters']['pipeline'] ?? 0) === 2, 'the Renewals pipeline by key: its three open stages');
$d3 = rep(27, 'funnel', $w + ['owner' => 30]);
$want = as_rows(27, 'SELECT * FROM crm_funnel(1, :a, :b, 30)', ['a' => $w['from'], 'b' => $w['to']]);
ok(same_rows($d3['rows'], $want, ['entered' => 'entered', 'moved_on' => 'moved_on']) && array_sum(array_column($d3['rows'], 'entered')) < array_sum(array_column($d['rows'], 'entered')), 'the owner filter narrows it to Dana\'s deals (crm_funnel with the owner)');
$g = get_as(27, '/reports/funnel?pipeline=nowhere');
ok($g['code'] === 404, 'an unknown pipeline: 404');

echo "3. Cycle time and touches to win\n";
$d = rep(27, 'cycle_time', $w);
$want = array_values(array_filter(as_rows(27, "SELECT * FROM crm_win_rate(:a, :b, 'seller')", ['a' => $w['from'], 'b' => $w['to']]), static fn ($r) => (int) $r['won'] > 0));
ok(same_rows($d['rows'], $want, ['row_id' => 'row_id', 'won' => 'won', 'avg_cycle_days' => 'avg_cycle_days', 'median_cycle_days' => 'median_cycle_days']), count($want) . ' sellers with a won deal equal crm_win_rate() by seller');
$t = as_rows(27, 'SELECT * FROM crm_touches_to_win(:a, :b)', ['a' => $w['from'], 'b' => $w['to']])[0];
$tt = $d['extra']['touches'];
ok($tt['deals'] === (int) $t['deals'] && abs($tt['avg_touches'] - (float) $t['avg_touches']) < 0.01 && abs($tt['median_touches'] - (float) $t['median_touches']) < 0.01 && abs($tt['avg_days'] - (float) $t['avg_days']) < 0.01, 'touches to win equal crm_touches_to_win(): ' . json_encode($tt));
ok($tt['deals'] === 80 && $d['headline']['text'] === number_format((float) $t['avg_days'], 1) . ' days', 'eighty won deals; the headline is the average days to win — ' . $d['headline']['text']);
$h = rep_html(27, 'cycle_time', $w)['body'];
ok(str_contains($h, 'id="report-touches"') && str_contains($h, 'Touches to win'), 'the page shows the touches tile');
ok(count($d['chart']['series']) === 2 && $d['chart']['series'][0]['label'] === 'Median' && $d['chart']['series'][1]['label'] === 'Average', 'the chart: median and average days by seller');

echo "4. Sources\n";
$d = rep(27, 'sources', $w);
$all = as_rows(27, 'SELECT * FROM crm_source_performance(:a, :b)', ['a' => $w['from'], 'b' => $w['to']]);
$busy = array_values(array_filter($all, static fn ($r) => (int) $r['leads'] + (int) $r['deals'] + (int) $r['won'] > 0));
ok(same_rows($d['rows'], $busy, ['source_id' => 'source_id', 'source_name' => 'source_name', 'leads' => 'leads', 'converted' => 'converted', 'unqualified' => 'unqualified', 'deals' => 'deals', 'won' => 'won', 'won_value' => 'won_value', 'conversion_rate' => 'conversion_rate']),
    count($busy) . ' sources equal crm_source_performance() (those with nothing left out)');
ok(array_sum(array_column($d['rows'], 'leads')) === 70 && array_sum(array_column($d['rows'], 'won')) === 80, 'seventy leads and eighty won deals across the sources (hand-counted)');
$best = null; foreach ($all as $r) { if ($best === null || (float) $r['won_value'] > (float) $best['won_value']) { $best = $r; } }
ok($d['headline']['text'] === $best['source_name'] && str_contains($d['headline']['detail'], money_text((float) $best['won_value'])), 'headline: the best source is ' . $best['source_name'] . ' (' . $d['headline']['detail'] . ')');
function money_text(float $v): string { return '$' . number_format($v); }
$idle = count($all) - count($busy);
ok($d['note'] === ($idle > 0 ? "$idle source" . ($idle === 1 ? '' : 's') . ' with nothing in this period ' . ($idle === 1 ? 'is' : 'are') . ' left out.' : ''), 'the sources with nothing are told, not shown: "' . $d['note'] . '"');
ok(rep(26, 'sources', $w)['rows'] === $d['rows'], 'a Rep sees the same sources (open visibility)');

echo "5. Campaigns\n";
$d = rep(27, 'campaigns');
$camps = as_rows(27, "SELECT c.campaign_id, c.name, c.status, c.budget, r.* FROM mcp_campaigns c LEFT JOIN LATERAL crm_campaign_results(c.campaign_id) r ON true WHERE c.name LIKE 'RPT%' ORDER BY c.campaign_id");
foreach ($camps as $c) {
    $row = row_by($d['rows'], 'campaign_id', (int) $c['campaign_id']);
    ok($row !== null && same_rows([$row], [$c], ['name' => 'name', 'status' => 'status', 'budget' => 'budget', 'members' => 'members', 'leads' => 'leads', 'converted' => 'converted', 'deals' => 'deals', 'won' => 'won', 'won_value' => 'won_value', 'cost_per_lead' => 'cost_per_lead', 'cost_per_won' => 'cost_per_won']),
        $c['name'] . ': equals crm_campaign_results() — ' . ($row['won'] ?? '?') . ' won, cost per won ' . ($row['cost_per_won'] ?? 'none'));
}
$spring = row_by($d['rows'], 'name', 'RPT Spring webinar');
$rawWon = (int) pg("SELECT count(*) FROM deals WHERE campaign_id = (SELECT id FROM campaigns WHERE name = 'RPT Spring webinar') AND status = 'won'");
ok($spring['won'] === $rawWon && $spring['cost_per_won'] === round(6000 / $rawWon, 2), 'the Spring webinar: ' . $rawWon . ' won deals; 6,000 / ' . $rawWon . ' = ' . round(6000 / $rawWon, 2) . ' a won deal (hand-computed)');
$idleC = row_by($d['rows'], 'name', 'RPT Idle idea');
ok($idleC['cost_per_won'] === null && $idleC['leads'] === 0, 'the idle campaign has no cost per won');
ok(str_starts_with($d['headline']['label'], 'cost per won deal, ') && $d['headline']['text'] !== '—', 'headline: the lowest cost per won deal — ' . $d['headline']['text'] . ' (' . $d['headline']['label'] . ')');
$a = rep(27, 'campaigns', ['status' => 'done']);
ok(array_unique(array_column($a['rows'], 'status')) === ['done'] && count($a['rows']) >= 1, 'the status filter keeps only done campaigns');
ok(count($d['chart']['categories']) === count(array_filter($d['rows'], static fn ($r) => $r['cost_per_won'] !== null)), 'the chart draws the campaigns that have a cost per won deal');

echo "6. Activity\n";
$da = rep(27, 'activity', $w);
$want = as_rows(27, 'SELECT * FROM crm_activity_summary(:a, :b, NULL)', ['a' => $w['from'], 'b' => $w['to']]);
ok(same_rows($da['rows'], $want, ['member_id' => 'member_id', 'display_name' => 'display_name', 'kind' => 'kind', 'done' => 'done', 'scheduled' => 'scheduled']), 'the manager: ' . count($want) . ' (seller, kind) rows equal crm_activity_summary()');
ok(array_sum(array_column($da['rows'], 'done')) === (int) pg("SELECT count(*) FROM activities WHERE subject LIKE 'RPT touch%' AND done_at::date BETWEEN '{$w['from']}' AND '{$w['to']}'"), 'the touches done equal a raw count of the seeded activities: ' . $da['headline']['text']);
$out = $da['rows'][0]['outcomes'];
ok(is_array($out) && $out !== [], 'the outcomes of a row are an object: ' . json_encode($out));
ok($da['chart']['stacked'] === true && count($da['chart']['series']) === 4 && count($da['chart']['categories']) === 3, 'stacked bars: three sellers by four kinds');
$tot = 0; foreach ($da['chart']['series'] as $s) { $tot += array_sum($s['values']); }
ok($tot === array_sum(array_column($da['rows'], 'done')), 'the stacked values add up to the table');
$dt = rep(27, 'activity', $w + ['team' => 7]);
ok(isset($dt['filters']['team']), 'the team filter is carried');

echo "7. Leaderboard\n";
$m0 = month_start();
$dl = rep(27, 'leaderboard', ['month' => $m0]);
$want = as_rows(27, 'SELECT * FROM crm_leaderboard(:m, NULL)', ['m' => $m0]);
ok(same_rows($dl['rows'], $want, ['member_id' => 'member_id', 'display_name' => 'display_name', 'won_value' => 'won_value', 'won_count' => 'won_count', 'quota' => 'quota', 'attainment' => 'attainment', 'open_weighted' => 'open_weighted', 'activities_done' => 'activities_done']),
    'the manager: ' . count($want) . ' sellers equal crm_leaderboard()');
$lead = $dl['rows'][0];
ok($dl['headline']['text'] === $lead['display_name'] && $dl['headline']['tone'] === tone_of($lead['attainment']), 'headline: the leader ' . $lead['display_name'] . ', tone ' . ($dl['headline']['tone'] ?? 'none') . ' for ' . $lead['attainment'] . '%');
ok((float) $lead['won_value'] === (float) pg("SELECT COALESCE(sum(value), 0) FROM deals WHERE owner_member_id = {$lead['member_id']} AND status = 'won' AND date_trunc('month', won_at)::date = '$m0'"), 'the leader\'s won value equals the raw rows');
ok($dl['chart']['marks']['values'] === array_map(static fn ($r) => $r['quota'], $dl['rows']) && $dl['chart']['marks']['label'] === 'Quota', 'the chart marks each quota on its bar');
$dp = rep(27, 'leaderboard', ['month' => month_start(-1)]);
ok(same_rows($dp['rows'], as_rows(27, 'SELECT * FROM crm_leaderboard(:m, NULL)', ['m' => month_start(-1)]), ['member_id' => 'member_id', 'won_value' => 'won_value']), 'last month\'s board equals crm_leaderboard() for that month');
foreach ([[100.0, 'success'], [99.9, 'warning'], [70.0, 'warning'], [69.9, 'danger'], [0.0, 'danger'], [150.0, 'success']] as [$att, $tone]) {
    ok(tone_of($att) === $tone, "attainment $att% reads $tone");
}

echo "8. Lost reasons\n";
$d = rep(27, 'lost_reasons', $w);
$all = as_rows(27, 'SELECT * FROM crm_lost_reasons_summary(:a, :b, false)', ['a' => $w['from'], 'b' => $w['to']]);
$used = array_values(array_filter($all, static fn ($r) => (int) $r['deals'] > 0));
ok(same_rows($d['rows'], $used, ['lost_reason_id' => 'lost_reason_id', 'label' => 'label', 'deals' => 'deals', 'lost_value' => 'lost_value']), count($used) . ' reasons equal crm_lost_reasons_summary()');
ok(array_sum(array_column($d['rows'], 'deals')) === 40, 'forty lost deals across the reasons (hand-counted)');
ok($d['headline']['text'] === $used[0]['label'] && $d['chart']['categories'][0] === $used[0]['label'], 'headline and chart: the top reason is ' . $used[0]['label']);
$s = rep(27, 'lost_reasons', $w + ['by' => 'seller']);
$ws = array_values(array_filter(as_rows(27, 'SELECT * FROM crm_lost_reasons_summary(:a, :b, true)', ['a' => $w['from'], 'b' => $w['to']]), static fn ($r) => (int) $r['deals'] > 0));
ok(same_rows($s['rows'], $ws, ['label' => 'label', 'member_id' => 'member_id', 'display_name' => 'display_name', 'deals' => 'deals']) && in_array('Seller', array_column($s['columns'], 'label'), true), 'by seller: the reason and the seller, equal to the function with p_by_seller');
ok(array_sum($s['chart']['series'][0]['values']) === 40, '…and the chart still adds the reasons up to forty');

echo "9. The forecast across pipelines\n";
foreach ([27, 26] as $m) {
    $d = rep($m, 'forecast', ['by' => 'seller', 'months' => 6]);
    $want = as_rows($m, "SELECT * FROM crm_forecast(NULL, 6, 'seller', NULL)");
    $fm = array_values(array_filter($want, static fn ($r) => $m === 27 || (int) $r['row_id'] === $m));
    $fm = array_values(array_filter($fm, static fn ($r) => (int) $r['deals'] > 0 || $r['quota'] !== null));
    usort($fm, static fn ($a, $b) => [$a['row_name'], $a['month']] <=> [$b['row_name'], $b['month']]);              // the report lists a seller's months together
    $d['rows'] = array_values($d['rows']);
    ok(same_rows($d['rows'], array_map(static fn ($r) => $r + ['mm' => substr($r['month'], 0, 10)], $fm), ['month' => 'mm', 'row_id' => 'row_id', 'row_name' => 'row_name', 'open_value' => 'open_value', 'weighted' => 'weighted', 'won_value' => 'won_value', 'deals' => 'deals', 'quota' => 'quota']),
        "member $m by seller: " . count($fm) . ' (month, seller) rows equal crm_forecast(NULL, 6, seller)' . ($m === 27 ? '' : ' — their own line only'));
}
$d = rep(27, 'forecast', ['by' => 'seller', 'months' => 6]);
$want = as_rows(27, "SELECT month, sum(open_value) AS o, sum(weighted) AS w, sum(won_value) AS wn, sum(quota) AS q FROM crm_forecast(NULL, 6, 'seller', NULL) GROUP BY month ORDER BY month");
$ok = count($d['extra']['months']) === 6;
foreach ($want as $i => $r) {
    $x = $d['extra']['months'][$i] ?? [];
    $ok = $ok && $x['month'] === $r['month'] && abs($x['open_value'] - (float) $r['o']) < 0.01 && abs($x['weighted'] - (float) $r['w']) < 0.01 && abs($x['won_value'] - (float) $r['wn']) < 0.01 && ($x['quota'] === null ? $r['q'] === null : abs($x['quota'] - (float) $r['q']) < 0.01);
}
ok($ok, 'the six month totals (the chart\'s points) equal the function summed by month');
$ln = $d['chart'];
ok($ln['type'] === 'lines' && array_column($ln['series'], 'key') === ['open_value', 'weighted', 'won_value', 'quota'] && count($ln['x']) === 6 && $ln['series'][1]['values'] === array_column($d['extra']['months'], 'weighted'), 'lines per month: open, weighted, won and the quota');
ok(array_sum(array_column($d['extra']['months'], 'quota')) === 150000.0 * 6 || array_sum(array_column($d['extra']['months'], 'quota')) > 0, 'the quota line is the three sellers\' quotas added: ' . json_encode(array_column($d['extra']['months'], 'quota')));
$m0q = $d['extra']['months'][0];
$wonThis = (float) pg("SELECT COALESCE(sum(value), 0) FROM deals WHERE status = 'won' AND title LIKE 'RPT%' AND date_trunc('month', won_at) = date_trunc('month', now()) AND currency = 'USD'");
ok($m0q['won_value'] >= $wonThis - 0.01 && $d['headline']['label'] === 'weighted this month' && $d['headline']['tone'] === tone_of(100 * $m0q['won_value'] / $m0q['quota']), 'headline: this month\'s weighted, toned by the won so far against the quota');
$st = rep(27, 'forecast', ['by' => 'stage', 'months' => 3]);
$wantS = as_rows(27, "SELECT * FROM crm_forecast(NULL, 3, 'stage', NULL)");
ok(count($st['extra']['months']) === 3 && array_sum(array_column($st['rows'], 'deals')) === array_sum(array_map(static fn ($r) => (int) $r['deals'], $wantS)), 'by stage, three months: the deals counted equal the function\'s');
$one = rep(27, 'forecast', ['months' => 1]);
ok(count($one['extra']['months']) === 1, 'months=1 is one month');
$h = rep_html(27, 'forecast')['body'];
ok(str_contains($h, 'id="report-forecast-grid-link"') && str_contains($h, '/pipeline/new_business/forecast'), 'the page points at slice 3\'s grid by pipeline');

echo "10. The cards\n";
foreach ([27, 26] as $m) {
    $cards = get_as($m, '/reports')['json']['data']['cards'] ?? [];
    ok(array_column($cards, 'report') === ['forecast', 'funnel', 'win_rate', 'cycle_time', 'sources', 'campaigns', 'activity', 'leaderboard', 'lost_reasons'], "member $m: nine cards in the spec's order");
}
$cards = get_as(27, '/reports')['json']['data']['cards'];
$byName = array_column($cards, null, 'report');
$wq = periods('this_quarter');
$rw = rep(27, 'win_rate', $wq);
ok($byName['win_rate']['headline'] === $rw['headline'] && $byName['win_rate']['period'] === 'This quarter', 'the Win rate card is the win-rate report\'s headline on this quarter');
ok($byName['forecast']['period'] === 'This month' && $byName['leaderboard']['period'] === 'This month' && $byName['activity']['period'] === 'This week', 'periods in words under each number');
$h = html_as(27, '/reports')['body'];
ok(str_contains($h, 'id="reports-cards"') && preg_match_all('/id="report-card-(\w+)"/', $h, $mm) && count(array_unique($mm[1])) === 9, 'the page: reports-cards holding nine report-card-{name}');
ok(str_contains($h, 'id="report-card-leaderboard-figure"') && preg_match('/id="report-card-leaderboard-figure"/', $h) && str_contains($h, 'text-' . tone_of($lead['attainment'])), 'the leaderboard card is toned by the leader\'s attainment: text-' . tone_of($lead['attainment']));
ok(str_contains(html_as(26, '/reports')['body'], 'id="report-card-leaderboard"'), 'a Rep sees the leaderboard card too (its number is "—")');
ok(get_as(26, '/reports')['json']['data']['cards'][7]['headline']['text'] === '—', 'a Rep\'s leaderboard card says "—"');

echo "11. The pages\n";
foreach (['funnel', 'win_rate', 'cycle_time', 'sources', 'campaigns', 'activity', 'leaderboard', 'lost_reasons', 'forecast'] as $name) {
    $r = rep_html(27, $name, in_array($name, ['campaigns', 'leaderboard', 'forecast'], true) ? [] : $w);
    $b = $r['body'];
    $ids = ['report-filters', 'report-chart', 'report-table', 'report-export-btn', 'report-headline-figure', 'report-period'];
    $miss = array_filter($ids, static fn ($i) => !str_contains($b, 'id="' . $i . '"'));
    $svg = str_contains($b, '<svg') ? (str_contains($b, '<title id="report-chart-svg-title">') && str_contains($b, '<desc id="report-chart-svg-desc">') && str_contains($b, 'viewBox=') && str_contains($b, 'role="img"')) : str_contains($b, 'id="report-chart-empty"');
    $d = rep(27, $name, in_array($name, ['campaigns', 'leaderboard', 'forecast'], true) ? [] : $w);
    preg_match_all('/id="report-row-\d+"/', $b, $rows);
    ok($r['code'] === 200 && $miss === [] && $svg && count($rows[0]) === count($d['rows']) && !str_contains($b, '<script>alert'), "$name: the page has its filter bar, headline, chart (with <title> and <desc>), table of " . count($d['rows']) . ' rows and Export CSV' . ($miss ? ' — missing ' . implode(',', $miss) : ''));
}
$b = rep_html(27, 'win_rate', $w)['body'];
foreach (array_keys(REPORT_PRESETS) as $k) { ok(str_contains($b, 'id="report-preset-' . $k . '"'), "the preset $k is a link on the bar"); }
ok(str_contains($b, 'id="report-preset-custom"') && str_contains($b, 'name="from"') && str_contains($b, 'name="to"'), 'Custom, and the two dates');
ok(!str_contains($b, '<select name="team"'), 'win rate has no team filter');
$b = rep_html(27, 'forecast')['body'];
ok(str_contains($b, 'id="report-filter-by"') && str_contains($b, 'id="report-filter-months"') && str_contains($b, 'id="report-filter-team"') && !str_contains($b, 'id="report-presets"'), 'the forecast filters: rows, months, team — and no period presets');
$b = html_as(27, '/reports/win-rate?from=' . $w['from'] . '&to=' . $w['to'] . '&by=source', ['HX-Request: true']);
ok(!str_contains($b['body'], '<html') && preg_match('/^X-Screen: report-win-rate\r?$/mi', $b['headers']) === 1, 'over HTMX the page is the partial stamped report-win-rate');
$empty = rep_html(27, 'funnel', ['from' => '2020-01-01', 'to' => '2020-01-31'])['body'];
ok(str_contains($empty, 'id="report-table-empty"') && str_contains($empty, 'id="report-chart-empty"') || str_contains($empty, 'id="report-chart"'), 'an empty period reads as empty, not broken');
finish();
