<?php
declare(strict_types=1);

/**
 * The token API (mcp-and-api.md §6; design §4, §8, D13): /api/v1/*.php, a session or a Bearer KEY (scope 'api'). A key is a PERSON's own:
 * it acts as that person and sees exactly what they see (the walls of db/010 judge every row), rate-limited per minute and per day
 * (crm_rate_ok, db/005), logged under the key's label (never the key). One 401 body for every failure. CORS from an allow-list, never with
 * credentials.
 */
require_once dirname(__DIR__) . '/bootstrap.php';

set_exception_handler(static function (Throwable $e): void {
    error_log('api error: ' . $e->getMessage());
    if (!headers_sent()) {
        api_error('server_error', 'Something went wrong.', 500);
    }
    exit;
});

function api_json(array $payload, int $status = 200): never
{
    json_response($payload, $status);
}

function api_error(string $code, string $message, int $status): never
{
    json_error($code, $message, $status);
}

function api_cors(): void
{
    header('Vary: Origin');
    $configured = array_filter(array_map('trim', explode(',', (string) env('API_CORS_ORIGINS', ''))));
    $origin = (string) ($_SERVER['HTTP_ORIGIN'] ?? '');
    if ($origin !== '' && in_array($origin, $configured, true)) {
        header('Access-Control-Allow-Origin: ' . $origin);
        header('Access-Control-Allow-Methods: GET, POST, PUT, OPTIONS');
        header('Access-Control-Allow-Headers: Authorization, Content-Type');
    }
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') {
        http_response_code(204);
        exit;
    }
}

function api_require_get(): void
{
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
        api_error('method_not_allowed', 'Only GET is supported.', 405);
    }
}

function api_require_method(string ...$methods): void
{
    if (!in_array($_SERVER['REQUEST_METHOD'] ?? 'GET', $methods, true)) {
        header('Allow: ' . implode(', ', $methods));
        api_error('method_not_allowed', 'Only ' . implode(' or ', $methods) . ' is supported.', 405);
    }
}

function api_bearer_token(): string
{
    $header = (string) ($_SERVER['HTTP_AUTHORIZATION'] ?? ($_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? ''));
    if ($header === '' && function_exists('getallheaders')) {
        foreach (getallheaders() as $name => $value) {
            if (strcasecmp($name, 'Authorization') === 0) {
                $header = (string) $value;
                break;
            }
        }
    }
    return preg_match('/^Bearer\s+(\S+)$/i', trim($header), $m) ? $m[1] : '';
}

/**
 * The caller as a member row, or 401 — a session first, then a Bearer token. Two kinds of bearer, never confused:
 *   - an `api` KEY resolves through crm_resolve_key(): its facts land in $GLOBALS['__api_key'] (token_id, label, rate_per_minute, rate_per_day),
 *     $GLOBALS['__api_token_id'] stamps every log row, and the caller acts as the key's person — every read and write judged as that person;
 *   - an `mcp` token is a person's read-only token for the MCP servers and is NOT a door to the token API (401 here).
 * Every key's call is counted (api_rate_limit) BEFORE the handler runs: the 61st in a minute is a 429 with Retry-After.
 */
function api_authenticate(): array
{
    $pdo = db();
    if (is_logged_in() && empty($GLOBALS['__action_authed'])) {
        $member = current_member();
        if ($member !== null && $member['status'] === 'active' && $member['capability'] !== null) {
            return $member;
        }
    }
    $token = api_bearer_token();
    if ($token === '') {
        api_error('unauthorized', 'A valid API key is required.', 401);
    }
    $st = $pdo->prepare('SELECT token_id, member_id, label, rate_per_minute, rate_per_day FROM crm_resolve_key(:h)');
    $st->execute(['h' => hash('sha256', $token)]);
    $key = $st->fetch();
    $member = $key === false ? null : find_member_by_id($pdo, (int) $key['member_id']);
    if ($key === false || $member === null) {
        api_error('unauthorized', 'A valid API key is required.', 401);
    }
    $_SESSION['member_id'] = (int) $member['id'];
    $GLOBALS['__api_token_authed'] = true;
    $GLOBALS['__api_token_id'] = (int) $key['token_id'];
    $GLOBALS['__api_key'] = ['token_id' => (int) $key['token_id'], 'label' => (string) $key['label'],
        'rate_per_minute' => (int) $key['rate_per_minute'], 'rate_per_day' => (int) $key['rate_per_day']];
    $GLOBALS['__public_door'] = 'api';
    header_remove('Set-Cookie');
    db_apply_context($pdo);
    api_rate_limit();
    return $member;
}

/** Is this request a key's? (a session caller is not). */
function api_key(): ?array
{
    return $GLOBALS['__api_key'] ?? null;
}

/** Count one call of a key into its minute and day buckets (crm_rate_ok); the 61st in a minute is a 429 with Retry-After. A session caller is not limited. */
function api_rate_limit(): void
{
    $key = api_key();
    if ($key === null) {
        return;
    }
    $st = db()->prepare('SELECT ok, limit_hit, retry_after FROM crm_rate_ok(:t)');
    $st->execute(['t' => $key['token_id']]);
    $r = $st->fetch();
    if ($r === false || !$r['ok']) {
        header('Retry-After: ' . max(1, (int) ($r['retry_after'] ?? 60)));
        api_error('rate_limited', 'Too many calls (' . ($r['limit_hit'] ?? 'limit') . ').', 429);
    }
}
