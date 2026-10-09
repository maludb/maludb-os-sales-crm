<?php
declare(strict_types=1);
/**
 * The gate: GET /attachments/{id} (?inline=1 shows an image, a PDF or plain text in the browser; everything else — HTML always — is a download),
 * for anyone who may see the record (mcp_attachments: the record's wall; the same 404 otherwise). Logs attachment.download. Not an action.
 */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_once dirname(__DIR__, 2) . '/app/features/attachments/store.php';
require_once dirname(__DIR__, 2) . '/app/features/attachments/queries.php';
require_login();
$pdo = db();
$a = ($id = request_integer('id')) === null ? null : find_attachment($pdo, $id);
if ($a === null) {
    refuse(404, 'File not found.');
}
$kind = (string) $a['record_type'];
$keys = in_array($kind, ['lead', 'account', 'contact', 'deal'], true) ? [$kind . '_id' => (int) $a['record_id']] : [];
log_activity($pdo, 'attachment.download', 'attachment', (int) $a['attachment_id'], $keys + ['after' => ['kind' => $kind, 'record_id' => (int) $a['record_id'], 'byte_size' => (int) $a['byte_size']]]);
session_write_close();
attachment_stream($a, request_bool('inline'));
