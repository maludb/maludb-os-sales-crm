<?php
declare(strict_types=1);

/**
 * Inbound mail (email.md; slice 5): MaluMail's new-mail notice (the webhook) and the poll of unread mail both end in mail_inbound_uid() — the message is read through
 * MaluMail's mailbox MCP, kept RAW as an inbound_email row (status received, match_status pending; a delivery report → bounce, an auto-reply → auto_reply, our own
 * address → ignored) with its files in the canonical store, then marked read. Nothing is decided here: the matcher (matcher.php) runs in the worker, never in the
 * webhook's request. A message seen twice is the same row (by its uid, and by its Message-ID — a duplicate is refused). A read that fails leaves a `failed` row
 * with the sentence and the message unread: the next poll tries again. The parsing helpers are Help Desk's (app/features/mail/parse.php), kept name for name.
 * A body, an address's local part beyond the From, or a file's bytes are never written to the activity log: `mail.received` carries ids and counts.
 */
require_once __DIR__ . '/../attachments/store.php';

/** What the person wrote: cut at "On … wrote:", "-----Original Message-----", "From: … Sent: …", a run of quoted lines to the end, or a signature line. */
function mail_strip_quoted(string $text): string
{
    $lines = preg_split('/\r\n|\n/', $text) ?: [];
    $out = [];
    foreach ($lines as $i => $line) {
        $t = trim($line);
        if (preg_match('/^On .{4,200} wrote:$/u', $t) || preg_match('/^-{2,}\s*Original Message\s*-{2,}$/i', $t) || preg_match('/^_{10,}$/', $t) || $t === '-- ') {
            break;
        }
        if (preg_match('/^From: .+/', $t) && isset($lines[$i + 1]) && preg_match('/^(Sent|Date|To): /', trim($lines[$i + 1]))) {
            break;
        }
        if (str_starts_with($t, '>')) {
            $rest = array_slice($lines, $i);
            if (array_filter($rest, static fn (string $l): bool => trim($l) !== '' && !str_starts_with(trim($l), '>')) === []) {
                break;
            }
            continue;
        }
        $out[] = rtrim($line);
    }
    return trim(implode("\n", $out));
}

/** Auto-Submitted other than no, X-Autoreply, Precedence bulk/auto_reply/junk. */
function mail_is_auto(array $headers): bool
{
    $h = array_change_key_case($headers, CASE_LOWER);
    $as = strtolower(trim((string) ($h['auto-submitted'] ?? ($h['auto_submitted'] ?? ''))));
    if ($as !== '' && $as !== 'no') {
        return true;
    }
    if (isset($h['x-autoreply']) || isset($h['x-autorespond'])) {
        return true;
    }
    return in_array(strtolower(trim((string) ($h['precedence'] ?? ''))), ['bulk', 'auto_reply', 'junk', 'list'], true);
}

/** A delivery-status report or a mailer-daemon: the bounced Message-ID when the report names one (else '') — null when it is no bounce. */
function mail_is_bounce(array $headers, array $parts): ?string
{
    $h = array_change_key_case($headers, CASE_LOWER);
    $from = mail_address_of((string) ($h['from'] ?? ''));
    $ct = strtolower((string) ($h['content-type'] ?? ($h['content_type'] ?? '')));
    $isReport = str_contains($ct, 'multipart/report') && str_contains($ct, 'delivery-status');
    $isDaemon = preg_match('/^(mailer-daemon|postmaster)@/i', $from) === 1;
    if (!$isReport && !$isDaemon) {
        return null;
    }
    foreach ($parts as $p) {
        $txt = is_array($p) ? (string) ($p['text'] ?? ($p['body'] ?? '')) : (string) $p;
        if (preg_match('/Original-Message-ID:\s*(<[^>]+>)/i', $txt, $m) || preg_match('/Message-ID:\s*(<[^>]+>)/i', $txt, $m)) {
            return trim($m[1]);
        }
    }
    return '';
}

/** The bare address of a From/To header ("Name <a@b>" → a@b), lower-cased; '' when none. */
function mail_address_of(string $from): string
{
    $addr = preg_match('/<([^<>\s]+@[^<>\s]+)>/', $from, $m) ? $m[1] : trim($from);
    return filter_var($addr, FILTER_VALIDATE_EMAIL) ? strtolower($addr) : '';
}

/** The display name of a From header, else ''. */
function mail_name_of(string $from): string
{
    return preg_match('/^\s*"?([^"<]*?)"?\s*<[^>]+>\s*$/', $from, $m) ? trim($m[1]) : '';
}

/** A list of addresses out of a To/Cc header or array. */
function mail_addresses_of($header): array
{
    $items = is_array($header) ? $header : preg_split('/,(?![^<]*>)/', (string) $header);
    return array_values(array_unique(array_filter(array_map(static fn ($x): string => mail_address_of((string) $x), $items ?: []))));
}

/** A Message-ID with its angle brackets ('' for none). */
function mail_norm_id(string $id): string
{
    $id = trim($id);
    return $id === '' ? '' : (str_starts_with($id, '<') ? $id : '<' . $id . '>');
}

/** The Message-IDs of a References header (or array), each with its brackets. */
function mail_ids_of($refs): array
{
    $refs = is_array($refs) ? $refs : (preg_split('/\s+/', trim((string) $refs)) ?: []);
    return array_values(array_unique(array_filter(array_map(static fn ($r): string => mail_norm_id((string) $r), $refs))));
}

/** A mailbox by the address a notice names (active ones only), with its webhook secret's env key. */
function mail_find_mailbox(PDO $pdo, string $address): ?array
{
    return one_row($pdo, 'SELECT id, address::text AS address, webhook_secret_ref, poll_enabled, last_uid FROM mailboxes WHERE address = :a AND active', ['a' => strtolower(trim($address))]);
}

/** Keep the failure on the mailbox row (the admin's health card) — the sentence, never a body. */
function mail_mailbox_error(PDO $pdo, int $mailboxId, string $why): void
{
    $pdo->prepare('UPDATE mailboxes SET last_error = :e, last_error_at = now() WHERE id = :id')->execute(['e' => mb_substr($why, 0, 300), 'id' => $mailboxId]);
}

/** A message that could not be read: a `failed` row for its uid (found again by the next poll), with the sentence. Answers the row's id. */
function mail_record_failure(PDO $pdo, array $mailbox, int $uid, ?string $messageId, string $why): int
{
    $st = $pdo->prepare("INSERT INTO inbound_email (mailbox_id, provider_uid, message_id, status, error) VALUES (:m, :u, :mid, 'failed', :e)
                         ON CONFLICT (mailbox_id, provider_uid) WHERE provider_uid IS NOT NULL DO UPDATE SET error = EXCLUDED.error WHERE inbound_email.status = 'failed' RETURNING id");
    try {
        $st->execute(['m' => $mailbox['id'], 'u' => (string) $uid, 'mid' => $messageId !== null && $messageId !== '' ? mail_norm_id($messageId) : null, 'e' => mb_substr($why, 0, 500)]);
    } catch (PDOException $e) {
        if ((string) $e->getCode() !== '23505') { throw $e; }
        $st->execute(['m' => $mailbox['id'], 'u' => (string) $uid, 'mid' => null, 'e' => mb_substr($why, 0, 500)]);        // its Message-ID is a row already: keep the uid's alone
    }
    $id = $st->fetchColumn();
    mail_mailbox_error($pdo, (int) $mailbox['id'], $why);
    return $id === false ? (int) one_value($pdo, 'SELECT id FROM inbound_email WHERE mailbox_id = :m AND provider_uid = :u', ['m' => $mailbox['id'], 'u' => (string) $uid]) : (int) $id;
}

/** The files of a read message into the canonical store as the inbound email's own (record type inbound_email); the ones over the ceiling or that run as programs are skipped. Answers how many. */
function mail_store_attachments(PDO $pdo, int $inboundId, array $attachments): int
{
    $n = 0;
    foreach ($attachments as $a) {
        $bytes = isset($a['content_base64']) ? base64_decode((string) $a['content_base64'], true) : false;
        if ($bytes === false || $bytes === '' || strlen($bytes) > attachment_max_bytes($pdo)) {
            continue;
        }
        $tmp = tempnam(sys_get_temp_dir(), 'crmmail');
        file_put_contents($tmp, $bytes);
        try {
            $s = attachment_store($pdo, 'inbound_email', $inboundId, ['tmp' => $tmp, 'name' => (string) ($a['filename'] ?? ($a['name'] ?? 'attachment')), 'size' => strlen($bytes)]);
            $pdo->prepare('INSERT INTO attachments (record_type, record_id, filename, mime_type, byte_size, sha256, storage_path, uploaded_by) VALUES (\'inbound_email\', :r, :f, :m, :n, :s, :p, NULL)')
                ->execute(['r' => $inboundId, 'f' => $s['filename'], 'm' => $s['mime_type'], 'n' => $s['byte_size'], 's' => $s['sha256'], 'p' => $s['storage_path']]);
            $n++;
        } catch (DomainException) {
            // a program, an empty file, over the ceiling: skipped, the message still lands
        } finally {
            @unlink($tmp);
        }
    }
    return $n;
}

/**
 * Bring one message of the logging mailbox in: ['recorded', id] a new row, ['duplicate', id] a message we have (by its uid or its Message-ID — refused),
 * ['failed', id] the read failed. $via is 'webhook' or 'poll' (it stamps the mailbox). Never matches: the worker does.
 */
function mail_inbound_uid(PDO $pdo, array $mailbox, int $uid, string $via = 'poll'): array
{
    $existing = one_row($pdo, 'SELECT id, status FROM inbound_email WHERE mailbox_id = :m AND provider_uid = :u', ['m' => $mailbox['id'], 'u' => (string) $uid]);
    if ($existing !== null && $existing['status'] !== 'failed') {
        mail_mark_read($mailbox, $uid);
        return ['duplicate', (int) $existing['id']];
    }
    try {
        $msg = malumail_mcp_call('read_message', ['address' => (string) $mailbox['address'], 'uid' => $uid, 'mark_seen' => false]);
    } catch (Throwable $e) {
        $id = mail_record_failure($pdo, $mailbox, $uid, null, $e->getMessage());
        log_activity($pdo, 'mail.received', 'inbound_email', $id, ['source' => $GLOBALS['__public_door'] ?? 'cron', 'actor_member_id' => null,
            'after' => ['mailbox_id' => (int) $mailbox['id'], 'uid' => $uid, 'outcome' => 'failed', 'via' => $via]]);
        return ['failed', $id];
    }
    $h = array_change_key_case((array) ($msg['headers'] ?? []), CASE_LOWER);
    $from = mail_address_of((string) ($h['from'] ?? ''));
    $text = (string) ($msg['text'] ?? '');
    $html = isset($msg['html']) && $msg['html'] !== null ? (string) $msg['html'] : null;
    if (trim($text) === '' && $html !== null) {
        $text = trim(html_entity_decode(strip_tags((string) preg_replace('#<(br|/p|/div|/li)[^>]*>#i', "\n", $html)), ENT_QUOTES, 'UTF-8'));
    }
    $bounce = mail_is_bounce($h, (array) ($msg['parts'] ?? []));
    $status = $bounce !== null ? 'bounce' : (mail_is_auto($h) ? 'auto_reply' : (($from === '' || $from === strtolower((string) $mailbox['address'])) ? 'ignored' : 'received'));
    $mid = mail_norm_id((string) ($h['message-id'] ?? ($h['message_id'] ?? '')));
    $row = ['m' => $mailbox['id'], 'uid' => (string) $uid, 'mid' => $mid !== '' ? $mid : null, 'irt' => mail_norm_id((string) ($h['in-reply-to'] ?? ($h['in_reply_to'] ?? ''))) ?: null,
        'refs' => pg_array_literal(mail_ids_of($h['references'] ?? [])), 'fa' => $from !== '' ? $from : null, 'fn' => mail_name_of((string) ($h['from'] ?? '')) ?: null,
        'to' => pg_array_literal(mail_addresses_of($h['to'] ?? '')), 'cc' => pg_array_literal(mail_addresses_of($h['cc'] ?? '')), 's' => mb_substr((string) ($h['subject'] ?? ''), 0, 998),
        'bt' => mb_substr($text, 0, 200000),
        'bh' => $html !== null ? mb_substr(strip_tags($html, '<p><br><b><i><strong><em><ul><ol><li><a><blockquote>'), 0, 200000) : null,
        'h' => json_encode((object) $h, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 'st' => $status,
        'err' => $status === 'ignored' ? ($from === '' ? 'no sender' : 'our own address') : null];
    $cols = 'mailbox_id, provider_uid, message_id, in_reply_to, references_ids, from_address, from_name, to_addresses, cc_addresses, subject, body_text, body_html, headers, status, error';
    $vals = ':m, :uid, :mid, :irt, CAST(:refs AS text[]), :fa, :fn, CAST(:to AS text[]), CAST(:cc AS text[]), :s, :bt, :bh, CAST(:h AS jsonb), :st, :err';
    $pdo->beginTransaction();
    try {
        if ($existing !== null) {                                 // a failed row for this uid: fill it in
            $st = $pdo->prepare("UPDATE inbound_email SET message_id = :mid, in_reply_to = :irt, references_ids = CAST(:refs AS text[]), from_address = :fa, from_name = :fn, to_addresses = CAST(:to AS text[]),
                                        cc_addresses = CAST(:cc AS text[]), subject = :s, body_text = :bt, body_html = :bh, headers = CAST(:h AS jsonb), status = :st, error = :err, match_status = 'pending' WHERE id = :id RETURNING id");
            $st->execute(array_diff_key($row, ['m' => 1, 'uid' => 1]) + ['id' => $existing['id']]);
        } else {
            $st = $pdo->prepare("INSERT INTO inbound_email ($cols) VALUES ($vals) ON CONFLICT DO NOTHING RETURNING id");
            $st->execute($row);
        }
        $id = $st->fetchColumn();
        if ($id === false) {                                      // the Message-ID is a row already (another uid, or the webhook and the poll racing): refused
            $pdo->rollBack();
            $dup = one_value($pdo, 'SELECT id FROM inbound_email WHERE mailbox_id = :m AND (message_id = :mid OR provider_uid = :u) ORDER BY id LIMIT 1', ['m' => $mailbox['id'], 'mid' => $mid, 'u' => (string) $uid]);
            mail_mark_read($mailbox, $uid);
            return ['duplicate', $dup === null ? 0 : (int) $dup];
        }
        $id = (int) $id;
        $files = mail_store_attachments($pdo, $id, (array) ($msg['attachments'] ?? []));
        if ($bounce !== null && $bounce !== '') {                  // the report names a message we sent: that send did not arrive
            $pdo->prepare("UPDATE outbound_email SET status = 'failed', error = 'bounced' WHERE status = 'sent' AND (message_id = :id OR provider_message_id = :id)")->execute(['id' => $bounce]);
        }
        $pdo->prepare($via === 'webhook' ? 'UPDATE mailboxes SET last_webhook_at = now() WHERE id = :id' : 'UPDATE mailboxes SET last_polled_at = now() WHERE id = :id')->execute(['id' => $mailbox['id']]);
        log_activity($pdo, 'mail.received', 'inbound_email', $id, ['source' => $GLOBALS['__public_door'] ?? 'cron', 'actor_member_id' => null,
            'after' => ['mailbox_id' => (int) $mailbox['id'], 'message_id' => $mid !== '' ? $mid : null, 'uid' => $uid, 'status' => $status, 'attachments' => $files, 'via' => $via]]);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) { $pdo->rollBack(); }
        $id = mail_record_failure($pdo, $mailbox, $uid, $mid, $e->getMessage());
        return ['failed', $id];
    }
    mail_mark_read($mailbox, $uid);
    return ['recorded', $id];
}

/** The message read in the mailbox (best effort: a message left unread is found again by the poll and recognised as a row we have). */
function mail_mark_read(array $mailbox, int $uid): void
{
    try {
        malumail_mcp_call('mark_message', ['address' => (string) $mailbox['address'], 'uid' => $uid, 'seen' => true]);
    } catch (Throwable) {
    }
}

/**
 * The poll (the fallback to the webhook): the unread messages of the mailbox, each through mail_inbound_uid(); last_uid and last_polled_at stamped; the mailbox's
 * last_error cleared when nothing failed. Answers ['received' => new rows, 'duplicate' => seen already, 'failed' => unreadable].
 */
function mail_poll_mailbox(PDO $pdo, array $mailbox, int $limit = 50): array
{
    $out = ['received' => 0, 'duplicate' => 0, 'failed' => 0];
    $list = malumail_mcp_call('list_messages', ['address' => (string) $mailbox['address'], 'unread_only' => true, 'limit' => $limit]);
    $uids = array_map(static fn ($m): int => (int) ($m['uid'] ?? 0), (array) ($list['messages'] ?? []));
    sort($uids);
    $max = (int) ($mailbox['last_uid'] ?? 0);
    foreach ($uids as $uid) {
        if ($uid <= 0) { continue; }
        [$what] = mail_inbound_uid($pdo, $mailbox, $uid, 'poll');
        $out[$what === 'recorded' ? 'received' : $what]++;
        if ($what !== 'failed') { $max = max($max, $uid); }
    }
    $st = $pdo->prepare('UPDATE mailboxes SET last_polled_at = now(), last_uid = :u, last_error = CASE WHEN CAST(:clean AS boolean) THEN NULL ELSE last_error END WHERE id = :id');
    $st->bindValue('u', $max > 0 ? $max : null, $max > 0 ? PDO::PARAM_INT : PDO::PARAM_NULL);
    $st->bindValue('clean', $out['failed'] === 0, PDO::PARAM_BOOL);
    $st->bindValue('id', (int) $mailbox['id'], PDO::PARAM_INT);
    $st->execute();
    return $out;
}
