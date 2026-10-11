<?php
declare(strict_types=1);
/**
 * Action `stage_reorder` (log stage.reorder {order before and after}; pauses for an agent as `other`; pipelines.manage): `pipeline` and `stages[]` — the pipeline's open, live stages in the order wanted, every one once (a comma-separated
 * list does as well). Won and Lost keep their places. From the pipelines screen (`on` = list, over HTMX) the answer is that pipeline's stage list re-rendered — the server's truth, so a dragged row that was refused snaps back.
 */
require_once dirname(__DIR__, 3) . '/app/bootstrap.php';
require_once dirname(__DIR__, 3) . '/app/features/pipelines/write.php';
crm_handler_begin();
require_right('pipelines.manage');
$pdo = db();
$pipeline = request_integer('pipeline');
if ($pipeline === null || pipeline_row($pdo, $pipeline) === null) { refuse(404, 'Pipeline not found.'); }
$ids = request_list('stages');
if ($ids === null || $ids === []) { crm_refuse_fields(['stages' => 'Name the open stages in the order you want.']); }
foreach ($ids as $s) { if (!ctype_digit($s)) { crm_refuse_fields(['stages' => 'Stages are named by their numbers.']); } }
$r = crm_guard($pdo, static function () use ($pdo, $pipeline, $ids): array {
    $pdo->beginTransaction();
    $r = stage_reorder($pdo, $pipeline, array_map('intval', $ids));
    if ($r['before'] !== $r['after']) {
        log_activity($pdo, 'stage.reorder', 'pipeline', $pipeline, ['before' => $r['before'], 'after' => $r['after']]);
    }
    $pdo->commit();
    return $r;
});
$did = 'Saved the order of the stages';
if (req_val('on') === 'list' && is_htmx_request() && !wants_json()) {
    emit_action_status(true, ['did' => $did, 'record_id' => $pipeline]);
    echo view('admin/partials/stage-list.php', ['p' => pipeline_row($pdo, $pipeline)]);
    exit;
}
crm_done($did, $pipeline, crm_land(return_path('/admin/pipelines'), 'reordered', 'pipeline-' . $pipeline), 'stageChanged');
