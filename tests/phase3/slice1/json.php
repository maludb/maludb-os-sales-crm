<?php
/**
 * Proof — JSON mode, the tabs, the registry (accounts-contacts.md "Proof" 9, 10, 11): every screen of the slice answers JSON (whitelisted — never the
 * storage path or a restriction's raw row); every handler answers {ok, did, record_id} or a refusal with its field; every tab renders from its own partial;
 * the Tickets panel shows the no-connection state; the History tab is crm_timeline; HTMX navigation answers the partial with its stamps; the registry
 * reads the 8 screens and 15 actions built with the 3 approvals in step.
 */
require __DIR__ . '/lib.php';
$x = run_tag();
$acc = (int) post_as(26, '/accounts/save.php', ['name' => "Soylent $x", 'website' => "https://soylent-$x.example.com", 'billing_address' => '1 Green St'])['json']['record_id'];
$con = (int) post_as(26, '/contacts/save.php', ['first_name' => 'Frank', 'last_name' => "Thorn $x", 'email' => "frank@soylent-$x.example.com"])['json']['record_id'];
post_as(26, '/notes/add.php', ['kind' => 'account', 'record' => $acc, 'body' => 'Note for the history']);

echo "1. Every screen answers JSON\n";
$screens = ['/accounts' => 'accounts', "/accounts/$acc" => 'account', '/accounts/new' => 'values', "/accounts/$acc/edit" => 'values', '/contacts' => 'contacts', "/contacts/$con" => 'contact',
    '/contacts/new' => 'values', "/contacts/$con/edit" => 'values'];
foreach ($screens as $url => $key) {
    $r = get_as(26, $url);
    ok($r['code'] === 200 && isset($r['json']['data'][$key]), "GET $url as JSON: 200 with `$key`");
}
$d = get_as(26, "/accounts/$acc")['json']['data'];
ok(!str_contains(json_encode($d), 'storage_path') && !str_contains(json_encode($d), 'restricted_to_member_id') && array_key_exists('member_id', $d['account']['restricted_to'] ?? []), 'the account JSON is whitelisted (no storage path, the restriction as a named object)');
ok($d['account']['domain'] === "soylent-$x.example.com" && $d['account']['primary_contact']['contact_id'] === $con && $d['account']['counts']['contacts'] === 1 && $d['account']['next_step'] === null, 'get_account\'s shape: domain, the primary contact, the counts, the next step (none)');
ok(count($d['history']) >= 2 && $d['tickets']['status'] === 'no_connection', 'with the history and the Tickets panel\'s state');
$c = get_as(26, "/contacts/$con")['json']['data']['contact'];
ok($c['account_id'] === $acc && $c['emails'][0]['address'] === "frank@soylent-$x.example.com" && $c['is_primary'] === true && $c['lifecycle'] === 'prospect', 'get_contact\'s shape: the account, every address, primary, the lifecycle');

echo "2. Every handler answers JSON\n";
$checks = [
    ['/accounts/save.php', ['account' => $acc, 'phone' => '555'], 'account_update'],
    ['/contacts/save.php', ['contact' => $con, 'title' => 'CTO'], 'contact_update'],
    ['/contacts/primary.php', ['contact' => $con], 'contact_set_primary'],
    ['/contacts/opt-out.php', ['contact' => $con, 'channel' => 'sms'], 'contact_opt_out'],
    ['/notes/add.php', ['kind' => 'contact', 'record' => $con, 'body' => 'x'], 'note_add'],
    ['/tags/apply.php', ['kind' => 'contact', 'record' => $con, 'tag' => "json $x"], 'tag_apply'],
    ['/tags/remove.php', ['kind' => 'contact', 'record' => $con, 'tag' => "json $x"], 'tag_remove'],
    ['/records/restrict.php', ['kind' => 'contact', 'record' => $con], 'record_restrict', 27],
    ['/records/restrict.php', ['kind' => 'contact', 'record' => $con, 'lift' => 'yes'], 'record_restrict (lift)', 27],
];
foreach ($checks as $c) {
    $r = post_as($c[3] ?? 26, $c[0], $c[1]);
    ok($r['code'] === 200 && ($r['json']['ok'] ?? false) === true && ($r['json']['did'] ?? '') !== '' && array_key_exists('record_id', $r['json']), "{$c[2]}: {ok, did, record_id} — " . ($r['json']['did'] ?? $r['body']));
}
$tmp = tempnam(sys_get_temp_dir(), 'j'); file_put_contents($tmp, 'hello');
$r = upload_as(26, '/attachments/add.php', ['kind' => 'contact', 'record' => (string) $con], [$tmp, 'text/plain', 'hello.txt']);
ok($r['code'] === 200 && ($r['json']['record_id'] ?? 0) > 0, 'attachment_add: {ok, did, record_id}');
$aid = (int) $r['json']['record_id'];
ok(post_as(26, '/attachments/delete.php', ['attachment' => $aid])['json']['ok'] ?? false, 'attachment_delete: ok');
$nid = (int) post_as(26, '/notes/add.php', ['kind' => 'contact', 'record' => $con, 'body' => 'to delete'])['json']['record_id'];
ok(post_as(26, '/notes/delete.php', ['note' => $nid])['json']['ok'] ?? false, 'note_delete: ok');
$tmpC = (int) post_as(26, '/contacts/save.php', ['first_name' => "Temp $x"])['json']['record_id'];
ok((post_as(26, '/records/delete.php', ['kind' => 'contact', 'record' => $tmpC])['json']['ok'] ?? false) && (post_as(26, '/records/restore.php', ['kind' => 'contact', 'record' => $tmpC])['json']['ok'] ?? false), 'record_delete and record_restore: ok');
$r = post_as(26, '/contacts/save.php', ['first_name' => '', 'last_name' => '']);
ok($r['code'] === 422 && ($r['json']['error']['code'] ?? '') === 'invalid' && is_array($r['json']['error']['fields'] ?? null), 'a refusal: 422 invalid with the fields');
$r = req('POST', '/accounts/save.php', ['headers' => ['Accept: application/json', 'X-Action-Token: ' . person_token(26)], 'form' => ['name' => "By token $x"]]);
ok($r['code'] === 200 && ($r['json'] = json_decode($r['body'], true))['record_id'] > 0, 'under a person\'s action token (the kernel\'s actions server) account_create answers JSON with the record_id');

echo "3. The tabs\n";
foreach (['overview', 'contacts', 'deals', 'activities', 'emails', 'notes', 'tickets', 'history'] as $t) {
    $h = html_as(26, "/accounts/$acc?tab=$t")['body'];
    ok(str_contains($h, 'id="account-tab-' . $t . '" role="tabpanel"') && preg_match('/class="tab-pane fade show active" id="account-tab-' . $t . '"/', $h) === 1, "account ?tab=$t renders that pane active (all eight in the page)");
}
foreach (['overview', 'deals', 'activities', 'emails', 'notes', 'history'] as $t) {
    $h = html_as(26, "/contacts/$con?tab=$t")['body'];
    ok(preg_match('/class="tab-pane fade show active" id="contact-tab-' . $t . '"/', $h) === 1, "contact ?tab=$t renders that pane active");
}
$h = html_as(26, "/accounts/$acc?tab=tickets")['body'];
ok(str_contains($h, 'id="account-tickets-no-connection"') && str_contains($h, 'Help Desk is not connected') && str_contains($h, 'id="account-ledger-none"'), 'the Tickets panel and the ledger panel show the no-connection state');
as_member(26);
$fn = (int) one("SELECT count(*) FROM crm_timeline('account', :i, 100, NULL)", ['i' => $acc]);
$tabRows = substr_count(html_as(26, "/accounts/$acc?tab=history")['body'], 'id="timeline-row-');
ok($fn > 0 && $tabRows === $fn, "the History tab is crm_timeline: $tabRows rows = the function's $fn");
$h = html_as(26, "/accounts/$acc?tab=history")['body'];
ok(str_contains($h, 'noted: Note for the history') && str_contains($h, 'added an account'), 'its rows read in words (the note, the change)');
$r = html_as(26, "/accounts/$acc", ['HX-Request: true']);
ok(!str_contains($r['body'], '<html') && preg_match('/^X-Screen: account-view\r?$/mi', $r['headers']) === 1 && preg_match('/^X-Record-Id: ' . $acc . '\r?$/mi', $r['headers']) === 1 && preg_match('/^X-Entity: account\r?$/mi', $r['headers']) === 1, 'over HTMX the page is the partial stamped account-view / account / its id (the command bar\'s context)');

echo "4. The registry\n";
exec('php ' . escapeshellarg(dirname(__DIR__, 3) . '/bin/build_action_registry.php') . ' --check 2>&1', $out, $rc);
ok($rc === 0, 'bin/build_action_registry.php --check: the registry and approvals[] match the manifest');
$reg = json_decode((string) file_get_contents(dirname(__DIR__, 3) . '/mcp/action_registry.json'), true);
$mine = ['account-list', 'account-view', 'account-add', 'account-edit', 'contact-list', 'contact-view', 'contact-add', 'contact-edit'];
ok(array_filter($mine, static fn ($s) => empty($reg['screens'][$s]['built'])) === [], 'the 8 screens read as built');
$acts = ['account_create', 'account_update', 'contact_create', 'contact_update', 'contact_set_primary', 'contact_opt_out', 'record_restrict', 'record_delete', 'record_restore', 'note_add', 'note_delete', 'attachment_add', 'attachment_delete', 'tag_apply', 'tag_remove'];
ok(array_filter($acts, static fn ($a) => empty($reg['actions'][$a]['built'])) === [], 'the 15 actions read as built');
$paused = array_values(array_filter($acts, static fn ($a) => !empty($reg['actions'][$a]['approval'] ?? $reg['actions'][$a]['agent_approval'] ?? null)));
sort($paused);
ok($paused === ['attachment_delete', 'note_delete', 'record_delete'], '3 approvals in step: ' . implode(', ', $paused));
finish();
