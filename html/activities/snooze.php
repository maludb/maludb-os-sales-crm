<?php
declare(strict_types=1);
/**
 * Action `activity_snooze` (log activity.snooze {until} — own, the assignee, or activities.any): `activity`, `until` — 10m, 1h, tomorrow (09:00 in the
 * owner's zone) or a time ahead. crm_activity_snooze: the reminder fires again then; a done activity is never snoozed.
 */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_once dirname(__DIR__, 2) . '/app/features/activities/write.php';
crm_handler_begin();
$pdo = db();
$id = request_integer('activity') ?? request_integer('id');
require_record($pdo, 'activity', $id);
$a = find_activity($pdo, $id);
require_activity_may($a, 'snooze');
$v = (string) (req_val('until') ?? '');
if ($v === '') { crm_refuse_fields(['until' => 'Say until when: 10m, 1h, tomorrow or a time.']); }
$ownerTz = (string) (one_value($pdo, 'SELECT timezone FROM members WHERE id = :m', ['m' => (int) $a['owner_member_id']]) ?: member_timezone());
$until = snooze_until($v, $ownerTz, member_timezone());
if ($until === null) { crm_refuse_fields(['until' => 'Snooze until a time ahead: 10m, 1h, tomorrow or a date and a time.']); }
crm_guard($pdo, static function () use ($pdo, $id, $until, $a): void {
    $pdo->beginTransaction();
    activity_snooze($pdo, $id, $until);
    log_activity($pdo, 'activity.snooze', 'activity', $id, activity_log_keys(activity_current($pdo, $id)) + ['before' => ['snoozed_until' => json_ts($a['snoozed_until'])], 'after' => ['until' => $until, 'subject' => $a['subject']]]);
    $pdo->commit();
});
crm_done('Snoozed until ' . format_ts($until, member_timezone(), 'D M j, g:i A'), $id, crm_land(return_path('/activities/' . $id), 'snoozed'), 'activityChanged', ['until' => $until]);
