<?php
declare(strict_types=1);
/** Action `record_restore` (log record.restore): a tombstone back — one the caller deleted or owns, or any for a manager. */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_once dirname(__DIR__, 2) . '/app/features/records/queries.php';
require_once dirname(__DIR__, 2) . '/app/features/records/write.php';
crm_handler_begin();
$pdo = db();
$me = (int) current_member_id();
$kind = record_kind_from_request();
$id = request_integer('record') ?? request_integer('id');
$t = $id === null ? null : tombstone_row($pdo, $kind, $id);
if ($t === null || !(is_manager() || (int) $t['deleted_by'] === $me || (int) $t['owner_member_id'] === $me)) {
    refuse(404, ucfirst(RECORD_KINDS[$kind]['noun']) . ' not found among the deleted.');
}
crm_guard($pdo, static function () use ($pdo, $kind, $id, $t): void {
    $pdo->beginTransaction();
    record_restore($pdo, $kind, $id);
    log_activity($pdo, 'record.restore', $kind, $id, ['account_id' => $kind === 'account' ? $id : null, 'after' => ['kind' => $kind, 'name' => $t['name']]]);
    $pdo->commit();
});
crm_done('Restored the ' . RECORD_KINDS[$kind]['noun'] . ' ' . $t['name'], $id, crm_land(RECORD_KINDS[$kind]['url'] . $id, 'restored'), $kind . 'Changed');
