<?php
/** Proof — JSON mode on every screen and handler of slice 3, the HTMX stamps, and the registry: 9 screens built, 15 actions built, 1 approval in step (deal_reopen). */
require __DIR__ . '/lib.php';
$x = run_tag();
as_member(26);
$d = deal_make(26, ['title' => "Json $x"]);
$c = (int) post_as(26, '/contacts/save.php', ['first_name' => 'Jo', 'last_name' => "Son $x"])['json']['record_id'];
$line = (int) post_as(26, '/deals/products/add.php', ['deal' => $d, 'name' => 'Thing', 'unit_price' => 10])['json']['record_id'];
$view = (int) post_as(26, '/views/save.php', ['name' => "J $x", 'record_kind' => 'deal'])['json']['record_id'];

echo "1. Every screen answers JSON\n";
$screens = ['/pipeline/new_business/board' => 'stages', '/pipeline/new_business/list' => 'deals', '/pipeline/new_business/forecast' => 'forecast', "/deals/$d" => 'deal', '/deals/new' => 'values',
    "/deals/$d/edit" => 'values', "/deals/$d/won" => 'close_date', "/deals/$d/lost" => 'reasons', '/views' => 'views', '/pipeline/new_business/columns' => 'stages', '/deals' => 'deals'];
foreach ($screens as $url => $key) {
    $r = get_as(26, $url);
    ok($r['code'] === 200 && array_key_exists($key, $r['json']['data'] ?? []), "GET $url as JSON: 200 with `$key`");
}
$g = get_as(26, "/deals/$d")['json']['data']['deal'];
ok($g['title'] === "Json $x" && $g['status'] === 'open' && is_array($g['stages']) && is_array($g['history']) && is_array($g['contacts']) && count($g['products']) === 1 && array_key_exists('missing_next', $g), 'get_deal\'s shape: the stages, the history, the people, the lines, what the next stage needs');
$r = html_as(26, "/deals/$d", ['HX-Request: true']);
ok(!str_contains($r['body'], '<html') && preg_match('/^X-Screen: deal-view\r?$/mi', $r['headers']) === 1 && preg_match('/^X-Record-Id: ' . $d . '\r?$/mi', $r['headers']) === 1, 'over HTMX the deal page is the partial stamped deal-view and its id');
$r = html_as(26, '/pipeline/new_business/board', ['HX-Request: true']);
ok(preg_match('/^X-Screen: board\r?$/mi', $r['headers']) === 1 && str_contains($r['body'], 'id="board-columns"'), 'the board over HTMX: stamped board, the columns region inside');

echo "2. Every handler answers JSON\n";
$checks = [
    ['/deals/save.php', ['deal' => $d, 'description' => 'J'], 'deal_update'], ["/deals/$d/move", ['deal' => $d, 'to_stage' => 'Discovery', 'position' => 0], 'deal_move'],
    ['/deals/products/save.php', ['product_line' => $line, 'quantity' => 2], 'deal_product_update'], ['/deals/contacts/add.php', ['deal' => $d, 'contact' => $c, 'role' => 'user'], 'deal_contact_add'],
    ['/deals/contacts/save.php', ['deal' => $d, 'contact' => $c, 'role' => 'champion'], 'deal_contact_update'], ['/deals/contacts/remove.php', ['deal' => $d, 'contact' => $c], 'deal_contact_remove'],
    ['/deals/products/remove.php', ['product_line' => $line], 'deal_product_remove'], ['/deals/assign.php', ['deal' => $d, 'owner' => 26], 'deal_assign'],
    ['/views/save.php', ['saved_view' => $view, 'name' => "J2 $x"], 'saved_view_save'], ['/views/delete.php', ['saved_view' => $view], 'saved_view_delete'],
    ["/deals/$d/lost", ['deal' => $d, 'reason' => 3], 'deal_lost'],
];
foreach ($checks as [$path, $form, $name]) {
    $r = post_as(26, $path, $form);
    ok($r['code'] === 200 && ($r['json']['ok'] ?? false) === true && ($r['json']['did'] ?? '') !== '' && array_key_exists('record_id', $r['json']), "$name: {ok, did, record_id} — " . ($r['json']['did'] ?? $r['body']));
}
$r = post_as(27, '/deals/reopen.php', ['deal' => $d]);
ok($r['code'] === 200 && ($r['json']['record_id'] ?? 0) === $d, 'deal_reopen: {ok, did, record_id}');
$r = post_as(26, "/deals/$d/won", ['deal' => $d]);
ok($r['code'] === 200 && ($r['json']['record_id'] ?? 0) === $d, 'deal_won: {ok, did, record_id}');
$r = post_as(26, '/deals/save.php', ['title' => "Created $x"]);
ok($r['code'] === 200 && ($r['json']['record_id'] ?? 0) > 0, 'deal_create: the record_id');
$r = post_as(26, '/deals/products/add.php', ['deal' => $r['json']['record_id'], 'name' => 'New']);
ok($r['code'] === 200 && ($r['json']['record_id'] ?? 0) > 0, 'deal_product_add: the line\'s record_id');
$r = post_as(26, '/deals/save.php', ['value' => 3]);
ok($r['code'] === 422 && ($r['json']['error']['code'] ?? '') === 'invalid' && isset($r['json']['error']['fields']['title']), 'a refusal: 422 invalid with the fields');

echo "3. The registry\n";
exec('php ' . escapeshellarg(dirname(__DIR__, 3) . '/bin/build_action_registry.php') . ' --check 2>&1', $out, $rc);
ok($rc === 0, 'bin/build_action_registry.php --check: the registry and approvals[] match the manifest');
$reg = json_decode((string) file_get_contents(dirname(__DIR__, 3) . '/mcp/action_registry.json'), true);
$mine = ['board', 'deal-list', 'forecast', 'deal-view', 'deal-add', 'deal-edit', 'deal-won', 'deal-lost', 'saved-views'];
ok(array_filter($mine, static fn ($s) => empty($reg['screens'][$s]['built'])) === [], 'the 9 screens read as built');
$acts = ['deal_create', 'deal_update', 'deal_move', 'deal_won', 'deal_lost', 'deal_reopen', 'deal_assign', 'deal_product_add', 'deal_product_update', 'deal_product_remove', 'deal_contact_add', 'deal_contact_update', 'deal_contact_remove', 'saved_view_save', 'saved_view_delete'];
ok(array_filter($acts, static fn ($a) => empty($reg['actions'][$a]['built'])) === [], 'the 15 actions read as built');
$paused = array_values(array_filter($acts, static fn ($a) => !empty($reg['actions'][$a]['approval'] ?? null)));
ok($paused === ['deal_reopen'], '1 approval in step: ' . implode(', ', $paused));
finish();
