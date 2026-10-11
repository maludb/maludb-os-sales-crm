<?php
/**
 * Proof — the meeting's invitation (email.md "Proof"): meeting_invite_send mails each contact of a meeting the .ics (METHOD:REQUEST, Priya organizing) with the line the seller wrote; an opted-out
 * contact is refused by name before anything goes; only a meeting still ahead, with someone to invite; own or activities.any; the page offers it where it can be used.
 */
require __DIR__ . '/lib.php';
$x = run_tag();
ensure_mailbox();
$since = last_activity_id();
$acc = (int) post_as(26, '/accounts/save.php', ['name' => "Invite Acct $x"])['json']['record_id'];
$ann = contact_with("ann-$x@inv-$x.example.invalid", 'Ann', $acc);
$cat = contact_with("cat-$x@inv-$x.example.invalid", 'Cat', $acc);
$bob = contact_with("bob-$x@inv-$x.example.invalid", 'Bob', $acc);
post_as(26, '/contacts/opt-out.php', ['contact' => $bob, 'channel' => 'email', 'reason' => 'asked']);
$deal = deal_make(26, ['title' => "Invite deal $x", 'account' => $acc, 'primary_contact' => $ann]);
$meet = act_id(26, ['kind' => 'meeting', 'subject' => "Quarterly review $x", 'deal' => $deal, 'contact' => $ann, 'participants' => [$cat], 'starts_at' => at('+4 days 14:00'), 'ends_at' => at('+4 days 15:00'),
    'location' => 'Our office, 5th floor', 'meeting_url' => 'https://meet.example.invalid/room-' . $x, 'body' => 'INTERNAL-NOTES keep to ourselves']);
$msg = static fn (array $r): string => (string) ($r['json']['error']['message'] ?? $r['body']);

echo "1. The page offers it, where it can be used\n";
$h = html_as(26, "/activities/$meet")['body'];
ok(str_contains($h, 'id="activity-invite-form"') && str_contains($h, 'id="activity-invite-btn"') && str_contains($h, "Ann Mail $x") && str_contains($h, "Cat Mail $x"), 'a meeting\'s page has the invitation form, naming who it goes to');
$task = act_id(26, ['kind' => 'task', 'subject' => "A task $x", 'deal' => $deal, 'due_at' => at('+2 days 09:00')]);
ok(!str_contains(html_as(26, "/activities/$task")['body'], 'id="activity-invite-form"'), 'a task\'s page does not');
ok(!str_contains(html_as(30, "/activities/$meet")['body'], 'id="activity-invite-form"') && str_contains(html_as(27, "/activities/$meet")['body'], 'id="activity-invite-form"'), 'another seller\'s page does not; the manager\'s does');

echo "2. The invitation goes to each contact\n";
$n0 = count(sends());
$r = post_as(26, '/email/invite.php', ['activity' => $meet, 'message' => 'Looking forward to the review.']);
ok($r['code'] === 200 && (int) $r['json']['record_id'] === $meet && ($r['json']['sent'] ?? 0) === 2 && ($r['json']['failed'] ?? 1) === 0, 'meeting_invite_send: ' . ($r['json']['did'] ?? $r['body']));
$two = array_slice(sends(), -2);
ok(count(sends()) === $n0 + 2 && count(array_unique(array_column($two, 'to'))) === 2, 'two messages, one to each');
$m = $two[0];
$files = array_column((array) ($m['attachments'] ?? []), null, 'filename');
ok(array_keys($files) === ['invite.ics'] && str_contains($files['invite.ics']['content_type'], 'text/calendar') && str_contains($files['invite.ics']['content_type'], 'method=REQUEST'), 'each carries invite.ics as text/calendar; method=REQUEST');
$ics = str_replace("\r\n ", '', base64_decode($files['invite.ics']['content_base64']));
ok(str_contains($ics, "METHOD:REQUEST\r\n") && str_contains($ics, "SUMMARY:Quarterly review $x\r\n") && str_contains($ics, 'DTSTART:' . gmdate('Ymd', strtotime('+4 days')) . 'T140000Z') && str_contains($ics, 'DTEND:' . gmdate('Ymd', strtotime('+4 days')) . 'T150000Z') && str_contains($ics, 'LOCATION:Our office\, 5th floor'), 'the event: the subject, the start and end in UTC, the place');
ok(str_contains($ics, 'DESCRIPTION:Looking forward to the review.') && str_contains($ics, 'https://meet.example.invalid/room-' . $x) && !str_contains($ics, 'INTERNAL-NOTES') && !str_contains($ics, '/activities/'), 'the description is the seller\'s line and the meeting link — the internal notes and the CRM\'s own address stay home');
ok(substr_count($ics, 'ATTENDEE;') === 1 && str_contains($ics, 'mailto:' . $m['to']) && str_contains($ics, 'ORGANIZER;CN="SMOKE Priya":mailto:priya@example.invalid'), 'each invitation names that one attendee, and Priya as the organizer');
ok(str_starts_with($m['subject'], "Invitation: Quarterly review $x — ") && str_contains($m['text'], 'Looking forward to the review.') && str_contains($m['text'], 'Our office, 5th floor') && str_contains($m['text'], 'https://meet.example.invalid/room-' . $x) && str_contains($m['text'], 'accept it'), 'the email: the line above, when, where, the link, how to accept');
ok(preg_match('/^Hi (Ann|Cat),/', $m['text']) === 1 && str_contains($m['text'], '/u/') && ($m['reply_to'] ?? '') === MAILBOX, 'each is greeted by name, carries the footer and the unsubscribe link, and replies go to the log mailbox');
ok((int) one("SELECT count(*) FROM outbound_email WHERE activity_id = :a AND has_ics AND status = 'sent' AND deal_id = :d", ['a' => $meet, 'd' => $deal]) === 2, 'two outbound emails: sent, with the invitation, on the meeting and the deal');
ok((int) one("SELECT count(*) FROM activities WHERE kind = 'email' AND outbound_email_id IN (SELECT id FROM outbound_email WHERE activity_id = :a)", ['a' => $meet]) === 2, 'and each is an email activity on its contact');
$rows = q("SELECT action, after::text AS after FROM activity_log WHERE id > :s AND action = 'email.invite'", ['s' => $since]);
ok(count($rows) === 1 && json_decode($rows[0]['after'], true)['sent'] === 2 && !str_contains($rows[0]['after'], 'Looking forward'), 'email.invite logged once, with the counts — not the line');
$pp = get_as(26, "/activities/$meet")['json']['data']['activity']['participants'] ?? [];
ok(count(array_filter($pp, static fn ($p) => ($p['contact_id'] ?? null) === $ann)) === 1, 'the contact the meeting is with is now a participant too (so their answer has a place)');

echo "3. Refusals\n";
$n1 = count(sends());
$meet2 = act_id(26, ['kind' => 'meeting', 'subject' => "With Bob $x", 'deal' => $deal, 'contact' => $ann, 'participants' => [$bob], 'starts_at' => at('+5 days 10:00')]);
$r = post_as(26, '/email/invite.php', ['activity' => $meet2]);
ok($r['code'] === 422 && str_contains($msg($r), 'Bob Mail') && str_contains($msg($r), 'opted out') && count(sends()) === $n1, 'an opted-out participant: refused by name, and nobody is sent to: ' . $msg($r));
$r = post_as(26, '/email/invite.php', ['activity' => $task]);
ok($r['code'] === 422 && str_contains($msg($r), 'Only a meeting'), 'a task has no invitation: ' . $msg($r));
$acc2 = act_id(26, ['kind' => 'meeting', 'subject' => "On an account $x", 'account' => $acc, 'starts_at' => at('+5 days 12:00')]);
$r = post_as(26, '/email/invite.php', ['activity' => $acc2]);
ok($r['code'] === 422 && str_contains($msg($r), 'Add a contact'), 'a meeting on an account alone: "Add a contact to the meeting first": ' . $msg($r));
$done = act_id(26, ['kind' => 'meeting', 'subject' => "Held $x", 'contact' => $ann, 'starts_at' => at('-2 days 10:00'), 'ends_at' => at('-2 days 11:00'), 'done' => 'yes']);
$r = post_as(26, '/email/invite.php', ['activity' => $done]);
ok($r['code'] === 422 && str_contains($msg($r), 'done') && count(sends()) === $n1, 'a meeting already held: ' . $msg($r));
$r = post_as(30, '/email/invite.php', ['activity' => $meet]);
ok($r['code'] === 403 && count(sends()) === $n1, 'another seller may not send Priya\'s meeting\'s invitation: 403');
$r = post_as(29, '/email/invite.php', ['activity' => $meet]);
ok($r['code'] === 403, 'a Viewer: 403');
$r = post_as(26, '/email/invite.php', ['activity' => 999999999]);
ok($r['code'] === 404, 'a meeting that is not there: 404');
$r = post_as(26, '/email/invite.php', ['activity' => $meet, 'message' => str_repeat('m', 2001)]);
ok($r['code'] === 422 && field_of($r) === 'message', 'a line over 2000 characters: named');
$r = post_as(27, '/email/invite.php', ['activity' => $meet]);
ok($r['code'] === 200 && count(sends()) === $n1 + 2, 'the manager (activities.any) may send it for her');
$flaky = contact_with("flaky-$x@inv-$x.example.invalid", 'Flo', $acc);
$meet3 = act_id(26, ['kind' => 'meeting', 'subject' => "Flaky $x", 'contact' => $ann, 'participants' => [$flaky], 'starts_at' => at('+6 days 10:00')]);
$r = post_as(26, '/email/invite.php', ['activity' => $meet3]);
ok($r['code'] === 200 && ($r['json']['sent'] ?? 0) === 1 && ($r['json']['failed'] ?? 0) === 1 && str_contains((string) ($r['json']['did'] ?? ''), 'not sent to Flo'), 'when one address fails the others still go, and the answer says who was not reached: ' . ($r['json']['did'] ?? $r['body']));
finish();
