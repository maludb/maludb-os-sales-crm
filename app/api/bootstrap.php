<?php
declare(strict_types=1);

/**
 * The token API (mcp-and-api.md §6; design §4, §8, D13): /api/v1/*.php, authenticated ONLY by a Bearer KEY (scope 'api') — no cookie, no session, no CSRF token: this door never reads a cookie
 * and never sets one (CRM_API makes app/bootstrap.php skip the session), so a browser carrying a person's session cannot be made to act through it. A key is a PERSON's own: it acts as that person and
 * sees exactly what they see (the walls of db/010 judge every row), rate-limited per minute and per day (crm_rate_ok, db/005), logged under its token id (never the key). One 401 body for every
 * failure to authenticate. A LEAD-CAPTURE key (lead_source_id set) admits one route only, POST /api/v1/leads, and answers 403 `capture_key` anywhere else. CORS from an allow-list of exact
 * origins, never `*`, never with credentials. Every authenticated call is logged `api.call` ({method, route, status, ms, key label}) — the query string and the body are not.
 */
define('CRM_API', true);
if (!defined('CRM_API_RAW')) {
    $_SERVER['HTTP_ACCEPT'] = 'application/json';       // the API speaks JSON; a route that answers another type (the calendar feed) defines CRM_API_RAW first
}
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
        header('Access-Control-Allow-Methods: GET, POST, PATCH, OPTIONS');
        header('Access-Control-Allow-Headers: Authorization, Content-Type');
        header('Access-Control-Max-Age: 600');                  // an origin from the list, echoed exactly; no Access-Control-Allow-Credentials, ever
    }
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') {
        $GLOBALS['__json_sent'] = true;                          // a preflight has no body: the JSON-mode shutdown must not answer for it
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
 * The caller as a member row, or 401 — a Bearer KEY (scope api) only. Two kinds of bearer, never confused: an `api` key resolves through crm_resolve_key(): its facts land in
 * $GLOBALS['__api_key'] (token_id, label, rate_per_minute, rate_per_day, lead_source_id), `__api_token_id` stamps every log row, and the caller acts as the key's person — every read and write
 * judged as that person; an `mcp` token is a person's read-only token for the MCP servers and is NOT a door here (401). Every key's call is counted (api_rate_limit) BEFORE the handler runs: the 61st
 * in a minute is a 429 with Retry-After. A capture key is refused (403 `capture_key`) unless the route said $captureAllowed. A cookie is never read: the session is not started.
 */
function api_authenticate(bool $captureAllowed = false): array
{
    $pdo = db();
    $token = api_bearer_token();
    if ($token === '' || strlen($token) > 200) {
        header('WWW-Authenticate: Bearer');
        api_error('unauthorized', 'A valid API key is required.', 401);
    }
    $st = $pdo->prepare('SELECT token_id, member_id, label, rate_per_minute, rate_per_day FROM crm_resolve_key(:h)');
    $st->execute(['h' => hash('sha256', $token)]);
    $key = $st->fetch();
    $member = $key === false ? null : find_member_by_id($pdo, (int) $key['member_id']);
    if ($key === false || $member === null) {
        header('WWW-Authenticate: Bearer');
        api_error('unauthorized', 'A valid API key is required.', 401);
    }
    $source = one_value($pdo, 'SELECT lead_source_id FROM mcp_access_tokens WHERE id = :t', ['t' => (int) $key['token_id']]);
    $_SESSION['member_id'] = (int) $member['id'];              // in memory for this request only (CRM_API: there is no session)
    $GLOBALS['__api_token_authed'] = true;
    $GLOBALS['__api_token_id'] = (int) $key['token_id'];
    $GLOBALS['__api_key'] = ['token_id' => (int) $key['token_id'], 'label' => (string) $key['label'], 'rate_per_minute' => (int) $key['rate_per_minute'], 'rate_per_day' => (int) $key['rate_per_day'],
        'lead_source_id' => $source === null ? null : (int) $source];
    $GLOBALS['__public_door'] = 'api';
    db_apply_context($pdo);
    api_log_call_at_end();
    api_rate_limit();
    if ($GLOBALS['__api_key']['lead_source_id'] !== null && !$captureAllowed) {
        api_error('capture_key', 'A lead-capture key may only post leads.', 403);
    }
    return $member;
}

/** One `api.call` row when the request ends: what was called and how it went — the route's shape, not its query or body; never the key. */
function api_log_call_at_end(): void
{
    $t0 = microtime(true);
    register_shutdown_function(static function () use ($t0): void {
        $key = api_key();
        if ($key === null) { return; }
        $route = (string) (parse_url((string) ($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH) ?: '');
        log_activity(db(), 'api.call', 'mcp_access_token', $key['token_id'], ['actor_member_id' => (int) ($_SESSION['member_id'] ?? 0) ?: null, 'source' => 'api', 'token_id' => $key['token_id'],
            'after' => ['method' => (string) ($_SERVER['REQUEST_METHOD'] ?? ''), 'route' => preg_replace('#/\d+#', '/{id}', $route), 'status' => (int) http_response_code(), 'ms' => (int) round((microtime(true) - $t0) * 1000), 'key' => $key['label'],
                        'capture' => $key['lead_source_id'] !== null]]);
    });
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
