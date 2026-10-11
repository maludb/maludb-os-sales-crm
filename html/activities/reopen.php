<?php
declare(strict_types=1);
/** Action `activity_reopen` (log activity.reopen — own or activities.any): `activity` — done → open again; the outcome and who did it are cleared, the reminder's moment comes back. */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_once dirname(__DIR__, 2) . '/app/features/activities/write.php';
crm_handler_begin();
$pdo = db();
$id = request_integer('activity') ?? request_integer('id');
require_record($pdo, 'activity', $id);
$a = find_activity($pdo, $id);
require_activity_may($a, 'reopen');
crm_guard($pdo, static function () use ($pdo, $id, $a): void {
    $pdo->beginTransaction();
    activity_reopen($pdo, $id);
    $saved = activity_current($pdo, $id);
    log_activity($pdo, 'activity.reopen', 'activity', $id, activity_log_keys($saved) + ['before' => ['outcome' => $a['outcome'], 'done_at' => json_ts($a['done_at'])], 'after' => ['subject' => $saved['subject'], 'done' => false]]);
    $pdo->commit();
});
crm_done('Reopened ' . $a['subject'], $id, crm_land(return_path('/activities/' . $id), 'reopened'), 'activityChanged, dealChanged');
