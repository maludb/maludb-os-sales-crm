<?php
declare(strict_types=1);
/** Action `pipeline_delete` (log pipeline.delete; confirm; pauses for an agent as `deletion`; pipelines.manage): a pipeline no deal has ever been in, with its stages. One with deals is refused — archive it (pipeline_save). */
require_once dirname(__DIR__, 3) . '/app/bootstrap.php';
require_once dirname(__DIR__, 3) . '/app/features/pipelines/write.php';
crm_handler_begin();
require_right('pipelines.manage');
$pdo = db();
$id = request_integer('pipeline') ?? request_integer('id');
if ($id === null || pipeline_row($pdo, $id) === null) { refuse(404, 'Pipeline not found.'); }
$r = crm_guard($pdo, static function () use ($pdo, $id): array {
    $pdo->beginTransaction();
    $r = pipeline_delete($pdo, $id);
    log_activity($pdo, 'pipeline.delete', 'pipeline', $id, ['before' => $r['before']]);
    $pdo->commit();
    return $r;
});
crm_done('Deleted the pipeline ' . $r['before']['name'], $id, crm_land('/admin/pipelines', 'deleted'), 'pipelineChanged');
