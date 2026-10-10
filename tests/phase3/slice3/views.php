<?php
/** Proof — saved views: one saved from the board's filters re-runs them; its columns lay out the list; shared only by a manager; mine alone to change; the
 * built-in ones stay; another's personal view does not exist to me. */
require __DIR__ . '/lib.php';
$x = run_tag();
as_member(27);
$mine = deal_make(26, ['title' => "View $x mine", 'stage' => 'Discovery']);
$other = deal_make(30, ['title' => "View $x other", 'stage' => 'Discovery']);
echo "1. Saving and running\n";
$since = last_activity_id();
$r = post_as(26, '/views/save.php', ['name' => "Mine $x", 'record_kind' => 'deal', 'filters' => json_encode(['owner' => 'me', 'q' => "View $x"]), 'layout' => 'list', 'columns' => ['number', 'title', 'value', 'owner'], 'pipeline' => 1]);
$v = (int) ($r['json']['record_id'] ?? 0);
ok($r['code'] === 200 && $v > 0 && str_starts_with($r['json']['location'], "/pipeline/new_business/list?view=$v"), 'saved: it lands on the list run with the view');
$a = activity('view.save', $since)[0] ?? null;
ok($a !== null && json_decode($a['after'], true)['filters']['owner'] === 'me', 'view.save logged with the filters');
$l = get_as(26, "/pipeline/new_business/list?view=$v")['json']['data'];
ok(array_map(static fn ($d) => $d['deal_id'], $l['deals']) === [$mine] && $l['columns'] === ['number', 'title', 'value', 'owner'], 'run: its filters (mine, the words) and its columns');
$h = html_as(26, "/pipeline/new_business/list?view=$v")['body'];
ok(str_contains($h, '>Number<') && !str_contains($h, '>Account<'), 'the table shows the view\'s columns only');
$b = get_as(26, "/pipeline/new_business/board?view=$v")['json']['data'];
ok(array_merge(...array_map(static fn ($s) => array_map(static fn ($c) => $c['deal_id'], $s['deals']), $b['stages'])) === [$mine], 'the board takes the same view');
$b = get_as(26, "/pipeline/new_business/board?view=$v&owner=" . 30)['json']['data'];
ok(array_merge(...array_map(static fn ($s) => array_map(static fn ($c) => $c['deal_id'], $s['deals']), $b['stages'])) === [$other], 'a filter in the request wins over the view\'s');
$sys = (int) one("SELECT id FROM saved_views WHERE system_key = 'no_next_step'");
$n = get_as(26, "/pipeline/new_business/list?view=$sys&q=" . rawurlencode("View $x"))['json']['data'];
ok($n['sort'] === 'entered' && count($n['deals']) === 2, 'a built-in view (No next step) runs with its sort');

echo "2. Who may\n";
ok(field_of(post_as(26, '/views/save.php', ['name' => "Shared $x", 'record_kind' => 'deal', 'scope' => 'global'])) === 'scope', 'a Rep may not share a view with everyone');
$g = (int) post_as(27, '/views/save.php', ['name' => "Team $x", 'record_kind' => 'deal', 'scope' => 'global', 'filters' => json_encode(['close' => 'this_month']), 'layout' => 'board'])['json']['record_id'];
ok($g > 0 && in_array($g, array_map(static fn ($w) => $w['saved_view_id'], get_as(30, '/views')['json']['data']['views']), true), 'a manager\'s shared view: everyone sees it');
$views30 = array_map(static fn ($w) => $w['saved_view_id'], get_as(30, '/views')['json']['data']['views']);
ok(!in_array($v, $views30, true) && post_as(30, '/views/delete.php', ['saved_view' => $v])['code'] === 404, 'Priya\'s personal view does not exist to Dana');
ok(post_as(26, '/views/delete.php', ['saved_view' => $g])['code'] === 403 && post_as(27, '/views/delete.php', ['saved_view' => $sys])['code'] === 403, 'a Rep may not delete a shared view; nobody deletes a built-in one');
$r = post_as(26, '/views/save.php', ['saved_view' => $v, 'name' => "Mine renamed $x"]);
ok($r['code'] === 200 && one('SELECT name FROM saved_views WHERE id = :v', ['v' => $v]) === "Mine renamed $x" && json_decode((string) one('SELECT filters FROM saved_views WHERE id = :v', ['v' => $v]), true)['owner'] === 'me', 'renamed: what was not sent stays (the filters)');
$h = html_as(26, '/views')['body'];
ok(str_contains($h, 'id="saved-view-' . $v . '-run"') && str_contains($h, 'Owner: mine') && str_contains($h, 'id="saved-view-' . $v . '-delete"') && !str_contains($h, 'id="saved-view-' . $sys . '-delete"'), 'the views page: Run, the filters as chips, Delete on mine only');
$since = last_activity_id();
ok(post_as(26, '/views/delete.php', ['saved_view' => $v])['code'] === 200 && activity('view.delete', $since) !== [] && get_as(26, "/pipeline/new_business/list?view=$v")['json']['data']['total'] > 0, 'deleted (logged); running it now runs no filter');
ok(field_of(post_as(26, '/views/save.php', ['name' => 'x', 'record_kind' => 'invoice'])) === 'record_kind' && field_of(post_as(26, '/views/save.php', ['name' => 'x', 'record_kind' => 'deal', 'filters' => '[1,2]'])) === 'filters', 'the kind and the filters are checked');
finish();
