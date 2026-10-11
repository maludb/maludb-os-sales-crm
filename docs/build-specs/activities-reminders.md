# Build spec: activities, reminders and THE CALENDAR (slice 4 — THE SECOND EXEMPLAR)

Builds `activities-calendar.md`. What exists at the end: the calendar in four views for me, a colleague or a team; the activities list; an
activity's page with Done and the next-step prompt, Snooze, Reschedule, Reassign and participants; schedule and edit forms; the two quick-log
forms for a phone; the worker's reminders pass delivering in-app, email and text; recurring tasks expanded; the ICS feed and its rotation;
every deal, contact, account and lead carrying its next step.

Schema (db/010, 013, 017, never modified): `activities`, `activity_participants`, `calendar_feeds`, `crm_refresh_next_activity()`, `crm_next_colour()`, `crm_reminders_due()`,
`crm_activity_snooze()`, `crm_feed_rows()` (db/010); `crm_notify()`, `crm_deliver_reminder()` (db/013); `crm_calendar()`, `crm_my_activities()` (db/017); `mcp_activities`,
`mcp_activity_participants`, `mcp_notification_prefs` (db/016).

## Divergences from the design
1. **`activity_complete` makes the next step in the same request** (`next_kind`, `next_subject`, `next_at`) — the prompt is a form section, never a modal.
2. **The quick-log forms post `activity_create` with `done = yes`** and the next-step fields; no action of their own.
3. **`feed_rotate` (the shell's row) is built here** with the feed.

## Who may do what
activity_create: `activities.write` + `see` on the record · activity_update / assign / participants: own or `activities.any` · activity_complete / snooze: own, the assignee, or
`activities.any` · activity_reschedule: own, the assignee, or `calendar.team` · the calendar of a colleague: `calendar.read`; of a team: `calendar.team` · feed_rotate: `calendar.own`, people.

## Screens
`calendar` · `activity-list` `/activities?who=&state=&kind=&from=&to=&account=&contact=&deal=&lead=&view=` · `activity-view` `/activities/{id}` · `activity-add` / `activity-edit`
`/activities/new?kind=&deal=&contact=&account=&lead=&at=&subject=` · `/activities/{id}/edit` · `log-call` `/log/call?contact=&deal=&account=&lead=` · `log-meeting` `/log/meeting?…`
(the build spec §1–§3).

## Files (exactly these)
The build spec §6, plus `tests/phase3/slice4/{run.sh,lib.php,calendar.php,activities.php,log.php,reminders.php,ics.php,json.php,browser.mjs}`.

## Query functions (signatures fixed)
`calendar_rows(PDO, string $view, string $at, string $who, array $kinds): array{from, to, rows, days}` · `agenda(PDO, ?int $member): array{overdue, today, tomorrow, week}` ·
`find_activities(PDO, array $filters, int $page): array` · `find_activity(PDO, int $id): ?array` · `activity_participants(PDO, int $id): array` · `open_deals_of_contact(PDO, int $contact): array` (the quick form's radios) ·
`activities_for_pick(…)` · writes: `activity_save(PDO, ?int $id, array $f, int $by): int` · `activity_complete(PDO, int $id, array $f): array{activity_id, next_activity_id}` · `activity_reopen(PDO, int $id)` ·
`activity_reschedule(PDO, int $id, string $at, ?string $ends, bool $allDay)` · `activity_snooze(PDO, int $id, string $until)` · `activity_assign(PDO, int $id, int $owner)` · `participant_add/remove(PDO, int $id, ?int $contact, ?int $member)` ·
`feed_rotate(PDO, int $member): string` · `feed_render(PDO, int $member): string` (the ICS text) · the worker: `worker_pass_reminders`, `worker_pass_recurring_tasks`, `worker_pass_next_step_flags`

## MCP tools this slice makes real
`my_activities`, `calendar`, `find_activities` (the resolver), `get_activity`, `activity_summary`, `reminders_due`.

## Manifest rows claimed
Screens (7): `calendar`, `activity-list`, `activity-view`, `activity-add`, `activity-edit`, `log-call`, `log-meeting`
Actions (10): `activity_create`, `activity_update`, `activity_complete`, `activity_reopen`, `activity_reschedule`, `activity_snooze`, `activity_assign`, `activity_participant_add`, `activity_participant_remove`, `feed_rotate`

## Activity log events
`activity.create|update|complete|reopen|reschedule|snooze|assign|participant_add|participant_remove`, `feed.rotate`, `feed.read` (the member, never the token), `reminder.deliver` (the worker,
with the count), `task.recur` (the worker). An activity's body is never in a payload.

## Status vocabulary
The build spec §7.

## Out of scope for this slice
The outbox's sending (8 — the rows are written here, the fake MaluMail proves them), the meeting invitation's email (5), texts (8).

## Proof (`tests/phase3/slice4/run.sh`: scratch `crm_dev4`; the worker run by hand with CRM_WORKER_NOW; Playwright)
The build spec §8, plus JSON mode and the registry `--check` (7 screens built, 10 actions, 0 approvals).

## Open questions
None.

## Built and proven (2026-10-11)
The calendar in four views drawn by the server from ONE query (`crm_calendar`): the month (six weeks from the Monday on or before the 1st, four chips a
day then "+N more", the day's number opening the day, a + on every cell), the week and the day (an all-day strip, the hours 07–19 as rows, meetings, calls
and lunches as blocks as long as they last, tasks and deadlines as chips), the agenda (overdue in red, today, tomorrow, the rest of the week) — for me, a
colleague (`calendar.read`) or a team (`calendar.team`: a colour per person, initials, a legend), the kinds as toggles; the region `#calendar-grid` swapped
on prev · today · next, a view change and `activityChanged`; the drag (SortableJS, `calendar.js`) posting `activity_reschedule` and swapping in the region the
server renders; the agenda the phone's default. The activities list (whose, the state as tabs, the kind, dates, words, a record, saved views, Save this view,
Done on each row). An activity's page (when and where, the reminder in words — when it fires, snoozed, delivered — Reschedule, the outcome, what was said, the
record, who does it with Reassign, participants with the record picker, custom fields, notes; Done or Reopen, Snooze, Edit, Delete). The schedule form (the
kind as buttons, its own time fields, the four records by picker, all day, the reminder, who, where, the priority, the repeat, people taking part, tags).
Done as a page with the outcome as large buttons and THE NEXT-STEP PROMPT; the two quick-log forms for a phone; the worker's `reminders`, `recurring_tasks`
and `next_step_flags` passes; the ICS feed and its rotation from My settings (the link shown once). Pickers `activity` and `member` (colleagues). The registry
reads 41 screens and 61 actions built (7 and 10 of them this slice's), nothing of this slice paused for agents.

**Proven** by `tests/phase3/slice4/run.sh` on a fresh `crm_dev4`, under php -S and a real Apache — calendar 29 (each view's cells and states; whose; a
private activity hidden from another seller and shown to the manager; New on a cell; the reschedule by the handler and by the drag's HTMX answer; the
rights), activities 72 (the defaults; ten refusals in words; a time in a named zone; update and the rules; the wall's same 404; Done with the prompt making
the next step in the same request, the prompt folded when another step is planned and off when the settings say so; reopen; reassign telling the new one —
never an agent; participants; delete; the list's filters; the pickers), log 24 (the forms from a deal, a contact with two open deals as buttons, a lead; a
call logged with its next step and the deal's next step following; "On…" a date; No next step; a meeting held; a lead turning working), reminders 27 (once at
its time with the email queued, never before; snooze and again; tomorrow at 09:00 in the owner's zone; done or deleted never; re-armed by a reschedule; a text
when asked; an agent's as a dispatch; recurring tasks one ahead and never in the past; the next step mended), ics 23 (the link once, its hash only; the
VEVENTs with UID, UTC times, the record's URL, the body's first 200 characters, CANCELLED when done, VALARM, all day as a date, folding; nobody else's; a
rotated, wrong or revoked token the same 404; feed.read without the token), json 41 (every screen and handler, the HTMX stamps, the log's vocabulary — never
a body — and the registry), the browser 47 (a real drag in the month and in the week; a refused drag snapping back; the month and the agenda as defaults;
New on a cell; the form's kind fields; Log a call from the phone's sheet; Done; Snooze; Done, a log and Reschedule with JavaScript off; the seven screens at
375 and 1280).

**Found and fixed:** **db/020** — `crm_record_delete()` (db/013) named a kind's table by adding "s", so an activity's was `activitys` and deleting or
restoring one failed; the table is now named per kind. Phase 2's routing proof follows whether slice 4 is built.

**Decisions taken here (within the spec):**
- The region the page, `/calendar/grid` and the drag's answer render is one function (`calendar_region_html`), so they never differ; the drag carries the
  calendar's state as `calendar_query`, as the board's carries `board_query`.
- A drop on a day keeps the activity's time of day; a drop on an hour keeps its minutes; a span keeps its length unless an end is given.
- The phone's default: the `Sec-CH-Viewport-Width` hint (asked with Accept-CH) when the browser sends it, else `calendar.js` switches a narrow screen that
  chose no view to the agenda; a view chosen by hand is remembered for the session.
- "Own" is the maker or the one who does it; Bea taking part in Marco's task may not complete it.
- The next step's quick choices are tomorrow 10:00, in three days 10:00, next Monday 10:00, or a date and a time ("On…"); a folded section's kind stays
  "No next step", so nothing is made unless the person chooses. A log without a subject is "Call with <the contact>" (the deal's primary contact when only
  the deal is named); the next step's default subject "Follow up with <first name>".
- New from a calendar cell returns to the calendar (its `back`), with "Scheduled."
- A person given an activity is told (a notification of kind `assigned`); an agent is not — its reminder is a dispatch.
- An agent's reminder is an `agent_dispatches` row of kind `reminder` with `reminded_at` set; slice 8's `dispatches` pass sends the turn.
- The ICS link is shown once — on My settings after "Make my link", or as `url` in JSON; the feed's rate is 60 opens a minute per token, counted in a file
  per token and minute in the temp directory (no schema).
- `next_step_flags` mends a record whose stored next step disagrees with its open activities; the colours are computed when read, so midnight needs nothing.
