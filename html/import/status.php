<?php
declare(strict_types=1);
/** GET /import/{id}/status — the status partial the import's page polls every 3 s while it is queued or running (Pattern A; nothing logged). When the import has finished, HX-Refresh reloads the page with its rows. */
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
header('Vary: HX-Request');
if (wants_json()) { respond_screen(['import' => present_import($i), 'state' => import_state_words($i)]); }
if (!in_array($i['status'], ['queued', 'running'], true) && request_string('was') === 'busy') { header('HX-Refresh: true'); }
echo view('import/partials/status.php', ['i' => $i]);
