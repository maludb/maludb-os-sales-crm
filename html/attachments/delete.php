<?php
declare(strict_types=1);
/** Action `attachment_delete` (log attachment.delete; confirm; PAUSES FOR AN AGENT — deletion): who uploaded it, or a manager. The bytes go when no other row names them. */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_once dirname(__DIR__, 2) . '/app/features/records/queries.php';
require_once dirname(__DIR__, 2) . '/app/features/attachments/store.php';
require_once dirname(__DIR__, 2) . '/app/features/attachments/queries.php';
require_once dirname(__DIR__, 2) . '/app/features/attachments/write.php';
crm_handler_begin();
$pdo = db();
$a = ($id = request_integer('attachment') ?? request_integer('id')) === null ? null : find_attachment($pdo, $id);
if ($a === null) {
    refuse(404, 'File not found.');
}
if ((int) $a['uploaded_by'] !== (int) current_member_id() && !is_manager()) {
    refuse(403, 'Only who attached it or a manager may delete it.');
}
$kind = (string) $a['record_type'];
$rid = (int) $a['record_id'];
$path = crm_guard($pdo, static function () use ($pdo, $id, $a, $kind, $rid): ?string {
    $pdo->beginTransaction();
    $path = attachment_delete($pdo, $id);
    $keys = in_array($kind, ['lead', 'account', 'contact', 'deal'], true) ? [$kind . '_id' => $rid] : [];
    log_activity($pdo, 'attachment.delete', 'attachment', $id, $keys + ['after' => ['kind' => $kind, 'record_id' => $rid, 'filename' => $a['filename']]]);
    $pdo->commit();
    return $path;
});
if ($path !== null) {
    attachment_unlink($path);
}
$url = isset(RECORD_KINDS[$kind]) ? RECORD_KINDS[$kind]['url'] . $rid : '/';
crm_done('Deleted the file ' . $a['filename'], $id, crm_land(return_path($url), 'detached', $kind . '-tab-notes'), 'noteChanged');
