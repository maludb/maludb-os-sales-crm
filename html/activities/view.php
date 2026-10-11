<?php
declare(strict_types=1);
/**
 * /activities/{id} (screen `activity-view`): one activity — the record it belongs to, when and where, the reminder (when it fires, snoozed, delivered),
 * the outcome once done, what was said, participants, notes; Done (the outcome and the next-step prompt) · Snooze · Reschedule · Reassign · ⋯ Edit ·
 * Delete; a done one Reopen. An activity behind its record's wall (or another seller's private one) is the same 404 as a missing one. JSON: get_activity.
 */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_once dirname(__DIR__, 2) . '/app/features/activities/present.php';
require_once dirname(__DIR__, 2) . '/app/features/notes/queries.php';
require_once dirname(__DIR__, 2) . '/app/features/custom_fields/queries.php';
require_login();
require_right('records.read');
$pdo = db();
$id = request_integer('id');
require_record($pdo, 'activity', $id);
$a = find_activity($pdo, $id);
$participants = activity_participants($pdo, $id);
$may = activity_may($a) + ['note' => true, 'manager' => is_manager()];
log_screen_view($pdo, 'activity-view');
if (wants_json()) {
    respond_screen(['activity' => present_activity($a, $participants), 'may' => $may]);
}
$next = null;
if (($nid = request_integer('next')) !== null) {
    $next = one_row($pdo, 'SELECT activity_id, kind, subject, at FROM mcp_activities WHERE activity_id = :id', ['id' => $nid]);
}
$tz = member_timezone();
render_screen(ACTIVITY_KINDS[$a['kind']] . ': ' . $a['subject'], view('activities/view.php', ['a' => $a, 'participants' => $participants, 'may' => $may, 'tz' => $tz, 'me' => (int) current_member_id(),
    'notes' => find_notes($pdo, 'activity', $id), 'custom' => custom_values_display($pdo, 'activity', json_decode((string) $a['custom_values'], true) ?: []), 'next' => $next,
    'snooze_tomorrow' => format_ts((new DateTimeImmutable('tomorrow 09:00', new DateTimeZone($tz)))->format(DATE_ATOM), $tz, 'D g:i A'),
    'notice' => crm_notice($_GET['notice'] ?? null, ['created' => ['success', 'Scheduled.'], 'invited' => ['success', 'Sent the invitation.'], 'invited_partial' => ['warning', 'Sent to some of them; the rest did not go — send it again to reach them.'], 'logged' => ['success', 'Logged.'], 'logged_next' => ['success', 'Logged — and the next step is scheduled:'],
        'updated' => ['success', 'Saved.'], 'done' => ['success', 'Done.'], 'done_next' => ['success', 'Done — and the next step is scheduled:'], 'reopened' => ['success', 'Reopened.'],
        'snoozed' => ['success', 'Snoozed: the reminder comes back then.'], 'rescheduled' => ['success', 'Moved.'], 'assigned' => ['success', 'Reassigned.'],
        'participant_added' => ['success', 'Added them.'], 'participant_removed' => ['success', 'Took them off.'], 'note_added' => ['success', 'Added the note.'],
        'note_deleted' => ['success', 'Deleted the note.'], 'restored' => ['success', 'Restored.']])]),
    ['activeNav' => 'activities', 'screen' => 'activity-view', 'entity' => 'activity', 'recordId' => (string) $id]);
