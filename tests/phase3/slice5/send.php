<?php
/**
 * Proof — sending (email.md "Proof"): the fake MaluMail receives From, the seller's name, Reply-To the logging mailbox, a Message-ID of ours, the footer with the unsubscribe link, the files and
 * the meeting's ICS; the opt-out refused by name before anything is sent; merge fields left standing refused; several To's as separate messages; a reply to our Message-ID — or to the id the
 * provider answered with — lands on the deal; a failure leaves a draft to send again; private hides from another seller and not from a manager. The unsubscribe token is nowhere in the database.
 */
require __DIR__ . '/lib.php';
$x = run_tag();
$mailbox = ensure_mailbox();
$since = last_activity_id();
$acc = (int) post_as(26, '/accounts/save.php', ['name' => "Send Acct $x"])['json']['record_id'];
$ann = contact_with("ann-$x@send-$x.example.invalid", 'Ann', $acc);
$bob = contact_with("bob-$x@send-$x.example.invalid", 'Bob', $acc);
$cat = contact_with("cat-$x@send-$x.example.invalid", 'Cat', $acc);
$deal = deal_make(26, ['title' => "Send deal $x", 'account' => $acc, 'primary_contact' => $ann]);
post_as(26, '/contacts/opt-out.php', ['contact' => $bob, 'channel' => 'email', 'reason' => 'asked']);
$intro = (int) one("SELECT id FROM email_templates WHERE name = 'Introduction' AND is_shared");
$uses = (int) one('SELECT usage_count FROM email_templates WHERE id = :i', ['i' => $intro]);
$token = static function (array $mail): string { preg_match('#/u/([a-f0-9]{48})#', (string) ($mail['text'] ?? ''), $m); return $m[1] ?? ''; };

echo "1. Send needs its word; nothing goes without it\n";
$n0 = count(sends());
$r = post_as(26, '/email/send.php', ['contact' => $ann, 'subject' => "Hello $x", 'body' => 'Hi.']);
ok($r['code'] === 422 && field_of($r) === 'confirm' && count(sends()) === $n0, 'no confirm: the field is named and nothing is sent');
$r = post_as(29, '/email/send.php', ['contact' => $ann, 'subject' => 's', 'body' => 'b', 'confirm' => 'yes']);
ok($r['code'] === 403, 'a Viewer: 403');
$mkt = fixture()['claims']['31']; $mkt['roles'] = ['marketing']; $mkt['role'] = 'marketing';
$mj = sign_on(31, ['claims' => $mkt])[0];
$res = req('POST', '/email/send.php', ['jar' => $mj, 'headers' => ['Accept: application/json', 'X-CSRF-Token: ' . page_csrf($mj)], 'form' => ['contact' => $ann, 'subject' => 's', 'body' => 'b', 'confirm' => 'yes']]);
ok($res['code'] === 403 && count(sends()) === $n0, 'Marketing holds no email.send: 403, nothing sent');

echo "2. A send: what MaluMail receives\n";
$r = post_as(26, '/email/send.php', ['contact' => $ann, 'deal' => $deal, 'template' => $intro, 'subject' => "Hello from us $x", 'body' => "Hi {{contact.first_name}},\n\nThis is for {{account.name}}: **please** read [our page](https://example.invalid/page).\n\n{{sender.signature}}", 'confirm' => 'yes']);
$oid = (int) ($r['json']['record_id'] ?? 0);
ok($r['code'] === 200 && $oid > 0 && ($r['json']['ok'] ?? false) && ($r['json']['sent'] ?? 0) === 1, 'email_send: ' . ($r['json']['did'] ?? $r['body']));
$mail = last_send();
ok(count(sends()) === $n0 + 1 && $mail['to'] === "ann-$x@send-$x.example.invalid" && $mail['from'] === 'crm@example.invalid' && $mail['from_name'] === 'SMOKE Priya', 'one message: To Ann, From the business\'s address under Priya\'s name');
ok(($mail['reply_to'] ?? null) === MAILBOX, 'Reply-To is the logging mailbox');
ok($mail['subject'] === "Hello from us $x" && str_starts_with($mail['text'], "Hi Ann,\n\nThis is for Send Acct $x:") && str_contains($mail['html'], '<strong>please</strong>') && str_contains($mail['html'], '<a href="https://example.invalid/page">our page</a>'), 'the merge is filled and the Markdown is HTML');
$tok = $token($mail);
ok(strlen($tok) === 48 && str_contains($mail['html'], 'href="http://127.0.0.1:8801/u/' . $tok . '"') && str_contains($mail['text'], 'unsubscribe: http://127.0.0.1:8801/u/' . $tok), 'the footer carries an unsubscribe link, in both parts, the same token');
ok(preg_match('/^<([a-f0-9]{64})@example\.invalid>$/', (string) $mail['message_id'], $mm) === 1 && $mm[1] === hash('sha256', $tok), 'the Message-ID is ours, and is the SHA-256 of that token at the sending domain');
ok(!isset($mail['in_reply_to']) && !isset($mail['attachments']), 'no In-Reply-To, no files');
$o = q('SELECT * FROM outbound_email WHERE id = :i', ['i' => $oid])[0];
ok($o['status'] === 'sent' && $o['sent_at'] !== null && $o['message_id'] === $mail['message_id'] && $o['error'] === null && $o['provider_message_id'] === $mail['message_id'] && (int) $o['template_id'] === $intro, 'the row: sent, its time, our Message-ID, the provider\'s answer, the template');
ok(str_starts_with((string) $o['body_text'], 'Hi Ann,') && !str_contains((string) $o['body_text'] . (string) $o['body_html'], $tok), 'it keeps what was written (merged); the token is not in it');
$act = email_acts('outbound_email_id', $oid);
ok(count($act) === 1 && $act[0]['kind'] === 'email' && $act[0]['email_direction'] === 'out' && $act[0]['done_at'] !== null && (int) $act[0]['contact_id'] === $ann && (int) $act[0]['deal_id'] === $deal && (int) $act[0]['owner_member_id'] === 26, 'an email activity, out, done, on the contact and the deal, Priya\'s');
ok((int) one('SELECT usage_count FROM email_templates WHERE id = :i', ['i' => $intro]) === $uses + 1, 'the template\'s use is counted');
$dirty = 0;
foreach (['outbound_email', 'inbound_email', 'activities', 'notes', 'notifications', 'notification_outbox', 'activity_log', 'contacts', 'leads'] as $t) {
    $dirty += (int) one("SELECT count(*) FROM $t x WHERE row_to_json(x)::text LIKE :t", ['t' => '%' . $tok . '%']);
}
ok($dirty === 0, 'the token is in no row of the database (only its SHA-256, in the Message-ID)');
$ev = q("SELECT after::text AS after FROM activity_log WHERE action = 'email.send' AND entity_id = :i", ['i' => $oid]);
ok(count($ev) === 1 && !str_contains($ev[0]['after'], 'Hi Ann') && json_decode($ev[0]['after'], true)['status'] === 'sent', 'email.send is logged with ids and the status, never the text');
$r2 = post_as(26, '/email/send.php', ['draft' => $oid, 'confirm' => 'yes']);
ok($r2['code'] === 404 && count(sends()) === $n0 + 1, 'sending it again is refused: it is not a draft any more');
ok(html_as(30, "/email/out/$oid")['code'] === 200 && str_contains(html_as(30, "/contacts/$ann?tab=emails")['body'], 'id="contact-email-out-' . $oid . '"'), 'a colleague sees the sent email, and it is on the contact\'s tab');

echo "3. Refusals before anything goes\n";
$n1 = count(sends());
$r = post_as(26, '/email/send.php', ['contact' => $bob, 'subject' => 's', 'body' => 'b', 'confirm' => 'yes']);
ok($r['code'] === 422 && str_contains((string) ($r['json']['error']['message'] ?? ''), 'Bob Mail') && str_contains((string) ($r['json']['error']['message'] ?? ''), 'opted out'), 'an opted-out contact is refused by name: ' . ($r['json']['error']['message'] ?? $r['body']));
$r = post_as(26, '/email/send.php', ['contacts' => [$ann, $bob], 'subject' => 's', 'body' => 'b', 'confirm' => 'yes']);
ok($r['code'] === 422 && count(sends()) === $n1, 'with an opted-out contact among several, nobody is sent to');
$r = post_as(26, '/email/send.php', ['contact' => $ann, 'subject' => 'About {{deal.title}}', 'body' => 'b', 'confirm' => 'yes']);
ok($r['code'] === 422 && str_contains((string) ($r['json']['error']['message'] ?? ''), '{{deal.title}}') && count(sends()) === $n1, 'a merge field with nothing to fill it is refused, named: ' . ($r['json']['error']['message'] ?? $r['body']));
$left = (int) one("SELECT id FROM outbound_email WHERE subject = 'About {{deal.title}}' AND sender_member_id = 26");
ok($left > 0 && one('SELECT status FROM outbound_email WHERE id = :i', ['i' => $left]) === 'queued', 'and it stays a draft to mend');
$r = post_as(26, '/email/send.php', ['contact' => $ann, 'subject' => 's', 'body' => 'Dear {{foo.bar}}', 'confirm' => 'yes']);
ok($r['code'] === 422 && str_contains((string) ($r['json']['error']['message'] ?? ''), '{{foo.bar}}'), 'so is a field that is none of ours');
$r = post_as(30, '/email/send.php', ['draft' => $left, 'confirm' => 'yes']);
ok($r['code'] === 404 && count(sends()) === $n1, 'another seller cannot send Priya\'s draft: 404');

echo "4. Several people: one message each, filled for each\n";
$r = post_as(26, '/email/send.php', ['contacts' => [$ann, $cat], 'deal' => $deal, 'subject' => "For both $x", 'body' => "Dear {{contact.first_name}} ({{contact.name}}),\n\nThanks.", 'confirm' => 'yes']);
ok($r['code'] === 200 && ($r['json']['sent'] ?? 0) === 2 && count($r['json']['outbound_email_ids'] ?? []) === 2, 'email_send to two: ' . ($r['json']['did'] ?? $r['body']));
$two = array_slice(sends(), -2);
$byTo = [];
foreach ($two as $m) { $byTo[$m['to']] = $m; }
$a1 = $byTo["ann-$x@send-$x.example.invalid"] ?? [];
$c1 = $byTo["cat-$x@send-$x.example.invalid"] ?? [];
ok(str_starts_with((string) ($a1['text'] ?? ''), "Dear Ann (Ann Mail $x)") && str_starts_with((string) ($c1['text'] ?? ''), "Dear Cat (Cat Mail $x)"), 'each person\'s message names them');
ok(($a1['message_id'] ?? 'a') !== ($c1['message_id'] ?? 'a') && $token($a1) !== $token($c1) && $token($a1) !== '', 'each has its own Message-ID and its own unsubscribe link');
ok((int) one("SELECT count(*) FROM outbound_email WHERE id = ANY (CAST(:ids AS bigint[])) AND status = 'sent' AND cc_addresses = '{}'", ['ids' => '{' . implode(',', $r['json']['outbound_email_ids']) . '}']) === 2, 'two rows, both sent, neither copying the other');

echo "5. A reply lands on the deal — by our Message-ID, by the provider's\n";
$thread = '<reply1-' . $x . '@send-' . $x . '.example.invalid>';
deliver(msg("Ann <ann-$x@send-$x.example.invalid>", [MAILBOX], 'Re: Hello from us', 'Thanks, yes.', [], ['message-id' => $thread, 'in-reply-to' => $mail['message_id'], 'references' => $mail['message_id']]));
worker('mail_match');
$rin = inbound($thread);
ok($rin['match_status'] === 'matched' && (int) $rin['deal_id'] === $deal && str_contains((string) $rin['matched_contact_ids'], (string) $ann), 'a reply to our Message-ID is on the deal (no tag in its subject)');
$prov = '<provider-' . $x . '@mm.example.invalid>';
pg_run("UPDATE outbound_email SET provider_message_id = '$prov' WHERE id = $oid");
$thread2 = '<reply2-' . $x . '@send-' . $x . '.example.invalid>';
deliver(msg("Ann <ann-$x@send-$x.example.invalid>", [MAILBOX], 'Re: Hello again', 'And another.', [], ['message-id' => $thread2, 'in-reply-to' => $prov]));
worker('mail_match');
ok((int) inbound($thread2)['deal_id'] === $deal, 'and when the provider answered with an id of its own, a reply to that one lands on the deal too');
$j = get_as(26, '/email/in/' . $rin['id'])['json']['data'] ?? [];
ok(array_filter($j['thread'] ?? [], static fn ($t) => $t['direction'] === 'out' && $t['email_id'] === $oid) !== [], 'the conversation on the reply\'s page includes the email we sent');
$h = html_as(26, '/email/in/' . $rin['id'])['body'];
ok(str_contains($h, 'id="email-thread-out-' . $oid . '"') && str_contains($h, 'id="email-reply-btn"'), 'and the page shows it, with Reply');

echo "6. Answering: In-Reply-To and References\n";
$r = post_as(26, '/email/send.php', ['contact' => $ann, 'deal' => $deal, 'subject' => 'Re: Hello from us', 'body' => 'Glad to hear it.', 'reply' => $rin['id'], 'confirm' => 'yes']);
ok($r['code'] === 200, 'the reply is sent');
$mail = last_send();
ok(($mail['in_reply_to'] ?? null) === $thread && in_array($thread, (array) ($mail['references'] ?? []), true) && in_array($mail['message_id'], [$mail['message_id']], true), 'the message carries In-Reply-To and References naming what it answers');
ok(one('SELECT in_reply_to FROM outbound_email WHERE id = :i', ['i' => $r['json']['record_id']]) === $thread, 'and the row keeps it');

echo "7. Files and the meeting's invitation travel with it\n";
$f = tempnam(sys_get_temp_dir(), 'crmf') . '.txt';
file_put_contents($f, "the price list $x\n");
$up = upload_as(26, '/attachments/add.php', ['kind' => 'deal', 'record' => $deal], [$f, 'text/plain', 'prices.txt']);
$att = (int) one("SELECT id FROM attachments WHERE record_type = 'deal' AND record_id = :d ORDER BY id DESC LIMIT 1", ['d' => $deal]);
$meet = act_id(26, ['kind' => 'meeting', 'subject' => "Demo $x", 'deal' => $deal, 'starts_at' => at('+3 days 10:00'), 'ends_at' => at('+3 days 11:00'), 'location' => 'Their office', 'body' => 'INTERNAL-NOTES do not share']);
$r = post_as(26, '/email/send.php', ['contact' => $ann, 'deal' => $deal, 'subject' => "With extras $x", 'body' => 'See attached.', 'attachments' => [$att], 'activity' => $meet, 'confirm' => 'yes']);
ok($r['code'] === 200, 'a send with a file and a meeting: ' . ($r['json']['did'] ?? $r['body']));
$mail = last_send();
$files = array_column((array) ($mail['attachments'] ?? []), null, 'filename');
ok(isset($files['prices.txt']) && base64_decode($files['prices.txt']['content_base64']) === "the price list $x\n" && $files['prices.txt']['content_type'] === 'text/plain', 'the file arrives with its bytes and type');
$ics = isset($files['invite.ics']) ? base64_decode($files['invite.ics']['content_base64']) : '';
ok(str_contains($files['invite.ics']['content_type'] ?? '', 'method=REQUEST') && str_contains($ics, "METHOD:REQUEST\r\n") && str_contains($ics, "SUMMARY:Demo $x\r\n") && str_contains($ics, 'LOCATION:Their office'), 'the invitation: text/calendar; method=REQUEST, the subject alone as the summary, the place');
$ics = str_replace("\r\n ", '', $ics);                                          // unfold long lines
ok(str_contains($ics, 'ORGANIZER;CN="SMOKE Priya":mailto:priya@example.invalid') && str_contains($ics, "ATTENDEE;CN=\"Ann Mail $x\";ROLE=REQ-PARTICIPANT;PARTSTAT=NEEDS-ACTION;RSVP=TRUE:mailto:ann-$x@send-$x.example.invalid"), 'Priya organizes, Ann attends');
ok(!str_contains($ics, 'INTERNAL-NOTES') && !str_contains($ics, '/activities/') && !str_contains($ics, 'URL:' . app_url_for_test()) && str_contains($ics, "UID:crm-activity-$meet@"), 'nothing internal is in it: not the notes, not the CRM\'s own address; the UID is the activity\'s');
$o = q('SELECT has_ics, activity_id FROM outbound_email WHERE id = :i', ['i' => $r['json']['record_id']])[0];
ok($o['has_ics'] === true && (int) $o['activity_id'] === $meet, 'the row says an invitation went');
$r = post_as(26, '/email/send.php', ['contact' => $ann, 'subject' => 'x', 'body' => 'y', 'activity' => $meet - 0, 'confirm' => 'yes'] + ['attachments' => [999999999]]);
ok($r['code'] === 200 && (int) one("SELECT count(*) FROM attachments WHERE record_type = 'outbound_email' AND record_id = :i", ['i' => $r['json']['record_id']]) === 0, 'an attachment id that is not there is dropped, not trusted');
$other = (int) post_as(30, '/accounts/save.php', ['name' => "Dana acct $x"])['json']['record_id'];
$dmeet = act_id(30, ['kind' => 'meeting', 'subject' => "Dana's meeting $x", 'account' => $other, 'starts_at' => at('+3 days 12:00')]);
$r = post_as(26, '/email/send.php', ['contact' => $ann, 'subject' => 'x', 'body' => 'y', 'activity' => $dmeet, 'confirm' => 'yes']);
ok($r['code'] === 422 && field_of($r) === 'activity', 'another seller\'s meeting\'s invitation is not Priya\'s to send: ' . ($r['json']['error']['message'] ?? $r['body']));

echo "8. A failure leaves a draft; a suppressed address does not\n";
$flaky = contact_with("flaky-$x@send-$x.example.invalid", 'Flo', $acc);
$n2 = count(sends());
$r = post_as(26, '/email/send.php', ['contact' => $flaky, 'subject' => "Flaky $x", 'body' => 'b', 'confirm' => 'yes']);
$fo = q("SELECT * FROM outbound_email WHERE subject = :s", ['s' => "Flaky $x"])[0] ?? [];
ok($r['code'] === 422 && str_contains((string) ($r['json']['error']['message'] ?? ''), 'send it again') && $fo['status'] === 'failed' && $fo['sent_at'] === null && str_contains((string) $fo['error'], '502'), 'MaluMail refusing leaves a failed row with the sentence: ' . ($r['json']['error']['message'] ?? $r['body']));
ok(email_acts('outbound_email_id', (int) $fo['id']) === [] && html_as(26, '/email/compose?draft=' . $fo['id'])['code'] === 200, 'no activity was logged, and it is still a draft Priya can open and send again');
$supp = contact_with("suppressed-$x@send-$x.example.invalid", 'Sue', $acc);
$r = post_as(26, '/email/send.php', ['contact' => $supp, 'subject' => "Supp $x", 'body' => 'b', 'confirm' => 'yes']);
$so = q("SELECT * FROM outbound_email WHERE subject = :s", ['s' => "Supp $x"])[0] ?? [];
ok($r['code'] === 422 && $so['status'] === 'suppressed' && str_contains((string) $so['error'], 'suppressed') && email_acts('outbound_email_id', (int) $so['id']) === [], 'a suppressed address: the row is suppressed with MaluMail\'s reason, nothing logged');
ok(html_as(26, '/email/compose?draft=' . $so['id'])['code'] === 404, 'and it is not a draft to send again');

echo "9. Private: its sender and the managers\n";
$pm = '<priv-' . $x . '@send-' . $x . '.example.invalid>';
$sentId = (int) q("SELECT id FROM outbound_email WHERE subject = :s AND status = 'sent'", ['s' => "Hello from us $x"])[0]['id'];
$actId = (int) email_acts('outbound_email_id', $sentId)[0]['id'];
$r = post_as(30, '/email/private.php', ['email' => $sentId, 'direction' => 'out', 'private' => 'yes']);
ok($r['code'] === 403, 'another seller may not mark it private: 403');
$r = post_as(26, '/email/private.php', ['email' => $sentId, 'direction' => 'out', 'private' => 'yes']);
ok($r['code'] === 200 && one('SELECT is_private FROM outbound_email WHERE id = :i', ['i' => $sentId]) === true && one('SELECT is_private FROM activities WHERE id = :a', ['a' => $actId]) === true, 'the sender marks it private; the activity it made follows');
ok(html_as(26, "/email/out/$sentId")['code'] === 200 && str_contains(html_as(26, "/email/out/$sentId")['body'], 'id="email-view-private"'), 'Priya still sees it, with the lock');
ok(html_as(30, "/email/out/$sentId")['code'] === 404 && get_as(30, "/activities/$actId")['code'] === 404, 'another seller: the email and its activity are the same 404 as a missing one');
ok(!str_contains(html_as(30, "/contacts/$ann?tab=emails")['body'], 'id="contact-email-out-' . $sentId . '"') && !str_contains(html_as(30, "/deals/$deal?tab=emails")['body'], 'id="deal-email-out-' . $sentId . '"'), 'and it is not on their lists');
ok(html_as(27, "/email/out/$sentId")['code'] === 200 && !str_contains(html_as(27, "/email/out/$sentId")['body'], 'id="email-view-body-private"'), 'the manager reads it, body and all');
$r = post_as(27, '/email/private.php', ['email' => $sentId, 'direction' => 'out', 'private' => 'no']);
ok($r['code'] === 200 && html_as(30, "/email/out/$sentId")['code'] === 200, 'a manager makes it public again; the colleague sees it once more');
$r = post_as(26, '/email/private.php', ['email' => $rin['id'], 'direction' => 'in', 'private' => 'yes']);
ok($r['code'] === 403, 'a reply a contact wrote has no sender of ours: Priya may not mark it private: 403');
$r = post_as(27, '/email/private.php', ['email' => $rin['id'], 'direction' => 'in', 'private' => 'yes']);
ok($r['code'] === 200 && html_as(26, '/email/in/' . $rin['id'])['code'] === 404 && html_as(27, '/email/in/' . $rin['id'])['code'] === 200, 'a manager marks it private: hidden from Priya, kept for managers');
post_as(27, '/email/private.php', ['email' => $rin['id'], 'direction' => 'in', 'private' => 'no']);
$r = post_as(26, '/email/private.php', ['email' => $sentId, 'direction' => 'sideways', 'private' => 'yes']);
ok($r['code'] === 422 && field_of($r) === 'direction', 'a direction that is neither in nor out: named');

echo "10. email_log: an email sent elsewhere\n";
$g = get_as(26, "/log/email?contact=$ann&deal=$deal");
ok($g['code'] === 200 && ($g['json']['data']['contact']['contact_id'] ?? 0) === $ann && ($g['json']['data']['deal_id'] ?? 0) === $deal, 'the log screen answers JSON with the contact and the deal');
$h = html_as(26, "/log/email?contact=$ann&deal=$deal")['body'];
ok(str_contains($h, 'id="log-email-form"') && str_contains($h, 'id="log-email-field-direction-out"') && str_contains($h, 'id="log-email-field-subject"') && str_contains($h, 'id="log-email-field-body"'), 'the screen: who, which way, subject, the pasted text');
$r = post_as(26, '/log/email.php', ['contact' => $ann, 'direction' => 'in', 'subject' => "Pasted $x", 'body' => 'PASTED-SECRET-TEXT from outlook', 'deal' => $deal, 'at' => at('-2 days 09:30')]);
$la = (int) ($r['json']['record_id'] ?? 0);
$lr = $la ? act_row($la) : [];
ok($r['code'] === 200 && $lr['kind'] === 'email' && $lr['email_direction'] === 'in' && $lr['done_at'] !== null && (int) $lr['contact_id'] === $ann && (int) $lr['deal_id'] === $deal && str_contains((string) $lr['body'], 'PASTED-SECRET-TEXT') && $lr['source'] === 'web', 'email_log makes one done email activity with the pasted text: ' . ($r['json']['did'] ?? $r['body']));
ok(substr((string) $lr['due_at'], 0, 10) === substr(at('-2 days 09:30'), 0, 10), 'at the time said, not now');
$r = post_as(26, '/log/email.php', ['contacts' => [$ann, $bob], 'direction' => 'out', 'subject' => "Two logged $x"]);
ok($r['code'] === 200 && count($r['json']['activity_ids'] ?? []) === 2, 'an opted-out contact can still have what passed logged; one activity per person');
$r = post_as(26, '/log/email.php', ['contact' => $ann, 'subject' => 's']);
ok($r['code'] === 422 && field_of($r) === 'direction', 'no direction: named');
$r = post_as(26, '/log/email.php', ['contact' => $ann, 'direction' => 'in', 'subject' => 's', 'at' => at('+3 days 09:00')]);
ok($r['code'] === 422 && field_of($r) === 'at', 'an email sent in the future: refused');
$r = post_as(29, '/log/email.php', ['contact' => $ann, 'direction' => 'in', 'subject' => 's']);
ok($r['code'] === 403, 'a Viewer may not log: 403');
$r = post_as(26, '/records/delete.php', ['kind' => 'activity', 'record' => $la]);
ok($r['code'] === 200, 'the undo (record_delete) takes it away');
$rows = q("SELECT action, after::text AS after FROM activity_log WHERE id > :s AND action IN ('email.log', 'email.send', 'email.private')", ['s' => $since]);
ok(count($rows) > 5 && !str_contains(json_encode($rows), 'PASTED-SECRET-TEXT') && !str_contains(json_encode($rows), 'Glad to hear it'), 'email.log/send/private are logged; no text is in any payload');
finish();

function app_url_for_test(): string { return 'http://127.0.0.1:8801/activities/'; }
