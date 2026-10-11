<?php
declare(strict_types=1);

/**
 * HTTP and rendering helpers — the kernel's contracts (php-patterns), kept name for name so the
 * kernel's actions server and approval replay read Sales CRM's handlers exactly as they read its own:
 * emit_action_status() + json_mode_finish() answer JSON; a browser gets HTML; HTMX gets a partial.
 */

function is_htmx_request(): bool
{
    return ($_SERVER['HTTP_HX_REQUEST'] ?? '') === 'true';
}

function is_htmx_boosted(): bool
{
    return ($_SERVER['HTTP_HX_BOOSTED'] ?? '') === 'true';
}

/** True when the caller asked for JSON (the kernel's actions server always does; a browser never). */
function wants_json(): bool
{
    static $wants = null;
    if ($wants === null) {
        $accept = strtolower((string) ($_SERVER['HTTP_ACCEPT'] ?? ''));
        $wants = !is_htmx_request() && str_starts_with(ltrim($accept), 'application/json');
    }
    return $wants;
}

function json_response(array $payload, int $status = 200): never
{
    $GLOBALS['__json_sent'] = true;
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

function json_error(string $code, string $message, int $status, array $extra = []): never
{
    json_response(['error' => ['code' => $code, 'message' => $message] + $extra], $status);
}

function respond_screen(array $payload): never
{
    json_response(['data' => $payload]);
}

function respond_saved(array $data = []): never
{
    json_response(['ok' => true] + $data);
}

function respond_invalid(array $errors, array $fields = []): never
{
    json_error('invalid', (string) ($errors[0] ?? 'That could not be saved.'), 422,
        ['errors' => array_values($errors), 'fields' => (object) $fields]);
}

function json_ts(?string $utc): ?string
{
    if ($utc === null || $utc === '') {
        return null;
    }
    try {
        return (new DateTimeImmutable($utc, new DateTimeZone('UTC')))->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z');
    } catch (Exception) {
        return null;
    }
}

function respond_not_found(string $message = 'Not found.'): never
{
    if (wants_json()) {
        json_error('not_found', $message, 404);
    }
    http_response_code(404);
    echo view('shared/message.php', ['title' => 'Not found', 'message' => $message]);
    exit;
}

// --------------------------------------------------------------------------
// JSON mode: buffer the handler and answer from what it REPORTED (the kernel's adapter, verbatim).
// --------------------------------------------------------------------------
function json_mode_begin(): void
{
    ob_start();
    register_shutdown_function('json_mode_finish');
}

function json_mode_finish(): void
{
    if (!empty($GLOBALS['__json_sent'])) {
        return;
    }
    $body = '';
    while (ob_get_level() > 0) {
        $body = (string) ob_get_clean() . $body;
    }
    $status = (int) http_response_code();
    $reported = $GLOBALS['__action_status'] ?? null;

    $location = null;
    foreach (headers_list() as $line) {
        [$name, $value] = array_map('trim', explode(':', $line, 2) + [1 => '']);
        $name = strtolower($name);
        if ($name === 'hx-push-url' || $name === 'hx-redirect' || $name === 'location') {
            $location = $value;
        } elseif ($name === 'hx-location') {
            $location = json_decode($value, true)['path'] ?? $location;
        }
        if (str_starts_with($name, 'hx-') || $name === 'location') {
            header_remove($name);
        }
    }

    $send = static function (array $payload, int $code): void {
        http_response_code($code);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    };
    $error = static fn (string $code, string $message, array $extra = []): array
        => ['error' => ['code' => $code, 'message' => $message] + $extra];

    if ($status >= 500) {
        $send($error('server_error', 'Something went wrong.'), $status);
        return;
    }
    if ($status >= 400) {
        $codes = [400 => 'bad_request', 401 => 'unauthorized', 403 => 'forbidden', 404 => 'not_found',
                  405 => 'method_not_allowed', 409 => 'conflict', 422 => 'invalid', 429 => 'rate_limited'];
        $words = trim(preg_replace('/\s+/', ' ', html_entity_decode(strip_tags($body), ENT_QUOTES, 'UTF-8')) ?? '');
        if ($words === '' || mb_strlen($words) > 400) {
            $words = (string) ($reported['data']['error'] ?? ($reported['data']['errors'][0] ?? 'That could not be done.'));
        }
        $send($error($codes[$status] ?? 'refused', $words), $status);
        return;
    }
    if ($reported !== null && ($reported['data']['status'] ?? '') === 'pending_approval') {
        $send(['ok' => false, 'status' => 'pending_approval',
               'message' => (string) ($reported['data']['did'] ?? 'This waits for approval.'),
               'approval_request_id' => $reported['data']['approval_request_id'] ?? null], 202);
        return;
    }
    if ($reported !== null && $reported['ok']) {
        $send(['ok' => true] + $reported['data'] + ($location !== null ? ['location' => $location] : []), 200);
        return;
    }
    if ($reported !== null) {
        $errors = $reported['data']['errors'] ?? [(string) ($reported['data']['error'] ?? 'That could not be saved.')];
        $send($error('invalid', (string) ($errors[0] ?? 'That could not be saved.'),
            ['errors' => array_values((array) $errors), 'fields' => (object) []]), 422);
        return;
    }
    if ($location !== null && $body === '') {
        $send(['ok' => true, 'location' => $location], 200);
        return;
    }
    $send($error('not_converted', 'This screen does not answer JSON yet.'), 501);
}

/** A path a page may send a person back to (return_to, ?back=, the kernel's navigate): local, no scheme, no protocol-relative — else null. */
function safe_local_path(?string $path): ?string
{
    if ($path === null || $path === '' || $path[0] !== '/' || str_starts_with($path, '//') || str_contains($path, '\\') || preg_match('/[\x00-\x1f]/', $path)) {
        return null;
    }
    return $path;
}

function client_ip(): string
{
    return (string) ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
}

/** Escape a value for HTML. Use on EVERY dynamic value. */
function e(mixed $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/** Render a template under app/views; names come from code, never from the request. */
function view(string $template, array $data = []): string
{
    $viewsRoot = realpath(__DIR__ . '/views');
    if ($viewsRoot === false) {
        throw new RuntimeException('View directory does not exist.');
    }
    $path = realpath($viewsRoot . '/' . ltrim($template, '/'));
    if ($path === false || !str_starts_with($path, $viewsRoot . DIRECTORY_SEPARATOR)) {
        throw new RuntimeException("Invalid view: {$template}");
    }
    extract($data, EXTR_SKIP);
    ob_start();
    try {
        require $path;
        return (string) ob_get_clean();
    } catch (Throwable $exception) {
        ob_end_clean();
        throw $exception;
    }
}

/**
 * Render a screen: the partial alone for an HTMX request (Pattern B), the shell around it for a
 * navigation. $layout: activeNav, screen, entity, recordId.
 */
function render_screen(string $title, string $pageHtml, array $layout = []): void
{
    if (wants_json()) {
        json_error('not_converted', 'This screen does not answer JSON.', 501);
    }
    if (is_htmx_request() && !is_htmx_boosted()) {
        header('Vary: HX-Request');
        header('HX-Title: ' . rawurlencode($title . ' · ' . app_name()));
        // What #page-content now shows — the shell re-stamps data-screen/-entity/-record-id and the menu highlight from these,
        // so the command bar's context and the active tab follow HTMX navigation (a partial cannot set attributes of its parent).
        header('X-Screen: ' . ($layout['screen'] ?? ''));
        header('X-Entity: ' . ($layout['entity'] ?? ''));
        header('X-Record-Id: ' . ($layout['recordId'] ?? ''));
        echo $pageHtml;
        return;
    }
    echo view('layout.php', array_merge(['title' => $title, 'content' => $pageHtml], $layout));
}

function require_post(): void
{
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
        header('Allow: POST');
        if (wants_json()) {
            json_error('method_not_allowed', 'Only POST is supported.', 405);
        }
        http_response_code(405);
        exit('Method Not Allowed');
    }
}

function request_integer(string $name): ?int
{
    $value = $_POST[$name] ?? $_GET[$name] ?? null;
    if ($value === null || $value === '') {
        return null;
    }
    $filtered = filter_var($value, FILTER_VALIDATE_INT);
    return $filtered === false ? null : $filtered;
}

function request_string(string $name, string $default = ''): string
{
    return trim((string) ($_POST[$name] ?? $_GET[$name] ?? $default));
}

function request_bool(string $name): bool
{
    $v = $_POST[$name] ?? $_GET[$name] ?? null;
    return in_array((string) $v, ['1', 'true', 'on', 'yes'], true);
}

/** A date input (YYYY-MM-DD) or null; '' when absent, false when malformed. */
function request_date(string $name): string|false|null
{
    $v = request_string($name);
    if ($v === '') {
        return null;
    }
    $d = DateTimeImmutable::createFromFormat('!Y-m-d', $v);
    return $d !== false && $d->format('Y-m-d') === $v ? $v : false;
}

function format_ts(?string $utc, string $tz, string $fmt = 'M j, Y g:i A'): string
{
    if ($utc === null || $utc === '') {
        return '';
    }
    try {
        $dt = new DateTime($utc, new DateTimeZone('UTC'));
        $dt->setTimezone(new DateTimeZone($tz !== '' ? $tz : 'UTC'));
        return $dt->format($fmt);
    } catch (Exception) {
        return $utc;
    }
}

/** A count of days for a screen: 4, 0.5, 16 — never 16.00. */
function days_label($days): string
{
    return rtrim(rtrim(number_format((float) $days, 2, '.', ''), '0'), '.');
}

/** A date column in the medium format the design system fixes: Jan 15, 2026. */
function format_date(?string $date): string
{
    if ($date === null || $date === '') {
        return '';
    }
    try {
        return (new DateTimeImmutable($date))->format('M j, Y');
    } catch (Exception) {
        return $date;
    }
}

function csrf_token(): string
{
    return (string) ($_SESSION['csrf_token'] ?? '');
}

function csrf_field(): string
{
    return '<input type="hidden" name="csrf_token" value="' . e(csrf_token()) . '">';
}

function verify_csrf(): void
{
    if (!in_array($_SERVER['REQUEST_METHOD'] ?? 'GET', ['POST', 'PUT', 'PATCH', 'DELETE'], true)) {
        return;
    }
    if (is_action_authed() || !empty($GLOBALS['__api_token_authed'])) {
        return;                     // the signed action token, or an API key in the Authorization header (no cookie ever carries it), is the CSRF protection
    }
    $sent = $_POST['csrf_token'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
    if (!hash_equals(csrf_token(), (string) $sent)) {
        if (wants_json()) {
            json_error('csrf_failed', 'CSRF validation failed.', 403);
        }
        http_response_code(403);
        exit('CSRF validation failed.');
    }
}

/** What the handler reports; JSON mode answers from it. The first failure stands. */
function emit_action_status(bool $ok, array $data = []): void
{
    if (!isset($GLOBALS['__action_status']) || $GLOBALS['__action_status']['ok']) {
        $GLOBALS['__action_status'] = ['ok' => $ok, 'data' => $data];
    }
    if (is_action_authed() && !headers_sent()) {
        header('X-Action-Status: ' . ($ok ? 'ok' : 'error'));
        if ($data !== []) {
            header('X-Action-Data: ' . json_encode($data, JSON_UNESCAPED_SLASHES));
        }
    }
}

/** A refusal in the handler's own words: 4xx + the sentence (HTML or JSON alike). */
function refuse(int $status, string $message): never
{
    emit_action_status(false, ['error' => $message]);
    if (wants_json()) {
        $codes = [400 => 'bad_request', 401 => 'unauthorized', 403 => 'forbidden', 404 => 'not_found', 409 => 'conflict', 422 => 'invalid', 503 => 'unavailable'];
        json_error($codes[$status] ?? 'refused', $message, $status);
    }
    http_response_code($status);
    if (is_htmx_request()) {
        header('HX-Retarget: #flash');
        header('HX-Reswap: innerHTML');
        echo view('shared/flash.php', ['kind' => 'danger', 'message' => $message]);
        exit;
    }
    echo view('shared/message.php', ['title' => 'Refused', 'message' => $message]);
    exit;
}

function redirect(string $path): never
{
    if (wants_json()) {
        respond_saved(['location' => $path]);
    }
    if (is_htmx_request()) {
        header('HX-Redirect: ' . $path);
    } else {
        header('Location: ' . $path);
    }
    http_response_code(is_htmx_request() ? 200 : 302);
    exit;
}

function hx_location(string $path, string $target = '#page-content'): void
{
    header('HX-Location: ' . json_encode(['path' => $path, 'target' => $target], JSON_THROW_ON_ERROR));
}

function hx_trigger(string $event): void
{
    header('HX-Trigger: ' . $event);
}

/** A saved form: HTMX goes to the record, JSON learns the location, a browser is redirected. */
function saved_go(string $path, string $event = ''): never
{
    if ($event !== '') {
        hx_trigger($event);
    }
    if (wants_json()) {
        respond_saved(['location' => $path] + ($GLOBALS['__action_status']['data'] ?? []));
    }
    if (is_htmx_request()) {
        hx_location($path);
        header('HX-Push-Url: ' . $path);
        exit;
    }
    header('Location: ' . $path, true, 302);
    exit;
}
