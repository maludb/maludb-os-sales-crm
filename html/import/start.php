<?php
declare(strict_types=1);
/**
 * Action `import_start` (log import.start — imports.own; people only): `kind`, `file` (a CSV up to the business's rows), `mapping` (header → field, custom:<key> or skip; an
 * object or mapping[header] fields — omitted: each header by its own words or the template's), `template`, `dedupe_policy` (skip, update, create), `owner`, `source`,
 * `list` (a static list of the kind every created record joins), `dry_run` (yes — the default). The worker runs it; the page follows its status.
 */
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
$me = (int) current_member_id();
$errors = [];
$kind = crm_choice('kind', null, array_keys(IMPORT_KINDS), 'The kind', $errors, false);
if ($kind === null && !isset($errors['kind'])) { $errors['kind'] = 'Say what the file holds: leads, contacts, accounts or deals.'; }
$template = null;
if (($tid = request_integer('template')) !== null) {
    $template = find_template($pdo, $tid);
    if ($template === null) { $errors['template'] = 'That saved mapping is not here.'; }
    elseif ($kind !== null && $template['kind'] !== $kind) { $errors['template'] = 'That mapping is for ' . strtolower(IMPORT_KINDS[$template['kind']]) . '.'; }
}
$policy = crm_choice('dedupe_policy', $template['dedupe_policy'] ?? 'skip', ['skip', 'update', 'create'], 'What to do with a match', $errors, false) ?? 'skip';
$owner = crm_ref($pdo, 'owner', null, 'SELECT 1 FROM mcp_members WHERE member_id = :id', 'owner', $errors);
$source = crm_ref($pdo, 'source', null, 'SELECT 1 FROM mcp_lead_sources WHERE source_id = :id', 'source', $errors);
$list = crm_ref($pdo, 'list', null, "SELECT 1 FROM mcp_lists WHERE list_id = :id AND kind = 'static'", 'list', $errors);
if ($list !== null && $kind !== null && one_value($pdo, 'SELECT record_kind FROM lists WHERE id = :id', ['id' => $list]) !== IMPORT_RECORD_KIND[$kind]) { $errors['list'] = 'The list holds another kind of record.'; }
$dry = crm_yes('dry_run', true);
$file = null;
if (isset($_FILES['file']) && is_array($_FILES['file']) && ($_FILES['file']['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK) {
    $file = ['tmp' => (string) $_FILES['file']['tmp_name'], 'name' => (string) $_FILES['file']['name']];
} elseif (isset($_FILES['file']['error']) && in_array((int) $_FILES['file']['error'], [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true)) {
    $errors['file'] = 'The file is too big for one upload.';
} else {
    $errors['file'] = 'Choose the CSV file.';
}
if ($file !== null && !preg_match('/\.(csv|txt)$/i', $file['name'])) { $errors['file'] = 'Upload a CSV file (.csv).'; }
if ($errors !== []) { crm_refuse_fields($errors); }
$sniff = csv_sniff($file['tmp']);
$given = $_POST['mapping'] ?? null;
if (is_string($given)) { $given = json_decode($given, true); if (!is_array($given)) { crm_refuse_fields(['mapping' => 'The mapping is an object of a column to a field.']); } }
$mapping = import_auto_mapping($pdo, $kind, $sniff['headers'], $template['mapping_list'] ?? []);
if (is_array($given)) {
    foreach ($given as $h => $field) { $mapping[(string) $h] = (string) $field === '' ? 'skip' : (string) $field; }
}
$id = crm_guard($pdo, static function () use ($pdo, $kind, $mapping, $policy, $owner, $source, $list, $template, $dry, $file, $me): int {
    $pdo->beginTransaction();
    $id = import_start($pdo, ['kind' => $kind, 'mapping' => $mapping, 'dedupe_policy' => $policy, 'owner_member_id' => $owner, 'source_id' => $source, 'list_id' => $list,
        'template_id' => $template !== null ? (int) $template['id'] : null, 'dry_run' => $dry], $file, $me);
    $i = $pdo->query('SELECT row_count, file_name FROM imports WHERE id = ' . $id)->fetch();
    log_activity($pdo, 'import.start', 'import', $id, ['after' => ['kind' => $kind, 'file_name' => $i['file_name'], 'rows' => (int) $i['row_count'], 'dedupe_policy' => $policy, 'dry_run' => $dry,
        'mapped' => count(array_filter($mapping, static fn ($v) => $v !== 'skip')), 'template_id' => $template !== null ? (int) $template['id'] : null]]);
    $pdo->commit();
    return $id;
});
crm_done(($dry ? 'Started the dry run of ' : 'Started the import of ') . $file['name'], $id, crm_land('/import/' . $id, 'started'), 'importChanged');
