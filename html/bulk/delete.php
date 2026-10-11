<?php
declare(strict_types=1);
/**
 * Action `bulk_delete` (log bulk.delete {kind, count, filters?}; confirm; PAUSES FOR AN AGENT — deletion; records.bulk): `kind`, and what to act on — `records[]` or `list` or `filters`. They go to the tombstone for
 * the business's tombstone days, restorable one by one. The edit rule on every record; an account that still has deals or contacts needs a manager — either refuses the whole action, naming it.
 */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_once dirname(__DIR__, 2) . '/app/features/bulk/select.php';
require_once dirname(__DIR__, 2) . '/app/features/bulk/write.php';
crm_handler_begin();
require_right('records.bulk');
$pdo = db();
$kind = record_kind_from_request(array_keys(FILTER_KINDS));
[$sel, $gone] = crm_guard($pdo, static function () use ($pdo, $kind): array {
    $sel = bulk_select($pdo, $kind, $_POST);
    bulk_require_editable($pdo, $kind, $sel['ids']);
    $pdo->beginTransaction();
    $gone = bulk_delete($pdo, $kind, $sel['ids']);
    log_activity($pdo, 'bulk.delete', null, null, ['after' => ['kind' => $kind, 'count' => count($gone), 'selected' => count($sel['ids']), 'source' => $sel['source'], 'list_id' => $sel['list'], 'filters' => $sel['filters'],
        'tombstone' => true, 'record_ids' => $gone]]);
    $pdo->commit();
    return [$sel, $gone];
});
bulk_done('delete', $kind, $sel, count($gone), 'Deleted ' . count($gone) . ' ' . RECORD_KINDS[$kind]['noun'] . (count($gone) === 1 ? '' : 's') . ' — restorable for a while', ['deleted' => $gone, 'undo' => 'record_restore']);
