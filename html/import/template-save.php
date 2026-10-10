<?php
declare(strict_types=1);
/** Action `import_template_save` (log import.template_save — imports.own; people only): `name`, `kind`, `mapping` (header → field), `dedupe_policy`, `is_shared` (imports.shared). The same name of the same kind replaces my own. */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_once dirname(__DIR__, 2) . '/app/features/records/queries.php';
require_once dirname(__DIR__, 2) . '/app/features/attachments/store.php';
require_once dirname(__DIR__, 2) . '/app/features/imports/csv.php';
require_once dirname(__DIR__, 2) . '/app/features/imports/queries.php';
require_once dirname(__DIR__, 2) . '/app/features/imports/present.php';
require_once dirname(__DIR__, 2) . '/app/features/imports/write.php';
crm_handler_begin();
require_right('imports.own');
require_human();
$pdo = db();
$errors = [];
$name = crm_text('name', null, 120, 'The name', $errors, true);
if ($name === null && !isset($errors['name'])) { $errors['name'] = 'Name the mapping.'; }
$kind = crm_choice('kind', null, array_keys(IMPORT_KINDS), 'The kind', $errors, false);
$policy = crm_choice('dedupe_policy', 'skip', ['skip', 'update', 'create'], 'What to do with a match', $errors, false) ?? 'skip';
$shared = crm_yes('is_shared', false);
if ($shared && !has_right('imports.shared')) { $errors['is_shared'] = 'You may not share a mapping with everyone.'; }
$m = $_POST['mapping'] ?? null;
if (is_string($m)) { $m = json_decode($m, true); }
if (!is_array($m) || $m === []) { $errors['mapping'] = 'Give the mapping: a column to a field.'; }
elseif ($kind !== null) {
    foreach ($m as $h => $f) { if ((string) $f !== 'skip' && !isset(IMPORT_FIELDS[$kind][(string) $f]) && !str_starts_with((string) $f, 'custom:')) { $errors['mapping'] = 'A ' . IMPORT_KINDS[$kind] . ' import has no field ' . $f . '.'; break; } }
}
if ($errors !== []) { crm_refuse_fields($errors); }
$m = array_map('strval', $m);
$tid = crm_guard($pdo, static function () use ($pdo, $name, $kind, $m, $policy, $shared): int {
    $pdo->beginTransaction();
    $tid = template_save($pdo, $name, $kind, $m, $policy, $shared, (int) current_member_id());
    log_activity($pdo, 'import.template_save', 'import_template', $tid, ['after' => ['name' => $name, 'kind' => $kind, 'columns' => count($m), 'dedupe_policy' => $policy, 'is_shared' => $shared]]);
    $pdo->commit();
    return $tid;
});
crm_done('Saved the mapping ' . $name, $tid, crm_land(return_path('/import/new?kind=' . $kind . '&template=' . $tid), 'template_saved'), 'importChanged');
