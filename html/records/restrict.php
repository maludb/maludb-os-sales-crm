<?php
declare(strict_types=1);
/**
 * Action `record_restrict` (log record.restrict — records.restrict, a manager): `kind` (lead, account, contact, deal) and `record`; `member` (the
 * owner by default) and/or `department` it is restricted to; `lift` yes opens it again. A restricted record is seen by its owner, the member, the
 * department's people and the managers only (crm_wall); the trigger refuses when the business has turned restricted records off.
 */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_once dirname(__DIR__, 2) . '/app/features/records/queries.php';
require_once dirname(__DIR__, 2) . '/app/features/records/write.php';
crm_handler_begin();
require_right('records.restrict');
$pdo = db();
$kind = record_kind_from_request(RESTRICTABLE_KINDS);
$id = request_integer('record') ?? request_integer('id');
$row = require_record($pdo, $kind, $id);
$errors = [];
$lift = crm_yes('lift', false);
$member = crm_ref($pdo, 'member', null, 'SELECT 1 FROM mcp_members WHERE member_id = :id', 'member', $errors);
$dept = crm_ref($pdo, 'department', null, 'SELECT 1 FROM mcp_departments WHERE department_id = :id AND archived_at IS NULL', 'team', $errors);
if (!$lift && $member === null && $dept === null) {
    $member = $row['owner_member_id'] !== null ? (int) $row['owner_member_id'] : null;
    if ($member === null) { $errors['member'] = 'Say who it is restricted to — it has no owner.'; }
}
if ($errors !== []) {
    crm_refuse_fields($errors);
}
$r = crm_guard($pdo, static function () use ($pdo, $kind, $id, $member, $dept, $lift, $row): array {
    $pdo->beginTransaction();
    $r = record_restrict($pdo, $kind, $id, $member, $dept, $lift);
    log_activity($pdo, 'record.restrict', $kind, $id, ['account_id' => $kind === 'account' ? $id : $row['account_id'], 'before' => $r['before'], 'after' => $r['after'] + ['lift' => $lift]]);
    $pdo->commit();
    return $r;
});
$noun = RECORD_KINDS[$kind]['noun'];
crm_done($lift ? 'The ' . $noun . ' is open to everyone again' : 'Restricted the ' . $noun . ' ' . $row['name'], $id, crm_land(return_path(RECORD_KINDS[$kind]['url'] . $id), $lift ? 'unrestricted' : 'restricted'), $kind . 'Changed', ['restricted_to' => $r['after']]);
