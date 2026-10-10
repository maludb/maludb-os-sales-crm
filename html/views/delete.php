<?php
declare(strict_types=1);
/** Action `saved_view_delete` (log view.delete — my own; a shared one with reports.team; never a built-in view): `saved_view`. Asked to confirm first. */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_once dirname(__DIR__, 2) . '/app/features/deals/queries.php';
require_once dirname(__DIR__, 2) . '/app/features/views/queries.php';
require_once dirname(__DIR__, 2) . '/app/features/views/write.php';
crm_handler_begin();
require_right('records.read');
$pdo = db();
$id = request_integer('saved_view');
$v = $id === null ? null : find_saved_view($pdo, $id);
if ($v === null) { refuse(404, 'Saved view not found.'); }
if (!saved_view_mine($v)) { refuse(403, $v['is_system'] ? 'A built-in view stays.' : 'Only its owner — or a manager, for a shared one — deletes this view.'); }
crm_guard($pdo, static function () use ($pdo, $id, $v): void {
    $pdo->beginTransaction();
    saved_view_delete($pdo, $id);
    log_activity($pdo, 'view.delete', 'saved_view', $id, ['before' => ['name' => $v['name'], 'scope' => $v['scope'], 'record_kind' => $v['record_kind']]]);
    $pdo->commit();
});
crm_done('Deleted the view ' . $v['name'], $id, crm_land('/views', 'deleted'), 'viewChanged');
