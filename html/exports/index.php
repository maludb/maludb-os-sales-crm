<?php
declare(strict_types=1);
/** /exports (screen `export-list`): my exports (everyone's for the CRM admin) — ready to download, being made, expiring. The page refreshes itself while one is waiting or being made. JSON: the exports. */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_once dirname(__DIR__, 2) . '/app/features/records/queries.php';
require_once dirname(__DIR__, 2) . '/app/features/exports/queries.php';
require_login();
require_right('export.own');
$pdo = db();
$rows = find_exports($pdo);
log_screen_view($pdo, 'export-list');
$present = static fn (array $e): array => ['export_id' => (int) $e['export_id'], 'kind' => $e['kind'], 'format' => $e['format'], 'status' => $e['status'], 'state' => $e['state'], 'state_words' => $e['state_words'],
    'title' => export_title(db(), $e), 'item_count' => (int) $e['item_count'], 'byte_size' => $e['byte_size'] === null ? null : (int) $e['byte_size'], 'created_at' => json_ts($e['created_at']), 'finished_at' => json_ts($e['finished_at']),
    'expires_at' => json_ts($e['expires_at']), 'downloaded_at' => json_ts($e['downloaded_at']), 'can_download' => $e['can_download'], 'created_by' => (int) $e['created_by'], 'error' => $e['status'] === 'failed' ? $e['log'] : null,
    'download' => $e['can_download'] ? '/exports/download.php?export=' . (int) $e['export_id'] : null];
if (wants_json()) {
    respond_screen(['exports' => array_map($present, $rows)]);
}
$notices = ['queued' => ['success', 'Queued. It is ready in a minute or two; this page shows when.'], 'gone' => ['warning', 'That export has been downloaded or has expired; make another.']];
render_screen('Exports', view('exports/index.php', ['rows' => $rows, 'tz' => member_timezone(), 'busy' => array_filter($rows, static fn (array $e): bool => in_array($e['status'], ['queued', 'running'], true)) !== [],
    'notice' => $notices[$_GET['notice'] ?? ''] ?? null, 'title_of' => static fn (array $e): string => export_title(db(), $e)]), ['activeNav' => 'lists', 'screen' => 'export-list', 'entity' => 'export']);
