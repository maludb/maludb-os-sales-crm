<?php
declare(strict_types=1);
/**
 * /admin/pipelines/{id}/stages/new and /admin/stages/{id}/edit (screens `admin-stage-add`, `admin-stage-edit`; pipelines.manage): name, kind, probability, rotting days, the fields a deal must have to enter (the deal's columns and its
 * custom fields as chips), ask the Assistant, colour, the WIP limit, the order, archive or restore. The route gives a new stage its pipeline (`pipeline`) and an existing one its id. JSON: the values and the choices.
 */
require_once dirname(__DIR__, 3) . '/app/bootstrap.php';
require_once dirname(__DIR__, 3) . '/app/features/admin/present.php';
require_login();
require_right('pipelines.manage');
$pdo = db();
$id = request_integer('id');
if ($id !== null) {
    $s = stage_one($pdo, $id);
    if ($s === null) { refuse(404, 'Stage not found.'); }
    $pipeline = pipeline_row($pdo, $s['pipeline_id']);
} else {
    $s = null;
    $pipeline = ($pid = request_integer('pipeline')) === null ? null : pipeline_row($pdo, $pid);
    if ($pipeline === null) { refuse(404, 'Pipeline not found.'); }
}
$screen = $id === null ? 'admin-stage-add' : 'admin-stage-edit';
$choices = stage_required_choices($pdo);
log_screen_view($pdo, $screen);
if (wants_json()) {
    respond_screen(['stage' => $s === null ? null : present_stage($s), 'pipeline_id' => $pipeline['id'], 'pipeline_name' => $pipeline['name'], 'kinds' => STAGE_KINDS, 'required_field_choices' => $choices]);
}
render_screen($s === null ? 'New stage' : 'Edit ' . $s['name'], view('admin/stage-form.php', ['s' => $s, 'pipeline' => $pipeline, 'choices' => $choices]),
    ['activeNav' => 'admin', 'screen' => $screen, 'entity' => 'stage', 'recordId' => $id === null ? '' : (string) $id]);
