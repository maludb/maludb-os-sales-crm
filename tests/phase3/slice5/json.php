<?php
/** Proof — JSON mode on every screen and handler of slice 5, the HTMX stamps, the log's vocabulary (email.md "Activity log events", never a body or a token), the worker's passes, and the registry:
 * 7 screens built, 10 actions built, 3 approvals in step (email_send, meeting_invite_send, template_delete — the pause for an agent is the registry's `approval`, as slices 1–3 proved it). */
require __DIR__ . '/lib.php';
$x = run_tag();
$mb = ensure_mailbox();
$since = last_activity_id() - 1;
$acc = (int) post_as(26, '/accounts/save.php', ['name' => "Json Acct $x"])['json']['record_id'];
$ann = contact_with("ann-$x@json-$x.example.invalid", 'Ann', $acc);
$deal = deal_make(26, ['title' => "Json deal $x", 'account' => $acc, 'primary_contact' => $ann]);
$r = post_as(26, '/email/send.php', ['contact' => $ann, 'deal' => $deal, 'subject' => "Json $x", 'body' => 'JSON-BODY-SECRET', 'confirm' => 'yes']);
$out = (int) $r['json']['record_id'];
$m = '<json-' . $x . '@json.example.invalid>';
deliver(msg("Ann <ann-$x@json-$x.example.invalid>", [MAILBOX], "Json reply $x", 'Reply body', [], ['message-id' => $m, 'in-reply-to' => last_send()['message_id']]));
worker('mail_match');
$in = (int) inbound($m)['id'];
$stranger = '<jsons-' . $x . '@stranger.example.invalid>';
deliver(msg("Stu Stranger <stu-$x@stranger.example.invalid>", [MAILBOX], "Stranger $x", 'Who are you'));  // no message-id of our own: the fake's helper makes one
worker('mail_match');
$unm = (int) one("SELECT id FROM inbound_email WHERE from_address = :a", ['a' => "stu-$x@stranger.example.invalid"]);
$meet = act_id(26, ['kind' => 'meeting', 'subject' => "Json meet $x", 'deal' => $deal, 'contact' => $ann, 'starts_at' => at('+3 days 10:00')]);
$tpl = (int) post_as(26, '/email/templates/save.php', ['name' => "Json tpl $x", 'subject' => 's', 'body' => 'b'])['json']['record_id'];

echo "1. Every screen answers JSON\n";
$screens = ['/email/unmatched' => 'rows', "/email/compose?contact=$ann" => 'contacts', "/email/out/$out" => 'subject', "/email/in/$in" => 'thread', '/log/email?contact=' . $ann => 'directions',
    '/email/templates' => 'rows', '/email/templates/new' => 'merge_fields', "/email/templates/$tpl/edit" => 'template'];
foreach ($screens as $url => $key) {
    $g = get_as(26, $url);
    ok($g['code'] === 200 && array_key_exists($key, $g['json']['data'] ?? []), "GET $url as JSON: 200 with `$key`");
}
$g = get_as(26, "/email/out/$out")['json']['data'];
ok($g['direction'] === 'out' && $g['email_id'] === $out && $g['status'] === 'sent' && $g['body_text'] === 'JSON-BODY-SECRET' && $g['deal_id'] === $deal && $g['contact_ids'] === [$ann] && $g['may']['private_set'] === true && is_string($g['message_id']), 'get_email\'s shape (out): the body for its sender, the deal, the contacts, the Message-ID, the rights');
$g = get_as(26, "/email/in/$in")['json']['data'];
ok($g['direction'] === 'in' && $g['match_status'] === 'matched' && $g['activities'] !== [] && $g['attachments'] === [] && $g['thread'] !== [] && $g['may']['reply'] === true, 'get_email\'s shape (in): the match, the activities it made, the files, the conversation, Reply');
$g = get_as(30, "/email/out/$out")['json']['data'] ?? [];
ok(($g['body_text'] ?? 'x') === 'JSON-BODY-SECRET', 'a colleague reads the sent email\'s body when it is not private');
pg_run("UPDATE outbound_email SET is_private = true WHERE id = $out");
$g = get_as(30, "/email/out/$out");
ok($g['code'] === 404 && !str_contains($g['body'], 'JSON-BODY-SECRET'), 'a private email is the same 404 to one who may not read it — its body is never given');
$g = get_as(27, "/email/out/$out");
ok($g['code'] === 200 && $g['json']['data']['body_text'] === 'JSON-BODY-SECRET' && $g['json']['data']['is_private'] === true, 'and the manager reads it');
pg_run("UPDATE outbound_email SET is_private = false WHERE id = $out");
$g = get_as(26, '/email/unmatched')['json']['data'];
ok(array_filter($g['rows'], static fn ($r) => $r['email_id'] === $unm) !== [] && isset($g['drafts'], $g['pages'], $g['total']), 'unmatched: the rows, the drafts, the paging');
$r = html_as(26, "/email/in/$in", ['HX-Request: true']);
ok(!str_contains($r['body'], '<html') && preg_match('/^X-Screen: email-view\r?$/mi', $r['headers']) === 1 && preg_match('/^X-Record-Id: in-' . $in . '\r?$/mi', $r['headers']) === 1, 'over HTMX an email\'s page is the partial stamped email-view and its id');
foreach (['/email/unmatched' => 'email-unmatched', "/email/compose?contact=$ann" => 'email-compose', '/email/templates' => 'template-list', '/email/templates/new' => 'template-add', '/log/email' => 'log-email'] as $u => $screen) {
    ok(preg_match('/^X-Screen: ' . preg_quote($screen, '/') . '\r?$/mi', html_as(26, $u, ['HX-Request: true'])['headers']) === 1, "over HTMX $u is stamped $screen");
}

echo "2. Every handler answers JSON\n";
$checks = [
    ['/email/draft.php', ['contact' => $ann, 'subject' => "J draft $x", 'body' => 'b'], 'email_draft'],
    ['/email/send.php', ['contact' => $ann, 'subject' => "J send $x", 'body' => 'b', 'confirm' => 'yes'], 'email_send'],
    ['/log/email.php', ['contact' => $ann, 'direction' => 'in', 'subject' => "J log $x"], 'email_log'],
    ['/email/private.php', ['email' => $out, 'direction' => 'out', 'private' => 'yes'], 'email_private_set'],
    ['/email/make-lead.php', ['email' => $unm], 'email_make_lead'],
    ['/email/invite.php', ['activity' => $meet, 'message' => 'See you'], 'meeting_invite_send'],
    ['/email/templates/save.php', ['name' => "J tpl2 $x", 'subject' => 's', 'body' => 'b'], 'template_save'],
    ['/email/templates/delete.php', ['template' => $tpl], 'template_delete'],
];
foreach ($checks as [$path, $form, $name]) {
    $r = post_as(26, $path, $form);
    ok($r['code'] === 200 && ($r['json']['ok'] ?? false) === true && ($r['json']['did'] ?? '') !== '' && array_key_exists('record_id', $r['json']), "$name: {ok, did, record_id} — " . ($r['json']['did'] ?? $r['body']));
}
$stranger2 = "stu2-$x@stranger.example.invalid";
deliver(msg("Stu Two <$stranger2>", [MAILBOX], "Stranger two $x", 'Hello again'));
worker('mail_match');
$unm2 = (int) one("SELECT id FROM inbound_email WHERE from_address = :a", ['a' => $stranger2]);
$newc = contact_with("stu2-contact-$x@json-$x.example.invalid", 'Stu', $acc);
$r = post_as(26, '/email/attach.php', ['email' => $unm2, 'contact' => $newc]);
ok($r['code'] === 200 && ($r['json']['ok'] ?? false) === true && array_key_exists('record_id', $r['json']), 'email_attach_contact: {ok, did, record_id} — ' . ($r['json']['did'] ?? $r['body']));
$stranger3 = "stu3-$x@stranger.example.invalid";
deliver(msg("Stu Three <$stranger3>", [MAILBOX], "Stranger three $x", 'Hello once more'));
worker('mail_match');
$unm3 = (int) one("SELECT id FROM inbound_email WHERE from_address = :a", ['a' => $stranger3]);
$r = post_as(26, '/email/ignore.php', ['email' => $unm3]);
ok($r['code'] === 200 && ($r['json']['ok'] ?? false) === true && array_key_exists('record_id', $r['json']), 'email_ignore: {ok, did, record_id} — ' . ($r['json']['did'] ?? $r['body']));
$r = post_as(26, '/email/send.php', ['contact' => $ann, 'subject' => 's', 'body' => 'b']);
ok($r['code'] === 422 && ($r['json']['error']['code'] ?? '') === 'invalid' && isset($r['json']['error']['fields']['confirm']), 'a refusal: 422 invalid with the field named');
$r = post_as(26, '/email/draft.php', ['draft' => 999999999, 'subject' => 's']);
ok($r['code'] === 404 && ($r['json']['error']['code'] ?? '') === 'not_found', 'and a missing record is a 404 not_found');

echo "3. The log's vocabulary\n";
$rows = q("SELECT action, after::text AS after, before::text AS before FROM activity_log WHERE id > :s AND (action LIKE 'email.%' OR action LIKE 'template.%' OR action LIKE 'mail.%' OR action = 'contact.opt_out')", ['s' => $since]);
$seen = array_unique(array_column($rows, 'action'));
foreach (['email.draft', 'email.send', 'email.log', 'email.private', 'email.attach', 'email.make_lead', 'email.ignore', 'email.invite', 'template.save', 'template.delete', 'mail.received', 'mail.matched', 'mail.proposed'] as $ev) {
    ok(in_array($ev, $seen, true), "$ev written");
}
$all = json_encode($rows);
ok(!str_contains($all, 'JSON-BODY-SECRET') && !str_contains($all, 'Reply body') && !str_contains($all, 'Who are you'), 'a body is in no payload');
ok(preg_match('#/u/[a-f0-9]{48}#', json_encode(q("SELECT route FROM activity_log WHERE id > :s", ['s' => $since]))) === 0, 'and a token is in no route');
$keys = q("SELECT contact_id, deal_id FROM activity_log WHERE id > :s AND action = 'email.send' AND contact_id IS NOT NULL", ['s' => $since]);
ok(count($keys) >= 1 && (int) $keys[0]['contact_id'] === $ann, 'a send carries the contact and the deal as audit keys (the record\'s timeline)');
$tl = get_as(26, "/contacts/$ann?tab=history")['json']['data'] ?? [];
ok(json_encode($tl) !== '' && (int) one("SELECT count(*) FROM activity_log WHERE contact_id = :c AND action = 'email.send'", ['c' => $ann]) >= 2, 'and the contact\'s history has them');

echo "4. The worker's passes\n";
$w = worker('mail_poll,mail_match');
ok(isset($w['ran']['mail_poll']['received'], $w['ran']['mail_match']['matched']) && empty($w['ran']['mail_poll']['stub']) && empty($w['ran']['mail_match']['stub']) && ($w['errors'] ?? []) === [], 'mail_poll and mail_match are real passes: ' . json_encode($w['ran'] ?? $w));

echo "5. The registry\n";
exec('php ' . escapeshellarg(dirname(__DIR__, 3) . '/bin/build_action_registry.php') . ' --check 2>&1', $o, $rc);
ok($rc === 0, 'bin/build_action_registry.php --check: the registry and approvals[] match the manifest');
$reg = json_decode((string) file_get_contents(dirname(__DIR__, 3) . '/mcp/action_registry.json'), true);
$mine = ['email-compose', 'email-view', 'email-unmatched', 'log-email', 'template-list', 'template-add', 'template-edit'];
ok(array_filter($mine, static fn ($s) => empty($reg['screens'][$s]['built'])) === [], 'the 7 screens read as built');
$acts = ['email_draft', 'email_send', 'email_log', 'email_private_set', 'email_attach_contact', 'email_make_lead', 'email_ignore', 'meeting_invite_send', 'template_save', 'template_delete'];
ok(array_filter($acts, static fn ($a) => empty($reg['actions'][$a]['built'])) === [], 'the 10 actions read as built');
$paused = array_values(array_filter($acts, static fn ($a) => !empty($reg['actions'][$a]['approval'] ?? null)));
sort($paused);
ok($paused === ['email_send', 'meeting_invite_send', 'template_delete'], '3 approvals in step: ' . implode(', ', $paused));
ok(($reg['actions']['email_send']['approval'] ?? '') === 'external_send' && ($reg['actions']['meeting_invite_send']['approval'] ?? '') === 'external_send' && ($reg['actions']['template_delete']['approval'] ?? '') === 'deletion', 'the categories: external_send, external_send, deletion');
$os = json_decode((string) file_get_contents(dirname(__DIR__, 3) . '/maludb-os.json'), true);
$ap = array_filter($os['approvals'] ?? [], static fn ($a) => in_array($a['action'] ?? ($a['tool'] ?? ''), ['email_send', 'meeting_invite_send', 'template_delete'], true));
ok(count($ap) === 3, 'maludb-os.json approvals[] holds the three');
$people = array_values(array_filter($acts, static fn ($a) => empty($reg['actions'][$a]['agent'])));
ok($people === [], 'all ten are open to an agent (the pause is the approval, not a refusal)');
finish();
