<?php
declare(strict_types=1);
/**
 * Action `activity_reschedule` (log activity.reschedule {before, after} — own, the assignee, or calendar.team): `activity`, `at` (the new due time or
 * start — a date alone keeps the time of day), `ends_at`, `all_day`. A span keeps its length unless an end is given; the reminder re-arms. From the
 * calendar's drag (`on` = calendar, over HTMX) the answer is the grid re-rendered with the calendar's state (`calendar_query`) — the server's truth.
 */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_once dirname(__DIR__, 2) . '/app/features/activities/write.php';
crm_handler_begin();
$pdo = db();
$id = request_integer('activity') ?? request_integer('id');
require_record($pdo, 'activity', $id);
$a = find_activity($pdo, $id);
require_activity_may($a, 'reschedule');
$tz = member_timezone();
$v = trim((string) (req_val('at') ?? ''));
if ($v === '') { crm_refuse_fields(['at' => 'Say the new time.']); }
if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) && !(bool) $a['all_day']) { $v .= 'T' . format_ts($a['at'], $tz, 'H:i'); }   // a day alone keeps the time of day
$at = activity_time($v, $tz, '00:00');
$errors = [];
if ($at === false || $at === null) { $errors['at'] = 'The new time is a date and a time (YYYY-MM-DD HH:MM).'; }
$ends = null;
if (req_has('ends_at') && (string) req_val('ends_at') !== '') {
    $ends = activity_time(req_val('ends_at'), $tz);
    if (!is_string($ends)) { $errors['ends_at'] = 'The end is a date and a time.'; $ends = null; }
}
$allDay = req_has('all_day') ? crm_yes('all_day') : null;
if ($errors !== []) { crm_refuse_fields($errors); }
$moved = crm_guard($pdo, static function () use ($pdo, $id, $at, $ends, $allDay): array {
    $pdo->beginTransaction();
    $m = activity_reschedule($pdo, $id, $at, $ends, $allDay);
    log_activity($pdo, 'activity.reschedule', 'activity', $id, activity_log_keys(activity_current($pdo, $id)) + $m);
    $pdo->commit();
    return $m;
});
$did = 'Moved ' . $a['subject'] . ' to ' . format_ts($moved['after']['starts_at'] ?? $moved['after']['due_at'], $tz, 'D M j, g:i A');
if (req_val('on') === 'calendar' && is_htmx_request() && !wants_json()) {
    require_once dirname(__DIR__, 2) . '/app/features/calendar/queries.php';
    emit_action_status(true, ['did' => $did, 'record_id' => $id]);
    parse_str((string) (req_val('calendar_query') ?? ''), $src);
    $_GET = $src;
    $cal = calendar_rows($pdo, array_key_exists((string) ($src['view'] ?? ''), CALENDAR_VIEWS) ? (string) $src['view'] : 'month', (string) ($src['at'] ?? ''), (string) ($src['who'] ?? 'me'), calendar_kinds_from_request());
    header('HX-Trigger: dealChanged');
    require_once dirname(__DIR__, 2) . '/app/features/calendar/present.php';
    echo calendar_region_html($cal);
    exit;
}
crm_done($did, $id, crm_land(return_path('/activities/' . $id), 'rescheduled'), 'activityChanged, dealChanged');
