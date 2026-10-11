<?php
/**
 * Helpers for the slice 5 proofs (email.md "Proof") — slice 4's helpers (actors, post_as, get_as, worker, pg) and the mail ones: a logging mailbox row, mail DELIVERED through the fake
 * MaluMail (it puts the message in the mailbox and posts the signed new-mail notice to the application, as MaluMail does), the sends the fake received, a contact with an address.
 * Actors as before: 1 the admin, 26 Priya a Rep (priya@example.invalid), 27 Marco the Sales Manager, 29 Ann a Viewer, 30 Dana a Rep, 31 Lee (no role), 40 the Expert (an agent).
 */
require dirname(__DIR__) . '/slice4/lib.php';

const MAILBOX = 'crm-log@example.invalid';
const FAKE = 'http://127.0.0.1:8806';

/** The logging mailbox's row (made once; its secret is the env key's NAME), and the settings pointing at it. Answers the id. */
function ensure_mailbox(): int
{
    $id = one("SELECT id FROM mailboxes WHERE address = :a", ['a' => MAILBOX]);
    if ($id === false) {
        $id = one("INSERT INTO mailboxes (address, display_name, provider, webhook_secret_ref, poll_enabled, active) VALUES (:a, 'Sales CRM log', 'malumail', 'MAILBOX_CRM_WEBHOOK_SECRET', true, true) RETURNING id", ['a' => MAILBOX]);
    }
    pg_run('UPDATE crm_settings SET mailbox_id = ' . (int) $id);
    return (int) $id;
}
/** One request to the fake MaluMail (bearer the key). The decoded answer. */
function fake_post(string $path, array $body): array
{
    $r = req('POST', FAKE . $path, ['headers' => ['Authorization: Bearer ' . need('MALUMAIL_API_KEY'), 'Content-Type: application/json'], 'raw' => json_encode($body)]);
    return (json_decode($r['body'], true) ?: []) + ['_code' => $r['code']];
}
/** The fake's mailbox state: read and change (a message flag, refuse_read …). */
function fake_state(?callable $change = null): array
{
    $f = getenv('CRM_DEV_STATE') . '/malumail.json';
    $s = is_file($f) ? (json_decode((string) file_get_contents($f), true) ?: []) : [];
    if ($change) { $s = $change($s) ?? $s; file_put_contents($f, json_encode($s)); }
    return $s;
}
/** A message as the fake takes it: headers (a Message-ID of its own unless given), text, and anything else ($extra: html, attachments, flags, notify, uid, secret, signed_at, parts). */
function msg(string $from, array $to, string $subject, string $text, array $extra = [], array $headers = []): array
{
    $mid = $headers['message-id'] ?? '<' . bin2hex(random_bytes(6)) . '@mail.example.invalid>';
    return $extra + ['address' => MAILBOX, 'text' => $text, 'headers' => $headers + ['from' => $from, 'to' => implode(', ', $to), 'subject' => $subject, 'message-id' => $mid, 'date' => gmdate('r')]];
}
/** Deliver a message: the fake stores it and posts the signed notice to the application. Answers {uid, webhook (the application's status)}. */
function deliver(array $m): array
{
    return fake_post('/_deliver', $m);
}
/** Every send the fake received (the bodies of /v1/send), oldest first. */
function sends(): array
{
    $f = getenv('CRM_DEV_STATE') . '/malumail.log';
    return array_values(array_map(static fn ($l) => json_decode($l, true), array_filter(explode("\n", is_file($f) ? (string) file_get_contents($f) : ''))));
}
function last_send(): ?array { $s = sends(); return $s === [] ? null : end($s); }
/** A contact with an address, made as Priya (or $m) on an account; the id. */
function contact_with(string $email, string $first = 'Pat', ?int $account = null, int $m = 26, array $more = []): int
{
    $f = ['first_name' => $first, 'last_name' => 'Mail ' . run_tag(), 'email' => $email] + $more;
    if ($account !== null) { $f['account'] = $account; }
    $r = post_as($m, '/contacts/save.php', $f);
    if (($r['json']['record_id'] ?? null) === null) { fwrite(STDERR, 'contact_with failed: ' . $r['body'] . "\n"); }
    return (int) ($r['json']['record_id'] ?? 0);
}
/** An inbound email row by its Message-ID. */
function inbound(string $messageId): array
{
    return q('SELECT * FROM inbound_email WHERE message_id = :m', ['m' => $messageId])[0] ?? [];
}
/** The activities of an inbound or outbound email. */
function email_acts(string $col, int $id): array
{
    return q("SELECT * FROM activities WHERE $col = :id ORDER BY id", ['id' => $id]);
}
/** A signed webhook notice posted to the application by hand (for the refusals). $o: secret, t (the signed time), body. */
function notice(array $payload, array $o = []): array
{
    $body = $o['body'] ?? json_encode($payload);
    $t = (string) ($o['t'] ?? time());
    $sig = $o['sig'] ?? 't=' . $t . ',v1=' . hash_hmac('sha256', $t . '.' . $body, $o['secret'] ?? need('MAILBOX_CRM_WEBHOOK_SECRET'));
    return req('POST', '/channels/malumail/mail', ['headers' => ['Content-Type: application/json', 'X-MaluMail-Signature: ' . $sig], 'raw' => $body]);
}
/** "{1,2,3}" from a bigint[] column → [1, 2, 3]. */
function pg_ints(?string $t): array
{
    $t = trim((string) $t, '{}');
    return $t === '' ? [] : array_map('intval', explode(',', $t));
}
