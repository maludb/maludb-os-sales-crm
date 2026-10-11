<?php
declare(strict_types=1);
/**
 * POST /exports/download.php (log export.download; not an action — a file door): `export` — its maker's alone, ONCE. The bytes are streamed as an attachment with nosniff; the first request marks the export
 * downloaded in the same statement that finds it (so two clicks never both get the file), an expired or already downloaded export is gone (410 here, or back to /exports with a note). A GET only
 * explains: the download is a POST with the page's CSRF token, so nothing that follows a link can use it up.
 */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_once dirname(__DIR__, 2) . '/app/features/records/queries.php';
require_once dirname(__DIR__, 2) . '/app/features/attachments/store.php';
require_once dirname(__DIR__, 2) . '/app/features/exports/queries.php';
require_login();
require_right('export.own');
$pdo = db();
$id = request_integer('export');
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    redirect('/exports');
}
if (wants_json()) {
    json_error('people_only', 'An export is downloaded by its maker from the Exports page; it is not handed to a tool.', 406);
}
verify_csrf();
$e = $id === null ? null : find_export($pdo, $id);
if ($e === null || (int) $e['created_by'] !== (int) current_member_id()) { refuse(404, 'Export not found.'); }
// the one statement that spends it: done, not yet downloaded, not expired, a file, the maker's
$st = $pdo->prepare("UPDATE exports SET downloaded_at = now() WHERE id = :id AND created_by = :me AND status = 'done' AND downloaded_at IS NULL AND expires_at > now() AND storage_path IS NOT NULL RETURNING storage_path");
$st->execute(['id' => $id, 'me' => (int) current_member_id()]);
$rel = $st->fetchColumn();
if ($rel === false) {
    if ($e['status'] !== 'done') { refuse(409, 'That export is not ready yet.'); }
    redirect('/exports?notice=gone');
}
$abs = attachment_storage_dir() . '/' . $rel;
log_activity($pdo, 'export.download', 'export', $id, ['after' => ['kind' => $e['kind'], 'format' => $e['format'], 'item_count' => (int) $e['item_count'], 'byte_size' => (int) $e['byte_size']]]);
if (!is_file($abs)) { refuse(404, 'That file is no longer here; make another export.'); }
session_write_close();
$name = 'sales-crm-' . $e['kind'] . '-' . $id . '.' . $e['format'];
header('X-Content-Type-Options: nosniff');
header('Content-Type: ' . ($e['format'] === 'csv' ? 'text/csv; charset=utf-8' : 'application/json; charset=utf-8'));
header('Content-Length: ' . (string) filesize($abs));
header('Content-Disposition: attachment; filename="' . $name . '"');
header('Content-Security-Policy: sandbox');
header('Cache-Control: no-store');
readfile($abs);
exit;
