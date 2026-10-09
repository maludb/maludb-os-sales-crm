<?php
/** A FAKE MaluDB API for the ingest proof — `php -S 127.0.0.1:8293 tests/fake_maludb.php` — appends every POST /v1/episodes body to $FAKE_MALUDB_LOG (one JSON per line) and answers 201. */
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
if ($path === '/v1/episodes' && ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    if (($_SERVER['HTTP_AUTHORIZATION'] ?? '') !== 'Bearer ' . getenv('MALUDB_API_TOKEN')) { http_response_code(401); exit; }
    file_put_contents((string) getenv('FAKE_MALUDB_LOG'), file_get_contents('php://input') . "\n", FILE_APPEND);
    http_response_code(201); header('Content-Type: application/json'); echo '{"id":1}'; exit;
}
if ($path === '/v1/send' && ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    // A stub of MaluMail's send API (never a real address; slice 7): logs each body to $FAKE_MALUMAIL_LOG and answers as the address asks —
    // "suppressed" → 400 all rejected, "rejected" → 200 with a rejection, "flaky" → 502, else 200 accepted.
    header('Content-Type: application/json');
    if (($_SERVER['HTTP_AUTHORIZATION'] ?? '') !== 'Bearer ' . getenv('MALUMAIL_API_KEY')) { http_response_code(401); echo '{"error":"bad key"}'; exit; }
    $in = json_decode((string) file_get_contents('php://input'), true) ?: [];
    file_put_contents((string) getenv('FAKE_MALUMAIL_LOG'), json_encode($in) . "\n", FILE_APPEND);
    $to = (string) ($in['to'] ?? '');
    if (str_contains($to, 'suppressed')) { http_response_code(400); echo json_encode(['error' => 'No deliverable recipients.', 'rejected' => [['email' => $to, 'reason' => 'suppressed:bounce']]]); exit; }
    if (str_contains($to, 'flaky')) { http_response_code(502); echo '{"error":"relay refused"}'; exit; }
    if (str_contains($to, 'rejected')) { echo json_encode(['status' => 'sent', 'accepted' => [], 'rejected' => [['email' => $to, 'reason' => 'invalid_address']]]); exit; }
    echo json_encode(['status' => 'sent', 'accepted' => [$to], 'rejected' => []]); exit;
}
http_response_code(404);
