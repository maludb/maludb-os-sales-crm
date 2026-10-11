<?php
/**
 * A FAKE MaluMail for the slice 7 proofs — `php -S 127.0.0.1:8294 tests/fake_malumail.php`: /mcp answers the mailbox MCP's list_messages, read_message and mark_message over the
 * messages the proofs put in $FAKE_MALUMAIL_STATE (JSON: {"mailboxes": {"<address>": {"<uid>": {headers, text, html, parts, attachments, flags, refuse_read}}}}); /v1/send logs each
 * body to $FAKE_MALUMAIL_LOG and answers as the address asks ("suppressed" → 400 all rejected, "rejected" → 200 with a rejection, "flaky" → 502, "ratelimited" → 429, "brokenmail" → 500, else 200 accepted with a message_id —
 * the one the body names (`message_id`, ours) when it does). Sales CRM, slice 5: POST /_deliver {address, uid?, headers{}, text, html?, parts?, attachments?, flags?, notify? (default true)} puts a
 * message in the mailbox and, as MaluMail does, posts the SIGNED new-mail notice ({type: message.new, address, folder, uid, message_id, from, subject, …}; X-MaluMail-Signature
 * "t=<unix>,v1=<HMAC-SHA256(secret, t.body)>") to $FAKE_MALUMAIL_WEBHOOK_URL with the secret in $FAKE_MALUMAIL_WEBHOOK_SECRET; answers {uid, webhook: the application's status}. Needs a server
 * with workers (the application reads the message back while this request waits).
 */
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$stateFile = (string) getenv('FAKE_MALUMAIL_STATE');
$state = is_file($stateFile) ? (json_decode((string) file_get_contents($stateFile), true) ?: []) : [];
header('Content-Type: application/json');
if (($_SERVER['HTTP_AUTHORIZATION'] ?? '') !== 'Bearer ' . getenv('MALUMAIL_API_KEY')) { http_response_code(401); echo '{"error":"bad key"}'; exit; }
if ($path === '/v1/send' && ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $in = json_decode((string) file_get_contents('php://input'), true) ?: [];
    $n = substr_count((string) @file_get_contents((string) getenv('FAKE_MALUMAIL_LOG')), "\n") + 1;
    file_put_contents((string) getenv('FAKE_MALUMAIL_LOG'), json_encode($in) . "\n", FILE_APPEND);
    $to = (string) ($in['to'] ?? '');
    if (str_contains($to, 'suppressed')) { http_response_code(400); echo json_encode(['error' => 'No deliverable recipients.', 'rejected' => [['email' => $to, 'reason' => 'suppressed:bounce']]]); exit; }
    if (str_contains($to, 'flaky')) { http_response_code(502); echo '{"error":"relay refused"}'; exit; }
    if (str_contains($to, 'ratelimited')) { http_response_code(429); echo '{"error":"slow down"}'; exit; }          // slice 6: a retryable answer
    if (str_contains($to, 'brokenmail')) { http_response_code(500); echo '{"error":"internal"}'; exit; }          // slice 6: a permanent one
    if (str_contains($to, 'rejected')) { echo json_encode(['status' => 'sent', 'accepted' => [], 'rejected' => [['email' => $to, 'reason' => 'invalid_address']], 'message_id' => null]); exit; }
    echo json_encode(['status' => 'sent', 'accepted' => [$to], 'rejected' => [], 'message_id' => (string) ($in['message_id'] ?? '<mm-' . $n . '@fake.malumail>')]); exit;
}
if ($path === '/_deliver' && ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $in = json_decode((string) file_get_contents('php://input'), true) ?: [];
    $addr = strtolower((string) ($in['address'] ?? ''));
    $box = (array) ($state['mailboxes'][$addr] ?? []);
    $uid = (int) ($in['uid'] ?? 0) ?: (max(array_merge([0], array_map('intval', array_keys($box)))) + 1);
    $state['mailboxes'][$addr][(string) $uid] = ['headers' => (array) ($in['headers'] ?? []), 'text' => (string) ($in['text'] ?? ''), 'html' => $in['html'] ?? null, 'parts' => (array) ($in['parts'] ?? []),
        'attachments' => (array) ($in['attachments'] ?? []), 'flags' => (array) ($in['flags'] ?? [])];
    file_put_contents($stateFile . '.tmp', json_encode($state)); rename($stateFile . '.tmp', $stateFile);
    $status = null;
    if (($in['notify'] ?? true) !== false) {
        $h = array_change_key_case((array) ($in['headers'] ?? []), CASE_LOWER);
        $body = json_encode(['type' => 'message.new', 'event_id' => 'evt-' . $uid, 'address' => $addr, 'folder' => 'INBOX', 'uid' => $uid, 'uid_validity' => 1, 'message_id' => $h['message-id'] ?? null,
            'from' => $h['from'] ?? null, 'subject' => $h['subject'] ?? null, 'date' => $h['date'] ?? null, 'received_at' => gmdate(DATE_ATOM)]);
        $t = (string) ($in['signed_at'] ?? time());
        $sig = 't=' . $t . ',v1=' . hash_hmac('sha256', $t . '.' . $body, (string) ($in['secret'] ?? getenv('FAKE_MALUMAIL_WEBHOOK_SECRET')));
        $ch = curl_init((string) (getenv('FAKE_MALUMAIL_WEBHOOK_URL') ?: 'http://127.0.0.1:8801/channels/malumail/mail'));
        curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => $body, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 30,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'X-MaluMail-Event: message.new', 'X-MaluMail-Signature: ' . $sig]]);
        curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
    }
    echo json_encode(['uid' => $uid, 'webhook' => $status]); exit;
}
if ($path === '/mcp' && ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $in = json_decode((string) file_get_contents('php://input'), true) ?: [];
    $tool = (string) ($in['params']['name'] ?? ''); $args = (array) ($in['params']['arguments'] ?? []);
    $answer = static function (array $result, bool $error = false): never { echo json_encode(['jsonrpc' => '2.0', 'id' => 1, 'result' => ['content' => [['type' => 'text', 'text' => json_encode($result)]], 'isError' => $error]]); exit; };
    $box = (array) ($state['mailboxes'][strtolower((string) ($args['address'] ?? ''))] ?? []);
    file_put_contents((string) getenv('FAKE_MALUMAIL_LOG') . '.mcp', json_encode(['tool' => $tool, 'args' => $args]) . "\n", FILE_APPEND);
    if ($tool === 'list_messages') {
        $out = [];
        foreach ($box as $uid => $m) { if (!empty($args['unread_only']) && in_array('\\Seen', $m['flags'] ?? [], true)) { continue; } $out[] = ['uid' => (int) $uid, 'subject' => $m['headers']['subject'] ?? '', 'from' => $m['headers']['from'] ?? '', 'flags' => $m['flags'] ?? []]; }
        usort($out, static fn ($a, $b) => $a['uid'] <=> $b['uid']);
        $answer(['messages' => array_slice($out, 0, (int) ($args['limit'] ?? 50))]);
    }
    if ($tool === 'read_message') {
        $m = $box[(string) (int) ($args['uid'] ?? 0)] ?? null;
        if ($m === null) { $answer(['error' => 'no such message'], true); }
        if (!empty($m['refuse_read'])) { $answer(['error' => 'the mailbox is locked'], true); }
        $answer(['uid' => (int) $args['uid'], 'headers' => $m['headers'] ?? [], 'text' => $m['text'] ?? '', 'html' => $m['html'] ?? null, 'parts' => $m['parts'] ?? [], 'attachments' => $m['attachments'] ?? [], 'flags' => $m['flags'] ?? []]);
    }
    if ($tool === 'mark_message') {
        $uid = (string) (int) ($args['uid'] ?? 0); $addr = strtolower((string) ($args['address'] ?? ''));
        if (isset($state['mailboxes'][$addr][$uid])) {
            $flags = array_values(array_diff($state['mailboxes'][$addr][$uid]['flags'] ?? [], ['\\Seen']));
            if (!empty($args['seen'])) { $flags[] = '\\Seen'; }
            $state['mailboxes'][$addr][$uid]['flags'] = $flags;
            file_put_contents($stateFile . '.tmp', json_encode($state)); rename($stateFile . '.tmp', $stateFile);
        }
        $answer(['ok' => true]);
    }
    $answer(['error' => 'unknown tool ' . $tool], true);
}
http_response_code(404); echo '{"error":"not found"}';
