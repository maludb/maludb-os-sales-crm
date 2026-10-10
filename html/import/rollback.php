<?php
declare(strict_types=1);
/** Action `import_rollback` (log import.rollback; confirm; PAUSES FOR AN AGENT — deletion — own or imports.shared; people only): within the window, what the run created goes to the tombstone; updated rows keep their values (the log says so). */
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
if ((int) $i['created_by'] !== (int) current_member_id() && !has_right('imports.shared')) { refuse(403, 'Only who started it, or someone who runs everyone\'s imports, may roll it back.'); }
if ($i['status'] !== 'done' || (bool) $i['dry_run']) { refuse(422, 'Only a finished real run is rolled back.'); }
if ($i['rollback_until'] === null || strtotime((string) $i['rollback_until']) <= time()) { refuse(422, 'The window to roll it back has closed.'); }
$n = crm_guard($pdo, static function () use ($pdo, $id, $i): int {
    $pdo->beginTransaction();
    $n = import_rollback($pdo, $id);
    log_activity($pdo, 'import.rollback', 'import', $id, ['after' => ['tombstoned' => $n, 'kept_updates' => (int) $i['rows_updated']]]);
    $pdo->commit();
    return $n;
});
crm_done('Rolled back: ' . $n . ' record' . ($n === 1 ? '' : 's') . ' to the tombstone' . ((int) $i['rows_updated'] > 0 ? '; ' . (int) $i['rows_updated'] . ' updated record(s) keep their new values' : ''), $id, crm_land('/import/' . $id, 'rolled_back'), 'importChanged');
