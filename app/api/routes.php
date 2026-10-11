<?php
declare(strict_types=1);

/**
 * The records API's routes (agents-k7-api.md "The API"; tool surface "The records API"): GET /api/v1/{leads|accounts|contacts|deals|activities}, GET /api/v1/{kind}/{id}, POST /api/v1/{kind},
 * PATCH /api/v1/{kind}/{id}. EVERY ROUTE IS THE SCREEN OR THE ACTION OF THE SAME NAME, run as the key's person: the list and the one record are the JSON the screens answer (the find_* filters
 * are the screens' query parameters, the answers their whitelist presenters), a create and a change are the `*_create` / `*_update` handlers (html/{kind}/save.php) with the JSON body as their form — so
 * the rights, the walls, the schema's refusals and the activity log are the ones the browser meets, and nothing the API does is a second rule to keep in step. An agent's key is refused a write (an agent acts
 * through the tools, where the approval pause lives). A LEAD-CAPTURE key never reaches those: it posts a lead through api_capture_lead() and nothing else.
 */

const API_KINDS = ['leads' => 'lead', 'accounts' => 'account', 'contacts' => 'contact', 'deals' => 'deal', 'activities' => 'activity'];
const API_MAX_BODY = 1048576;
const API_CAPTURE_MAX_BODY = 32768;
/** The capture route's fields and their ceilings (leads-and-conversion.md §6). */
const API_CAPTURE_FIELDS = ['first_name' => 100, 'last_name' => 100, 'email' => 254, 'company' => 200, 'phone' => 40, 'message' => 2000,
    'utm_source' => 200, 'utm_medium' => 200, 'utm_campaign' => 200, 'utm_term' => 200, 'utm_content' => 200];
const API_HONEYPOT = 'website_url';

/** The request body as an array: JSON (Content-Type application/json) or a urlencoded form; refused when larger than $max (413) or not an object (400). */
function api_read_body(int $max = API_MAX_BODY): array
{
    $length = (int) ($_SERVER['CONTENT_LENGTH'] ?? 0);
    if ($length > $max) {
        api_error('payload_too_large', 'The body is larger than ' . intdiv($max, 1024) . ' kB.', 413);
    }
    $raw = (string) file_get_contents('php://input', false, null, 0, $max + 1);
    if (strlen($raw) > $max) {
        api_error('payload_too_large', 'The body is larger than ' . intdiv($max, 1024) . ' kB.', 413);
    }
    if ($raw === '') {
        return [];
    }
    if (str_contains(strtolower((string) ($_SERVER['CONTENT_TYPE'] ?? '')), 'json')) {
        $body = json_decode($raw, true);
        if (!is_array($body) || ($body !== [] && array_is_list($body))) {
            api_error('bad_request', 'The body is a JSON object.', 400);
        }
        return $body;
    }
    parse_str($raw, $form);
    return is_array($form) ? $form : [];
}

/** A JSON body as the form the handlers read: a scalar becomes its string (true 1, false 0, null empty — a cleared field), a list or object is left as it is. */
function api_as_form(array $body): array
{
    $out = [];
    foreach ($body as $k => $v) {
        $k = (string) $k;
        if (in_array($k, ['_partial', 'return_to', 'csrf_token'], true)) { continue; }
        $out[$k] = match (true) { is_bool($v) => $v ? '1' : '0', $v === null => '', is_int($v), is_float($v) => (string) $v, default => $v };
    }
    return $out;
}

/** Run a controller file as the request (it answers and exits). */
function api_run(string $file): never
{
    require dirname(__DIR__, 2) . '/html/' . $file;
    exit;
}

/**
 * One route of the records API: $kind is the plural segment, the record id (when the path had one) arrives as ?id= from the vhost's rewrite.
 */
function api_route(string $kind): never
{
    api_cors();
    $method = (string) ($_SERVER['REQUEST_METHOD'] ?? 'GET');
    if (!in_array($method, ['GET', 'POST', 'PATCH'], true)) {
        header('Allow: GET, POST, PATCH, OPTIONS');
        api_error('method_not_allowed', 'GET, POST or PATCH.', 405);
    }
    $rawId = $_GET['id'] ?? null;
    $id = $rawId === null || $rawId === '' ? null : (filter_var($rawId, FILTER_VALIDATE_INT) !== false && (int) $rawId > 0 ? (int) $rawId : 0);
    if ($id === 0) {
        api_error('not_found', 'Not found.', 404);
    }
    if (($method === 'PATCH' && $id === null) || ($method === 'POST' && $id !== null)) {
        header('Allow: ' . ($id === null ? 'GET, POST' : 'GET, PATCH'));
        api_error('method_not_allowed', $id === null ? 'Create with POST /api/v1/' . $kind . '; change one with PATCH /api/v1/' . $kind . '/{id}.' : 'Change a record with PATCH /api/v1/' . $kind . '/' . $id . '.', 405);
    }
    $capture = $kind === 'leads' && $method === 'POST';
    $member = api_authenticate($capture);
    $key = api_key();
    if ($key !== null && $key['lead_source_id'] !== null) {
        api_capture_lead(db(), $key, api_read_body(API_CAPTURE_MAX_BODY));
    }
    $noun = API_KINDS[$kind];
    unset($_GET['id']);
    if ($method === 'GET') {
        if ($id === null) {
            api_run($kind . '/index.php');
        }
        $_GET['id'] = (string) $id;
        api_run($kind . '/view.php');
    }
    if (($member['member_kind'] ?? '') === 'agent') {
        api_error('forbidden', 'An agent acts through the tools, not the API.', 403);
    }
    $form = api_as_form(api_read_body());
    foreach ([$noun, 'id'] as $reserved) { unset($form[$reserved]); }        // the path names the record, never the body
    if ($id !== null) {
        $form[$noun] = (string) $id;
    }
    $_POST = $form;
    $_SERVER['REQUEST_METHOD'] = 'POST';                                       // the handler is the action: a POST
    api_run($kind . '/save.php');
}

/**
 * POST /api/v1/leads with a LEAD-CAPTURE key (the website's form): the fields of API_CAPTURE_FIELDS, the source the KEY's (never the body's), the owner the assignment rules'. The honeypot `website_url`: filled, and the
 * answer is success and NOTHING is made (a bot learns nothing). Lengths are capped (422 naming the field); a company, a name or an email is required. Nothing is read back but the new lead's id — no other
 * record, no count. The Assistant is woken for a first qualification (a `lead_qualify` dispatch) when proposals are on. 600 a day by default (the key's limits, db/005).
 */
function api_capture_lead(PDO $pdo, array $key, array $body): never
{
    require_once dirname(__DIR__) . '/features/leads/queries.php';
    require_once dirname(__DIR__) . '/features/leads/write.php';
    require_once dirname(__DIR__) . '/features/agents/dispatch.php';
    $honey = $body[API_HONEYPOT] ?? '';
    if (is_scalar($honey) && trim((string) $honey) !== '' || is_array($honey)) {
        log_activity($pdo, 'lead.capture_trap', 'mcp_access_token', $key['token_id'], ['token_id' => $key['token_id'], 'after' => ['key' => $key['label']]]);
        api_json(['ok' => true]);
    }
    $f = [];
    $errors = [];
    foreach (API_CAPTURE_FIELDS as $name => $max) {
        $v = $body[$name] ?? null;
        if ($v === null || $v === '') { continue; }
        if (!is_scalar($v) || is_bool($v)) { $errors[$name] = $name . ' is text.'; continue; }
        $v = trim(str_replace("\r\n", "\n", (string) $v));
        if (mb_strlen($v) > $max) { $errors[$name] = $name . ' is up to ' . $max . ' characters.'; continue; }
        if ($v !== '') { $f[$name] = $v; }
    }
    if (isset($f['email'])) {
        if (filter_var($f['email'], FILTER_VALIDATE_EMAIL) === false) { $errors['email'] = 'email is not an email address.'; } else { $f['email'] = strtolower($f['email']); }
    }
    if ($errors === [] && !isset($f['first_name']) && !isset($f['last_name']) && !isset($f['company']) && !isset($f['email'])) {
        $errors['company'] = 'A lead needs a company, a name or an email address.';
    }
    if ($errors !== []) {
        api_error('invalid', (string) reset($errors), 422);
    }
    $utm = [];
    foreach (['utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content'] as $u) {
        if (isset($f[$u])) { $utm[$u] = $f[$u]; unset($f[$u]); }
    }
    $lead = $f + ['source_id' => $key['lead_source_id']] + ($utm === [] ? [] : ['utm' => $utm]);
    $id = 0;
    try {
        $pdo->beginTransaction();
        $id = lead_save($pdo, null, $lead, (int) current_member_id());
        $owner = one_value($pdo, 'SELECT owner_member_id FROM leads WHERE id = :id', ['id' => $id]);
        log_activity($pdo, 'lead.create', 'lead', $id, ['token_id' => $key['token_id'], 'after' => ['label' => (string) lead_label($pdo, $id), 'email' => $f['email'] ?? null, 'company' => $f['company'] ?? null, 'source_id' => $key['lead_source_id'],
            'owner_member_id' => $owner === null ? null : (int) $owner, 'via' => 'capture', 'key' => $key['label']]]);
        dispatch_lead_qualify($pdo, $id, $owner === null ? null : (int) $owner);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) { $pdo->rollBack(); }
        error_log('capture: ' . $e->getMessage());
        api_error('invalid', $e instanceof PDOException ? db_message($e, 'That lead could not be saved.') : 'That lead could not be saved.', 422);
    }
    api_json(['ok' => true, 'lead_id' => $id], 201);
}
