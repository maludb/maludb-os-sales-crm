<?php
/** Proof — the quick-log forms (activities-calendar.md §2; divergence 2): /log/call and /log/meeting are activity_create with done = yes — the touch done,
 * the next step open, one request; the deal's next step follows; the contact's open deals as buttons; a log without a subject names itself; touches. */
require __DIR__ . '/lib.php';
$x = run_tag();
$d = deal_make(26);
$deal = q('SELECT account_id, primary_contact_id FROM deals WHERE id = :d', ['d' => $d])[0];
$con = (int) $deal['primary_contact_id'];
echo "1. The forms\n";
$f = get_as(26, "/log/call?deal=$d")['json']['data'] ?? [];
ok(($f['kind'] ?? '') === 'call' && ($f['deal_id'] ?? 0) === $d && ($f['contact']['contact_id'] ?? 0) === $con && ($f['outcomes'][0] ?? '') === 'connected', '/log/call from a deal: the deal, its primary contact, the call\'s outcomes');
ok(($f['prompt_next_step'] ?? null) === true, '…the deal has no step planned: the next-step section asks');
$h = html_as(26, "/log/call?deal=$d")['body'];
ok(str_contains($h, 'id="log-call-form"') && str_contains($h, 'id="outcome-connected"') && str_contains($h, 'id="next-step-section"') && str_contains($h, 'name="done" value="yes"') && str_contains($h, 'id="next-step-at-tomorrow"'),
    'the form: the outcome buttons, the next step with its quick choices, done = yes');
$d2 = deal_make(26, ['account' => (int) $deal['account_id'], 'primary_contact' => $con]);
$h = html_as(26, "/log/call?contact=$con")['body'];
ok(str_contains($h, 'id="log-call-field-deal-' . $d . '"') && str_contains($h, 'id="log-call-field-deal-' . $d2 . '"') && str_contains($h, 'id="log-call-field-deal-none"'), 'from a contact with two open deals: both as buttons, and No deal');
ok((get_as(26, "/log/meeting?contact=$con")['json']['data']['outcomes'][0] ?? '') === 'meeting_held' && str_contains(html_as(26, "/log/meeting?deal=$d")['body'], 'id="log-meeting-form"'), '/log/meeting: the meeting\'s outcomes, its own form');
ok(get_as(29, '/log/call')['code'] === 403, 'a Viewer logs nothing');

echo "2. A call logged with its next step\n";
$since = last_activity_id();
$before = q('SELECT last_touched_at FROM deals WHERE id = :d', ['d' => $d])[0]['last_touched_at'];
$r = post_as(26, '/activities/save.php', ['kind' => 'call', 'done' => 'yes', 'deal' => $d, 'outcome' => 'connected', 'body' => 'They want a demo next week', 'next_kind' => 'meeting', 'next_at' => 'nextweek']);
$touch = (int) ($r['json']['record_id'] ?? 0);
$step = (int) ($r['json']['next_activity_id'] ?? 0);
$t = act_row($touch);
$s = act_row($step);
ok($r['code'] === 200 && $t['done_at'] !== null && $t['outcome'] === 'connected' && $t['remind_at'] === null && str_starts_with($t['subject'], 'Call with '), 'the touch: done now, connected, no reminder, "Call with <the contact>"');
ok($step > 0 && $s['done_at'] === null && $s['kind'] === 'meeting' && str_starts_with($s['subject'], 'Follow up with ') && (int) $s['deal_id'] === $d && (int) $s['contact_id'] === $con, 'the next step: an open meeting on the deal with the contact, "Follow up with <first name>"');
$mon = (new DateTimeImmutable('next monday 10:00', new DateTimeZone('UTC')))->format('Y-m-d H:i');
ok(str_starts_with($s['starts_at'], $mon), 'next week = next Monday at 10:00');
$dr = q('SELECT next_activity_id, next_activity_at, last_touched_at FROM deals WHERE id = :d', ['d' => $d])[0];
ok((int) $dr['next_activity_id'] === $step && $dr['next_activity_at'] === $s['starts_at'] && $dr['last_touched_at'] !== $before, 'the deal: its next step is the meeting, touched now');
ok(count(activity('activity.create', $since)) === 2 && !str_contains(json_encode(activity('activity.create', $since)), 'demo next week'), 'two activity.create rows (the touch, the step); the words never in the log');
ok(str_contains((string) $r['json']['location'], "/activities/$touch?next=$step"), 'it lands on the touch with the step linked');
$r = post_as(26, '/activities/save.php', ['kind' => 'call', 'done' => 'yes', 'contact' => $con, 'outcome' => 'no_answer', 'next_kind' => 'call', 'next_at' => 'pick', 'next_at_time' => at('+4 days 15:30'), 'next_subject' => "Ring back $x"]);
$s2 = act_row((int) $r['json']['next_activity_id']);
ok($r['code'] === 200 && str_starts_with($s2['starts_at'], gmdate('Y-m-d', strtotime('+4 days')) . ' 15:30') && $s2['subject'] === "Ring back $x" && $s2['deal_id'] === null, 'a date and time chosen ("On…"); on the contact alone, no deal');
$r = post_as(26, '/activities/save.php', ['kind' => 'call', 'done' => 'yes', 'contact' => $con, 'outcome' => 'busy', 'next_kind' => 'none']);
ok($r['code'] === 200 && array_key_exists('next_activity_id', $r['json']) && $r['json']['next_activity_id'] === null, 'No next step: the touch alone');
$r = post_as(26, '/activities/save.php', ['kind' => 'call', 'done' => 'yes', 'contact' => $con, 'done_at' => at('-2 hours'), 'outcome' => 'left_message']);
ok(str_starts_with(act_row((int) $r['json']['record_id'])['done_at'], (new DateTimeImmutable('-2 hours', new DateTimeZone('UTC')))->format('Y-m-d H:i')), 'a call made earlier: done at the time said');
ok(field_of(post_as(26, '/activities/save.php', ['kind' => 'call', 'done' => 'yes', 'contact' => $con, 'outcome' => 'meeting_held'])) === 'outcome', 'a meeting\'s outcome on a call is refused');
ok(field_of(post_as(26, '/activities/save.php', ['kind' => 'call', 'done' => 'yes', 'contact' => $con, 'next_kind' => 'call'])) === 'next_at' && field_of(post_as(26, '/activities/save.php', ['kind' => 'call', 'done' => 'yes', 'contact' => $con, 'next_kind' => 'fax', 'next_at' => 'tomorrow'])) === 'next_kind', 'a next step without a time, or of no kind: refused, nothing made');

echo "3. A meeting held, a lead's call\n";
$r = post_as(26, '/activities/save.php', ['kind' => 'meeting', 'done' => 'yes', 'deal' => $d2, 'starts_at' => at('-1 hour'), 'outcome' => 'meeting_held', 'next_kind' => 'task', 'next_subject' => "Send the proposal $x", 'next_at' => 'tomorrow']);
$m = act_row((int) $r['json']['record_id']);
$n = act_row((int) $r['json']['next_activity_id']);
ok($m['kind'] === 'meeting' && $m['done_at'] !== null && $m['outcome'] === 'meeting_held' && str_starts_with($m['starts_at'], (new DateTimeImmutable('-1 hour', new DateTimeZone('UTC')))->format('Y-m-d H')) && $n['kind'] === 'task' && $n['due_at'] !== null && $n['starts_at'] === null,
    'a meeting held an hour ago: done, held; the next step a task with a due time (tomorrow 10:00)');
$lead = (int) post_as(26, '/leads/save.php', ['first_name' => 'Lia', 'last_name' => "Lead $x", 'company' => "Lead Co $x"])['json']['record_id'];
ok(str_contains(html_as(26, "/log/call?lead=$lead")['body'], 'id="log-call-field-lead"'), '/log/call from a lead: the lead, no contact or deal');
$r = post_as(26, '/activities/save.php', ['kind' => 'call', 'done' => 'yes', 'lead' => $lead, 'outcome' => 'connected']);
$l = q('SELECT status, last_touched_at FROM leads WHERE id = :l', ['l' => $lead])[0];
ok($r['code'] === 200 && $l['status'] === 'working' && $l['last_touched_at'] !== null, 'a call logged on a new lead: the lead is working, touched');

echo "4. The record pages offer it\n";
foreach (["/deals/$d" => 'deal-log-call-btn', "/contacts/$con" => 'contact-log-call-btn', "/leads/$lead" => '/log/call?lead=' . $lead] as $page => $needle) {
    ok(str_contains(html_as(26, $page)['body'], $needle), "$page offers Log call");
}
ok(str_contains(html_as(26, "/deals/$d?tab=activities")['body'], 'id="deal-schedule-tab-btn"') && str_contains(html_as(26, "/deals/$d")['body'], 'id="deal-next-step-subject"'), 'the deal page: Schedule on its activities, the next step linked');
finish();
