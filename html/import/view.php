<?php
declare(strict_types=1);
/** /import/{id} (screen `import-view`; params result, page): the counts, the rows filtered by result, Run · Roll back, the mapping used (save it as a template), the status poll. */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_once dirname(__DIR__, 2) . '/app/features/records/queries.php';
require_once dirname(__DIR__, 2) . '/app/features/attachments/store.php';
require_once dirname(__DIR__, 2) . '/app/features/imports/csv.php';
require_once dirname(__DIR__, 2) . '/app/features/imports/queries.php';
require_once dirname(__DIR__, 2) . '/app/features/imports/present.php';
require_once dirname(__DIR__, 2) . '/app/features/imports/write.php';
require_login();
require_right('imports.own');
$pdo = db();
$id = request_integer('id');
$i = $id === null ? null : find_import($pdo, $id);
if ($i === null) { refuse(404, 'Import not found.'); }
$result = isset(IMPORT_RESULTS[$r = request_string('result')]) ? $r : null;
$page = max(1, request_integer('page') ?? 1);
$rows = import_rows($pdo, $id, $result, $page);
log_screen_view($pdo, 'import-view');
if (wants_json()) {
    respond_screen(['import' => present_import($i), 'state' => import_state_words($i), 'result' => $result, 'page' => $page, 'total' => $rows['total'],
        'rows' => array_map(static fn (array $r): array => ['row' => (int) $r['row_number'], 'result' => $r['result'], 'record_id' => $r['record_id'] === null ? null : (int) $r['record_id'], 'message' => $r['message']], $rows['rows'])]);
}
$mine = (int) $i['created_by'] === (int) current_member_id();
$may = ['run' => ($mine || has_right('imports.shared')) && $i['status'] === 'done' && (bool) $i['dry_run'],
        'rollback' => ($mine || has_right('imports.shared')) && $i['status'] === 'done' && !(bool) $i['dry_run'] && $i['rollback_until'] !== null && strtotime((string) $i['rollback_until']) > time(),
        'template' => true];
$notices = ['started' => ['info', 'Started. The page follows it.'], 'run' => ['info', 'Running for real now.'], 'rolled_back' => ['warning', 'Rolled back: what it created is in the tombstone.'], 'template_saved' => ['success', 'Saved the mapping.']];
render_screen('Import ' . $i['file_name'], view('import/view.php', ['i' => $i, 'rows' => $rows, 'result' => $result, 'page' => $page, 'may' => $may, 'tz' => member_timezone(), 'notice' => $notices[$_GET['notice'] ?? ''] ?? null]),
    ['activeNav' => 'leads', 'screen' => 'import-view', 'entity' => 'import', 'recordId' => (string) $id]);
