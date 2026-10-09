# Build spec: activities, reminders and the calendar (design §6.3; D8, D9) — read before slice 4

One table holds every touch and every plan (`activities`, db/010); the reminder is its columns; the calendar is PHP drawing that table in
grids; the worker delivers reminders. This spec fixes the grids, the quick-log forms, the done → next-step prompt, the reminder path and
the ICS feed so slice 4 builds them once.

## 1. The calendar (`html/calendar/index.php`, `/calendar?view=&at=&who=&kinds=`)
- **Views**: `month` (a 6-row grid of days, each cell the day's chips — at most four, then "+N more" linking the day view), `week`
  (7 columns × the hours 07–19 as rows; meetings, calls and lunches as blocks spanning their duration, tasks and deadlines as chips at
  their time, all-day items in a strip above), `day` (one column), `agenda` (a list: overdue first in red, then today, tomorrow, the
  rest of the week; the phone's default — `view` defaults to `agenda` under 768 px by the server reading `Sec-CH-Viewport-Width` or the
  session's remembered choice, else `month`). `at` is a date (the month's or week's anchor); `who` is `me` (default), a member id (needs
  `calendar.read`), or `team:<department id>` (needs `calendar.team`: one colour per person, a legend); `kinds` filters.
- **The grid partial** `html/calendar/grid.php` (`#calendar-grid`): the same query (`crm_calendar(from, to, member, team, kinds, true)`),
  re-rendered on navigation (prev · today · next), on a view change and on `activityChanged from:body`. Each chip (`#cal-item-{id}`): the
  kind's icon, the time (none when all-day), the subject, the record's name; the state class (`done` muted and struck, `overdue` red,
  `today` amber, `scheduled` by kind's colour: call blue, meeting purple, task grey, deadline orange, lunch green, email teal); a click
  opens `/activities/{id}`. A cell's **New** (the `+` on hover; always shown at 375) opens `/activities/new?at=<date>` (+ the hour in the
  week and day views).
- **Drag to reschedule** (the enhancement, `assets/js/calendar.js`): SortableJS between day cells (month) and hour rows (week/day) with
  `group: 'calendar'`; `onEnd` posts `/activities/{id}/reschedule` with `at` = the target cell's date (and hour); the grid re-renders. Without
  JavaScript the activity's edit form stands.
- **The team view** reads every member of the department; a chip carries the owner's initials; private activities are hidden from all but
  their seller and managers (the function's rule).

## 2. Scheduling and logging (`activity_create`)
One handler, `html/activities/save.php`, two shapes: **schedule** (open: a `due_at` for a task or a deadline, `starts_at`/`ends_at` for a
meeting, call or lunch; `remind_minutes_before` defaulted by the trigger) and **log** (`done = yes`: `done_at` now or the time said, the
outcome, the body). The record: `deal` first (the trigger fills `account_id` and `contact_id` from it), else `contact`, else `account`,
else `lead`; a bare `task` needs none. **The quick-log forms** `/log/call` and `/log/meeting` are the same action with the form laid out for a
phone: who (the `contact` picker, prefilled from the page), the deal (the contact's open deals as radio buttons when ≤ 3, else the
picker), the outcome as large buttons, the body (a textarea the dictation lands in), and **the next step section** (kind, when as quick
choices — tomorrow 10:00, in 3 days, next week, a date — and a subject prefilled "Follow up with <name>"): posting makes the touch
(done) and the next step (open) in one request; the answer's `record_id` is the touch, `next_activity_id` the step.

## 3. Done and the next step (`activity_complete`)
`html/activities/complete.php`: `done_at` (now by default), the outcome (the kind's choices; the trigger defaults it), `outcome_note`;
**the prompt**: when `crm_settings.next_step_prompt` is on and the activity belongs to an open deal that will have no next step after this
one (`deals.next_activity_id = this`), the form shows "Schedule the next step?" with the same quick fields as the log forms — `next_kind`,
`next_subject`, `next_at` — posted in the same request; the handler makes the second activity after the first is done. The command bar's
expert does the same in words (the `activity-based-selling` skill). The deal's card changes colour on the board's next poll.

## 4. Reminders (the worker's `reminders` pass)
Every minute: `crm_reminders_due(now, 200)` → per row `crm_deliver_reminder(id)` in its own transaction (a notification with the kind
`reminder`, the outbox rows the person's preferences ask for — email by default, a text when `text_enabled` and `reminder` in
`text_kinds` and the kernel's K6 confirms a verified phone — and `reminded_at`). The outbox pass (slice 8) sends. A reminder for an
activity whose owner is an agent writes an `agent_dispatches` row of kind `reminder` instead (the worker's `dispatches` pass sends the
chat turn). **Snooze** (`activity_snooze`): `10m`, `1h`, `tomorrow` (09:00 in the owner's zone) or a time → `crm_activity_snooze`; the
reminder fires again then. A done or deleted activity never reminds. The bell shows the reminder; the home's *Today* strip shows the
activity.

## 5. The ICS feed (`html/api/v1/calendar_ics.php`, `GET /api/v1/calendar.ics?token=`)
txtSchedules' `app/features/calendar/ics.php` copied: a 48-hex token per member (`calendar_feeds`, sha256 kept; `feed_rotate` replaces
it), `crm_feed_rows(member)` → one `VEVENT` per activity (`UID` = `crm-activity-{id}@{domain}`, `DTSTART`/`DTEND` in UTC or `VALUE=DATE`
when all-day, `SUMMARY` = kind: subject — record, `DESCRIPTION` = the record's URL and the body's first 200 characters, `LOCATION`,
`STATUS` CANCELLED when done, a `VALARM` `TRIGGER:-PT{remind_minutes_before}M` when set), `X-WR-CALNAME` "Sales CRM — <name>",
`REFRESH-INTERVAL` 15 minutes; `text/calendar; charset=utf-8`; a bad or revoked token answers 404; every open logged `feed.read` with the
member, never the token; rate 60/min per token. A meeting's **invitation** to a contact is the same `VEVENT` (one event) attached to the
email `meeting_invite_send` sends (slice 5) with `METHOD:REQUEST` and the contact as `ATTENDEE`.

## 6. Files (exactly these, slice 4)
`html/calendar/{index,grid}.php`, `html/activities/{index,view,form,save,complete,reopen,reschedule,snooze,assign}.php`,
`html/activities/participants/{add,remove}.php`, `html/log/{call,meeting}.php`, `html/api/v1/calendar_ics.php`, `html/settings/feed.php`
(`feed_rotate` — the shell's row, built here); `app/features/activities/{queries,present,write}.php`, `app/features/calendar/{queries,ics,present}.php`;
`app/views/calendar/{index}.php`, `app/views/calendar/partials/{grid-month,grid-week,grid-day,agenda,chip,legend,nav}.php`,
`app/views/activities/{index,view,form,complete}.php`, `app/views/activities/partials/{row,next-step,outcome-buttons,participants}.php`,
`app/views/log/{call,meeting}.php`; `html/assets/js/calendar.js`; `bin/worker.php` gains the real `reminders`, `recurring_tasks`,
`next_step_flags`; `app/pickers.php` gains `activity`, `member` (colleagues).

## 7. Ids and vocabulary
`calendar`, `calendar-nav`, `calendar-view-{month|week|day|agenda}`, `calendar-who`, `calendar-grid`, `cal-day-{yyyy-mm-dd}`, `cal-item-{id}`,
`activity-form`, `activity-form-field-{name}`, `activity-complete-form`, `next-step-section`, `log-call-form`, `log-meeting-form`,
`outcome-{value}`. Kind colours as §1; states `done`, `overdue`, `today`, `scheduled`.

## 8. Proof (slice 4's `tests/phase3/slice4/run.sh`)
The month, week, day and agenda render the fixtures in the right cells and states; `who` as a colleague needs `calendar.read` (a Viewer
may, nobody may not), a team needs `calendar.team`; a private activity is hidden from another seller's view of the team; New on a cell
prefills the date; a reschedule by the handler moves the chip; a quick log of a call makes the touch done and the next step open and
the deal's `next_activity_at` follows; complete with the prompt makes the next activity; the reminder pass delivers a due reminder once,
a snooze re-delivers, a done one never; the ICS feed lists the owner's activities with VALARM, a rotated token kills the old URL; JSON
mode; 375 (the agenda default) and 1280 (the month); the registry `--check`.
