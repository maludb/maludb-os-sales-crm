<?php
declare(strict_types=1);
/**
 * Action `note_add` (log note.add with the note's length — never its words): `kind`, `record`, `body` (up to 10000 characters). Anyone who may see
 * the record (records.read + the wall). A note is a touch: the trigger moves the record's (and its account's, its deal's) last touch.
 */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_once dirname(__DIR__, 2) . '/app/features/records/queries.php';
require_once dirname(__DIR__, 2) . '/app/features/notes/write.php';
crm_handler_begin();
require_right('records.read');
$pdo = db();
$kind = record_kind_from_request();
$id = request_integer('record') ?? request_integer('id');
$row = require_record($pdo, $kind, $id);
$errors = [];
$body = crm_text('body', null, 10000, 'The note', $errors, true);
if ($body === null && !isset($errors['body'])) { $errors['body'] = 'Write the note.'; }
if ($errors !== []) {
    crm_refuse_fields($errors);
}
$nid = crm_guard($pdo, static function () use ($pdo, $kind, $id, $row, $body): int {
    $pdo->beginTransaction();
    $nid = note_add($pdo, $kind, $id, $body, (int) current_member_id());
    log_activity($pdo, 'note.add', 'note', $nid, [$kind . '_id' => $id, 'account_id' => $kind === 'account' ? $id : $row['account_id'], 'after' => ['kind' => $kind, 'record_id' => $id, 'length' => mb_strlen($body)]]);
    $pdo->commit();
    return $nid;
});
crm_done('Added a note', $nid, crm_land(return_path(RECORD_KINDS[$kind]['url'] . $id), 'note_added', $kind . '-tab-notes'), 'noteChanged');
