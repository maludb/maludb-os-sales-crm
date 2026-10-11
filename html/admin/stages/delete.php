<?php
declare(strict_types=1);
/** Action `stage_delete` (log stage.delete; confirm; pauses for an agent as `deletion`; pipelines.manage): an open stage no deal sits in and none ever passed through — else archive it (stage_save). Won, Lost and a pipeline's last open stage are refused. */
require_once dirname(__DIR__, 3) . '/app/bootstrap.php';
require_once dirname(__DIR__, 3) . '/app/features/pipelines/write.php';
crm_handler_begin();
require_right('pipelines.manage');
$pdo = db();
$id = request_integer('stage') ?? request_integer('id');
if ($id === null || stage_one($pdo, $id) === null) { refuse(404, 'Stage not found.'); }
$r = crm_guard($pdo, static function () use ($pdo, $id): array {
    $pdo->beginTransaction();
    $r = stage_delete($pdo, $id);
    log_activity($pdo, 'stage.delete', 'stage', $id, ['before' => $r['before'] + ['pipeline_id' => $r['pipeline_id']]]);
    $pdo->commit();
    return $r;
});
crm_done('Deleted the stage ' . $r['before']['name'], $id, crm_land('/admin/pipelines', 'stage_deleted', 'pipeline-' . $r['pipeline_id']), 'stageChanged');
