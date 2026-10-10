<?php
declare(strict_types=1);
/** Action `import_run` (log import.run; confirm — own or imports.shared; people only): `import` after its dry run — the same code, for real; the worker runs it. */
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
$id = request_integer('import') ?? request_integer('id');
$i = $id === null ? null : find_import($pdo, $id);
if ($i === null) { refuse(404, 'Import not found.'); }
if ((int) $i['created_by'] !== (int) current_member_id() && !has_right('imports.shared')) { refuse(403, 'Only who started it, or someone who runs everyone\'s imports, may run it.'); }
if (!($i['status'] === 'done' && (bool) $i['dry_run'])) { refuse(422, 'An import runs after its dry run is done.'); }
crm_guard($pdo, static function () use ($pdo, $id, $i): void {
    $pdo->beginTransaction();
    import_run($pdo, $id);
    log_activity($pdo, 'import.run', 'import', $id, ['after' => ['dry_run_counts' => ['created' => (int) $i['rows_created'], 'updated' => (int) $i['rows_updated'], 'skipped' => (int) $i['rows_skipped'], 'refused' => (int) $i['rows_refused']]]]);
    $pdo->commit();
});
crm_done('Running the import of ' . $i['file_name'] . ' for real', $id, crm_land('/import/' . $id, 'run'), 'importChanged', ['undo' => 'import_rollback']);
