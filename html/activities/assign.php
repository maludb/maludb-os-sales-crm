<?php
declare(strict_types=1);
/** Action `activity_assign` (log activity.assign {owner_member_id before → after} — own or activities.any): `activity`, `owner` (a person or an agent holding a role here); a person given it hears of it. */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_once dirname(__DIR__, 2) . '/app/features/activities/write.php';
crm_handler_begin();
$pdo = db();
$id = request_integer('activity') ?? request_integer('id');
require_record($pdo, 'activity', $id);
$a = find_activity($pdo, $id);
require_activity_may($a, 'assign');
$errors = [];
$owner = crm_ref($pdo, 'owner', null, 'SELECT 1 FROM mcp_members WHERE member_id = :id', 'owner', $errors, false);
if ($errors !== []) { crm_refuse_fields($errors); }
if ($owner === (int) $a['owner_member_id']) { crm_done('Nothing to change', $id, crm_land(return_path('/activities/' . $id))); }
crm_guard($pdo, static function () use ($pdo, $id, $owner): void {
    $pdo->beginTransaction();
    $prev = activity_assign($pdo, $id, $owner);
    log_activity($pdo, 'activity.assign', 'activity', $id, activity_log_keys(activity_current($pdo, $id)) + ['before' => ['owner_member_id' => $prev], 'after' => ['owner_member_id' => $owner]]);
    $pdo->commit();
});
crm_done('Gave ' . $a['subject'] . ' to ' . member_name($pdo, $owner), $id, crm_land(return_path('/activities/' . $id), 'assigned'), 'activityChanged, dealChanged');
