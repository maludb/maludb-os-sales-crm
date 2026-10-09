<?php
declare(strict_types=1);
/**
 * Action `attachment_add` (log attachment.add: the name, the size and the type — never the path): `kind`, `record`, `file` (the bytes, multipart;
 * an agent may send `file_base64` and `filename`). The edit rule on the record. The canonical store judges it (app/features/attachments/store.php):
 * up to the business's ceiling (25 MB), never a program, the type sniffed.
 */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_once dirname(__DIR__, 2) . '/app/features/records/queries.php';
require_once dirname(__DIR__, 2) . '/app/features/attachments/store.php';
require_once dirname(__DIR__, 2) . '/app/features/attachments/write.php';
crm_handler_begin();
$pdo = db();
$kind = record_kind_from_request(['lead', 'account', 'contact', 'deal', 'activity', 'campaign']);
$id = request_integer('record') ?? request_integer('id');
$row = require_record($pdo, $kind, $id);
require_editable($pdo, $kind, $row);
$file = null;
$cleanup = null;
if (isset($_FILES['file']) && is_array($_FILES['file']) && ($_FILES['file']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
    $err = (int) $_FILES['file']['error'];
    if (in_array($err, [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true)) {
        crm_refuse_fields(['file' => 'A file is up to ' . (int) round(attachment_max_bytes($pdo) / 1048576) . ' MB.']);
    }
    if ($err !== UPLOAD_ERR_OK) {
        crm_refuse_fields(['file' => 'The file did not arrive whole; try again.']);
    }
    $file = ['tmp' => (string) $_FILES['file']['tmp_name'], 'name' => (string) $_FILES['file']['name']];
} elseif (req_has('file_base64')) {
    $bytes = base64_decode((string) $_POST['file_base64'], true);
    if ($bytes === false) {
        crm_refuse_fields(['file' => 'file_base64 is not base64.']);
    }
    $cleanup = tempnam(sys_get_temp_dir(), 'crmatt');
    file_put_contents($cleanup, $bytes);
    $file = ['tmp' => $cleanup, 'name' => (string) (req_val('filename') ?? 'file')];
}
if ($file === null) {
    crm_refuse_fields(['file' => 'Choose a file to attach.']);
}
$aid = crm_guard($pdo, static function () use ($pdo, $kind, $id, $row, $file): int {
    $pdo->beginTransaction();
    $aid = attachment_add($pdo, $kind, $id, $file, (int) current_member_id());
    $a = one_value($pdo, 'SELECT json_build_object(\'filename\', filename, \'mime_type\', mime_type, \'byte_size\', byte_size) FROM attachments WHERE id = :id', ['id' => $aid]);
    $keys = in_array($kind, ['lead', 'account', 'contact', 'deal'], true) ? [$kind . '_id' => $id] : [];
    log_activity($pdo, 'attachment.add', 'attachment', $aid, $keys + ['account_id' => $kind === 'account' ? $id : $row['account_id'], 'after' => ['kind' => $kind, 'record_id' => $id] + (json_decode((string) $a, true) ?: [])]);
    $pdo->commit();
    return $aid;
});
if ($cleanup !== null) { @unlink($cleanup); }
crm_done('Attached the file', $aid, crm_land(return_path(RECORD_KINDS[$kind]['url'] . $id), 'attached', $kind . '-tab-notes'), 'noteChanged');
