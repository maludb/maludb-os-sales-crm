<?php
declare(strict_types=1);
/** Action `activity_participant_add` (log activity.participant_add — own or activities.any): `activity`, `contact` or `member` (a colleague) — one of them. Already there → nothing. */
require_once dirname(__DIR__, 3) . '/app/bootstrap.php';
require_once dirname(__DIR__, 3) . '/app/features/activities/write.php';
crm_handler_begin();
$pdo = db();
$id = request_integer('activity') ?? request_integer('id');
require_record($pdo, 'activity', $id);
$a = find_activity($pdo, $id);
require_activity_may($a, 'edit');
$errors = [];
$contact = crm_ref($pdo, 'contact', null, 'SELECT 1 FROM mcp_contacts WHERE contact_id = :id', 'contact', $errors);
$member = crm_ref($pdo, 'member', null, "SELECT 1 FROM mcp_members WHERE member_id = :id AND member_kind = 'human'", 'colleague', $errors);
if ($errors === [] && ($contact === null) === ($member === null)) { $errors['contact'] = 'Choose one person: a contact or a colleague.'; }
if ($errors !== []) { crm_refuse_fields($errors); }
$added = crm_guard($pdo, static function () use ($pdo, $id, $contact, $member, $a): bool {
    $pdo->beginTransaction();
    $added = participant_add($pdo, $id, $contact, $member);
    if ($added) {
        log_activity($pdo, 'activity.participant_add', 'activity', $id, activity_log_keys(activity_current($pdo, $id)) + ['after' => ['contact_id' => $contact, 'member_id' => $member, 'subject' => $a['subject']]]);
    }
    $pdo->commit();
    return $added;
});
$name = $contact !== null ? (string) one_value($pdo, 'SELECT name FROM mcp_contacts WHERE contact_id = :c', ['c' => $contact]) : (string) member_name($pdo, $member);
crm_done($added ? 'Added ' . $name : $name . ' takes part already', $id, crm_land(return_path('/activities/' . $id), 'participant_added', 'activity-participants'), 'activityChanged');
