<?php
/** Proof — the calendar (activities-calendar.md §1, §8): the month, week, day and agenda put the fixtures in the right cells and states; whose calendar
 * (a colleague's needs calendar.read, a team's calendar.team); a private activity hidden from another seller; New on a cell prefills the date; a
 * reschedule moves the chip; the grid partial; kinds. */
require __DIR__ . '/lib.php';
$x = run_tag();
$d = deal_make(26);
$today = gmdate('Y-m-d');
$tom = gmdate('Y-m-d', strtotime('+1 day'));
$past = gmdate('Y-m-d', strtotime('-2 days'));
echo "1. The fixtures in their cells\n";
$overdue = act_id(26, ['kind' => 'task', 'subject' => "Overdue $x", 'deal' => $d, 'due_at' => $past . 'T09:00']);
$todayA = act_id(26, ['kind' => 'deadline', 'subject' => "Today $x", 'deal' => $d, 'due_at' => $today . 'T23:30']);
$meet = act_id(26, ['kind' => 'meeting', 'subject' => "Meeting $x", 'deal' => $d, 'starts_at' => $tom . 'T13:00', 'ends_at' => $tom . 'T14:30']);
$allday = act_id(26, ['kind' => 'task', 'subject' => "All day $x", 'deal' => $d, 'due_at' => $tom, 'all_day' => 'yes']);
$done = act_id(26, ['kind' => 'call', 'subject' => "Done $x", 'deal' => $d, 'done' => 'yes', 'outcome' => 'connected']);
$c = cal(26, 'view=month&at=' . $today);
ok(($c['view'] ?? '') === 'month' && count($c['days'] ?? []) === 42 && in_array($overdue, on_day($c, $past), true) && in_array($todayA, on_day($c, $today), true) && in_array($meet, on_day($c, $tom), true) && in_array($done, on_day($c, $today), true),
    'the month: six weeks of days, each activity on its date');
ok(cal_row($c, $overdue)['state'] === 'overdue' && cal_row($c, $todayA)['state'] === 'today' && cal_row($c, $meet)['state'] === 'scheduled' && cal_row($c, $done)['state'] === 'done' && cal_row($c, $done)['done'] === true,
    'the states: overdue, today, scheduled, done');
ok(cal_row($c, $allday)['all_day'] === true && str_ends_with((string) cal_row($c, $allday)['starts_at'], 'T00:00:00Z') && in_array($allday, on_day($c, $tom), true), 'an all-day task sits on its date at midnight');
$h = html_as(26, '/calendar?view=month&at=' . $today)['body'];
ok(str_contains($h, 'id="cal-day-' . $today . '"') && str_contains($h, 'id="cal-item-' . $todayA . '"') && preg_match('/id="cal-item-' . $overdue . '"[^>]*/', $h) === 1
    && preg_match('/class="cal-chip cal-kind-task cal-state-overdue[^"]*" id="cal-item-' . $overdue . '"/', $h) === 1 && preg_match('/class="cal-chip cal-kind-call cal-state-done[^"]*" id="cal-item-' . $done . '"/', $h) === 1,
    'the month\'s HTML: the cells by date, the chips by kind and state');
ok(preg_match('/class="cal-day[^"]*\bcal-today\b[^"]*" id="cal-day-' . $today . '"/', $h) === 1, 'today\'s cell is marked');
$w = cal(26, 'view=week&at=' . $tom);
$wh = html_as(26, '/calendar?view=week&at=' . $tom)['body'];
ok(count($w['days'] ?? []) === 7 && in_array($meet, on_day($w, $tom), true) && preg_match('/id="cal-slot-' . $tom . '-13".*?id="cal-item-' . $meet . '"/s', $wh) === 1 && str_contains($wh, '--mins:90'),
    'the week: seven days; the meeting a block in its 1pm row, 90 minutes long');
ok(preg_match('/id="cal-allday-' . $tom . '"[^>]*>\s*<a[^>]*id="cal-item-' . $allday . '"/s', $wh) === 1, '…the all-day task in the strip above the hours');
$dy = cal(26, 'view=day&at=' . $tom);
ok(count($dy['days'] ?? []) === 1 && on_day($dy, $tom) !== [] && ($dy['prev'] ?? '') === $today, 'the day: one column, prev is the day before');
$ag = cal(26, 'view=agenda');
ok(in_array($overdue, $ag['agenda']['overdue'] ?? [], true) && in_array($todayA, $ag['agenda']['today'] ?? [], true) && in_array($meet, $ag['agenda']['tomorrow'] ?? [], true) && !in_array($done, $ag['agenda']['overdue'] ?? [], true),
    'the agenda: overdue, today, tomorrow — a done one is never overdue');
$agh = html_as(26, '/calendar?view=agenda')['body'];
ok(strpos($agh, 'calendar-agenda-overdue') !== false && strpos($agh, 'calendar-agenda-overdue') < strpos($agh, 'calendar-agenda-today'), 'the agenda\'s HTML: overdue first, then today');
$k = cal(26, 'view=month&at=' . $today . '&kinds=meeting');
ok(cal_row($k, $meet) !== null && cal_row($k, $overdue) === null, 'kinds=meeting leaves the meetings only');

echo "2. Whose calendar\n";
$dana = act_id(30, ['kind' => 'call', 'subject' => "Dana's call $x", 'account' => (int) post_as(30, '/accounts/save.php', ['name' => "Dana Co $x"])['json']['record_id'], 'starts_at' => $tom . 'T11:00']);
pg_run("UPDATE activities SET is_private = true WHERE id = $dana");
$danaPub = act_id(30, ['kind' => 'task', 'subject' => "Dana's task $x", 'due_at' => $tom . 'T16:00']);
$v = get_as(29, '/calendar?view=month&at=' . $today . '&who=30');
ok($v['code'] === 200 && cal_row($v['json']['data'], $danaPub) !== null, 'a Viewer opens a colleague\'s calendar (calendar.read)');
ok(cal_row($v['json']['data'], $dana) === null && cal_row(cal(26, 'view=month&at=' . $today . '&who=30'), $dana) === null, 'Dana\'s private call is hidden from the Viewer and from another seller');
ok(get_as(26, '/calendar?who=team:8')['code'] === 403 && str_contains(message_of(get_as(26, '/calendar?who=team:8')), 'team'), 'a Rep may not open a team\'s calendar: 403 in words');
$t = get_as(27, '/calendar?view=month&at=' . $today . '&who=team:8');
ok($t['code'] === 200 && cal_row($t['json']['data'], $dana) !== null && cal_row($t['json']['data'], $danaPub) !== null && cal_row($t['json']['data'], $meet) === null, 'the manager\'s team view (Partnerships): Dana\'s activities, the private one too; not Priya\'s');
$th = html_as(27, '/calendar?view=month&at=' . $today . '&who=team:8')['body'];
ok(str_contains($th, 'id="calendar-legend"') && str_contains($th, 'cal-initials'), '…a colour per person, initials on the chips, a legend');
ok(get_as(26, '/calendar?who=99999')['code'] === 404 && get_as(26, '/calendar?who=team:99999')['code'] === 403, 'a person not here is a 404; a team needs the right before anything');

echo "3. New on a cell, reschedule, the grid\n";
$cell = gmdate('Y-m-d', strtotime('+9 days'));
$f = get_as(26, '/activities/new?at=' . $cell)['json']['data']['values'];
ok(str_starts_with((string) $f['starts_at'], $cell . 'T09:00') && str_starts_with((string) $f['due_at'], $cell . 'T09:00'), 'New on a month cell: the form arrives on that date at 09:00');
$f = get_as(26, '/activities/new?at=' . $cell . 'T15:00&deal=' . $d)['json']['data']['values'];
ok(str_starts_with((string) $f['starts_at'], $cell . 'T15:00') && $f['deal_id'] === $d && $f['account_id'] !== null, 'New on a week cell: the date and the hour; from a deal, its account along');
ok(str_contains(html_as(26, '/calendar?view=month&at=' . $today)['body'], '/activities/new?at=' . $today), 'every cell has its New link');
$to = gmdate('Y-m-d', strtotime('+3 days'));
$r = post_as(26, "/activities/$meet/reschedule", ['at' => $to]);
$m = act_row($meet);
ok($r['code'] === 200 && str_starts_with($m['starts_at'], $to . ' 13:00') && str_starts_with($m['ends_at'], $to . ' 14:30'), 'a reschedule to a day keeps the time of day and the length');
$c2 = cal(26, 'view=month&at=' . $today);
ok(in_array($meet, on_day($c2, $to), true) && !in_array($meet, on_day($c2, $tom), true), 'the chip moved to the new day');
$r = post_as(26, "/activities/$meet/reschedule", ['at' => $to . 'T08:30']);
ok(str_starts_with(act_row($meet)['starts_at'], $to . ' 08:30') && str_starts_with(act_row($meet)['ends_at'], $to . ' 10:00'), 'to an hour (the week\'s drag): the start moves, the length stays');
[$j, $tok] = actor(26);
$g = req('POST', "/activities/$meet/reschedule", ['jar' => $j, 'headers' => ['HX-Request: true', 'X-CSRF-Token: ' . $tok], 'form' => ['activity' => $meet, 'at' => $tom . 'T10:00', 'on' => 'calendar', 'calendar_query' => 'view=week&at=' . $tom]]);
ok($g['code'] === 200 && str_contains($g['body'], 'id="calendar-grid"') && str_contains($g['body'], 'id="cal-slot-' . $tom . '-10"') && preg_match('/id="cal-slot-' . $tom . '-10".*?id="cal-item-' . $meet . '"/s', $g['body']) === 1,
    'the drag\'s answer (on=calendar over HTMX): the week\'s region re-rendered, the chip in its new hour');
ok(post_as(30, "/activities/$meet/reschedule", ['at' => $tom])['code'] === 403, 'another Rep may not move Priya\'s meeting');
ok(post_as(27, "/activities/$meet/reschedule", ['at' => $tom . 'T10:00'])['code'] === 200, 'the manager may (calendar.team)');
ok(post_as(26, "/activities/$meet/reschedule", ['at' => 'soon'])['code'] === 422 && post_as(26, "/activities/$done/reschedule", ['at' => $tom])['code'] === 422, 'a time that is not one, or a done activity: 422');
$gr = html_as(26, '/calendar/grid?view=week&at=' . $tom, ['HX-Request: true']);
ok($gr['code'] === 200 && str_starts_with(trim($gr['body']), '<div id="calendar-grid"') && !str_contains($gr['body'], '<html'), 'the grid partial is the region alone');
ok(str_contains($gr['body'], 'hx-trigger="activityChanged from:body"'), '…re-rendered on activityChanged');
finish();
