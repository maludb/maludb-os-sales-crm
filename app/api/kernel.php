<?php
declare(strict_types=1);

/**
 * The kernel's services, as Sales CRM calls them (the one transport is kernel_call() in app/auth.php, with the application's
 * token): the chat endpoint (A6 — the command bar's one call and a watch's dispatch to an agent), a text to a member (K6 —
 * Sales CRM never holds a Twilio key), and a sibling's shared tool (K7 — reads only, over a connection a super-admin approved).
 * The directory's calls are in app/directory.php. Nothing here calls a model: the expert answers through the kernel.
 */

/**
 * The command bar's one call: the utterance goes to the kernel's chat endpoint as the acting person; ONE turn of Sales CRM's
 * expert (or the agent named by id) answers. A long run is polled (GET ?run=) until finished or the wait is spent.
 * Answers ['reply', 'actions', 'navigate', 'run_id', 'status', 'finished', 'approval', 'cost', 'http', 'error'] — `error` set when
 * the kernel refused or could not be reached (its own words when it had any), `navigate` a local path the reply asks the screen to open.
 */
function ask_assistant(PDO $pdo, int $memberId, string $utterance, array $context, string $agent = 'expert'): array
{
    $conversation = (string) ($context['conversation_id'] ?? '');
    $answer = kernel_call('POST', '/api/v1/agents/chat.php?agent=' . rawurlencode($agent), [
        'utterance' => $utterance, 'screen' => (string) ($context['screen'] ?? ''),
        'context' => ['entity' => (string) ($context['entity'] ?? ''), 'record_id' => $context['record_id'] ?? null, 'application' => app_key()],
        'conversation_id' => $conversation, 'wait' => 60,
    ], ['X-Acting-Member: ' . $memberId], 75);                     // the kernel holds the request up to `wait`
    $fallback = [
        400 => 'The kernel did not know who was asking.',
        403 => 'You are not allowed to use the assistant here.',
        404 => 'Sales CRM has no expert yet — a super-admin names one in the kernel.',
        409 => 'The expert is busy; try again in a moment.',
        422 => 'Say what you want in a sentence or two.',
    ];
    $out = ['reply' => '', 'actions' => [], 'navigate' => null, 'run_id' => null, 'status' => null, 'finished' => false, 'approval' => null, 'cost' => null, 'http' => $answer['status'] ?? null, 'error' => null];
    if ($answer === null || $answer['status'] === 401 || $answer['status'] >= 500) {
        $out['error'] = 'The kernel is not reachable right now.';
        $out['http'] = $answer === null ? 503 : $answer['status'];
        return $out;
    }
    $body = $answer['body'] ?? [];
    if ($answer['status'] >= 400) {
        $out['error'] = (string) ($body['error']['message'] ?? ($fallback[$answer['status']] ?? 'The assistant could not answer.'));
        return $out;
    }
    $deadline = time() + 55;
    while ($answer['status'] === 202 && empty($body['finished']) && time() < $deadline && !empty($body['run_id'])) {
        usleep(1500000);
        $answer = kernel_call('GET', '/api/v1/agents/chat.php?run=' . (int) $body['run_id'], null, [], 20);
        if ($answer === null || $answer['status'] >= 400) {
            break;
        }
        $body = $answer['body'] ?? [];
    }
    $out['reply'] = (string) ($body['reply'] ?? '');
    $out['actions'] = is_array($body['actions'] ?? null) ? $body['actions'] : [];
    $out['run_id'] = isset($body['run_id']) ? (int) $body['run_id'] : null;
    $out['status'] = isset($body['status']) ? (string) $body['status'] : null;
    $out['finished'] = !empty($body['finished']);
    $out['approval'] = $body['approval_request_id'] ?? null;
    $out['cost'] = $body['cost'] ?? null;
    $out['navigate'] = safe_local_path(is_string($body['navigate'] ?? null) ? $body['navigate'] : null);
    return $out;
}

/** A tool `{entity}_{verb}` that succeeded refreshes the screens listening for `{entity}Changed` (chat-actions, Pattern D). */
function assistant_refresh_events(array $actions): array
{
    $families = ['lead' => 'lead', 'leads' => 'lead', 'account' => 'account', 'contact' => 'contact', 'deal' => 'deal', 'deals' => 'deal', 'product' => 'deal',
        'activity' => 'activity', 'activities' => 'activity', 'participant' => 'activity', 'meeting' => 'activity', 'call' => 'activity', 'feed' => 'activity',
        'email' => 'email', 'template' => 'email', 'campaign' => 'campaign', 'list' => 'list', 'bulk' => 'record', 'record' => 'record', 'records' => 'record',
        'tag' => 'tag', 'note' => 'note', 'attachment' => 'note', 'import' => 'import', 'duplicate' => 'record', 'export' => 'export', 'view' => 'view',
        'pipeline' => 'pipeline', 'stage' => 'pipeline', 'source' => 'settings', 'lost' => 'settings', 'assignment' => 'settings', 'custom' => 'settings',
        'mailbox' => 'settings', 'quota' => 'settings', 'settings' => 'settings', 'prefs' => 'prefs', 'signature' => 'prefs', 'token' => 'token', 'key' => 'token',
        'capture' => 'token', 'notification' => 'notification', 'notifications' => 'notification', 'proposal' => 'proposal', 'next' => 'proposal',
        'agent' => 'agent', 'dispatch' => 'agent', 'sms' => 'activity', 'report' => 'export'];
    $events = [];
    foreach ($actions as $a) {
        if (($a['status'] ?? '') === 'ok' && preg_match('/^([a-z]+)(?:_[a-z_]+)?$/', (string) ($a['tool'] ?? ''), $m) && isset($families[$m[1]])) {
            $events[] = $families[$m[1]] . 'Changed';
        }
    }
    return array_values(array_unique($events));
}

/**
 * K6 — a text to a MEMBER through the kernel (`POST /api/v1/notify/sms.php`, the application's token): their verified phone, a grant
 * here, not opted out, 30 a day — the kernel decides all of it. Answers [outcome, code, provider_ref]: sent | skipped | retry |
 * unconfigured. A customer or a supplier is never texted: they are reached by the order's own email.
 */
function kernel_send_text(int $memberId, string $text, string $reference): array
{
    if ((string) env('OS_APPLICATION_TOKEN', '') === '') {
        return ['unconfigured', 'unconfigured', null];
    }
    $answer = kernel_call('POST', '/api/v1/notify/sms.php', ['member_id' => $memberId, 'text' => mb_substr($text, 0, 480), 'reference' => $reference]);
    if ($answer === null) {
        return ['retry', 'unreachable', null];
    }
    if ($answer['status'] === 202 || $answer['status'] === 200) {
        $kid = $answer['body']['notification']['id'] ?? null;
        return ['sent', null, $kid === null ? null : 'kernel:' . $kid];
    }
    $code = (string) ($answer['body']['error']['code'] ?? ('http_' . $answer['status']));
    if (in_array($answer['status'], [422, 503, 404, 403], true)) {
        return ['skipped', $code, null];
    }
    return ['retry', $code, null];
}

/**
 * K7 — one read of a sibling's shared tool through the kernel (`POST /api/v1/apps/read.php`, the application's token): the kernel calls
 * the provider's tool only over a connection a super-admin approved (403 `no_connection` otherwise); Sales CRM never writes to another
 * application. Answers ['status' => HTTP status (0 = the kernel did not answer), 'result' => the provider's answer | null, 'error' => the
 * kernel's code | null]. Logs `share.read` (direction out): the provider, the tool, the argument KEYS, the outcome and the rows — never
 * the answer; a refusal already logged in the last 24 hours is not logged again. Version 1 declares `reads []` (design §14): this is the
 * client a later slice uses when a read is added.
 */
function kernel_read(PDO $pdo, string $provider, string $tool, array $arguments): array
{
    $answer = kernel_call('POST', '/api/v1/apps/read.php', ['provider' => $provider, 'tool' => $tool, 'arguments' => (object) $arguments], [], 20);
    $status = $answer === null ? 0 : $answer['status'];
    $body = $answer['body'] ?? [];
    $result = $status === 200 ? ($body['result'] ?? []) : null;
    $code = $status === 200 ? null : (string) ($body['error']['code'] ?? ($status === 0 ? 'unreachable' : 'http_' . $status));
    $rows = is_array($result) ? count(kernel_rows($result)) : null;
    $again = $code !== null && (int) one_value($pdo, "SELECT count(*) FROM activity_log WHERE action = 'share.read' AND after->>'direction' = 'out' AND after->>'provider' = :p AND after->>'tool' = :t AND after->>'code' = :c AND occurred_at > now() - interval '24 hours'",
        ['p' => $provider, 't' => $tool, 'c' => $code]) > 0;
    if (!$again) {
        log_activity($pdo, 'share.read', 'share', null, ['actor_member_id' => null, 'after' => ['direction' => 'out', 'provider' => $provider, 'tool' => $tool, 'keys' => array_keys($arguments), 'status' => $status, 'code' => $code, 'rows' => $rows]]);
    }
    return ['status' => $status, 'result' => $result, 'error' => $code];
}

/**
 * A sibling's shared tool as the screens read it: ['status' => ok | no_connection | unavailable, 'rows' => [...]]. SLICE 1 STUB (accounts-contacts.md,
 * divergence 3): it answers `no_connection` without asking the kernel, so the Tickets tab and the ledger panel render their empty state; slice 8
 * (agents-k7-api.md) makes it real — kernel_read() under a five-minute cache, a refusal degrading to no_connection, the records server through the bridge.
 */
function k7_read(PDO $pdo, string $provider, string $tool, array $arguments): array
{
    return ['status' => 'no_connection', 'rows' => []];
}

/** The rows of a provider's answer: the list itself, or its `rows`, `results` or `items`. */
function kernel_rows(?array $result): array
{
    if ($result === null) {
        return [];
    }
    $rows = array_is_list($result) ? $result : ($result['rows'] ?? $result['results'] ?? $result['items'] ?? []);
    return is_array($rows) ? array_values(array_filter($rows, 'is_array')) : [];
}
