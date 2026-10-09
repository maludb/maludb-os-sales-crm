<?php
declare(strict_types=1);
/** Action `note_delete` (log note.delete; confirm; PAUSES FOR AN AGENT — deletion): the note's author, or a manager. */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_once dirname(__DIR__, 2) . '/app/features/records/queries.php';
require_once dirname(__DIR__, 2) . '/app/features/notes/queries.php';
require_once dirname(__DIR__, 2) . '/app/features/notes/write.php';
crm_handler_begin();
$pdo = db();
$n = ($id = request_integer('note') ?? request_integer('id')) === null ? null : find_note($pdo, $id);
if ($n === null) {
    refuse(404, 'Note not found.');
}
if ((int) $n['member_id'] !== (int) current_member_id() && !is_manager()) {
    refuse(403, 'Only the note\'s author or a manager may delete it.');
}
$kind = (string) $n['record_type'];
$rid = (int) $n['record_id'];
crm_guard($pdo, static function () use ($pdo, $id, $kind, $rid): void {
    $pdo->beginTransaction();
    note_delete($pdo, $id);
    $keys = isset(RECORD_KINDS[$kind]) && in_array($kind, ['lead', 'account', 'contact', 'deal'], true) ? [$kind . '_id' => $rid] : [];
    log_activity($pdo, 'note.delete', 'note', $id, $keys + ['after' => ['kind' => $kind, 'record_id' => $rid]]);
    $pdo->commit();
});
$url = isset(RECORD_KINDS[$kind]) ? RECORD_KINDS[$kind]['url'] . $rid : '/';
crm_done('Deleted the note', $id, crm_land(return_path($url), 'note_deleted', $kind . '-tab-notes'), 'noteChanged');
