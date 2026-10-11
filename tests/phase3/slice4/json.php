<?php
/** Proof — JSON mode on every screen and handler of slice 4, the HTMX stamps, the log's vocabulary (the events of activities-reminders.md, never a body), and
 * the registry: 7 screens built, 10 actions built, 0 approvals. */
require __DIR__ . '/lib.php';
$x = run_tag();
$d = deal_make(26, ['title' => "Json $x"]);
$con = (int) one('SELECT primary_contact_id FROM deals WHERE id = :d', ['d' => $d]);
$a = act_id(26, ['kind' => 'call', 'subject' => "Json call $x", 'deal' => $d, 'starts_at' => at('+1 day 10:00'), 'body' => 'Private words of a call']);
$since0 = last_activity_id() - 1;

echo "1. Every screen answers JSON\n";
$screens = ['/calendar' => 'rows', '/calendar?view=agenda' => 'agenda', '/calendar/grid?view=week' => 'days', '/activities' => 'rows', "/activities/$a" => 'activity', '/activities/new' => 'values',
    "/activities/$a/edit" => 'values', "/activities/$a/complete" => 'outcomes', "/log/call?deal=$d" => 'outcomes', "/log/meeting?contact=$con" => 'open_deals'];
foreach ($screens as $url => $key) {
    $r = get_as(26, $url);
    ok($r['code'] === 200 && array_key_exists($key, $r['json']['data'] ?? []), "GET $url as JSON: 200 with `$key`");
}
$g = get_as(26, "/activities/$a")['json']['data']['activity'];
ok($g['activity_id'] === $a && $g['kind'] === 'call' && $g['record']['kind'] === 'deal' && $g['record']['id'] === $d && $g['remind_minutes_before'] === 30 && is_array($g['participants']) && $g['state'] === 'scheduled',
    'get_activity\'s shape: the record, the reminder, participants, the state');
$c = get_as(26, '/calendar?view=month')['json']['data'];
ok(isset($c['view'], $c['from'], $c['to'], $c['who']['key'], $c['days'], $c['prev'], $c['next']) && count($c['days']) === 42, 'the calendar\'s shape: the window, whose, the days');
$r = html_as(26, "/activities/$a", ['HX-Request: true']);
ok(!str_contains($r['body'], '<html') && preg_match('/^X-Screen: activity-view\r?$/mi', $r['headers']) === 1 && preg_match('/^X-Record-Id: ' . $a . '\r?$/mi', $r['headers']) === 1, 'over HTMX the activity page is the partial stamped activity-view and its id');
$r = html_as(26, '/calendar', ['HX-Request: true']);
ok(preg_match('/^X-Screen: calendar\r?$/mi', $r['headers']) === 1 && str_contains($r['body'], 'id="calendar-grid"'), 'the calendar over HTMX: stamped calendar, the region inside');

echo "2. Every handler answers JSON\n";
$b = act_id(26, ['kind' => 'task', 'subject' => "Json task $x", 'deal' => $d, 'due_at' => at('+2 days 09:00')]);
$checks = [
    ['/activities/save.php', ['activity' => $a, 'location' => 'Desk'], 'activity_update'], ["/activities/$a/reschedule", ['activity' => $a, 'at' => at('+1 day 11:00')], 'activity_reschedule'],
    ['/activities/snooze.php', ['activity' => $a, 'until' => '1h'], 'activity_snooze'], ['/activities/assign.php', ['activity' => $a, 'owner' => 27], 'activity_assign'],
    ['/activities/participants/add.php', ['activity' => $a, 'contact' => $con], 'activity_participant_add'], ['/activities/participants/remove.php', ['activity' => $a, 'contact' => $con], 'activity_participant_remove'],
    ['/activities/complete.php', ['activity' => $b, 'outcome' => 'completed'], 'activity_complete'], ['/activities/reopen.php', ['activity' => $b], 'activity_reopen'],
];
foreach ($checks as [$path, $form, $name]) {
    $r = post_as(26, $path, $form);
    ok($r['code'] === 200 && ($r['json']['ok'] ?? false) === true && ($r['json']['did'] ?? '') !== '' && array_key_exists('record_id', $r['json']), "$name: {ok, did, record_id} — " . ($r['json']['did'] ?? $r['body']));
}
$r = post_as(26, '/activities/save.php', ['kind' => 'meeting', 'subject' => "Created $x", 'deal' => $d, 'starts_at' => at('+4 days 10:00')]);
ok($r['code'] === 200 && ($r['json']['record_id'] ?? 0) > 0 && array_key_exists('next_activity_id', $r['json']), 'activity_create: the record_id (and next_activity_id)');
$r = post_as(26, '/settings/feed.php', ['confirm' => 'yes']);
ok($r['code'] === 200 && ($r['json']['record_id'] ?? 0) === 26 && str_contains((string) ($r['json']['url'] ?? ''), 'calendar.ics?token='), 'feed_rotate: {ok, did, record_id (the member), url}');
$r = post_as(26, '/activities/save.php', ['kind' => 'call', 'deal' => $d]);
ok($r['code'] === 422 && ($r['json']['error']['code'] ?? '') === 'invalid' && isset($r['json']['error']['fields']['subject'], $r['json']['error']['fields']['starts_at']), 'a refusal: 422 invalid with the fields');

echo "3. The log's vocabulary\n";
$rows = q("SELECT action, after::text AS after, before::text AS before FROM activity_log WHERE id > :s AND (action LIKE 'activity.%' OR action LIKE 'feed.%')", ['s' => $since0]);
$seen = array_unique(array_column($rows, 'action'));
foreach (['activity.create', 'activity.update', 'activity.complete', 'activity.reopen', 'activity.reschedule', 'activity.snooze', 'activity.assign', 'activity.participant_add', 'activity.participant_remove', 'feed.rotate'] as $ev) {
    ok(in_array($ev, $seen, true), "$ev written");
}
ok(!str_contains(json_encode($rows), 'Private words'), 'an activity\'s body is never in a payload');
ok((int) one("SELECT count(*) FROM activity_log WHERE action IN ('reminder.deliver', 'task.recur', 'feed.read')") > 0, 'reminder.deliver, task.recur and feed.read are in the log too (the other proofs wrote them)');

echo "4. The registry\n";
exec('php ' . escapeshellarg(dirname(__DIR__, 3) . '/bin/build_action_registry.php') . ' --check 2>&1', $out, $rc);
ok($rc === 0, 'bin/build_action_registry.php --check: the registry and approvals[] match the manifest');
$reg = json_decode((string) file_get_contents(dirname(__DIR__, 3) . '/mcp/action_registry.json'), true);
$mine = ['calendar', 'activity-list', 'activity-view', 'activity-add', 'activity-edit', 'log-call', 'log-meeting'];
ok(array_filter($mine, static fn ($s) => empty($reg['screens'][$s]['built'])) === [], 'the 7 screens read as built');
$acts = ['activity_create', 'activity_update', 'activity_complete', 'activity_reopen', 'activity_reschedule', 'activity_snooze', 'activity_assign', 'activity_participant_add', 'activity_participant_remove', 'feed_rotate'];
ok(array_filter($acts, static fn ($a) => empty($reg['actions'][$a]['built'])) === [], 'the 10 actions read as built');
ok(array_values(array_filter($acts, static fn ($a) => !empty($reg['actions'][$a]['approval'] ?? null))) === [], '0 approvals: nothing of this slice pauses an agent');
finish();
