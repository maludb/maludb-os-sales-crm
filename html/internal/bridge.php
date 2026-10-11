<?php
declare(strict_types=1);
/**
 * POST /internal/bridge.php — the records server's door to what only PHP can do (tool surface "The bridge"; agents-k7-api.md "The bridge"). LOOPBACK ONLY (REMOTE_ADDR, never a forwarded header) and signed:
 * `X-CRM-Bridge: {unix ts}.{hex hmac_sha256(ACTIONS_RELAY_KEY, "crm-bridge:" + ts + "." + sha256(body))}` within 30 seconds, compared in constant time. The public vhost answers 404 under /internal/ and the Apache
 * rules of the internal port reach it. No session, no cookie. Ops (any other is a 404): the five shares — `consumer` REQUIRED, the X-OS-Consumer the kernel set (K26); without it nothing is read and nothing is
 * shared (403) —, `k7_read` (the account's tickets or ledger customer, as the member the server vouches for, the wall judged first) and `notify` (an AGENT's notice to a person, by the same rules as the
 * action; never under an evaluation run). Answers JSON always: {ok: true, …} or {ok: false, error: {code, message}}.
 */
define('CRM_API', true);                                  // no session: the bridge's identity is the signature and the body's caller, for this request alone
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_once dirname(__DIR__, 2) . '/app/features/shares/queries.php';
require_once dirname(__DIR__, 2) . '/app/features/records/queries.php';
require_once dirname(__DIR__, 2) . '/app/features/notify/write.php';
header_remove('Set-Cookie');
$fail = static function (string $code, string $message, int $status): never {
    header('Cache-Control: no-store');
    json_response(['ok' => false, 'error' => ['code' => $code, 'message' => $message]], $status);
};
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    header('Allow: POST');
    $fail('method_not_allowed', 'POST only.', 405);
}
$raw = (string) file_get_contents('php://input', false, null, 0, 262145);
if (strlen($raw) > 262144) {
    $fail('invalid_argument', 'The body is up to 256 kB.', 413);
}
if (bridge_verify($raw, (string) ($_SERVER['HTTP_X_CRM_BRIDGE'] ?? ''), (string) ($_SERVER['REMOTE_ADDR'] ?? ''), time()) !== null) {
    $fail('unauthorized', 'Not a call the bridge accepts.', 401);
}
$body = json_decode($raw, true);
if (!is_array($body)) {
    $fail('invalid_argument', 'The body is JSON.', 422);
}
$op = (string) ($body['op'] ?? '');
if (!in_array($op, BRIDGE_OPS, true)) {
    $fail('no_such_op', 'The bridge answers ' . implode(', ', BRIDGE_OPS) . ' only.', 404);
}
$args = is_array($body['args'] ?? null) ? $body['args'] : [];
$pdo = db();
if (is_string($body['request_id'] ?? null) && preg_match('/^[A-Za-z0-9._:-]{4,80}$/', $body['request_id'])) {
    $_SERVER['HTTP_X_REQUEST_ID'] = $body['request_id'];
}
try {
    if (str_starts_with($op, 'share_')) {
        $consumer = share_consumer($body['consumer'] ?? null);
        if ($consumer === null) {
            $fail('consumer_required', 'A share is read on behalf of an application: X-OS-Consumer names it, and none came.', 403);
        }
        $agent = $body['consumer_agent'] ?? null;
        $GLOBALS['__share_consumer_agent'] = is_string($agent) && preg_match('/^[A-Za-z0-9 ._:@-]{1,64}$/', $agent) ? $agent : null;
        $GLOBALS['__public_door'] = 'application';
        $doc = ('share_' . substr($op, 6))($consumer, $args);
        header('Cache-Control: no-store');
        json_response(['ok' => true, 'result' => $doc]);
    }
    $member = bridge_caller($pdo, $body);
    if ($member === null) {
        $fail('unknown_member', 'That caller is not a member admitted here.', 403);
    }
    $_SESSION['member_id'] = $member;
    db_apply_context($pdo);
    $door = ($body['door'] ?? '') === 'agent' ? 'agent' : 'mcp';
    $GLOBALS['__public_door'] = $door;
    if ($door === 'agent' && is_int($body['run_id'] ?? null)) { $GLOBALS['__agent_run_id'] = $body['run_id']; }
    if ($op === 'k7_read') {
        if (!has_right('records.read')) {
            $fail('insufficient_right', 'Reading this needs the right to ' . RIGHT_WORDS['records.read'] . '.', 403);
        }
        header('Cache-Control: no-store');
        json_response(['ok' => true] + bridge_k7_read($pdo, $args));
    }
    // notify: an agent's notice to a person — never a person's (the action is theirs), never under an evaluation run (it writes nothing)
    if (!empty($body['is_eval'])) {
        $fail('eval_no_write', 'An evaluation run writes nothing.', 403);
    }
    if ((current_member()['member_kind'] ?? '') !== 'agent') {
        $fail('agents_only', 'The bridge sends an agent\'s notice; a person uses the action.', 403);
    }
    $to = isset($args['member']) && filter_var($args['member'], FILTER_VALIDATE_INT) !== false ? (int) $args['member'] : 0;
    if (($deny = notify_authorize($pdo, $to)) !== null) {
        $fail($deny[0] === 404 ? 'not_found' : 'forbidden', $deny[1], $deny[0]);
    }
    $links = [];
    foreach (['deal', 'contact', 'activity'] as $l) { $links[$l] = isset($args[$l]) && filter_var($args[$l], FILTER_VALIDATE_INT) !== false ? (int) $args[$l] : null; }
    $pdo->beginTransaction();
    $nid = notify($pdo, $to, (string) ($args['kind'] ?? ''), (string) ($args['title'] ?? ''), isset($args['body']) ? (string) $args['body'] : null, $links);
    log_activity($pdo, 'notification.send', 'notification', $nid, array_filter(['deal_id' => $links['deal'], 'contact_id' => $links['contact']], static fn ($v) => $v !== null)
        + ['after' => ['member_id' => $to, 'kind' => $args['kind'] ?? null, 'deal_id' => $links['deal'], 'contact_id' => $links['contact'], 'activity_id' => $links['activity'], 'has_body' => isset($args['body'])]]);
    $pdo->commit();
    json_response(['ok' => true, 'notification_id' => $nid]);
} catch (InvalidArgumentException | DomainException $e) {
    if ($pdo->inTransaction()) { $pdo->rollBack(); }
    $fail($e->getMessage() === 'consumer_required' ? 'consumer_required' : 'invalid_argument', $e->getMessage() === 'Not found.' ? 'Not found.' : $e->getMessage(), $e->getMessage() === 'Not found.' ? 404 : 422);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) { $pdo->rollBack(); }
    error_log('bridge ' . $op . ': ' . $e->getMessage());
    $fail('server_error', 'The bridge could not do that.', 500);
}
