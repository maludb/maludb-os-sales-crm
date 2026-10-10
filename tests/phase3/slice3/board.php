<?php
/** Proof — the board (pipeline-board.md §7): the columns and their numbers, the four colours, rotting, the WIP warning, the flag, the moves (the menu's
 * select, a refusal that keeps the card, the poll after another seller's move), the closed columns, the filters, the list and the forecast. */
require __DIR__ . '/lib.php';
$x = run_tag();
as_member(27);
$q = 'q=' . rawurlencode("B$x");
echo "1. The columns and their numbers\n";
$d1 = deal_make(26, ['title' => "B$x one", 'value' => 1000, 'stage' => 'Qualification']);
$d2 = deal_make(26, ['title' => "B$x two", 'value' => 2000, 'stage' => 'Discovery']);
$d3 = deal_make(27, ['title' => "B$x three", 'value' => 3000, 'stage' => 'Discovery']);
$d4 = deal_make(30, ['title' => "B$x four", 'value' => 4000, 'stage' => 'Negotiation']);
$b = get_as(27, '/pipeline/new_business/board')['json']['data'];
ok(array_map(static fn ($s) => $s['name'], $b['stages']) === ['Qualification', 'Discovery', 'Proposal', 'Negotiation'] && $b['closed']['won']['name'] === 'Won' && $b['closed']['lost']['name'] === 'Lost',
    'every open stage in order, then Won and Lost');
$sum = [];
foreach (q('SELECT stage_id, deals, value, weighted FROM crm_pipeline_summary(1)') as $s) { $sum[(int) $s['stage_id']] = $s; }
$same = true;
foreach ($b['stages'] as $s) { $t = $sum[$s['stage_id']]; $same = $same && $s['count'] === (int) $t['deals'] && abs($s['value'] - (float) $t['value']) < 0.01 && abs($s['weighted'] - (float) $t['weighted']) < 0.01; }
ok($same, 'with no filter, each head is crm_pipeline_summary()\'s count, value and weighted');
$f = get_as(27, "/pipeline/new_business/board?$q")['json']['data'];
ok(head_of($f, stage('Discovery'))['count'] === 2 && head_of($f, stage('Discovery'))['value'] == 5000 && head_of($f, stage('Discovery'))['weighted'] == 1250, 'filtered, a head counts what is left: Discovery 2, $5,000, $1,250 weighted (25%)');
$h = columns_html(27, $q);
ok(cards_in($h, stage('Discovery')) === [$d2, $d3] || cards_in($h, stage('Discovery')) === [$d3, $d2], 'the cards sit in their stage\'s column');
ok(str_contains($h, 'id="board-col-' . stage('Won') . '-month"') && str_contains($h, 'Drop a deal here to mark it won'), 'Won and Lost are drop targets with the month\'s totals');

echo "2. The card\n";
next_step($d1, 26, '-2 days');
next_step($d2, 26, 'today');
next_step($d3, 27, '+3 days');
$b = get_as(27, "/pipeline/new_business/board?$q")['json']['data'];
$cards = [];
foreach ($b['stages'] as $s) { foreach ($s['deals'] as $c) { $cards[$c['deal_id']] = $c; } }
ok($cards[$d1]['next_colour'] === 'overdue' && $cards[$d2]['next_colour'] === 'today' && $cards[$d3]['next_colour'] === 'scheduled' && $cards[$d4]['next_colour'] === 'none', 'the four colours: overdue, today, scheduled, none');
$h = columns_html(27, $q);
ok(str_contains($h, 'id="deal-card-' . $d1 . '-next"') && preg_match('/class="next-chip next-overdue" id="deal-card-' . $d1 . '-next"/', $h) === 1 && preg_match('/class="next-chip next-none" id="deal-card-' . $d4 . '-next"[^>]*>No next step/', $h) === 1,
    'the card draws them: the chip\'s class and "No next step"');
pg("UPDATE deals SET stage_entered_at = now() - interval '40 days' WHERE id = $d4");
pg("UPDATE stages SET rotting_days = 30 WHERE id = " . stage('Negotiation'));                // a stage's own rotting days (none are set by the seed)
pg("UPDATE stages SET wip_limit = 1 WHERE id = " . stage('Discovery'));
$b = get_as(27, "/pipeline/new_business/board?$q")['json']['data'];
$h = columns_html(27, $q);
ok(head_of($b, stage('Negotiation'))['rotting'] === 1 && str_contains($h, 'id="deal-card-' . $d4 . '-rotting"'), 'a deal 40 days in its stage rots: the head counts it, the card shows the hourglass');
ok(head_of($b, stage('Discovery'))['over_wip'] === true && str_contains($h, 'id="board-col-' . stage('Discovery') . '-wip"'), 'more deals than the WIP limit: the warning (a warning, never a refusal)');
pg("UPDATE stages SET wip_limit = NULL WHERE id = " . stage('Discovery'));
pg("UPDATE stages SET rotting_days = NULL WHERE id = " . stage('Negotiation'));
$bare = deal_make(26, ['title' => "B$x bare", 'stage' => 'Qualification', 'primary_contact' => '', 'expected_close_date' => '']);
$h = columns_html(27, $q);
$b = get_as(27, "/pipeline/new_business/board?$q")['json']['data'];
$card = array_values(array_filter(head_of($b, stage('Qualification'))['deals'], static fn ($c) => $c['deal_id'] === $bare))[0] ?? [];
ok(($card['missing_next'] ?? null) === ['primary_contact_id'] && preg_match('/id="deal-card-' . $bare . '-missing" title="The next stage needs: primary contact"/', $h) === 1, 'the flag: the next stage (Discovery) needs a primary contact this deal lacks');

echo "3. The moves\n";
$since = last_activity_id();
$r = post_as(26, "/deals/$d1/move", ['deal' => $d1, 'to_stage' => stage('Discovery'), 'position' => 0]);
ok($r['code'] === 200 && ($r['json']['did'] ?? '') === "Moved B$x one to Discovery", 'deal_move: "Moved B… one to Discovery"');
$a = activity('deal.move', $since)[0] ?? null;
$p = json_decode((string) ($a['after'] ?? '{}'), true);
ok($a !== null && $p['from_stage'] === stage('Qualification') && $p['to_stage'] === stage('Discovery') && $p['stage_name'] === 'Discovery' && is_int($p['seconds_in_stage']) && $p['position'] === 0 && (int) $a['deal_id'] === $d1,
    'deal.move logged with {from_stage, to_stage, stage_name, seconds_in_stage, position} and the deal');
ok(cards_in(columns_html(27, $q), stage('Discovery'))[0] === $d1, 'position 0: the top of the column; the others shifted');
[$j, $t] = actor(26);
$r = req('POST', "/deals/$d1/move", ['jar' => $j, 'headers' => ['HX-Request: true', 'X-CSRF-Token: ' . $t], 'form' => ['deal' => $d1, 'to_stage' => stage('Proposal'), 'on' => 'board', 'board_query' => $q]]);
ok($r['code'] === 200 && str_starts_with(ltrim($r['body']), '<div id="board-columns"') && in_array($d1, cards_in($r['body'], stage('Proposal')), true), 'the menu\'s select over HTMX: the answer is the columns region, the card in Proposal');
$r = req('POST', "/deals/$bare/move", ['jar' => $j, 'headers' => ['HX-Request: true', 'X-CSRF-Token: ' . $t], 'form' => ['deal' => $bare, 'to_stage' => stage('Proposal'), 'on' => 'board', 'board_query' => $q]]);
ok($r['code'] === 422 && str_contains($r['body'], 'Proposal needs: primary contact, value, expected close date.') || $r['code'] === 422 && str_contains($r['body'], 'Proposal needs: primary contact, expected close date.'), 'a stage the deal may not enter: 422 naming the fields');
ok(in_array($bare, cards_in(columns_html(27, $q), stage('Qualification')), true), '…and the card stays where it was (the region re-renders from the server)');
$r = post_as(26, "/deals/$bare/move", ['deal' => $bare, 'to_stage' => 'Proposal']);
ok($r['code'] === 422 && isset($r['json']['error']['fields']['primary_contact'], $r['json']['error']['fields']['expected_close_date']), 'in JSON the refusal names the form\'s fields: primary_contact, expected_close_date');
$r = post_as(26, "/deals/$d1/move", ['deal' => $d1, 'to_stage' => stage('Agreed', 2)]);
ok($r['code'] === 422 && field_of($r) === 'to_stage', 'another pipeline\'s stage is refused');
$r = post_as(26, "/deals/$d4/move", ['deal' => $d4, 'to_stage' => 'Proposal']);
ok($r['code'] === 403 && str_contains(message_of($r), 'SMOKE Dana, the owner'), 'a Rep moves only their own (or an unowned) deal: Dana\'s is refused naming her');
$before = cards_in(columns_html(26, $q), stage('Negotiation'));
post_as(30, "/deals/$d4/move", ['deal' => $d4, 'to_stage' => 'Proposal']);
$after = columns_html(26, $q);
ok(in_array($d4, $before, true) && in_array($d4, cards_in($after, stage('Proposal')), true), 'the poll: after another seller\'s move, the next render shows it');
$r = post_as(26, "/deals/$d2/move", ['deal' => $d2, 'to_stage' => 'Lost']);
ok($r['code'] === 422 && str_contains(message_of($r), 'needs a reason'), 'a move straight to Lost without a reason is refused (the Lost form asks for it)');

echo "4. The filters\n";
$ids = static fn (array $b) => array_merge(...array_map(static fn ($s) => array_map(static fn ($c) => $c['deal_id'], $s['deals']), $b['stages']));
$mine = $ids(get_as(26, "/pipeline/new_business/board?$q&owner=me")['json']['data']);
ok(!in_array($d4, $mine, true) && !in_array($d3, $mine, true) && in_array($d1, $mine, true), 'owner=me: Priya\'s deals only');
$none = $ids(get_as(27, "/pipeline/new_business/board?$q&no_next_step=1")['json']['data']);
ok(in_array($d4, $none, true) && !in_array($d1, $none, true), 'no_next_step: the deals with none');
$rot = $ids(get_as(27, "/pipeline/new_business/board?$q&rotting=1")['json']['data']);
ok($rot === [] || !in_array($d1, $rot, true), 'rotting: none of the fresh ones');
$team = $ids(get_as(27, "/pipeline/new_business/board?$q&team=8")['json']['data']);
ok($team === [$d4], 'team (Partnerships): Dana\'s deal alone');
$val = $ids(get_as(27, "/pipeline/new_business/board?$q&min_value=2500&max_value=3500")['json']['data']);
ok($val === [$d3], 'a value range: 2,500 to 3,500 → the $3,000 deal');

echo "5. The list and the forecast\n";
$l = get_as(27, "/pipeline/new_business/list?$q&sort=value")['json']['data'];
ok($l['total'] === 5 && $l['deals'][0]['value'] >= $l['deals'][1]['value'] && $l['group'] === 'stage', 'the list: the same five deals, sorted by value, grouped by stage');
$h = html_as(27, "/pipeline/new_business/list?$q&group=owner")['body'];
ok(str_contains($h, 'SMOKE Dana') && str_contains($h, 'id="deal-list-row-' . $d4 . '"'), 'grouped by owner');
pg("INSERT INTO quotas (member_id, month, amount) VALUES (26, date_trunc('month', now())::date, 50000) ON CONFLICT (member_id, month) DO UPDATE SET amount = 50000");
$fc = get_as(27, '/pipeline/new_business/forecast?months=3')['json']['data']['forecast'];
$priya = array_values(array_filter($fc['rows'], static fn ($r) => $r['id'] === 26))[0] ?? null;
$m0 = date('Y-m');
$want = q("SELECT COALESCE(sum(value), 0) AS v, COALESCE(sum(weighted_value), 0) AS w FROM deals WHERE owner_member_id = 26 AND pipeline_id = 1 AND status = 'open' AND deleted_at IS NULL AND currency = 'USD' AND date_trunc('month', expected_close_date) = date_trunc('month', now())")[0];
ok($priya !== null && abs($priya['cells'][$m0]['open'] - (float) $want['v']) < 0.01 && abs($priya['cells'][$m0]['weighted'] - (float) $want['w']) < 0.01, 'the forecast\'s cell sums Priya\'s deals closing this month (value and weighted)');
ok($priya['quota'][$m0] == 50000, 'the quota line under the seller (the manager sees it)');
$fr = get_as(30, '/pipeline/new_business/forecast?months=3')['json']['data']['forecast'];
$pr = array_values(array_filter($fr['rows'], static fn ($r) => $r['id'] === 26))[0] ?? null;
ok($pr !== null && $pr['quota'][$m0] === null, 'another Rep does not see Priya\'s quota');
$cell = get_as(27, "/pipeline/new_business/list?owner=26&status=all&close_month=$m0")['json']['data'];
ok(abs(array_sum(array_map(static fn ($d) => $d['status'] === 'open' ? $d['value'] : 0, $cell['deals'])) - (float) $want['v']) < 0.01, 'a forecast cell opens the list of exactly those deals');
ok(get_as(27, '/pipeline/renewals/board')['code'] === 200 && get_as(27, '/pipeline/2/board')['json']['data']['pipeline']['key'] === 'renewals' && get_as(27, '/pipeline/nope/board')['code'] === 404, 'a pipeline by key or id; an unknown one is 404');
finish();
