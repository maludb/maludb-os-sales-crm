<?php
declare(strict_types=1);
/** Action `source_delete` (log source.delete; confirm; pauses for an agent as `deletion`; sources.manage): a source nothing names. One that a lead, deal, account, contact, import, lead-capture key or assignment rule names is refused by name — deactivate it (source_save). */
require_once dirname(__DIR__, 3) . '/app/bootstrap.php';
require_once dirname(__DIR__, 3) . '/app/features/vocab/write.php';
crm_handler_begin();
require_right('sources.manage');
$pdo = db();
$id = request_integer('source') ?? request_integer('id');
if ($id === null || source_one($pdo, $id) === null) { refuse(404, 'Source not found.'); }
$r = crm_guard($pdo, static function () use ($pdo, $id): array {
    $pdo->beginTransaction();
    $r = source_delete($pdo, $id);
    log_activity($pdo, 'source.delete', 'lead_source', $id, ['before' => $r['before']]);
    $pdo->commit();
    return $r;
});
crm_done('Deleted the source ' . $r['before']['name'], $id, crm_land('/admin/sources', 'deleted'), 'sourceChanged');
