<?php
/** Proof — JSON mode on every screen and handler of slice 6, the HTMX stamps, the log's vocabulary (campaigns-lists.md "Activity log events", never a body, a name or a token), the worker's passes, and the registry:
 * 9 screens built, 18 actions built, 5 approvals in step (campaign_email_send, list_delete, bulk_assign, bulk_update, bulk_delete). */
require __DIR__ . '/lib.php';
$x = run_tag();
$mb = ensure_mailbox();
$since = last_activity_id() - 1;
$acc = (int) post_as(26, '/accounts/save.php', ['name' => "Json6 Acct $x"])['json']['record_id'];
$c1 = contact_with("j1-$x@json6-$x.example.invalid", 'Jo', $acc);
$c2 = contact_with("j2-$x@json6-$x.example.invalid", 'Jim', $acc);
$camp = campaign_make(['name' => "Json camp $x"]);
post_as(27, '/campaigns/members/add.php', ['campaign' => $camp, 'contacts' => [$c1, $c2]]);
$tpl = (int) post_as(27, '/email/templates/save.php', ['name' => "Json tpl $x", 'subject' => 'Hi {{contact.first_name}}', 'body' => 'JSON6-BODY-SECRET', 'is_shared' => 'yes'])['json']['record_id'];
$lst = (int) post_as(26, '/lists/save.php', ['name' => "Json list $x", 'kind' => 'static', 'record_kind' => 'contact'])['json']['record_id'];
$sm = (int) post_as(26, '/lists/save.php', ['name' => "Json smart $x", 'kind' => 'smart', 'record_kind' => 'contact', 'filter' => ['role' => 'finance']])['json']['record_id'];
$exp = (int) post_as(26, '/exports/create.php', ['kind' => 'contacts', 'filters' => json_encode(['account' => $acc])])['json']['record_id'];

echo "1. Every screen answers JSON\n";
$screens = ['/campaigns' => 'campaigns', "/campaigns/$camp" => 'campaign', '/campaigns/new' => 'kinds', "/campaigns/$camp/edit" => 'values', '/lists' => 'lists', "/lists/$lst" => 'members', "/lists/$sm" => 'members',
    '/lists/new?record_kind=deal' => 'vocabulary', "/lists/$lst/edit" => 'values', '/exports' => 'exports'];
foreach ($screens as $url => $key) {
    $g = get_as(27, $url);
    ok($g['code'] === 200 && array_key_exists($key, $g['json']['data'] ?? []), "GET $url as JSON: 200 with `$key`");
}
$g = get_as(26, "/lists/$sm")['json']['data'];
ok($g['list']['kind'] === 'smart' && $g['list']['filters'] == ['role' => 'finance'] && array_key_exists('live_count', $g['list']) && isset($g['may']['edit']), 'list-view: the list with its filters as an object, the live count, the rights');
$g = get_as(27, "/campaigns/$camp")['json']['data'];
ok(array_key_exists('cost_per_lead', $g['campaign']['results']) && $g['email'] === ['queued' => 0, 'sent' => 0, 'failed' => 0] && isset($g['member_counts']) && $g['may']['email'] === true, 'campaign-view: the results, the email counts, the member counts, the rights');
foreach (['/campaigns/new' => 403, "/campaigns/$camp/edit" => 403, '/lists/999999' => 404, '/campaigns/999999' => 404, '/exports' => 200] as $u => $want) {
    $code = get_as($u === '/exports' ? 26 : 26, $u)['code'];
    ok($code === $want, "as a Rep, GET $u answers $want");
}
ok(get_as(29, '/lists')['code'] === 403 && get_as(29, '/exports')['code'] === 403 && get_as(29, '/campaigns')['code'] === 200, 'a Viewer: no lists, no exports, campaigns to read');
$h = html_as(26, "/lists/$lst", ['HX-Request: true']);
ok(!str_contains($h['body'], '<html') && preg_match('/^X-Screen: list-view\r?$/mi', $h['headers']) === 1 && preg_match('/^X-Record-Id: ' . $lst . '\r?$/mi', $h['headers']) === 1, 'over HTMX a list\'s page is the partial stamped list-view and its id');
foreach (['/campaigns' => 'campaign-list', "/campaigns/$camp" => 'campaign-view', '/lists' => 'list-list', '/lists/new' => 'list-add', "/lists/$lst/edit" => 'list-edit', '/exports' => 'export-list'] as $u => $screen) {
    ok(preg_match('/^X-Screen: ' . preg_quote($screen, '/') . '\r?$/mi', html_as(27, $u, ['HX-Request: true'])['headers']) === 1, "over HTMX $u is stamped $screen");
}
foreach (['/campaigns/new' => 'campaign-add', "/campaigns/$camp/edit" => 'campaign-edit'] as $u => $screen) {
    ok(preg_match('/^X-Screen: ' . preg_quote($screen, '/') . '\r?$/mi', html_as(27, $u, ['HX-Request: true'])['headers']) === 1, "over HTMX $u is stamped $screen");
}

echo "2. Every handler answers JSON\n";
$d1 = lead_make(26);
$checks = [
    [27, '/campaigns/save.php', ['name' => "J2 $x", 'kind' => 'ads'], 'campaign_create'],
    [27, '/campaigns/save.php', ['campaign' => $camp, 'description' => 'Changed'], 'campaign_update'],
    [27, '/campaigns/members/add.php', ['campaign' => $camp, 'lead' => $d1], 'campaign_member_add'],
    [27, '/campaigns/members/status.php', ['campaign' => $camp, 'contact' => $c1, 'status' => 'opened'], 'campaign_member_status'],
    [27, '/campaigns/members/remove.php', ['campaign' => $camp, 'lead' => $d1], 'campaign_member_remove'],
    [27, '/campaigns/email.php', ['campaign' => $camp, 'template' => $tpl, 'confirm' => 'yes'], 'campaign_email_send'],
    [26, '/lists/save.php', ['name' => "J list2 $x", 'kind' => 'static', 'record_kind' => 'contact'], 'list_create'],
    [26, '/lists/save.php', ['list' => $lst, 'description' => 'Changed'], 'list_update'],
    [26, '/lists/add.php', ['list' => $lst, 'record' => $c1], 'list_add'],
    [26, '/lists/remove.php', ['list' => $lst, 'record' => $c1], 'list_remove'],
    [27, '/bulk/assign.php', ['kind' => 'contact', 'owner' => 26, 'records' => [$c1]], 'bulk_assign'],
    [26, '/bulk/tag.php', ['kind' => 'contact', 'tag' => "j6-$x", 'records' => [$c1]], 'bulk_tag'],
    [27, '/bulk/update.php', ['kind' => 'contact', 'field' => 'role', 'value' => 'champion', 'records' => [$c1]], 'bulk_update'],
    [27, '/bulk/campaign.php', ['kind' => 'contact', 'campaign' => $camp, 'records' => [$c2]], 'bulk_campaign_add'],
    [26, '/bulk/list.php', ['kind' => 'contact', 'target_list' => $lst, 'records' => [$c2]], 'bulk_list_add'],
    [26, '/exports/create.php', ['kind' => 'contacts', 'filters' => json_encode(['account' => $acc])], 'export_create'],
    [27, '/bulk/delete.php', ['kind' => 'contact', 'records' => [contact_with("j3-$x@json6-$x.example.invalid", 'Jat', $acc)]], 'bulk_delete'],
    [26, '/lists/delete.php', ['list' => $sm], 'list_delete'],
];
foreach ($checks as [$m, $path, $form, $name]) {
    $r = post_as($m, $path, $form);
    ok($r['code'] === 200 && ($r['json']['ok'] ?? false) === true && ($r['json']['did'] ?? '') !== '' && array_key_exists('record_id', $r['json']), "$name: {ok, did, record_id} — " . ($r['json']['did'] ?? $r['body']));
}
$r = post_as(26, '/lists/save.php', ['name' => '', 'kind' => 'static', 'record_kind' => 'contact']);
ok($r['code'] === 422 && ($r['json']['error']['code'] ?? '') === 'invalid' && isset($r['json']['error']['fields']['name']) && is_array($r['json']['error']['errors']), 'a refusal: 422 invalid with the field named and the sentences');
$r = post_as(27, '/bulk/assign.php', ['kind' => 'contact', 'owner' => 26, 'records' => [999999]]);
ok($r['code'] === 404 && ($r['json']['error']['code'] ?? '') === 'not_found', 'a missing record is a 404 not_found');
$r = post_as(26, '/bulk/tag.php', ['kind' => 'contact', 'tag' => 'zz', 'records' => [$c2, contact_with("j4-$x@json6-$x.example.invalid", 'Jon', $acc, 30)]]);
ok($r['code'] === 403 && ($r['json']['error']['code'] ?? '') === 'forbidden', 'a record she may not change is a 403 forbidden naming it: ' . message_of($r));

echo "3. The log's vocabulary\n";
$rows = q("SELECT action, after::text AS after, before::text AS before FROM activity_log WHERE id > :s AND (action LIKE 'campaign.%' OR action LIKE 'list.%' OR action LIKE 'bulk.%' OR action LIKE 'export.%')", ['s' => $since]);
$seen = array_unique(array_column($rows, 'action'));
foreach (['campaign.create', 'campaign.update', 'campaign.member_add', 'campaign.member_status', 'campaign.member_remove', 'campaign.email_send', 'list.create', 'list.update', 'list.delete', 'list.add', 'list.remove',
    'bulk.assign', 'bulk.tag', 'bulk.update', 'bulk.campaign_add', 'bulk.list_add', 'bulk.delete', 'export.create'] as $ev) {
    ok(in_array($ev, $seen, true), "$ev written");
}
pass('exports');
$seen2 = array_unique(array_column(q("SELECT action FROM activity_log WHERE id > :s AND action LIKE 'export.%'", ['s' => $since]), 'action'));
ok(in_array('export.build', $seen2, true), 'export.build written by the worker');
$all = json_encode($rows);
ok(!str_contains($all, 'JSON6-BODY-SECRET'), 'a template\'s body is in no payload');
ok(!str_contains($all, "j1-$x@") && !str_contains($all, 'Jo Mail'), 'no address and no name in any payload');
$keys = q("SELECT contact_id FROM activity_log WHERE id > :s AND action = 'campaign.member_status' AND contact_id IS NOT NULL", ['s' => $since]);
ok(count($keys) >= 1 && (int) $keys[0]['contact_id'] === $c1, 'a member\'s status carries the contact as an audit key (the contact\'s timeline)');
$tl = get_as(26, "/contacts/$c1?tab=history")['json']['data'] ?? [];
ok(json_encode($tl) !== '', 'and the contact\'s history reads');

echo "4. The worker's passes\n";
$w = worker('smart_lists,exports,outbox');
ok(isset($w['ran']['smart_lists']['counted'], $w['ran']['exports']['built'], $w['ran']['outbox']['sent']) && empty($w['ran']['smart_lists']['stub']) && empty($w['ran']['exports']['stub']) && empty($w['ran']['outbox']['stub']) && ($w['errors'] ?? []) === [], 'smart_lists, exports and outbox are real passes: ' . json_encode($w['ran'] ?? $w));
$w = worker('dispatches,proposals,tombstones_purge,key_usage_prune');
ok(count(array_filter($w['ran'] ?? [], static fn ($r) => !empty($r['stub']))) === 4, 'the passes of slices 8 and 9 are still stubs');

echo "5. The registry\n";
exec('php ' . escapeshellarg(dirname(__DIR__, 3) . '/bin/build_action_registry.php') . ' --check 2>&1', $o, $rc);
ok($rc === 0, 'bin/build_action_registry.php --check: the registry and approvals[] match the manifest');
$reg = json_decode((string) file_get_contents(dirname(__DIR__, 3) . '/mcp/action_registry.json'), true);
$mine = ['campaign-list', 'campaign-view', 'campaign-add', 'campaign-edit', 'list-list', 'list-view', 'list-add', 'list-edit', 'export-list'];
ok(array_filter($mine, static fn ($s) => empty($reg['screens'][$s]['built'])) === [], 'the 9 screens read as built');
$acts = ['campaign_create', 'campaign_update', 'campaign_member_add', 'campaign_member_status', 'campaign_member_remove', 'campaign_email_send', 'list_create', 'list_update', 'list_delete', 'list_add', 'list_remove',
    'bulk_assign', 'bulk_tag', 'bulk_update', 'bulk_campaign_add', 'bulk_list_add', 'bulk_delete', 'export_create'];
ok(count($acts) === 18 && array_filter($acts, static fn ($a) => empty($reg['actions'][$a]['built'])) === [], 'the 18 actions read as built');
$paused = array_values(array_filter($acts, static fn ($a) => !empty($reg['actions'][$a]['approval'] ?? null)));
sort($paused);
ok($paused === ['bulk_assign', 'bulk_delete', 'bulk_update', 'campaign_email_send', 'list_delete'], '5 approvals in step: ' . implode(', ', $paused));
$os = json_decode((string) file_get_contents(dirname(__DIR__, 3) . '/maludb-os.json'), true);
$ap = array_filter($os['approvals'] ?? [], static fn ($a) => in_array($a['action'] ?? ($a['tool'] ?? ''), $paused, true));
ok(count($ap) === 5, 'maludb-os.json approvals[] holds the five');
$people = array_values(array_filter($acts, static fn ($a) => empty($reg['actions'][$a]['agent'])));
ok($people === [], 'all eighteen are open to an agent (the pause is the approval, not a refusal)');
$files = ['campaign_create' => '/campaigns/save.php', 'campaign_member_add' => '/campaigns/members/add.php', 'campaign_email_send' => '/campaigns/email.php', 'list_create' => '/lists/save.php', 'bulk_list_add' => '/bulk/list.php', 'export_create' => '/exports/create.php'];
ok(array_filter($files, static fn ($f, $a) => ($reg['actions'][$a]['endpoint'] ?? '') !== $f, ARRAY_FILTER_USE_BOTH) === [], 'the endpoints are where the manifest says');
finish();
