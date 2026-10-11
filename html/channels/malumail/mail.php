<?php
declare(strict_types=1);
/**
 * POST /channels/malumail/mail — MaluMail's new-mail notice for the logging mailbox (email.md). PUBLIC, and nothing is acted on until the signature holds: X-MaluMail-Signature
 * "t=<unix>,v1=<hex HMAC-SHA256(secret, t . '.' . body)>", at most five minutes old, with the secret the env key named by mailboxes.webhook_secret_ref holds (the database keeps the
 * NAME of the key, never the secret). The notice carries headers only ({type: message.new, address, folder, uid, message_id, from, subject, …}); the message is read through
 * MaluMail's mailbox MCP and kept RAW as an inbound_email row (inbound.php) — and NOT matched here: the worker's mail_match pass does that. 204 once verified, whatever became of
 * the message (a failed read leaves a `failed` row the poll retries); 403 otherwise, the same for an unknown mailbox, no secret and a bad signature.
 */
require_once dirname(__DIR__, 3) . '/app/bootstrap.php';
require_once dirname(__DIR__, 3) . '/app/features/email/inbound.php';
header_remove('Set-Cookie');                                    // no session for a machine
$done = static function (int $status): never {
    http_response_code($status);
    exit;
};
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') { header('Allow: POST'); $done(405); }
$raw = (string) file_get_contents('php://input', false, null, 0, 65536);
$notice = json_decode($raw, true);
$pdo = db();
$mailbox = is_array($notice) && is_string($notice['address'] ?? null) ? mail_find_mailbox($pdo, $notice['address']) : null;
$secret = $mailbox !== null && (string) $mailbox['webhook_secret_ref'] !== '' ? (string) env((string) $mailbox['webhook_secret_ref'], '') : '';
if ($mailbox === null || $secret === '' || !malumail_signature_valid($secret, (string) ($_SERVER['HTTP_X_MALUMAIL_SIGNATURE'] ?? ''), $raw)) {
    error_log('malumail webhook: refused (no mailbox, no secret, or a bad signature)');
    $done(403);
}
$GLOBALS['__public_door'] = 'webhook';
if (($notice['type'] ?? '') === 'message.new' && ($notice['folder'] ?? 'INBOX') === 'INBOX' && (int) ($notice['uid'] ?? 0) > 0) {
    try {
        mail_inbound_uid($pdo, $mailbox, (int) $notice['uid'], 'webhook');
        $pdo->prepare('UPDATE mailboxes SET last_webhook_at = now() WHERE id = :id')->execute(['id' => $mailbox['id']]);
    } catch (Throwable $e) {
        error_log('malumail webhook: ' . $e->getMessage());       // the worker's poll picks it up
    }
}
$done(204);
