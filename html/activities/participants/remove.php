<?php
declare(strict_types=1);
/** Action `activity_participant_remove` (log activity.participant_remove — own or activities.any): `activity`, `contact` or `member`. */
require_once dirname(__DIR__, 3) . '/app/bootstrap.php';
require_once dirname(__DIR__, 3) . '/app/features/activities/write.php';
crm_handler_begin();
$pdo = db();
$id = request_integer('activity') ?? request_integer('id');
require_record($pdo, 'activity', $id);
$a = find_activity($pdo, $id);
require_activity_may($a, 'edit');
$contact = request_integer('contact');
$member = request_integer('member');
if (($contact === null) === ($member === null)) { crm_refuse_fields(['contact' => 'Say who: a contact or a colleague.']); }
$gone = crm_guard($pdo, static function () use ($pdo, $id, $contact, $member, $a): bool {
    $pdo->beginTransaction();
    $gone = participant_remove($pdo, $id, $contact, $member);
    if ($gone) {
        log_activity($pdo, 'activity.participant_remove', 'activity', $id, activity_log_keys(activity_current($pdo, $id)) + ['before' => ['contact_id' => $contact, 'member_id' => $member], 'after' => ['subject' => $a['subject']]]);
    }
    $pdo->commit();
    return $gone;
});
if (!$gone) { refuse(404, 'They are not on this activity.'); }
crm_done('Took them off', $id, crm_land(return_path('/activities/' . $id), 'participant_removed', 'activity-participants'), 'activityChanged');
