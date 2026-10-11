<?php
declare(strict_types=1);
/** Action `lost_reason_delete` (log reason.delete; confirm; pauses for an agent as `deletion`; sources.manage): a reason no deal or lead names; one that is named is refused by name — deactivate it (lost_reason_save). */
require_once dirname(__DIR__, 3) . '/app/bootstrap.php';
require_once dirname(__DIR__, 3) . '/app/features/vocab/write.php';
crm_handler_begin();
require_right('sources.manage');
$pdo = db();
$id = request_integer('lost_reason') ?? request_integer('reason') ?? request_integer('id');
if ($id === null || reason_one($pdo, $id) === null) { refuse(404, 'Reason not found.'); }
$r = crm_guard($pdo, static function () use ($pdo, $id): array {
    $pdo->beginTransaction();
    $r = reason_delete($pdo, $id);
    log_activity($pdo, 'reason.delete', 'lost_reason', $id, ['before' => $r['before']]);
    $pdo->commit();
    return $r;
});
crm_done('Deleted the reason ' . $r['before']['label'], $id, crm_land('/admin/sources', 'deleted'), 'reasonChanged');
