<?php
declare(strict_types=1);
/** /import (screen `import-list`; params status, kind): my imports (everyone's with imports.shared), newest first. */
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
$filters = ['status' => in_array($s = request_string('status'), array_keys(IMPORT_STATUS_BADGE), true) ? $s : null, 'kind' => isset(IMPORT_KINDS[$k = request_string('kind')]) ? $k : null];
$rows = find_imports($pdo, $filters);
log_screen_view($pdo, 'import-list');
if (wants_json()) { respond_screen(['filters' => $filters, 'imports' => array_map('present_import', $rows)]); }
render_screen('Imports', view('import/index.php', ['rows' => $rows, 'filters' => $filters, 'tz' => member_timezone()]), ['activeNav' => 'leads', 'screen' => 'import-list', 'entity' => 'import']);
