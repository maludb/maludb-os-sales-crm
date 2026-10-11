<?php
declare(strict_types=1);
/**
 * /admin/pipelines (screen `admin-pipelines`; pipelines.manage): every pipeline a card with its stages as a sortable list — probability, rotting, the fields a deal must have to enter, the Assistant flag, the deals in it — Add a
 * stage, Edit, the archived pipelines below. The order is dragged (assets/js/stage-order.js, an enhancement over each row's Move up and Move down). JSON: the same, whitelisted.
 */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_once dirname(__DIR__, 2) . '/app/features/admin/present.php';
require_login();
require_right('pipelines.manage');
$pdo = db();
$live = pipelines_with_stages($pdo, false);
$archived = pipelines_with_stages($pdo, true);
log_screen_view($pdo, 'admin-pipelines');
if (wants_json()) {
    respond_screen(['pipelines' => array_map('present_pipeline', $live), 'archived' => array_map('present_pipeline', $archived)]);
}
$notices = ['saved' => ['success', 'Saved the pipeline.'], 'created' => ['success', 'Added the pipeline. It has its Won and Lost stages — add the stages between.'], 'deleted' => ['success', 'Deleted the pipeline.'],
    'stage' => ['success', 'Saved the stage.'], 'stage_deleted' => ['success', 'Deleted the stage.'], 'reordered' => ['success', 'Saved the order.']];
render_screen('Pipelines', view('admin/pipelines.php', ['live' => $live, 'archived' => $archived, 'notice' => crm_notice($_GET['notice'] ?? null, $notices)]),
    ['activeNav' => 'admin', 'screen' => 'admin-pipelines', 'entity' => 'pipeline']);
