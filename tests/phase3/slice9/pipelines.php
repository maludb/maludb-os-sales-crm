<?php
/**
 * Proof — pipelines and stages (admin-settings.md "Proof"): a pipeline added with its stages, reordered by the handler (and by the page's Move up/Move down buttons without JavaScript, and over HTMX with the list as the answer), a stage's
 * required fields chosen from the deal's columns and a custom key and REFUSING A MOVE IN SLICE 3's BOARD HANDLER, a probability change reaching the open deals, archive (deals keep it; open deals and the default refuse it) and delete (refused with deals),
 * the default moved as two facts, the rights.
 */
require __DIR__ . '/lib.php';
$x = run_tag();
$since = last_activity_id();

echo "1. A pipeline with stages\n";
$r = adm('/admin/pipelines/save.php', ['name' => "P9 Partners $x", 'currency' => 'eur', 'rotting_days' => 20]);
$p = (int) ($r['json']['record_id'] ?? 0);
$row = q('SELECT * FROM pipelines WHERE id = :i', ['i' => $p])[0] ?? [];
ok($r['code'] === 200 && ($r['json']['ok'] ?? false) === true && $p > 0 && $row['currency'] === 'EUR' && (int) $row['rotting_days'] === 20 && $row['is_default'] === false, 'pipeline_save adds a pipeline: ' . ($r['json']['did'] ?? $r['body']));
ok($row['key'] === pipeline_key_from_test("P9 Partners $x"), 'its key was made from the name: ' . $row['key']);
$ks = q("SELECT kind, probability, name FROM stages WHERE pipeline_id = :p ORDER BY kind", ['p' => $p]);
ok(array_column($ks, 'kind') === ['lost', 'won'] && (int) $ks[0]['probability'] === 0 && (int) $ks[1]['probability'] === 100, 'a new pipeline has its Won (100) and Lost (0) stages — a deal can close in it from the first day');
$dup = adm('/admin/pipelines/save.php', ['name' => "Another $x", 'key' => $row['key']]);
ok($dup['code'] === 422 && str_contains(says($dup), 'already has the key'), 'a key another pipeline has is refused: ' . message_of($dup));
$bad = adm('/admin/pipelines/save.php', ['name' => "Bad $x", 'key' => 'Not A Slug', 'currency' => 'EURO', 'rotting_days' => 999]);
ok($bad['code'] === 422 && isset($bad['json']['error']['fields']['key'], $bad['json']['error']['fields']['currency'], $bad['json']['error']['fields']['rotting_days']), 'a bad key, currency and rotting days: 422, each field named');
$a = stage_made($p, "A $x", ['probability' => 20]);
$b = stage_made($p, "B $x", ['probability' => 40, 'rotting_days' => 7, 'wip_limit' => 5, 'colour' => '#AA00BB', 'ask_assistant' => 'yes']);
$c = stage_made($p, "C $x", ['probability' => 60]);
$srow = q('SELECT * FROM stages WHERE id = :i', ['i' => $b])[0];
ok(stage_order($p) === [$a, $b, $c] && (int) q('SELECT position FROM stages WHERE id = :i', ['i' => $a])[0]['position'] === 10 && (int) q('SELECT position FROM stages WHERE id = :i', ['i' => $c])[0]['position'] === 30, 'three stages, in the order made (10, 20, 30)');
ok((int) $srow['rotting_days'] === 7 && (int) $srow['wip_limit'] === 5 && $srow['colour'] === '#aa00bb' && $srow['ask_assistant'] === true, 'a stage keeps its rotting days, WIP limit, colour (lower-cased) and the Assistant flag');
$r = adm('/admin/stages/save.php', ['pipeline' => $p, 'name' => "a $x"]);
ok($r['code'] === 422 && str_contains(says($r), 'is called'), 'a second stage with the same name (any case) is refused: ' . message_of($r));
$r = adm('/admin/stages/save.php', ['pipeline' => $p, 'name' => "Won again $x", 'kind' => 'won']);
ok($r['code'] === 422 && str_contains(says($r), 'its won stage already'), 'a second Won stage is refused: ' . message_of($r));
$r = adm('/admin/stages/save.php', ['pipeline' => $p, 'name' => "Odd $x", 'probability' => 'lots']);
ok($r['code'] === 422 && field_of($r) === 'probability', 'a probability that is no number names its field');
$r = adm('/admin/stages/save.php', ['pipeline' => $p, 'name' => "Bad colour $x", 'colour' => 'red']);
ok($r['code'] === 422 && field_of($r) === 'colour', 'a colour that is no hex names its field');

echo "2. Reorder — the handler, HTMX, and the buttons without scripts\n";
$r = adm('/admin/stages/reorder.php', ['pipeline' => $p, 'stages' => [$c, $a, $b]]);
ok($r['code'] === 200 && stage_order($p) === [$c, $a, $b] && (int) q('SELECT position FROM stages WHERE id = :i', ['i' => $c])[0]['position'] === 10, 'stage_reorder puts the open stages in the order given');
$r = adm('/admin/stages/reorder.php', ['pipeline' => $p, 'stages' => implode(',', [$a, $b, $c])]);
ok($r['code'] === 200 && stage_order($p) === [$a, $b, $c], 'a comma-separated list does as well (an agent\'s way)');
$r = adm('/admin/stages/reorder.php', ['pipeline' => $p, 'stages' => [$a, $b]]);
ok($r['code'] === 422 && str_contains(says($r), 'every open stage') && stage_order($p) === [$a, $b, $c], 'a list that leaves a stage out is refused and nothing moved');
$other = (int) one("SELECT id FROM stages WHERE pipeline_id = 1 AND kind = 'open' LIMIT 1");
$r = adm('/admin/stages/reorder.php', ['pipeline' => $p, 'stages' => [$a, $b, $other]]);
ok($r['code'] === 422 && stage_order($p) === [$a, $b, $c], 'another pipeline\'s stage in the list is refused');
$r = adm('/admin/stages/reorder.php', ['pipeline' => $p, 'stages' => [$a, $b, $c, (int) one("SELECT id FROM stages WHERE pipeline_id = :p AND kind = 'won'", ['p' => $p])]]);
ok($r['code'] === 422, 'Won in the list is refused: only the open stages are ordered');
[$j, $t] = actor(1);
$h = req('POST', '/admin/stages/reorder.php', ['jar' => $j, 'headers' => ['HX-Request: true', 'X-CSRF-Token: ' . $t], 'form' => ['pipeline' => $p, 'stages' => implode(',', [$b, $c, $a]), 'on' => 'list']]);
$ids = preg_match_all('/id="stage-row-(\d+)"/', $h['body'], $m) ? array_map('intval', $m[1]) : [];
ok($h['code'] === 200 && str_contains($h['body'], 'id="stage-list-' . $p . '"') && !str_contains($h['body'], '<html') && array_slice($ids, 0, 3) === [$b, $c, $a], 'over HTMX from the screen the answer is that pipeline\'s stage list, re-rendered in the new order: ' . implode(',', $ids));
$page = html_as(1, '/admin/pipelines')['body'];
ok(str_contains($page, 'id="stage-' . $b . '-down-btn"') && str_contains($page, 'id="stage-' . $c . '-up-btn"') && !str_contains($page, 'id="stage-' . $b . '-up-btn"'), 'the page offers Move up and Move down on each open stage, none off the ends');
$r = adm('/admin/stages/reorder.php', ['pipeline' => $p, 'stages' => implode(',', [$c, $b, $a])]);
ok($r['code'] === 200 && stage_order($p) === [$c, $b, $a], 'the button posts the swapped list and the order follows');
$nojs = req('POST', '/admin/stages/reorder.php', ['jar' => $j, 'form' => ['csrf_token' => $t, 'pipeline' => $p, 'stages' => implode(',', [$a, $b, $c]), 'return_to' => '/admin/pipelines']]);
ok(in_array($nojs['code'], [302, 303], true) && str_contains($nojs['location'], '/admin/pipelines') && stage_order($p) === [$a, $b, $c], 'without scripts a button\'s form redirects back to the pipelines');
ok(file_exists(dirname(__DIR__, 3) . '/html/assets/js/stage-order.js') && str_contains(html_as(1, '/admin/pipelines')['body'], '/assets/js/stage-order.js') && str_contains($page, 'data-stage-sortable'), 'stage-order.js is loaded by the layout and the lists carry data-stage-sortable (the drag is an enhancement)');

echo "3. A required field refuses a move in the board's handler\n";
$cf = adm('/admin/fields/save.php', ['applies_to' => 'deal', 'key' => "needs9_$x", 'label' => "Needs9 $x", 'field_type' => 'text']);
ok($cf['code'] === 200, 'a deal custom field to require');
$r = adm('/admin/stages/save.php', ['stage' => $b, 'required_fields' => ['value', 'expected_close_date', "needs9_$x"], 'required_fields_present' => 1]);
ok($r['code'] === 200 && texts((string) one('SELECT required_fields::text FROM stages WHERE id = :i', ['i' => $b])) === ['value', 'expected_close_date', "needs9_$x"], 'required_fields[] chosen from the deal\'s columns and a custom key are stored');
$r = adm('/admin/stages/save.php', ['stage' => $b, 'required_fields' => ['value', 'nonsense_column'], 'required_fields_present' => 1]);
ok($r['code'] === 422 && str_contains(says($r), 'nonsense_column'), 'a field a deal does not have is refused by name');
$d = deal_in($p, $a, "P9 deal $x");
$m = post_as(26, "/deals/$d/move", ['to_stage' => $b]);
ok($m['code'] === 422 && str_contains(says($m), 'value') && str_contains(says($m), 'expected_close_date') && str_contains(says($m), "needs9_$x"), 'slice 3\'s deal_move refuses the move, naming the fields the admin chose: ' . message_of($m));
ok((int) one('SELECT stage_id FROM deals WHERE id = :i', ['i' => $d]) === $a, '…and the deal stayed where it was');
pg_run("UPDATE deals SET value = 500, expected_close_date = current_date + 10, custom_values = jsonb_build_object('needs9_$x', 'yes') WHERE id = $d");
$m = post_as(26, "/deals/$d/move", ['to_stage' => $b]);
ok($m['code'] === 200 && (int) one('SELECT stage_id FROM deals WHERE id = :i', ['i' => $d]) === $b, 'with the fields filled the same move goes through');
ok(html_as(26, '/deals/' . $d)['code'] === 200, 'the deal page still renders');

echo "4. A probability change reaches the open deals; a stage with deals keeps its kind\n";
$r = adm('/admin/stages/save.php', ['stage' => $b, 'probability' => 80]);
$dealRow = q('SELECT probability, weighted_value, value FROM deals WHERE id = :i', ['i' => $d])[0];
ok($r['code'] === 200 && (int) $dealRow['probability'] === 80 && (float) $dealRow['weighted_value'] === 400.0, 'raising the stage to 80% re-weights the open deal in it: 500 × 80% = 400');
pg_run("UPDATE deals SET probability_override = 25 WHERE id = $d");
adm('/admin/stages/save.php', ['stage' => $b, 'probability' => 90]);
ok((int) one('SELECT probability FROM deals WHERE id = :i', ['i' => $d]) === 25, 'a deal\'s own probability override stays');
pg_run("UPDATE deals SET probability_override = NULL WHERE id = $d");
$r = adm('/admin/stages/save.php', ['stage' => $b, 'kind' => 'won']);
ok($r['code'] === 422 && str_contains(says($r), 'stays open'), 'a stage deals sit in keeps its kind: ' . message_of($r));
$r = adm('/admin/stages/save.php', ['stage' => (int) one("SELECT id FROM stages WHERE pipeline_id = :p AND kind = 'won'", ['p' => $p]), 'probability' => 50]);
ok($r['code'] === 422 && str_contains(says($r), '100 percent'), 'Won must stay at 100: ' . message_of($r));
$won = (int) one("SELECT id FROM stages WHERE pipeline_id = :p AND kind = 'won'", ['p' => $p]);
$r = adm('/admin/stages/save.php', ['stage' => $won, 'required_fields' => ['value'], 'required_fields_present' => 1]);
ok($r['code'] === 422 && str_contains(says($r), 'asks for nothing'), 'a Won stage asks for nothing: ' . message_of($r));
$r = adm('/admin/stages/save.php', ['stage' => $won, 'ask_assistant' => 'yes']);
ok($r['code'] === 422, 'the Assistant is asked on open stages only');

echo "5. Archive and delete\n";
$r = adm('/admin/stages/save.php', ['stage' => $b, 'archived' => 'yes']);
ok($r['code'] === 422 && str_contains(says($r), 'open deal'), 'a stage holding an open deal is not archived: ' . message_of($r));
$r = adm('/admin/stages/delete.php', ['stage' => $b]);
ok($r['code'] === 422 && str_contains(says($r), 'archive it instead'), 'nor deleted — "archive it instead": ' . message_of($r));
$r = adm('/admin/pipelines/save.php', ['pipeline' => $p, 'archived' => 'yes']);
ok($r['code'] === 422 && str_contains(says($r), 'open deal'), 'a pipeline holding an open deal is not archived: ' . message_of($r));
$r = adm('/admin/pipelines/delete.php', ['pipeline' => $p]);
ok($r['code'] === 422 && str_contains(says($r), 'archive it instead'), 'a pipeline with deals is not deleted — "archive it instead": ' . message_of($r));
$empty = stage_made($p, "Empty $x");
$r = adm('/admin/stages/save.php', ['stage' => $empty, 'archived' => 'yes']);
ok($r['code'] === 200 && one('SELECT archived_at FROM stages WHERE id = :i', ['i' => $empty]) !== null, 'an empty stage is archived');
ok(!in_array($empty, stage_order($p), true) && str_contains(html_as(1, '/admin/pipelines')['body'], 'id="stage-' . $empty . '-archived"'), '…it leaves the order and the page marks it archived');
$m = post_as(26, "/deals/" . deal_in($p, $a, "P9 into archived $x") . "/move", ['to_stage' => $empty]);
ok($m['code'] === 422 && str_contains(says($m), 'archived'), 'no deal can move into an archived stage: ' . message_of($m));
$r = adm('/admin/stages/save.php', ['stage' => $empty, 'archived' => 'no']);
ok($r['code'] === 200 && one('SELECT archived_at FROM stages WHERE id = :i', ['i' => $empty]) === null, 'and it is restored');
$r = adm('/admin/stages/delete.php', ['stage' => $won]);
ok($r['code'] === 422 && str_contains(says($r), 'needs its won stage'), 'Won cannot be deleted: ' . message_of($r));
$r = adm('/admin/stages/save.php', ['stage' => $won, 'archived' => 'yes']);
ok($r['code'] === 422, 'nor archived');
$r = adm('/admin/stages/delete.php', ['stage' => $empty]);
ok($r['code'] === 200 && !exists_row('stages', $empty), 'a stage no deal ever sat in is deleted');
$used = stage_made($p, "Used $x");
$dd = deal_in($p, $used, "P9 passes $x");
post_as(26, "/deals/$dd/move", ['to_stage' => $a]);
$r = adm('/admin/stages/delete.php', ['stage' => $used]);
ok($r['code'] === 422 && str_contains(says($r), 'archive it instead'), 'a stage a deal once passed through is not deleted either (the funnel reads that history): ' . message_of($r));
// close every deal, then the pipeline may be archived
foreach (q("SELECT id FROM deals WHERE pipeline_id = :p AND status = 'open'", ['p' => $p]) as $dr) { as_member(1); pdo()->prepare('SELECT crm_deal_move(:d, :s, NULL)')->execute(['d' => $dr['id'], 's' => $won]); }
$r = adm('/admin/pipelines/save.php', ['pipeline' => $p, 'archived' => 'yes']);
ok($r['code'] === 200 && one('SELECT archived_at FROM pipelines WHERE id = :i', ['i' => $p]) !== null, 'with its deals closed the pipeline is archived');
ok((int) one('SELECT count(*) FROM deals WHERE pipeline_id = :p', ['p' => $p]) >= 3, '…the deals keep it');
$s = screen(1, '/admin/pipelines');
ok(in_array($p, array_column($s['archived'], 'pipeline_id'), true) && !in_array($p, array_column($s['pipelines'], 'pipeline_id'), true), 'the screen lists it under Archived, not among the live ones');
ok(get_as(26, '/pipeline/' . $row['key'] . '/board')['code'] === 404, 'its board is gone (404) while archived');
$r = adm('/admin/pipelines/delete.php', ['pipeline' => $p]);
ok($r['code'] === 422 && str_contains(says($r), 'archive it instead'), 'archived with deals it still cannot be deleted');
$r = adm('/admin/pipelines/save.php', ['pipeline' => $p, 'archived' => 'no']);
ok($r['code'] === 200 && one('SELECT archived_at FROM pipelines WHERE id = :i', ['i' => $p]) === null, 'restored: the board is back (' . get_as(26, '/pipeline/' . $row['key'] . '/board')['code'] . ')');
[$fresh] = pipeline_made("P9 Fresh $x");
$r = adm('/admin/pipelines/delete.php', ['pipeline' => $fresh]);
ok($r['code'] === 200 && !exists_row('pipelines', $fresh) && (int) one('SELECT count(*) FROM stages WHERE pipeline_id = :p', ['p' => $fresh]) === 0, 'a pipeline no deal was ever in is deleted with its stages');
$r = adm('/admin/pipelines/delete.php', ['pipeline' => 1]);
ok($r['code'] === 422, 'the default pipeline is not deleted');
$r = adm('/admin/pipelines/save.php', ['pipeline' => 1, 'archived' => 'yes']);
ok($r['code'] === 422 && str_contains(says($r), 'default'), 'nor archived: ' . message_of($r));
$r = adm('/admin/pipelines/save.php', ['pipeline' => 1, 'is_default' => 'no']);
ok($r['code'] === 422 && str_contains(says($r), 'another the default'), 'nor made not-default (make another the default): ' . message_of($r));

echo "6. The default is two facts that move together\n";
$r = adm('/admin/pipelines/save.php', ['pipeline' => $p, 'is_default' => 'yes']);
ok($r['code'] === 200 && (int) one('SELECT default_pipeline_id FROM crm_settings WHERE id = 1') === $p && (int) one('SELECT count(*) FROM pipelines WHERE is_default') === 1 && one('SELECT is_default FROM pipelines WHERE id = :i', ['i' => $p]) === true, 'is_default on one pipeline: pipelines.is_default and crm_settings.default_pipeline_id both follow, and only one is default');
ok(str_contains(html_as(26, '/')['body'], '/pipeline/' . $row['key'] . '/board'), 'the menu\'s Pipeline link follows the default');
$r = adm('/admin/pipelines/save.php', ['pipeline' => 1, 'is_default' => 'yes']);
ok($r['code'] === 200 && (int) one('SELECT default_pipeline_id FROM crm_settings WHERE id = 1') === 1, 'and back: the seeded pipeline is the default again');
$r = adm('/admin/pipelines/save.php', ['pipeline' => $p, 'name' => "P9 Renamed $x", 'rotting_days' => '', 'currency' => '', 'position' => 5]);
$row2 = q('SELECT * FROM pipelines WHERE id = :i', ['i' => $p])[0];
ok($r['code'] === 200 && $row2['name'] === "P9 Renamed $x" && $row2['rotting_days'] === null && $row2['currency'] === null && (int) $row2['position'] === 5 && $row2['key'] === $row['key'], 'a name, an emptied rotting days and currency, and a position change; the key stays');

echo "7. Rights, and the log\n";
foreach ([26 => 'a Rep', 29 => 'a Viewer', 31 => 'Marketing'] as $m => $who) {
    $codes = [get_as($m, '/admin/pipelines')['code'], post_as($m, '/admin/pipelines/save.php', ['name' => 'x'])['code'], post_as($m, '/admin/stages/reorder.php', ['pipeline' => $p, 'stages' => '1'])['code']];
    ok($codes === [403, 403, 403], "$who: the screen and the handlers are 403");
}
$codes = [req('GET', '/admin/pipelines', ['headers' => as_agent(run_token(40, 9101))])['code'], agent_post(40, 9102, '/admin/pipelines/save.php', ['name' => 'x'])['code'], agent_post(40, 9103, '/admin/stages/reorder.php', ['pipeline' => $p, 'stages' => '1'])['code']];
ok($codes === [403, 403, 403], 'an agent without the right (the Expert, a Rep): the screen and the handlers are 403');
ok(get_as(27, '/admin/pipelines')['code'] === 200 && post_as(27, '/admin/pipelines/save.php', ['pipeline' => $p, 'position' => 6])['code'] === 200, 'the Sales Manager (pipelines.manage) may');
$ev = array_unique(array_column(q('SELECT action FROM activity_log WHERE id > :s', ['s' => $since]), 'action'));
foreach (['pipeline.save', 'pipeline.delete', 'stage.save', 'stage.reorder', 'stage.delete'] as $e) { ok(in_array($e, $ev, true), "$e written"); }
$lr = q("SELECT after::text AS a FROM activity_log WHERE action = 'stage.reorder' AND id > :s ORDER BY id DESC LIMIT 1", ['s' => $since])[0] ?? [];
ok(str_contains($lr['a'] ?? '', '"order"'), 'stage.reorder logs the order before and after');
finish();

function pipeline_key_from_test(string $n): string
{
    $s = strtolower(trim((string) preg_replace('/[^a-z0-9]+/i', '_', $n), '_'));
    return preg_match('/^[a-z]/', $s) ? substr($s, 0, 40) : 'p_' . $s;
}
