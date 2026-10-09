<?php
declare(strict_types=1);

/**
 * MaluMail: one outbound send (`/v1/send`, bearer MALUMAIL_API_KEY; MALUMAIL_API_URL points the proofs at a stub), the mailbox MCP (JSON-RPC `tools/call` at
 * MALUMAIL_MCP_URL — list_messages, read_message, mark_message; Sales CRM holds no mailbox password), and the new-mail webhook's signature. The order confirmation and the purchase order's email go through malumail_send().
 */

const MAIL_WEBHOOK_SKEW = 300;                 // a webhook signed longer ago than five minutes is refused

/** Send one email. Answers ['status' => int, 'body' => array, 'message_id' => ?string]; throws on a missing key or a transport error (never on an HTTP status). */
function malumail_send(array $mail): array
{
    $key = (string) env('MALUMAIL_API_KEY', '');
    if ($key === '') {
        throw new RuntimeException('MALUMAIL_API_KEY is not configured.');
    }
    $ch = curl_init(rtrim((string) env('MALUMAIL_API_URL', 'https://api.malumail.com'), '/') . '/v1/send');
    curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 30, CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $key, 'Content-Type: application/json'], CURLOPT_POSTFIELDS => json_encode($mail, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)]);
    $raw = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $errno = curl_errno($ch);
    curl_close($ch);
    if ($errno !== 0 || $raw === false) {
        throw new RuntimeException('MaluMail transport error.');
    }
    $body = json_decode((string) $raw, true);
    $body = is_array($body) ? $body : [];
    return ['status' => $status, 'body' => $body, 'message_id' => isset($body['message_id']) ? (string) $body['message_id'] : null];
}

/** One tool of the mailbox MCP; the mailbox is named by address in $args. 15 s. A failure raises. */
function malumail_mcp_call(string $tool, array $args): array
{
    $key = (string) env('MALUMAIL_API_KEY', '');
    if ($key === '') {
        throw new RuntimeException('MALUMAIL_API_KEY is not configured.');
    }
    $ch = curl_init((string) env('MALUMAIL_MCP_URL', 'https://api.malumail.com/mcp'));
    curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_CONNECTTIMEOUT => 10, CURLOPT_TIMEOUT => 15,
        CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $key, 'Content-Type: application/json', 'Accept: application/json, text/event-stream'],
        CURLOPT_POSTFIELDS => json_encode(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call', 'params' => ['name' => $tool, 'arguments' => (object) $args]], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)]);
    $raw = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    $body = is_string($raw) ? json_decode($raw, true) : null;
    if ($status !== 200 || !is_array($body)) {
        throw new RuntimeException('MaluMail did not answer ' . $tool . ' (HTTP ' . $status . ').');
    }
    if (isset($body['error'])) {
        throw new RuntimeException('MaluMail refused ' . $tool . ': ' . (string) ($body['error']['message'] ?? 'no reason'));
    }
    $text = (string) ($body['result']['content'][0]['text'] ?? '');
    if (!empty($body['result']['isError'])) {
        throw new RuntimeException('MaluMail refused ' . $tool . ': ' . mb_substr($text, 0, 300));
    }
    $out = json_decode($text, true);
    return is_array($out) ? $out : [];
}

/** "t=<unix>,v1=<hex HMAC-SHA256(secret, t . '.' . raw body)>", at most five minutes old, compared in constant time. */
function malumail_signature_valid(string $secret, string $header, string $raw): bool
{
    if (!preg_match('/^t=(\d+),v1=([0-9a-f]{64})$/', trim($header), $m) || abs(time() - (int) $m[1]) > MAIL_WEBHOOK_SKEW) {
        return false;
    }
    return hash_equals(hash_hmac('sha256', $m[1] . '.' . $raw, $secret), $m[2]);
}
