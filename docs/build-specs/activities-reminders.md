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
