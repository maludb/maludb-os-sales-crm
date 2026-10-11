<?php
/**
 * Proof — lists (campaigns-lists.md "Proof"): a static list counts by trigger (add, add again, remove); who may keep it (its owner, or lists.shared; a private list is the same 404 to another seller; a shared one
 * needs lists.shared); a record behind the wall is in the list but not in what the reader sees; a smart list has no members of its own; the pages (cards for the three kinds, rows for deals), the JSON, delete.
 */
require __DIR__ . '/lib.php';
$x = run_tag();
$since = last_activity_id();
$mkt = role_actor(31, 'marketing');
$acc = (int) post_as(26, '/accounts/save.php', ['name' => "List Acct $x"])['json']['record_id'];
$c1 = contact_with("l1-$x@list-$x.example.invalid", 'Una', $acc);
$c2 = contact_with("l2-$x@list-$x.example.invalid", 'Vic', $acc);
$c3 = contact_with("l3-$x@list-$x.example.invalid", 'Wes', $acc);
$hid = (int) post_as(27, '/contacts/save.php', ['first_name' => 'Hidden', 'last_name' => "Lister $x", 'email' => "hid-$x@list.example.invalid"])['json']['record_id'];
post_as(27, '/records/restrict.php', ['kind' => 'contact', 'record' => $hid]);

echo "1. Making a static list\n";
$r = post_as(26, '/lists/save.php', ['name' => "Priya's contacts $x", 'kind' => 'static', 'record_kind' => 'contact', 'description' => 'By hand']);
$list = (int) ($r['json']['record_id'] ?? 0);
$row = q('SELECT * FROM lists WHERE id = :i', ['i' => $list])[0] ?? [];
ok($r['code'] === 200 && $list > 0 && $row['kind'] === 'static' && $row['record_kind'] === 'contact' && (int) $row['owner_member_id'] === 26 && $row['is_shared'] === false && $row['filters'] === '{}' && (int) $row['member_count'] === 0, 'list_create: static, contacts, hers, private, empty: ' . ($r['json']['did'] ?? $r['body']));
ok(post_as(29, '/lists/save.php', ['name' => 'Viewer list', 'kind' => 'static', 'record_kind' => 'contact'])['code'] === 403, 'a Viewer has no lists');
$r = post_as(26, '/lists/save.php', ['name' => "Shared try $x", 'kind' => 'static', 'record_kind' => 'contact', 'is_shared' => 'yes']);
ok($r['code'] === 422 && field_of($r) === 'is_shared', 'a Rep may not share a list: is_shared needs lists.shared — the field is named');
$r = post_role($mkt, '/lists/save.php', ['name' => "Everyone's leads $x", 'kind' => 'static', 'record_kind' => 'lead', 'is_shared' => 'yes']);
$shared = (int) ($r['json']['record_id'] ?? 0);
ok($r['code'] === 200 && $shared > 0 && one('SELECT is_shared FROM lists WHERE id = :i', ['i' => $shared]) === true, 'Marketing shares one');
foreach ([['name' => ''], ['kind' => 'dynamic'], ['record_kind' => 'activity'], ['record_kind' => ''], ['kind' => '']] as $f) {
    $rr = post_as(26, '/lists/save.php', $f + ['name' => "Bad $x", 'kind' => 'static', 'record_kind' => 'contact']);
    ok($rr['code'] === 422 && field_of($rr) !== null, 'refused with the field named: ' . json_encode($f) . ' → ' . field_of($rr));
}

echo "2. Its count is the trigger's\n";
$r = post_as(26, '/lists/add.php', ['list' => $list, 'record' => $c1]);
ok($r['code'] === 200 && ($r['json']['added'] ?? 0) === 1 && (int) one('SELECT member_count FROM lists WHERE id = :i', ['i' => $list]) === 1, 'list_add (record): one in, the count is 1');
$r = post_as(26, '/lists/add.php', ['list' => $list, 'records' => [$c1, $c2, $c3]]);
ok(($r['json']['added'] ?? 0) === 2 && ($r['json']['already'] ?? 0) === 1 && (int) one('SELECT member_count FROM lists WHERE id = :i', ['i' => $list]) === 3, 'records[]: two new, one already there — the count is 3, not 4');
$r = post_as(26, '/lists/remove.php', ['list' => $list, 'record' => $c3]);
ok($r['code'] === 200 && ($r['json']['removed'] ?? 0) === 1 && (int) one('SELECT member_count FROM lists WHERE id = :i', ['i' => $list]) === 2, 'list_remove: the count follows: 2');
ok(post_as(26, '/lists/remove.php', ['list' => $list, 'record' => $c3])['json']['removed'] === 0, 'removing one that is not there removes nothing');
ok(post_as(26, '/lists/add.php', ['list' => $list, 'record' => 999999])['code'] === 404, 'a record that is not there: 404');
ok(post_as(26, '/lists/add.php', ['list' => $list])['code'] === 422, 'which record to add is required');
$r = post_as(27, '/lists/add.php', ['list' => $list, 'record' => $hid]);
ok($r['code'] === 200 && (int) one('SELECT member_count FROM lists WHERE id = :i', ['i' => $list]) === 3, 'the manager (lists.shared) may add to Priya\'s list — a contact only he sees: the count is 3');
$r = post_as(26, '/lists/add.php', ['list' => $list, 'record' => $hid]);
ok($r['code'] === 404, 'Priya cannot put a record she may not see in a list: the same 404');
$v = get_as(26, "/lists/$list")['json']['data'];
ok($v['total'] === 2 && count($v['members']) === 2 && !in_array($hid, array_column($v['members'], 'record_id'), true) && $v['list']['member_count'] === 3, 'Priya\'s page lists the two she may see (total 2); the stored count (3) still counts the one behind the wall');
$vm = get_as(27, "/lists/$list")['json']['data'];
ok($vm['total'] === 3 && in_array($hid, array_column($vm['members'], 'record_id'), true), 'Marco sees all three');
$g = post_as(26, '/lists/add.php', ['list' => $list, 'record' => $c3]);
post_as(26, '/lists/remove.php', ['list' => $list, 'record' => $c3]);

echo "3. Who may see and change\n";
ok(get_as(30, "/lists/$list")['code'] === 404 && html_as(30, "/lists/$list")['code'] === 404, 'Priya\'s private list is a 404 to another seller');
ok(!in_array($list, array_column(get_as(30, '/lists')['json']['data']['lists'], 'list_id'), true), 'and is not in their lists');
ok(in_array($list, array_column(get_as(27, '/lists')['json']['data']['lists'], 'list_id'), true), 'the manager sees every list');
ok(post_as(30, '/lists/add.php', ['list' => $list, 'record' => $c1])['code'] === 404, 'another seller cannot add to it: 404');
ok(in_array($shared, array_column(get_as(30, '/lists')['json']['data']['lists'], 'list_id'), true) && get_as(30, "/lists/$shared")['code'] === 200, 'a shared list is seen by every seller');
$lead1 = lead_make(26);
ok(post_as(30, '/lists/add.php', ['list' => $shared, 'record' => $lead1])['code'] === 403, 'but only its owner or lists.shared changes it: Dana gets 403');
ok(post_role($mkt, '/lists/add.php', ['list' => $shared, 'record' => $lead1])['code'] === 200 && post_as(30, '/lists/save.php', ['list' => $shared, 'name' => 'Stolen'])['code'] === 403, 'Marketing adds; Dana cannot rename it');
$r = post_as(26, '/lists/save.php', ['list' => $list, 'name' => "Renamed $x", 'is_shared' => 'yes']);
ok($r['code'] === 422 && field_of($r) === 'is_shared', 'nor can Priya share her own');
$r = post_as(26, '/lists/save.php', ['list' => $list, 'name' => "Renamed $x", 'description' => '']);
$row = q('SELECT * FROM lists WHERE id = :i', ['i' => $list])[0];
ok($r['code'] === 200 && $row['name'] === "Renamed $x" && $row['description'] === null && $row['kind'] === 'static', 'list_update renames and clears the description');
ok(post_as(26, '/lists/save.php', ['list' => $list, 'kind' => 'smart'])['code'] === 422, 'a list\'s kind is chosen once');
ok(post_as(26, '/lists/save.php', ['list' => $list, 'record_kind' => 'lead'])['code'] === 422, 'and so is what it holds');
$smart = (int) post_as(26, '/lists/save.php', ['name' => "A smart one $x", 'kind' => 'smart', 'record_kind' => 'contact', 'filters' => json_encode(['owner' => 'me'])])['json']['record_id'];
$r = post_as(26, '/lists/add.php', ['list' => $smart, 'record' => $c1]);
ok($r['code'] === 422 && field_of($r) === 'list', 'a smart list has no members of its own: list_add is refused, the list named');
ok(str_contains(pg("INSERT INTO list_members (list_id, record_id) VALUES ($smart, $c1)"), 'A smart list has no members of its own') && (int) one('SELECT count(*) FROM list_members WHERE list_id = :l', ['l' => $smart]) === 0, 'and the database refuses it too');

echo "4. The pages\n";
$h = html_as(26, '/lists')['body'];
ok(str_contains($h, 'id="list-card-' . $list . '"') && str_contains($h, 'id="list-card-' . $list . '-kind">Static<') && str_contains($h, 'id="list-card-' . $smart . '-kind">Smart<') && str_contains($h, 'id="list-card-' . $list . '-count">3<'), 'the cards: #list-card-{id}, Static / Smart, the count');
ok(str_contains(html_as(26, '/lists')['body'], 'id="list-new-btn"') && preg_match('/id="list-card-' . $shared . '-shared">Shared</', html_as(30, '/lists')['body']) === 1, 'a New list button; a shared list is marked');
$l = get_as(26, '/lists?record_kind=lead')['json']['data'];
ok($l['lists'] !== [] && array_diff(array_column($l['lists'], 'record_kind'), ['lead']) === [], 'filter by record kind');
$l = get_as(26, '/lists?kind=smart')['json']['data'];
ok(array_diff(array_column($l['lists'], 'kind'), ['smart']) === [] && in_array($smart, array_column($l['lists'], 'list_id'), true), 'filter by kind');
$pv = html_as(26, "/lists/$list")['body'];
ok(str_contains($pv, 'id="list-rows"') && str_contains($pv, 'id="contact-card-' . $c1 . '"') && str_contains($pv, 'id="bulk-bar"') && str_contains($pv, 'id="bulk-tick-contact-' . $c1 . '"'), 'a static contact list: #list-rows holds the contact cards with a tick each, and the bulk bar');
$lv = html_as(30, "/lists/$shared")['body'];
ok(str_contains($lv, 'id="lead-card-' . $lead1 . '"'), 'a lead list shows lead cards');
$conv = post_as(26, '/leads/convert.php', ['lead' => $lead1, 'make_deal' => 'no']);
$lv = html_as(30, "/lists/$shared")['body'];
ok($conv['code'] === 200 && str_contains($lv, 'id="lead-card-' . $lead1 . '"'), 'a converted lead stays in the list it was put in');
$deal = deal_make(26, ['title' => "Listed deal $x", 'value' => 1500]);
$dl = (int) post_as(26, '/lists/save.php', ['name' => "Deals $x", 'kind' => 'static', 'record_kind' => 'deal'])['json']['record_id'];
post_as(26, '/lists/add.php', ['list' => $dl, 'record' => $deal]);
$won = deal_make(26, ['title' => "Listed won $x", 'value' => 2500]);
post_as(26, '/deals/won.php', ['deal' => $won, 'value' => 2500]);
post_as(26, '/lists/add.php', ['list' => $dl, 'record' => $won]);
$dh = html_as(26, "/lists/$dl")['body'];
ok(str_contains($dh, 'id="list-row-' . $deal . '"') && str_contains($dh, 'id="list-row-' . $won . '"') && str_contains($dh, 'id="bulk-tick-deal-' . $deal . '"'), 'deals are rows (#list-row-{id}), open and won alike, each with a tick');
$jd = get_as(26, "/lists/$dl")['json']['data'];
ok($jd['total'] === 2 && $jd['members'][0]['name'] !== null && isset($jd['members'][0]['stage_name']), 'JSON: the deals with their stage');
$none = html_as(26, '/lists/999999');
ok($none['code'] === 404, 'a list that is not there: 404');
ok(html_as(26, "/lists/$list/edit")['code'] === 200 && html_as(30, "/lists/$list/edit")['code'] === 404 && html_as(30, "/lists/$shared/edit")['code'] === 403, 'the edit form: the owner\'s; 404 for a list unseen, 403 for a list seen but not theirs to change');

echo "5. Deleting\n";
ok(post_as(30, '/lists/delete.php', ['list' => $shared])['code'] === 403, 'Dana cannot delete a shared list');
$r = post_as(26, '/lists/delete.php', ['list' => $list]);
ok($r['code'] === 200 && get_as(26, "/lists/$list")['code'] === 404 && !in_array($list, array_column(get_as(26, '/lists')['json']['data']['lists'], 'list_id'), true), 'list_delete: the list is gone from the lists and its page; the records are not');
ok((int) one('SELECT count(*) FROM contacts WHERE id = :c AND deleted_at IS NULL', ['c' => $c1]) === 1, 'the contact is untouched');
ok(post_role($mkt, '/lists/delete.php', ['list' => $shared])['code'] === 200, 'Marketing deletes the shared one');
$reg = json_decode((string) file_get_contents(dirname(__DIR__, 3) . '/mcp/action_registry.json'), true);
ok(($reg['actions']['list_delete']['approval'] ?? '') === 'deletion' && ($reg['actions']['list_delete']['confirm'] ?? false) === true, 'list_delete pauses an agent: deletion, with confirm');
$a = q("SELECT action FROM activity_log WHERE id > :s AND action LIKE 'list.%'", ['s' => $since]);
$seen = array_unique(array_column($a, 'action'));
foreach (['list.create', 'list.update', 'list.delete', 'list.add', 'list.remove'] as $evt) { ok(in_array($evt, $seen, true), "$evt written"); }
finish();
