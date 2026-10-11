<?php
declare(strict_types=1);
/**
 * Action `stage_save` (log stage.save {name, kind, probability, rotting_days, required_fields, ask_assistant, colour, wip_limit, position, archived}; pauses for an agent as `other`; pipelines.manage): `stage` changes an existing
 * one, otherwise `pipeline` and a new stage. kind open|won|lost (a pipeline has one Won and one Lost; Won is 100 and Lost 0), probability 0 to 100, rotting_days 1 to 365, required_fields[] (the deal's columns or custom field keys —
 * open stages only), ask_assistant, colour (a hex like #198754), wip_limit (a warning, never a refusal), position, archived. A field left out stays as it was. A stage deals sit in keeps its kind; a changed probability reaches the open deals in it.
 */
require_once dirname(__DIR__, 3) . '/app/bootstrap.php';
require_once dirname(__DIR__, 3) . '/app/features/pipelines/write.php';
crm_handler_begin();
require_right('pipelines.manage');
$pdo = db();
$id = request_integer('stage') ?? request_integer('id');
$cur = $id === null ? null : stage_one($pdo, $id);
if ($id !== null && $cur === null) { refuse(404, 'Stage not found.'); }
$pipeline = $cur['pipeline_id'] ?? request_integer('pipeline');
if ($pipeline === null || pipeline_row($pdo, $pipeline) === null) { refuse(404, 'Pipeline not found.'); }
$errors = [];
$name = crm_text('name', $cur['name'] ?? null, 60, 'The name', $errors, true);
if ($cur === null && $name === null && !isset($errors['name'])) { $errors['name'] = 'The name is required.'; }
$f = ['name' => $name, 'kind' => crm_choice('kind', $cur['kind'] ?? 'open', array_keys(STAGE_KINDS), 'The kind', $errors, false),
      'probability' => crm_int('probability', $cur['probability'] ?? null, 0, 100, 'The probability', $errors, true),
      'rotting_days' => crm_int('rotting_days', $cur['rotting_days'] ?? null, 1, 365, 'Rotting days', $errors, true),
      'ask_assistant' => crm_yes('ask_assistant', $cur['ask_assistant'] ?? false), 'wip_limit' => crm_int('wip_limit', $cur['wip_limit'] ?? null, 1, 100000, 'The WIP limit', $errors, true),
      'position' => crm_int('position', $cur['position'] ?? null, 0, 100000, 'The position', $errors, true), 'archived' => crm_yes('archived', $cur !== null && $cur['archived_at'] !== null)];
$f['required_fields'] = $cur['required_fields'] ?? [];
if (req_has('required_fields') || req_has('required_fields_present')) {
    $f['required_fields'] = request_list('required_fields') ?? [];
}
$colour = crm_text('colour', $cur['colour'] ?? null, 7, 'The colour', $errors);
if ($colour !== null && !preg_match('/^#[0-9a-fA-F]{6}$/', $colour)) { $errors['colour'] = 'The colour is a hex code like #198754.'; $colour = $cur['colour'] ?? null; }
$f['colour'] = $colour === null ? null : strtolower($colour);
if ($f['probability'] === null && $cur !== null) { $f['probability'] = $cur['probability']; }
if ($errors !== []) { crm_refuse_fields($errors); }
$r = crm_guard($pdo, static function () use ($pdo, $id, $pipeline, $f): array {
    $pdo->beginTransaction();
    $r = stage_save($pdo, $id, $pipeline, $f);
    log_activity($pdo, 'stage.save', 'stage', $r['id'], ['before' => $r['before'], 'after' => $r['after'] + ['name' => $f['name'], 'pipeline_id' => $pipeline]]);
    $pdo->commit();
    return $r;
});
crm_done(($id === null ? 'Added the stage ' : 'Saved the stage ') . $f['name'], $r['id'], crm_land('/admin/pipelines', 'stage', 'pipeline-' . $pipeline), 'stageChanged');
