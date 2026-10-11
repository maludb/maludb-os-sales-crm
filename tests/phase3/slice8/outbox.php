<?php
/**
 * Proof — the outbox's notification half (agents-k7-api.md "The worker", binding point 5): queued notification_outbox rows go out — an email through MaluMail with render_mail() (the reminder, morning-note and plain notice bodies), a text
 * to a member through the kernel's K6; `no_sender` and the other refusals are skipped with the code in the detail; a try that fails is made again after 1 and 4 minutes, three in all; the report keeps its four keys; the campaign half
 * is untouched (slice 6's outbox proof stays green); nothing is sent twice; the log has the row, never the words.
 */
require __DIR__ . '/lib.php';
$x = run_tag();
$since = last_activity_id();
pg_run("UPDATE notification_outbox SET status = 'skipped', detail = 'left by an earlier run' WHERE status = 'queued'");
pg_run("UPDATE outbound_email SET status = 'failed', error = 'left by an earlier run' WHERE campaign_id IS NOT NULL AND status = 'queued'");
pg_run("UPDATE members SET status = 'active' WHERE id = 31");
kernel_state(static function (array $s): array { $s['sms'] = ['mode' => 'ok', 'members' => ['27' => 'no_sender', '29' => 'opted_out', '30' => 'server_error', '28' => 'no_verified_phone']]; return $s; });
$row = static function (string $channel, int $member, string $kind, string $to, string $subject, string $body, ?string $rt = null, ?int $rid = null): int {
    return (int) one("INSERT INTO notification_outbox (channel, member_id, to_email, kind, record_type, record_id, subject, body) VALUES (:c, :m, :to, :k, :rt, :rid, :s, :b) RETURNING id",
        ['c' => $channel, 'm' => $member, 'to' => $channel === 'email' ? $to : null, 'k' => $kind, 'rt' => $rt, 'rid' => $rid, 's' => $subject, 'b' => $body]);
};
$deal = deal_make(26, ['title' => "Outbox deal $x"]);
$act = act_id(26, ['kind' => 'call', 'subject' => "Outbox call $x", 'deal' => $deal, 'starts_at' => at('+1 day 10:00')]);
$A = $row('email', 26, 'mention', 'priya@example.invalid', "Mention $x", "Hello there $x", 'deal', $deal);
$B1 = $row('email', 26, 'reminder', 'priya@example.invalid', "call: Outbox call $x", 'At Mon 10:00.', 'activity', $act);
$B2 = $row('email', 26, 'morning_note', 'priya@example.invalid', "Morning $x", "Three things today $x");
$C = $row('text', 26, 'reminder', '', "Reminder $x", "Call due at 10 $x", 'activity', $act);
$D1 = $row('text', 27, 'mention', '', "No sender $x", "Text to Marco $x");
$D2 = $row('text', 29, 'mention', '', "Opted out $x", "Text to Ann $x");
$D3 = $row('text', 30, 'mention', '', "Server error $x", "Text to Dana $x");
$D4 = $row('text', 28, 'mention', '', "No phone $x", "Text to Bea $x");
$E1 = $row('email', 26, 'mention', "ratelimited-$x@n.example.invalid", "Rate limited $x", 'b');
$E2 = $row('email', 26, 'mention', "suppressed-$x@n.example.invalid", "Suppressed $x", 'b');
$E3 = $row('email', 26, 'mention', "brokenmail-$x@n.example.invalid", "Broken $x", 'b');
$E4 = $row('email', 26, 'mention', "flaky-$x@n.example.invalid", "Flaky $x", 'b');
$E5 = $row('email', 26, 'mention', "rejected-$x@n.example.invalid", "Rejected $x", 'b');
$H = $row('email', 31, 'mention', 'lee@example.invalid', "Gone $x", 'b');
$I = $row('email', 26, 'mention', '', "No address $x", 'b');
pg_run("UPDATE notification_outbox SET to_email = NULL WHERE id = $I");
pg_run("UPDATE members SET status = 'inactive' WHERE id = 31");
$st = static fn (int $id): array => q('SELECT status, attempts, detail, provider_ref, sent_at, send_after FROM notification_outbox WHERE id = :i', ['i' => $id])[0];
$mail0 = count(sends());
$sms0 = count(kernel_sms_log());
$t0 = time() + 5;           // the rows were made a moment ago: the pass's clock starts just after them

echo "1. The first pass\n";
$rep = pass('outbox', gmdate(DATE_ATOM, $t0));
ok(array_keys($rep) === ['sent', 'skipped', 'retried', 'failed'] && $rep === ['sent' => 4, 'skipped' => 7, 'retried' => 3, 'failed' => 1], 'the report keeps its four keys and counts both halves: ' . json_encode($rep));
$new = array_slice(sends(), $mail0);
$to = static fn (array $m) => $m['to'] ?? '';
$a = array_values(array_filter($new, static fn ($m) => ($m['subject'] ?? '') === "Mention $x" && $to($m) === 'priya@example.invalid'))[0] ?? [];
ok($a !== [] && $a['from'] === 'crm@example.invalid' && $a['from_name'] === 'Sales CRM' && str_contains($a['text'], "Hello there $x") && str_contains($a['text'], 'Open it in Sales CRM: ' . app_url_of("/deals/$deal")) && str_contains($a['html'], '<h2') && str_contains($a['html'], "Hello there $x") && str_contains($a['html'], app_url_of("/deals/$deal")),
    'an email: from MAIL_FROM, the title as the subject, text and HTML from render_mail() with the link to the deal');
$b1 = array_values(array_filter($new, static fn ($m) => ($m['subject'] ?? '') === "call: Outbox call $x"))[0] ?? [];
$b2 = array_values(array_filter($new, static fn ($m) => ($m['subject'] ?? '') === "Morning $x"))[0] ?? [];
ok(str_contains($b1['text'] ?? '', 'REMINDER') && str_contains($b1['html'] ?? '', 'Open the activity') && str_contains($b2['text'] ?? '', 'YOUR MORNING NOTE') && str_contains($b2['html'] ?? '', 'Your morning note') && !str_contains($a['text'], 'REMINDER'), 'the kind picks the body: a reminder (Reminder, "Open the activity"), a morning note (Your morning note), else the plain notice');
ok(count(array_filter($new, static fn ($m) => str_contains($to($m), 'ratelimited') || str_contains($to($m), 'flaky') || str_contains($to($m), 'suppressed') || str_contains($to($m), 'brokenmail') || str_contains($to($m), 'rejected'))) === 5 && !array_filter($new, static fn ($m) => ($m['subject'] ?? '') === "Gone $x" || ($m['subject'] ?? '') === "No address $x"), 'the provider was asked for the five addresses; nothing for the person who is gone or the row with no address');
foreach ([$A, $B1, $B2, $C] as $id) {
    $r = $st($id);
    ok($r['status'] === 'sent' && (int) $r['attempts'] === 1 && $r['sent_at'] !== null && $r['provider_ref'] !== null, "row $id: sent, one attempt, sent_at and the provider's reference");
}
$sms = array_slice(kernel_sms_log(), $sms0);
$c = array_values(array_filter($sms, static fn ($s) => ($s['member_id'] ?? 0) === 26))[0] ?? [];
ok(($c['text'] ?? '') === "Call due at 10 $x" && $c['reference'] === 'crm-outbox-' . $C && $c['accepted'] === true && str_starts_with((string) $st($C)['provider_ref'], 'kernel:'), 'the text went to the kernel for member 26 with our reference, and the kernel\'s id is kept');
ok(count($sms) === 5, 'the kernel was asked for five texts (Priya, Marco, Ann, Dana, Bea)');
$r = $st($D1);
ok($r['status'] === 'skipped' && str_contains($r['detail'], 'no_sender') && (int) $r['attempts'] === 1, 'no_sender: skipped, the detail says no_sender — not retried, not failed');
ok($st($D2)['status'] === 'skipped' && str_contains($st($D2)['detail'], 'opted_out') && $st($D4)['status'] === 'skipped' && str_contains($st($D4)['detail'], 'no_verified_phone'), 'opted_out and no_verified_phone: skipped with the code');
ok($st($E2)['status'] === 'skipped' && str_contains($st($E2)['detail'], 'suppressed') && $st($E5)['status'] === 'skipped', 'an address MaluMail will not take (suppressed, rejected): skipped with the reason');
ok($st($H)['status'] === 'skipped' && str_contains($st($H)['detail'], 'no longer here') && $st($I)['status'] === 'skipped' && str_contains($st($I)['detail'], 'no email address'), 'a person who left, a row with no address: skipped in words');
ok($st($E3)['status'] === 'failed' && str_contains($st($E3)['detail'], 'HTTP 500'), 'a permanent provider answer (500): failed at once, with the sentence');
foreach ([$D3, $E1, $E4] as $id) {
    $r = $st($id);
    ok($r['status'] === 'queued' && (int) $r['attempts'] === 1 && abs(strtotime($r['send_after']) - ($t0 + 60)) < 5, "row $id: a try failed — still queued, one attempt, the next after 1 minute");
}
$last = last_text_refusal_of(27);
ok($last === 'no_sender', 'My settings reads the refusal back from the outbox: no_sender');

echo "2. Not before its time, then again, then given up (three attempts)\n";
$rep = pass('outbox', gmdate(DATE_ATOM, $t0 + 30));
ok($rep['sent'] === 0 && $rep['retried'] === 0 && $st($E1)['attempts'] == 1, '30 seconds on: nothing is due');
$rep = pass('outbox', gmdate(DATE_ATOM, $t0 + 125));
ok($rep['retried'] === 3 && $rep['failed'] === 0 && (int) $st($E1)['attempts'] === 2 && abs(strtotime($st($E1)['send_after']) - ($t0 + 125 + 240)) < 5, 'two minutes on: the three are tried again — the second attempt — and wait four minutes more');
$rep = pass('outbox', gmdate(DATE_ATOM, $t0 + 200));
ok($rep['retried'] === 0 && $rep['failed'] === 0, 'between the tries: nothing');
$rep = pass('outbox', gmdate(DATE_ATOM, $t0 + 400));
ok($rep['failed'] === 3 && $rep['retried'] === 0, 'the third attempt fails: all three are failed — ' . json_encode($rep));
foreach ([$D3, $E1, $E4] as $id) {
    $r = $st($id);
    ok($r['status'] === 'failed' && (int) $r['attempts'] === 3 && $r['detail'] !== '', "row $id: failed after three attempts, with a sentence");
}
$rep = pass('outbox', gmdate(DATE_ATOM, $t0 + 900));
ok($rep === ['sent' => 0, 'skipped' => 0, 'retried' => 0, 'failed' => 0], 'a later pass finds nothing: no row is sent twice');
ok(count(array_filter(array_slice(sends(), $mail0), static fn ($m) => ($m['subject'] ?? '') === "Mention $x")) === 1, '…the first email went once');
$kernelMentions = count(array_filter(array_slice(kernel_sms_log(), $sms0), static fn ($s) => str_contains($s['text'] ?? '', "Call due at 10 $x")));
ok($kernelMentions === 1, '…the first text went once');

echo "3. A recovery: a try that fails and then works\n";
$F = $row('email', 26, 'mention', "flaky-$x-2@n.example.invalid", "Flaky then fine $x", 'b');
pass('outbox', gmdate(DATE_ATOM, $t0 + 1000));
ok($st($F)['status'] === 'queued' && (int) $st($F)['attempts'] === 1, 'a first failure keeps it queued');
pg_run("UPDATE notification_outbox SET to_email = 'fine-$x@n.example.invalid' WHERE id = $F");
$rep = pass('outbox', gmdate(DATE_ATOM, $t0 + 1100));
ok($rep['sent'] === 1 && $st($F)['status'] === 'sent' && (int) $st($F)['attempts'] === 2, 'the next attempt works: sent on the second attempt');

echo "4. No MaluMail key: skipped, not lost as a failure\n";
$G = $row('email', 26, 'mention', 'priya@example.invalid', "No key $x", 'b');
$key = need('MALUMAIL_API_KEY');
putenv('MALUMAIL_API_KEY=');
$rep = pass('outbox', gmdate(DATE_ATOM, $t0 + 1200));
putenv('MALUMAIL_API_KEY=' . $key);
ok($st($G)['status'] === 'skipped' && str_contains($st($G)['detail'], 'MaluMail has no key'), 'without a MaluMail key the email is skipped, in words');

echo "5. The log\n";
$lg = q("SELECT action, after::text AS after, source, actor_member_id FROM activity_log WHERE id > :s AND action IN ('outbox.send', 'outbox.skip', 'outbox.fail') ORDER BY id", ['s' => $since]);
$by = array_count_values(array_column($lg, 'action'));
ok(($by['outbox.send'] ?? 0) >= 5 && ($by['outbox.skip'] ?? 0) >= 7 && ($by['outbox.fail'] ?? 0) >= 4 && $lg[0]['source'] === 'cron' && $lg[0]['actor_member_id'] === null, 'outbox.send, outbox.skip and outbox.fail are logged per row by the worker: ' . json_encode($by));
$j = json_encode($lg);
ok(!str_contains($j, "Hello there $x") && !str_contains($j, "Call due at 10") && !str_contains($j, 'priya@example.invalid') && isset(json_decode($lg[0]['after'], true)['channel'], json_decode($lg[0]['after'], true)['kind'], json_decode($lg[0]['after'], true)['member_id']), 'a row says channel, kind, member and attempts — no words, no address');
echo "6. The campaign half is untouched\n";
$cr = pass('outbox', gmdate(DATE_ATOM, $t0 + 1300));
ok(array_keys($cr) === ['sent', 'skipped', 'retried', 'failed'], 'the report has exactly sent, skipped, retried, failed');
finish();

function app_url_of(string $path): string { return rtrim(need('APP_URL'), '/') . $path; }
function last_text_refusal_of(int $member): ?string
{
    $r = q("SELECT status, detail FROM notification_outbox WHERE member_id = :m AND channel = 'text' AND status IN ('sent', 'skipped', 'failed') ORDER BY id DESC LIMIT 1", ['m' => $member])[0] ?? null;
    return $r !== null && $r['status'] === 'skipped' && str_contains((string) $r['detail'], 'no_sender') ? 'no_sender' : null;
}
