<?php
declare(strict_types=1);
/**
 * Action `pipeline_save` (log pipeline.save {name, key, currency, rotting_days, position, archived, is_default}; pauses for an agent as `other`; pipelines.manage): `pipeline` changes an existing one, none makes one — with its Won and Lost
 * stages. key is a slug (made from the name when omitted), currency a three-letter code (blank = the business's), rotting_days 1 to 365 (blank = the settings'), is_default yes or no, archived yes or no. A field left out stays as it was.
 * The default pipeline, the last live one, and one holding open deals cannot be archived; the sentence says which.
 */
require_once dirname(__DIR__, 3) . '/app/bootstrap.php';
require_once dirname(__DIR__, 3) . '/app/features/pipelines/write.php';
crm_handler_begin();
require_right('pipelines.manage');
$pdo = db();
$id = request_integer('pipeline') ?? request_integer('id');
$cur = $id === null ? null : pipeline_row($pdo, $id);
if ($id !== null && $cur === null) { refuse(404, 'Pipeline not found.'); }
$errors = [];
$name = crm_text('name', $cur['name'] ?? null, 80, 'The name', $errors, true);
if ($cur === null && $name === null && !isset($errors['name'])) { $errors['name'] = 'The name is required.'; }
$key = $cur['key'] ?? null;
if (req_has('key') && (string) req_val('key') !== '') {
    $key = strtolower((string) req_val('key'));
    if (!preg_match('/^[a-z][a-z0-9_]{0,39}$/', $key)) { $errors['key'] = 'The key is lower-case letters, digits and underscores, starting with a letter (up to 40).'; $key = $cur['key'] ?? null; }
}
$key ??= $name === null ? null : pipeline_key_from($name);
$currency = crm_text('currency', $cur['currency'] ?? null, 3, 'The currency', $errors);
if ($currency !== null && !preg_match('/^[A-Za-z]{3}$/', $currency)) { $errors['currency'] = 'The currency is a three-letter code like USD, or blank for the business\'s.'; }
$f = ['name' => $name, 'key' => $key, 'currency' => $currency === null ? null : strtoupper($currency),
      'rotting_days' => crm_int('rotting_days', $cur['rotting_days'] ?? null, 1, 365, 'Rotting days', $errors, true),
      'is_default' => crm_yes('is_default', $cur['is_default'] ?? false), 'position' => crm_int('position', $cur['position'] ?? null, 0, 100000, 'The position', $errors, true),
      'archived' => crm_yes('archived', $cur !== null && $cur['archived_at'] !== null)];
if ($errors !== []) { crm_refuse_fields($errors); }
$r = crm_guard($pdo, static function () use ($pdo, $id, $f): array {
    $pdo->beginTransaction();
    $r = pipeline_save($pdo, $id, $f);
    log_activity($pdo, 'pipeline.save', 'pipeline', $r['id'], ['before' => $r['before'], 'after' => $r['after'] + ['name' => $f['name']]]);
    $pdo->commit();
    return $r;
});
crm_done(($id === null ? 'Added the pipeline ' : 'Saved the pipeline ') . $f['name'], $r['id'], crm_land('/admin/pipelines', $id === null ? 'created' : 'saved', 'pipeline-' . $r['id']), 'pipelineChanged');
