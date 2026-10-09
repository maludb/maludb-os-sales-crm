<?php
/**
 * Proof — the verbs every record shares (accounts-contacts.md "Proof" 7 and 8): a restriction by a manager hides the account from another seller's list,
 * page, search, timeline, notes and picker, while the owner, the department named and the managers still see it; a Rep's restrict is 403; the setting
 * off refuses it; delete tombstones (out of the lists, the search and the picker; the account's counters follow), restore brings it back; an account
 * with deals needs a manager; restoring someone else's tombstone is refused; an agent's record_delete pauses (the registry's deletion category).
 */
require __DIR__ . '/lib.php';
$x = run_tag();
setting('allow_restricted_records', 'true');
$acc = (int) post_as(26, '/accounts/save.php', ['name' => "Initech $x", 'website' => "https://initech-$x.example.com"])['json']['record_id'];
$con = (int) post_as(26, '/contacts/save.php', ['first_name' => 'Bill', 'last_name' => "Lumbergh $x", 'email' => "bill@initech-$x.example.com"])['json']['record_id'];
post_as(26, '/notes/add.php', ['kind' => 'account', 'record' => $acc, 'body' => 'Wants the TPS reports']);

echo "1. The restriction\n";
$r = post_as(26, '/records/restrict.php', ['kind' => 'account', 'record' => $acc]);
ok($r['code'] === 403 && message_of($r) === 'You may not restrict a record.', 'a Rep\'s restrict is 403, in words');
$r = post_as(27, '/records/restrict.php', ['kind' => 'account', 'record' => $acc, 'member' => 26, 'department' => 8]);
ok($r['code'] === 200 && one('SELECT restricted_to_member_id || \':\' || restricted_to_department_id FROM accounts WHERE id = :i', ['i' => $acc]) === '26:8', 'the manager restricts it to Priya and the Partnerships team');
$sees = static function (int $m) use ($acc, $x): array {
    $list = array_column(get_as($m, '/accounts?q=' . rawurlencode("Initech $x"))['json']['data']['accounts'] ?? [], 'account_id');
    $search = array_map(static fn ($r) => $r['entity_kind'] . '-' . $r['entity_id'], get_as($m, '/search?q=' . rawurlencode("Initech $x"))['json']['data']['rows'] ?? []);
    as_member($m);
    $timeline = (int) one('SELECT count(*) FROM crm_timeline(\'account\', :i, 100, NULL)', ['i' => $acc]);
    $notes = (int) one("SELECT count(*) FROM mcp_notes WHERE record_type = 'account' AND record_id = :i", ['i' => $acc]);
    $pick = str_contains(html_as($m, '/pick/?source=account&q=' . rawurlencode("Initech $x"))['body'], 'id="record-picker-row-' . $acc . '"');
    return ['list' => in_array($acc, $list, true), 'page' => get_as($m, "/accounts/$acc")['code'], 'search' => in_array("account-$acc", $search, true), 'timeline' => $timeline > 0, 'notes' => $notes > 0, 'pick' => $pick];
};
$bea = $sees(28);
ok($bea === ['list' => false, 'page' => 404, 'search' => false, 'timeline' => false, 'notes' => false, 'pick' => false], 'Bea (another seller, Accounting) does not see it: not in the list, 404 on the page, not in the search, no timeline, no notes, not in the picker');
ok($sees(26) === ['list' => true, 'page' => 200, 'search' => true, 'timeline' => true, 'notes' => true, 'pick' => true], 'Priya (the owner) sees it everywhere');
ok($sees(30)['page'] === 200 && $sees(30)['list'], 'Dana (Partnerships, the team named) sees it');
ok($sees(27)['page'] === 200 && $sees(1)['page'] === 200, 'the manager and the admin see it');
ok(str_contains(html_as(26, "/accounts/$acc")['body'], 'id="account-view-lock"'), 'the owner sees the lock in the header');
ok(post_as(28, '/notes/add.php', ['kind' => 'account', 'record' => $acc, 'body' => 'x'])['code'] === 404 && post_as(28, '/tags/apply.php', ['kind' => 'account', 'record' => $acc, 'tag' => 'x'])['code'] === 404, 'Bea cannot reach it through a write either: the same 404');
$a = activity('record.restrict');
ok(json_decode((string) end($a)['after'], true)['member'] === 26 && (int) end($a)['account_id'] === $acc, 'logged record.restrict with the member and team, the account the audit key');
$r = post_as(27, '/records/restrict.php', ['kind' => 'account', 'record' => $acc, 'lift' => 'yes']);
ok($r['code'] === 200 && $sees(28)['page'] === 200, 'lifting it opens it to Bea again');
$r = post_as(27, '/records/restrict.php', ['kind' => 'contact', 'record' => $con]);
ok($r['code'] === 200 && one('SELECT restricted_to_member_id FROM contacts WHERE id = :i', ['i' => $con]) === 26 && get_as(28, "/contacts/$con")['code'] === 404, 'a contact restricted with no one named goes to its owner (Bea: 404)');
post_as(27, '/records/restrict.php', ['kind' => 'contact', 'record' => $con, 'lift' => 'yes']);
setting('allow_restricted_records', 'false');
$r = post_as(27, '/records/restrict.php', ['kind' => 'account', 'record' => $acc]);
ok($r['code'] === 422 && str_contains(message_of($r), 'Restricted records are off'), 'with the setting off the database refuses it, in its words: ' . message_of($r));
setting('allow_restricted_records', 'true');
ok(post_as(27, '/records/restrict.php', ['kind' => 'campaign', 'record' => 1])['code'] === 422, 'a kind with no wall (campaign) is refused');

echo "2. Delete, the tombstone, restore\n";
$before = (int) get_as(26, "/accounts/$acc")['json']['data']['account']['counts']['contacts'];
$r = post_as(26, '/records/delete.php', ['kind' => 'contact', 'record' => $con]);
ok($r['code'] === 200 && ($r['json']['undo'] ?? '') === 'record_restore' && one('SELECT deleted_by FROM contacts WHERE id = :i', ['i' => $con]) === 26, 'record_delete tombstones the contact (deleted_by the caller), naming the undo');
ok((int) get_as(26, "/accounts/$acc")['json']['data']['account']['counts']['contacts'] === $before - 1, 'the account\'s contact count follows (' . $before . ' → ' . ($before - 1) . ')');
ok(get_as(26, "/contacts/$con")['code'] === 404 && !in_array($con, array_column(get_as(26, '/contacts?q=' . rawurlencode("Lumbergh $x"))['json']['data']['contacts'] ?? [], 'contact_id'), true), 'out of the page and the list');
ok(!in_array("contact-$con", array_map(static fn ($r) => $r['entity_kind'] . '-' . $r['entity_id'], get_as(26, '/search?q=' . rawurlencode("Lumbergh $x"))['json']['data']['rows'] ?? []), true), '…and the search');
ok(post_as(30, '/records/restore.php', ['kind' => 'contact', 'record' => $con])['code'] === 404, 'another Rep cannot restore Priya\'s tombstone (404)');
$r = post_as(26, '/records/restore.php', ['kind' => 'contact', 'record' => $con]);
ok($r['code'] === 200 && get_as(26, "/contacts/$con")['code'] === 200, 'record_restore brings it back for the one who deleted it');
ok(post_as(26, '/records/restore.php', ['kind' => 'contact', 'record' => $con])['code'] === 404, 'restoring a live record answers 404 (nothing among the deleted)');
ok(post_as(29, '/records/delete.php', ['kind' => 'contact', 'record' => $con])['code'] === 403, 'a Viewer may not delete');
ok(post_as(30, '/records/delete.php', ['kind' => 'account', 'record' => $acc])['code'] === 403, 'another Rep may not delete Priya\'s account');
$r = post_as(26, '/records/delete.php', ['kind' => 'account', 'record' => $acc]);
ok($r['code'] === 403 && str_contains(message_of($r), 'a manager deletes it'), 'an account with contacts needs a manager: ' . message_of($r));
as_member(26);
$deal = (int) one("INSERT INTO deals (title, account_id, pipeline_id, stage_id, value, currency, owner_member_id, created_by) SELECT 'SMOKE Initech deal $x', :a, p.id, s.id, 5000, 'USD', 26, 26 FROM pipelines p JOIN stages s ON s.pipeline_id = p.id AND s.kind = 'open' WHERE p.key = 'new_business' ORDER BY s.position LIMIT 1 RETURNING id", ['a' => $acc]);
$r = post_as(27, '/records/delete.php', ['kind' => 'account', 'record' => $acc]);
ok($r['code'] === 200 && one('SELECT deleted_at IS NOT NULL FROM accounts WHERE id = :i', ['i' => $acc]), 'the manager deletes an account with deals and contacts');
ok(get_as(26, "/accounts/$acc")['code'] === 404 && !str_contains(html_as(26, '/pick/?source=account&q=' . rawurlencode("Initech $x"))['body'], 'id="record-picker-row-' . $acc . '"'), 'it is gone from the page and the picker');
ok(post_as(26, '/records/restore.php', ['kind' => 'account', 'record' => $acc])['code'] === 200, 'Priya, its owner, may restore what the manager deleted');
ok(get_as(26, "/accounts/$acc")['json']['data']['account']['open_deals_count'] === 1, 'restored with its deal counted');
$r = post_as(26, '/records/delete.php', ['kind' => 'deal', 'record' => $deal]);
ok($r['code'] === 200 && (int) one('SELECT open_deals_count FROM accounts WHERE id = :i', ['i' => $acc]) === 0, 'deleting a deal recounts its account\'s open deals (crm_record_delete)');
post_as(26, '/records/restore.php', ['kind' => 'deal', 'record' => $deal]);
ok(post_as(26, '/records/delete.php', ['kind' => 'nonsense', 'record' => 1])['code'] === 422, 'an unknown kind is refused');

echo "3. An agent's delete pauses\n";
$reg = json_decode((string) file_get_contents(dirname(__DIR__, 3) . '/mcp/action_registry.json'), true);
ok(($reg['actions']['record_delete']['approval'] ?? $reg['actions']['record_delete']['agent_approval'] ?? null) === 'deletion' && ($reg['actions']['record_delete']['log_event'] ?? '') === 'record.delete', 'the registry marks record_delete for the deletion category on its log event record.delete');
$manifest = json_decode((string) file_get_contents(dirname(__DIR__, 3) . '/maludb-os.json'), true);
$cats = [];
foreach ($manifest['approvals'] ?? [] as $a) { $cats[$a['action'] ?? $a['log_event'] ?? $a['action_pattern'] ?? ''] = $a['category'] ?? null; }
ok(($cats['record_delete'] ?? null) === 'deletion' && ($cats['note_delete'] ?? null) === 'deletion' && ($cats['attachment_delete'] ?? null) === 'deletion', 'maludb-os.json pauses record_delete, note_delete and attachment_delete for agents (the kernel\'s approval hook)');
ok(!isset($cats['record_restore']) && !isset($cats['record_restrict']) && !isset($cats['tag_apply']), '…and never restore, restrict or tag (free inside the business)');
$tok = run_token(40, 801);
$r = req('POST', '/records/delete.php', ['headers' => as_agent($tok), 'form' => ['kind' => 'contact', 'record' => $con]]);
ok($r['code'] === 403, 'the Expert (a Rep) under a run token is held to the edit rule like a person: not its contact, 403');
finish();
