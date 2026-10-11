<?php
declare(strict_types=1);
/** /admin/pipelines/new and /admin/pipelines/{id}/edit (screens `admin-pipeline-add`, `admin-pipeline-edit`; pipelines.manage): key, name, currency, rotting days, the default, the order, archive or restore. JSON: the values. */
require_once dirname(__DIR__, 3) . '/app/bootstrap.php';
require_once dirname(__DIR__, 3) . '/app/features/admin/present.php';
require_login();
require_right('pipelines.manage');
$pdo = db();
$id = request_integer('id');
$p = null;
if ($id !== null) {
    $p = pipeline_row($pdo, $id);
    if ($p === null) { refuse(404, 'Pipeline not found.'); }
}
$screen = $id === null ? 'admin-pipeline-add' : 'admin-pipeline-edit';
log_screen_view($pdo, $screen);
if (wants_json()) {
    respond_screen(['pipeline' => $p === null ? null : present_pipeline($p), 'base_currency' => base_currency($pdo)]);
}
render_screen($p === null ? 'New pipeline' : 'Edit ' . $p['name'], view('admin/pipeline-form.php', ['p' => $p, 'base_currency' => base_currency($pdo)]),
    ['activeNav' => 'admin', 'screen' => $screen, 'entity' => 'pipeline', 'recordId' => $id === null ? '' : (string) $id]);
