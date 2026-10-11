<?php
/**
 * Proof — the logging mailbox: the signed webhook, the poll, the worker's matcher (email.md "Proof", the Phase 0 proof's mailbox cases through the webhook and the worker): two contacts
 * and a deal by the subject tag, a reply by thread, a stranger proposed, a bounce and an auto-reply ignored, a duplicate message id refused, a message the webhook missed brought by the
 * poll, a read that failed retried, a file on its activity, the unmatched screen with Attach · Make lead · Ignore, the log's vocabulary (a body never in it).
 */
require __DIR__ . '/lib.php';
$x = run_tag();
$mb = ensure_mailbox();
$since = last_activity_id();
$acc = (int) post_as(26, '/accounts/save.php', ['name' => "Mail Acct $x"])['json']['record_id'];
$dana = contact_with("dana-$x@acme-$x.example.invalid", 'Dana', $acc);
$sam = contact_with("sam-$x@acme-$x.example.invalid", 'Sam', $acc);
$deal = deal_make(26, ['title' => "Mail deal $x", 'account' => $acc, 'primary_contact' => $dana]);
$number = (string) one('SELECT number FROM deals WHERE id = :d', ['d' => $deal]);

echo "1. The webhook is a door that opens only to a good signature\n";
$p = ['type' => 'message.new', 'address' => MAILBOX, 'folder' => 'INBOX', 'uid' => 999, 'message_id' => '<nope@x>'];
ok(notice($p, ['secret' => 'not-the-secret'])['code'] === 403, 'a wrong secret: 403');
ok(notice($p, ['t' => time() - 900])['code'] === 403, 'a notice signed 15 minutes ago: 403');
ok(notice($p, ['sig' => 'v1=deadbeef'])['code'] === 403, 'a malformed signature: 403');
ok(notice(['address' => 'nobody@example.invalid'] + $p)['code'] === 403, 'a mailbox we do not have: 403 — the same answer');
ok(req('GET', '/channels/malumail/mail')['code'] === 405 && req('POST', '/channels/malumail/mail', ['raw' => 'not json'])['code'] === 403, 'a GET is 405; a body that is no notice is 403');
ok((int) one('SELECT count(*) FROM inbound_email WHERE message_id = :m', ['m' => '<nope@x>']) === 0, 'nothing was written by any of them');
ok(notice(['type' => 'message.new', 'address' => MAILBOX, 'folder' => 'Junk', 'uid' => 998])['code'] === 204, 'a good notice for another folder is answered 204 and ignored');

echo "2. A seller's BCC: stored raw by the webhook, matched by the worker\n";
$m1 = '<m1-' . $x . '@mail.example.invalid>';
$d = deliver(msg('Priya <priya@example.invalid>', ["dana-$x@acme-$x.example.invalid"], "Training proposal [$number]", 'Attached is the proposal. SECRET-BODY-1',
    [], ['message-id' => $m1, 'cc' => "sam-$x@acme-$x.example.invalid, " . MAILBOX]));
ok($d['webhook'] === 204, 'MaluMail\'s signed notice is answered 204');
$r = inbound($m1);
ok($r !== [] && $r['status'] === 'received' && $r['match_status'] === 'pending' && str_contains((string) $r['body_text'], 'proposal') && $r['from_address'] === 'priya@example.invalid', 'the row is kept raw: received, pending, the text and the sender');
ok(email_acts('inbound_email_id', (int) $r['id']) === [] && (int) one('SELECT count(*) FROM leads WHERE proposed_from_email_id = :e', ['e' => $r['id']]) === 0, 'the webhook request matched nothing: no activity yet, no lead');
ok(one('SELECT last_webhook_at IS NOT NULL FROM mailboxes WHERE id = :i', ['i' => $mb]) === true, 'the mailbox records the webhook');
$st = fake_state();
ok(in_array('\\Seen', $st['mailboxes'][MAILBOX][(string) $d['uid']]['flags'] ?? [], true), 'the message is marked read in the mailbox once kept');
$w = worker('mail_match');
ok(($w['ran']['mail_match']['matched'] ?? 0) >= 1, 'the worker\'s mail_match pass matches it: ' . json_encode($w['ran']['mail_match'] ?? $w));
$r = inbound($m1);
ok($r['match_status'] === 'matched' && $r['status'] === 'processed' && (int) $r['deal_id'] === $deal && (int) $r['sender_member_id'] === 26 && count(pg_ints($r['matched_contact_ids'])) === 2, 'matched to the seller, the deal by the [D-…] tag in the subject, and two contacts');
$acts = email_acts('inbound_email_id', (int) $r['id']);
ok(count($acts) === 2 && $acts[0]['kind'] === 'email' && $acts[0]['email_direction'] === 'out' && (int) $acts[0]['deal_id'] === $deal && $acts[0]['done_at'] !== null && $acts[0]['source'] === 'mailbox', 'two email activities, direction out, done, on the deal');
ok((int) one('SELECT count(*) FROM activity_log WHERE action = \'mail.matched\' AND entity_id = :e', ['e' => $r['id']]) === 1, 'logged mail.matched once');

echo "3. A reply by thread lands on the deal; the owner is told\n";
$m2 = '<m2-' . $x . '@acme.example.invalid>';
$d = deliver(msg("Dana <dana-$x@acme-$x.example.invalid>", [MAILBOX, 'priya@example.invalid'], 'Re: Training proposal', 'Looks good, let us talk Tuesday. SECRET-BODY-2', [],
    ['message-id' => $m2, 'in-reply-to' => $m1, 'references' => $m1]));
ok($d['webhook'] === 204, 'the reply\'s notice is answered 204');
worker('mail_match');
$r2 = inbound($m2);
ok($r2['match_status'] === 'matched' && (int) $r2['deal_id'] === $deal && $r2['sender_member_id'] === null, 'the reply is on the deal by thread (no tag in its subject); its sender is no member');
$a2 = email_acts('inbound_email_id', (int) $r2['id']);
ok(count($a2) === 1 && $a2[0]['email_direction'] === 'in' && (int) $a2[0]['contact_id'] === $dana && (int) $a2[0]['owner_member_id'] === 26, 'one email activity, direction in, on Dana, owned by Dana\'s owner');
ok((int) one("SELECT count(*) FROM notifications WHERE kind = 'reply_received' AND member_id = 26 AND record_id = :e", ['e' => $r2['id']]) === 1, 'the deal\'s owner has a reply_received notification');

echo "4. A stranger is proposed as a lead; a bounce and an auto-reply make nothing\n";
$m3 = '<m3-' . $x . '@stranger.example.invalid>';
deliver(msg("Maria K <maria-$x@stranger.example.invalid>", [MAILBOX], 'Pricing?', 'Hello, how much for 10 seats? SECRET-BODY-3', [], ['message-id' => $m3]));
$m4 = '<m4-' . $x . '@mailer.example.invalid>';
deliver(msg('Mail Delivery <mailer-daemon@example.invalid>', [MAILBOX], 'Undelivered Mail Returned to Sender', 'Original-Message-ID: <gone@x>', [], ['message-id' => $m4]));
$m5 = '<m5-' . $x . '@away.example.invalid>';
deliver(msg("Dana <dana-$x@acme-$x.example.invalid>", [MAILBOX], 'Out of office', 'I am away. SECRET-BODY-5', [], ['message-id' => $m5, 'auto-submitted' => 'auto-replied']));
ok(inbound($m4)['status'] === 'bounce' && inbound($m5)['status'] === 'auto_reply', 'a delivery report is kept as a bounce, an out-of-office as an auto-reply');
$w = worker('mail_match')['ran']['mail_match'] ?? [];
$r3 = inbound($m3);
ok($r3['match_status'] === 'proposed_lead' && (int) one('SELECT count(*) FROM leads WHERE proposed_from_email_id = :e AND is_proposed AND first_name = \'Maria\' AND owner_member_id IS NULL', ['e' => $r3['id']]) === 1, 'the stranger is a proposed lead: unowned, in the inbox, the name split');
ok(inbound($m4)['match_status'] === 'bounce' && inbound($m5)['match_status'] === 'ignored' && email_acts('inbound_email_id', (int) inbound($m4)['id']) === [] && email_acts('inbound_email_id', (int) inbound($m5)['id']) === [], 'the bounce and the auto-reply are ignored: no activity, no lead');
ok(($w['proposed'] ?? 0) >= 1 && ($w['ignored'] ?? 0) >= 2, 'the pass counted them: ' . json_encode($w));

echo "5. A duplicate message id is refused\n";
$before = (int) one('SELECT count(*) FROM inbound_email');
$d = deliver(msg("Maria K <maria-$x@stranger.example.invalid>", [MAILBOX], 'Pricing? (again)', 'the same message', [], ['message-id' => $m3]));
ok($d['webhook'] === 204 && (int) one('SELECT count(*) FROM inbound_email') === $before, 'the same Message-ID under a new uid: 204 and no second row');
$r = notice(['type' => 'message.new', 'address' => MAILBOX, 'folder' => 'INBOX', 'uid' => (int) one('SELECT provider_uid FROM inbound_email WHERE message_id = :m', ['m' => $m1])]);
ok($r['code'] === 204 && (int) one('SELECT count(*) FROM inbound_email') === $before, 'the same notice twice (a retry): 204 and no second row');

echo "6. The poll brings what the webhook missed; a failed read is tried again\n";
$m6 = '<m6-' . $x . '@acme.example.invalid>';
$d = deliver(msg("Sam <sam-$x@acme-$x.example.invalid>", [MAILBOX], 'Missed by the webhook', 'Sam writes. SECRET-BODY-6', ['notify' => false], ['message-id' => $m6]));
ok($d['webhook'] === null && inbound($m6) === [], 'a message the webhook never announced is not a row');
$w = worker('mail_poll')['ran']['mail_poll'] ?? [];
ok(($w['received'] ?? 0) >= 1 && inbound($m6)['status'] === 'received' && inbound($m6)['match_status'] === 'pending', 'the poll made its row (received, pending): ' . json_encode($w));
$w2 = worker('mail_poll')['ran']['mail_poll'] ?? [];
ok(($w2['received'] ?? 1) === 0 && (int) one('SELECT count(*) FROM inbound_email WHERE message_id = :m', ['m' => $m6]) === 1, 'a second poll finds nothing new and makes no second row');
ok(one('SELECT last_polled_at IS NOT NULL FROM mailboxes WHERE id = :i', ['i' => $mb]) === true, 'the mailbox records the poll');
$m7 = '<m7-' . $x . '@acme.example.invalid>';
$d = deliver(msg("Sam <sam-$x@acme-$x.example.invalid>", [MAILBOX], 'Locked at first', 'Sam again. SECRET-BODY-7', ['notify' => false], ['message-id' => $m7]));
fake_state(static function (array $s) use ($d): array { $s['mailboxes'][MAILBOX][(string) $d['uid']]['refuse_read'] = true; return $s; });
$w = worker('mail_poll')['ran']['mail_poll'] ?? [];
$failed = q("SELECT * FROM inbound_email WHERE provider_uid = :u AND mailbox_id = :m", ['u' => (string) $d['uid'], 'm' => $mb])[0] ?? [];
ok(($w['failed'] ?? 0) >= 1 && $failed !== [] && $failed['status'] === 'failed' && str_contains((string) $failed['error'], 'locked') && one('SELECT last_error IS NOT NULL FROM mailboxes WHERE id = :i', ['i' => $mb]) === true, 'a read that fails leaves a failed row with the sentence, and the mailbox says so');
fake_state(static function (array $s) use ($d): array { unset($s['mailboxes'][MAILBOX][(string) $d['uid']]['refuse_read']); return $s; });
$w = worker('mail_poll')['ran']['mail_poll'] ?? [];
$row = q("SELECT * FROM inbound_email WHERE provider_uid = :u AND mailbox_id = :m", ['u' => (string) $d['uid'], 'm' => $mb]);
ok(count($row) === 1 && $row[0]['status'] === 'received' && $row[0]['message_id'] === $m7 && str_contains((string) $row[0]['body_text'], 'Sam again'), 'the next poll reads it into the same row');
worker('mail_match');

echo "7. A file lands on the activity through the canonical store\n";
$m8 = '<m8-' . $x . '@acme.example.invalid>';
deliver(msg("Dana <dana-$x@acme-$x.example.invalid>", [MAILBOX], 'The signed order form', 'Please find it attached.', ['attachments' => [
    ['filename' => 'order form.txt', 'content_type' => 'text/plain', 'content_base64' => base64_encode("order form $x\n")],
    ['filename' => 'run.php', 'content_type' => 'text/x-php', 'content_base64' => base64_encode('<?php echo 1;')]]], ['message-id' => $m8, 'in-reply-to' => $m1]));
worker('mail_match');
$r8 = inbound($m8);
$files = q("SELECT * FROM attachments WHERE record_type = 'inbound_email' AND record_id = :i", ['i' => $r8['id']]);
ok(count($files) === 1 && $files[0]['filename'] === 'order-form.txt', 'the file is stored against the email; the program (.php) is skipped');
$act8 = email_acts('inbound_email_id', (int) $r8['id'])[0];
$onAct = q("SELECT * FROM attachments WHERE record_type = 'activity' AND record_id = :a", ['a' => $act8['id']]);
ok(count($onAct) === 1 && $onAct[0]['storage_path'] === $files[0]['storage_path'] && is_file(rtrim(getenv('CRM_STORAGE_DIR'), '/') . '/' . $onAct[0]['storage_path']), 'and on the activity — the same bytes, in the store');
$dl = html_as(26, '/attachments/' . $onAct[0]['id']);
ok($dl['code'] === 200 && str_contains($dl['body'], "order form $x"), 'the gate serves it to the seller');

echo "8. The unmatched screen: Attach · Make lead · Ignore\n";
$u1 = "newbie-$x@elsewhere.example.invalid";
$m9 = '<m9-' . $x . '@example.invalid>';
deliver(msg('Priya <priya@example.invalid>', ["Nia Newbie <$u1>"], "Note to the log $x", 'A seller copies the log on a new person. SECRET-BODY-9', [], ['message-id' => $m9, 'cc' => MAILBOX]));
$u2 = "held-$x@elsewhere.example.invalid";
$m10 = '<m10-' . $x . '@example.invalid>';
deliver(msg('Priya <priya@example.invalid>', ["Hal Held <$u2>"], "Another note $x", 'Nor here. SECRET-BODY-10', [], ['message-id' => $m10, 'cc' => MAILBOX]));
$u3 = "ignoreme-$x@elsewhere.example.invalid";
$m11 = '<m11-' . $x . '@example.invalid>';
deliver(msg('Priya <priya@example.invalid>', [$u3], "A third note $x", 'Nothing to do with sales. SECRET-BODY-11', [], ['message-id' => $m11, 'cc' => MAILBOX]));
worker('mail_match');
$r9 = inbound($m9);
$r10 = inbound($m10);
$r11 = inbound($m11);
ok($r9['match_status'] === 'unmatched' && $r10['match_status'] === 'unmatched' && $r11['match_status'] === 'unmatched', 'a seller\'s BCC to people we do not know is unmatched (not a lead: the sender is ours)');
$page = html_as(26, '/email/unmatched')['body'];
ok(str_contains($page, 'id="unmatched-' . $r9['id'] . '"') && str_contains($page, 'id="unmatched-' . $r3['id'] . '"') && !str_contains($page, 'id="unmatched-' . $r2['id'] . '"'), 'the screen lists the unmatched and the proposed, not the matched');
ok(str_contains($page, 'id="unmatched-' . $r9['id'] . '-lead-btn"') && str_contains($page, 'id="unmatched-' . $r9['id'] . '-attach-btn"') && str_contains($page, 'id="unmatched-' . $r9['id'] . '-ignore-btn"'), 'each row has Make lead · Attach · Ignore');
$j = get_as(26, '/email/unmatched')['json']['data'] ?? [];
ok(array_filter($j['rows'] ?? [], static fn ($r) => $r['email_id'] === (int) $r3['id'] && $r['match_status'] === 'proposed_lead' && $r['proposed_lead_id'] !== null) !== [], 'in JSON each row carries its state and the proposed lead');
$page = html_as(26, '/email/unmatched?status=proposed_lead')['body'];
ok(str_contains($page, 'id="unmatched-' . $r3['id'] . '"') && !str_contains($page, 'id="unmatched-' . $r9['id'] . '"'), 'the filter narrows to the proposed ones');
// Attach: the unknown address becomes the contact's
$newc = contact_with("nia-$x@acme-$x.example.invalid", 'Nia', $acc);
$r = post_as(26, '/email/attach.php', ['email' => $r9['id'], 'contact' => $newc]);
ok($r['code'] === 200 && ($r['json']['ok'] ?? false), 'Attach to contact: ' . ($r['json']['did'] ?? $r['body']));
$r9 = inbound($m9);
ok($r9['match_status'] === 'matched' && (int) one('SELECT count(*) FROM contact_emails WHERE contact_id = :c AND address = :a', ['c' => $newc, 'a' => $u1]) === 1, 'the address is now the contact\'s and the email is matched');
$a9 = email_acts('inbound_email_id', (int) $r9['id']);
ok(count($a9) === 1 && (int) $a9[0]['contact_id'] === $newc && $a9[0]['email_direction'] === 'out', 'one email activity on the contact, direction out (a seller wrote)');
// an address another contact holds is refused naming whose it is
$holder = contact_with($u2, 'Hal', $acc);
$r = post_as(26, '/email/attach.php', ['email' => $r10['id'], 'contact' => $newc]);
ok($r['code'] === 422 && str_contains((string) ($r['json']['error']['message'] ?? ''), 'belongs to Hal Mail'), 'an address another contact holds is refused naming whose it is: ' . ($r['json']['error']['message'] ?? $r['body']));
// a stranger's address attached to a contact dismisses the lead it had proposed
$r = post_as(27, '/email/attach.php', ['email' => $r3['id'], 'contact' => $dana]);
ok($r['code'] === 200 && inbound($m3)['match_status'] === 'matched' && (int) one('SELECT count(*) FROM leads WHERE proposed_from_email_id = :e AND deleted_at IS NULL', ['e' => $r3['id']]) === 0, 'a stranger\'s email attached to a contact: matched, and the lead it had proposed is dismissed');
$r = post_as(26, '/email/attach.php', ['email' => $r2['id'], 'contact' => $dana]);
ok($r['code'] === 422 && str_contains((string) ($r['json']['error']['message'] ?? ''), 'matched already'), 'an email that is matched already is refused in words');
$r = post_as(29, '/email/attach.php', ['email' => $r11['id'], 'contact' => $dana]);
ok($r['code'] === 403, 'a Viewer may not attach: 403');
// Make lead from the party the BCC names
$r = post_as(26, '/email/make-lead.php', ['email' => $r10['id']]);
$lead = (int) ($r['json']['record_id'] ?? 0);
$lr = $lead ? q('SELECT * FROM leads WHERE id = :l', ['l' => $lead])[0] : [];
ok($r['code'] === 200 && $lr !== [] && $lr['email'] === $u2 && $lr['first_name'] === 'Hal' && $lr['last_name'] === 'Held' && (int) $lr['owner_member_id'] === 26 && !$lr['is_proposed'], 'Make lead: a lead of the person the seller wrote to, named from the header, mine');
ok(inbound($m10)['match_status'] === 'proposed_lead' && (int) one('SELECT count(*) FROM activities WHERE lead_id = :l AND inbound_email_id = :e', ['l' => $lead, 'e' => $r10['id']]) === 1, 'the email is on the lead as an activity and leaves the unmatched list');
$r = post_as(29, '/email/make-lead.php', ['email' => $r11['id']]);
ok($r['code'] === 403, 'a Viewer may not make a lead of an email: 403');
$r = post_as(26, '/email/ignore.php', ['email' => $r11['id']]);
ok($r['code'] === 200 && inbound($m11)['match_status'] === 'ignored', 'Ignore: ' . ($r['json']['did'] ?? $r['body']));
$page = html_as(26, '/email/unmatched')['body'];
ok(!str_contains($page, 'id="unmatched-' . $r11['id'] . '"') && !str_contains($page, 'id="unmatched-' . $r10['id'] . '"') && !str_contains($page, 'id="unmatched-' . $r9['id'] . '"'), 'the three have left the list');
$r = post_as(26, '/email/ignore.php', ['email' => $r2['id']]);
ok($r['code'] === 422, 'a matched email is not ignored: 422');

echo "9. The log: ids and counts, never a body\n";
$rows = q("SELECT action, after::text AS after, before::text AS before FROM activity_log WHERE id > :s AND (action LIKE 'mail.%' OR action LIKE 'email.%')", ['s' => $since]);
$seen = array_unique(array_column($rows, 'action'));
foreach (['mail.received', 'mail.matched', 'mail.proposed', 'mail.ignored', 'email.attach', 'email.make_lead', 'email.ignore'] as $ev) { ok(in_array($ev, $seen, true), "$ev written"); }
$all = json_encode($rows);
ok(!str_contains($all, 'SECRET-BODY') && !str_contains($all, 'let us talk Tuesday'), 'no body is in any payload');
$withM1 = array_values(array_filter($rows, static fn ($r) => $r['action'] === 'mail.matched' && str_contains((string) $r['after'], $m1)));
$after = json_decode((string) ($withM1[0]['after'] ?? ''), true) ?: [];
ok(($after['message_id'] ?? null) === $m1 && count($after['contact_ids'] ?? []) === 2 && count($after['activities'] ?? []) === 2, 'mail.matched names the message id, the two contact ids and the activities it made');
ok(in_array('mail.unmatched', $seen, true), 'mail.unmatched written');
finish();
