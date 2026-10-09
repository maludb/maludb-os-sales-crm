<?php
declare(strict_types=1);
/**
 * Action `record_delete` (log record.delete; confirm; PAUSES FOR AN AGENT — deletion): `kind` and `record` go to the tombstone for the business's
 * tombstone days (out of every list, search and report; restorable), then the worker purges them. The edit rule; an account that still has deals or
 * contacts needs a manager (its people and deals would lose their company).
 */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_once dirname(__DIR__, 2) . '/app/features/records/queries.php';
require_once dirname(__DIR__, 2) . '/app/features/records/write.php';
crm_handler_begin();
$pdo = db();
$kind = record_kind_from_request();
$id = request_integer('record') ?? request_integer('id');
$row = require_record($pdo, $kind, $id);
require_editable($pdo, $kind, $row);
if ($kind === 'account' && !is_manager()) {
    $n = (int) one_value($pdo, 'SELECT (SELECT count(*) FROM deals WHERE account_id = :id AND deleted_at IS NULL) + (SELECT count(*) FROM contacts WHERE account_id = :id AND deleted_at IS NULL)', ['id' => $id]);
    if ($n > 0) {
        refuse(403, 'This account still has deals or contacts: a manager deletes it.');
    }
}
crm_guard($pdo, static function () use ($pdo, $kind, $id, $row): void {
    $pdo->beginTransaction();
    record_delete($pdo, $kind, $id);
    log_activity($pdo, 'record.delete', $kind, $id, ['account_id' => $kind === 'account' ? $id : $row['account_id'], 'after' => ['kind' => $kind, 'name' => $row['name'], 'tombstone' => true]]);
    $pdo->commit();
});
$list = ['lead' => '/leads', 'account' => '/accounts', 'contact' => '/contacts', 'deal' => pipeline_board_url(), 'activity' => '/activities', 'campaign' => '/campaigns'][$kind];
crm_done('Deleted the ' . RECORD_KINDS[$kind]['noun'] . ' ' . $row['name'] . ' — restorable for a while', $id, crm_land(return_path($list), 'deleted'), $kind . 'Changed', ['undo' => 'record_restore']);
