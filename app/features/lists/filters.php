<?php
declare(strict_types=1);

/**
 * THE FILTER VOCABULARY — the ONE place the `find_*` filters are defined for a smart list, a bulk action's "everything matching", an export and the tools' `segment_preview`
 * (campaigns-lists.md divergence 2). The list screens of slices 1–4 parse their own query strings (`lead_filters_from_request`, `contact_filters_from_request`,
 * `account_filters_from_request`, `board_filters_from_request`, `activity_filters_from_request`); this file CALLS those parsers rather than defining a second grammar, so a smart list
 * can never mean something other than the screen it was made from. A filter set is stored as a small JSON object of the screen's own keys (`{"owner":"me","status":"new","untouched_days":30}`),
 * empty values left out; the saved views' older spellings (`status: ["new"]`, `source_id`, `campaign_id`, `tags`, `pipeline_id`) are read too.
 *
 *   filters_parse(kind, input)           input (a form's filter[…], a stored object, a query) → the compact object, only the kind's vocabulary
 *   filters_form(kind, filters)          the builder's rows (#filter-builder / .filter-row-{n}) as HTML for list-add / list-edit
 *   filters_words(pdo, kind, filters)    [[label, value in words]…] for the chips
 *   filters_find(pdo, kind, filters, page, extra)   one page of the kind's find_* — rows and total; `extra` may hold in_list (a static list's id)
 *   filters_ids(pdo, kind, filters, limit)          the ids a filter matches, the wall applied by the views, up to $limit (over_limit says there are more)
 *   filters_page(kind)                   the kind's page size
 */
require_once dirname(__DIR__) . '/records/queries.php';
require_once dirname(__DIR__) . '/accounts/queries.php';
require_once dirname(__DIR__) . '/contacts/queries.php';
require_once dirname(__DIR__) . '/leads/queries.php';
require_once dirname(__DIR__) . '/pipeline/queries.php';
require_once dirname(__DIR__) . '/deals/queries.php';
require_once dirname(__DIR__) . '/activities/queries.php';

/** The kinds a smart list, a bulk action and the filter builder work on. */
const FILTER_KINDS = ['lead' => 'Leads', 'contact' => 'Contacts', 'account' => 'Accounts', 'deal' => 'Deals'];
/** The kinds an export can take filters for (the four and the activities). */
const FILTER_EXPORT_KINDS = ['lead' => 'leads', 'contact' => 'contacts', 'account' => 'accounts', 'deal' => 'deals', 'activity' => 'activities'];

/**
 * The vocabulary per kind, in the order the builder shows it: key => [label, type, options|source]. Types: text, number, select (options), owner (me/none + a person), picker (a record
 * source of app/pickers.php), bool (a checkbox), days (a select of days).
 */
function filter_vocabulary(string $kind): array
{
    $days = [7 => '7+ days', 30 => '30+ days', 90 => '90+ days', 180 => '180+ days'];
    return match ($kind) {
        'lead' => ['q' => ['Text', 'text', 'name, company or email'], 'status' => ['Status', 'select', LEAD_STATUSES], 'owner' => ['Owner', 'owner', ['me', 'none']],
                   'source' => ['Source', 'picker', 'source'], 'campaign' => ['Campaign', 'picker', 'campaign'], 'tag' => ['Tag', 'text', 'a tag\'s name'], 'untouched_days' => ['Untouched for', 'days', [2 => '2+ days'] + $days]],
        'contact' => ['q' => ['Text', 'text', 'name, email or company'], 'account' => ['At the account', 'picker', 'account'], 'lifecycle' => ['Lifecycle', 'select', CONTACT_LIFECYCLES],
                      'role' => ['Role', 'select', CONTACT_ROLES], 'owner' => ['Owner', 'owner', ['me']], 'opt_out' => ['Opted out of', 'select', ['email' => 'Email', 'sms' => 'Text messages']],
                      'tag' => ['Tag', 'text', 'a tag\'s name'], 'untouched_days' => ['Untouched for', 'days', $days]],
        'account' => ['q' => ['Text', 'text', 'name or domain'], 'kind' => ['Kind', 'select', ACCOUNT_KINDS], 'owner' => ['Owner', 'owner', ['me']], 'industry' => ['Industry', 'text', 'an industry'],
                      'country' => ['Country', 'text', 'US'], 'tag' => ['Tag', 'text', 'a tag\'s name'], 'untouched_days' => ['Untouched for', 'days', $days]],
        'deal' => ['q' => ['Text', 'text', 'title, number or company'], 'status' => ['Status', 'select', ['open' => 'Open', 'won' => 'Won', 'lost' => 'Lost', 'all' => 'Any']], 'owner' => ['Owner', 'owner', ['me', 'none']],
                   'pipeline' => ['Pipeline', 'picker', 'pipeline'], 'close' => ['Closing', 'select', BOARD_CLOSE], 'source' => ['Source', 'picker', 'source'], 'campaign' => ['Campaign', 'picker', 'campaign'],
                   'tag' => ['Tag', 'text', 'a tag\'s name'], 'min_value' => ['Worth at least', 'number', '0'], 'max_value' => ['Worth at most', 'number', '0'],
                   'no_next_step' => ['No next step', 'bool', null], 'rotting' => ['Rotting', 'bool', null]],
        default => [],
    };
}

/** The keys a kind's compact filters may hold (the vocabulary, plus the two a deal screen reads besides the board's: account, contact, stage). */
function filter_keys(string $kind): array
{
    return array_merge(array_keys(filter_vocabulary($kind)), $kind === 'deal' ? ['account', 'contact', 'stage'] : []);
}

/** The older spellings of the saved views and the tools, folded into the screen's own keys; an array takes its first element; nested things are dropped. */
function filters_normalise(array $input): array
{
    $alias = ['source_id' => 'source', 'campaign_id' => 'campaign', 'pipeline_id' => 'pipeline', 'stage_id' => 'stage', 'account_id' => 'account', 'contact_id' => 'contact', 'tags' => 'tag'];
    $out = [];
    foreach ($input as $k => $v) {
        $k = $alias[$k] ?? (string) $k;
        if (is_array($v)) {
            $v = $v[0] ?? null;
        }
        if (is_bool($v)) {
            $v = $v ? '1' : '';
        }
        if ($v === null || is_array($v) || is_object($v) || (is_string($v) && trim($v) === '')) {
            continue;
        }
        $out[$k] ??= is_int($v) || is_float($v) ? (string) $v : trim((string) $v);
    }
    if (isset($out['owner_id']) && ctype_digit($out['owner_id'])) {          // the builder's "or a person" wins over Anyone / Mine
        $out['owner'] = $out['owner_id'];
    }
    unset($out['owner_id'], $out['view'], $out['page']);
    return $out;
}

/**
 * A form's filter[…] (or a stored object, or a screen's query) as the compact object of a kind: the kind's own parser reads it as it reads its query string — so what a smart list holds is
 * exactly what the screen it came from would show. Values the parser rejects are dropped; nothing outside the kind's vocabulary survives.
 */
function filters_parse(string $kind, array $input): array
{
    if (!isset(FILTER_EXPORT_KINDS[$kind])) {
        return [];
    }
    $in = filters_normalise($input);
    $pdo = db();
    $get = $_GET;
    $post = $_POST;
    $_GET = $in;                                                                 // the screens' parsers read the request
    $_POST = [];
    try {
        $f = match ($kind) {
            'lead' => lead_filters_from_request($pdo),
            'contact' => contact_filters_from_request($pdo),
            'account' => account_filters_from_request($pdo),
            'deal' => board_filters_from_request($pdo, $in),
            'activity' => activity_filters_from_request($pdo),
        };
    } finally {
        $_GET = $get;
        $_POST = $post;
    }
    if ($kind === 'activity') {
        $q = activity_query($f);                                                   // the activities' own string form: who, state, kind, from, to, account, contact, deal, lead, q
        parse_str($q, $out);
        return array_map('strval', $out);
    }
    if ($kind === 'deal') {
        foreach (['account', 'contact', 'pipeline', 'stage'] as $k) {
            $f[$k] = isset($in[$k]) && ctype_digit($in[$k]) ? (int) $in[$k] : null;
        }
    }
    $out = [];
    foreach (filter_keys($kind) as $k) {
        $v = $f[$k] ?? null;
        if ($v === null || $v === '' || $v === false) {
            continue;
        }
        $out[$k] = $v === true ? 1 : (is_int($v) || is_string($v) ? $v : (string) $v);
    }
    return $out;
}

/** The stored JSON (or a query string) of a filter set as an array; a malformed one is an empty set. */
function filters_decode(mixed $raw): array
{
    if (is_array($raw)) {
        return $raw;
    }
    $raw = trim((string) $raw);
    if ($raw === '') {
        return [];
    }
    if ($raw[0] === '{') {
        $d = json_decode($raw, true);
        return is_array($d) ? $d : [];
    }
    parse_str($raw, $q);
    return $q;
}

/** A compact set as JSON for the lists table: stable key order, an empty set as {}. */
function filters_json(array $compact): string
{
    ksort($compact);
    return json_encode($compact === [] ? (object) [] : $compact, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
}

function filters_page(string $kind): int
{
    return match ($kind) { 'lead' => LEAD_PAGE, 'contact' => CONTACT_PAGE, 'account' => ACCOUNT_PAGE, 'deal' => DEAL_PAGE, default => 50 };
}

/**
 * One page of the kind's find_*: ['rows', 'total'] — the views' wall applied. $extra: in_list (a static list's id: only its members; a lead list then shows converted leads too, a deal list every status).
 */
function filters_find(PDO $pdo, string $kind, array $compact, int $page = 1, array $extra = []): array
{
    $f = filters_parse($kind, $compact);
    if ($kind === 'activity') {
        return find_activities($pdo, activity_filters_from_request_with($f), $page);
    }
    $f += array_intersect_key($extra, ['in_list' => 1]);
    return match ($kind) {
        'lead' => find_leads($pdo, $f + ['q' => '', 'owner' => null], $page),
        'contact' => find_contacts($pdo, $f + ['q' => ''], $page),
        'account' => find_accounts($pdo, $f + ['q' => ''], $page),
        'deal' => find_deals($pdo, ($f + ['q' => '']) + (isset($f['in_list']) ? ['status' => 'all'] : []), $page),
    };
}

/** The activities' parsed filters from a compact set (the $_GET swap again: the activities screen's parser is the one grammar). */
function activity_filters_from_request_with(array $compact): array
{
    $get = $_GET;
    $_GET = $compact;
    try {
        return activity_filters_from_request(db());
    } finally {
        $_GET = $get;
    }
}

/**
 * The ids a filter set (and optionally a static list) matches, in the kind's own order, the wall applied. At most $limit are gathered: when there are more, `over_limit` is true and `ids` is empty
 * (a caller that refuses the action does not need them). `total` is the whole count. Pages through find_* — whose order is total (ties broken by id) — so no row is read twice or missed.
 */
function filters_ids(PDO $pdo, string $kind, array $compact, int $limit, array $extra = []): array
{
    $first = filters_find($pdo, $kind, $compact, 1, $extra);
    if ($first['total'] > $limit) {
        return ['ids' => [], 'total' => $first['total'], 'over_limit' => true];
    }
    $idCol = ['lead' => 'lead_id', 'contact' => 'contact_id', 'account' => 'account_id', 'deal' => 'deal_id', 'activity' => 'activity_id'][$kind];
    $ids = array_map(static fn (array $r): int => (int) $r[$idCol], $first['rows']);
    $pages = (int) ceil($first['total'] / filters_page($kind));
    for ($p = 2; $p <= $pages; $p++) {
        foreach (filters_find($pdo, $kind, $compact, $p, $extra)['rows'] as $r) {
            $ids[] = (int) $r[$idCol];
        }
    }
    return ['ids' => array_values(array_unique($ids)), 'total' => $first['total'], 'over_limit' => false];
}

/** A set as words for the chips: [[label, value]…] in the vocabulary's order (a record by its name). */
function filters_words(PDO $pdo, string $kind, array $compact): array
{
    $words = [];
    foreach (filter_vocabulary($kind) as $key => [$label, $type, $opt]) {
        if (!isset($compact[$key])) { continue; }
        $v = $compact[$key];
        $text = match (true) {
            $type === 'bool' => 'yes',
            $type === 'select' => (string) ($opt[$v] ?? $v),
            $type === 'days' => (string) ($opt[(int) $v] ?? ($v . '+ days')),
            $type === 'owner' => $v === 'me' ? 'Me' : ($v === 'none' ? 'Nobody' : (string) (member_name($pdo, (int) $v) ?? $v)),
            $type === 'picker' => (string) (picker_label($pdo, (string) $opt, $v) ?? $v),
            default => (string) $v,
        };
        $words[] = [$label, $text];
    }
    return $words;
}

/**
 * The builder's rows (list-add, list-edit): one row per word of the vocabulary — `filter-row-{n}` — each field named filter[<key>] (the owner's person: filter[owner_id]) so the list's save reads the whole
 * object from one array. Pickers for records, selects only for short enumerations. $filters is a compact set (what the list holds, or a prefill).
 */
function filters_form(string $kind, array $filters): string
{
    $html = '';
    $n = 0;
    foreach (filter_vocabulary($kind) as $key => [$label, $type, $opt]) {
        $id = 'filter-field-' . $key;
        $v = $filters[$key] ?? null;
        $name = 'filter[' . $key . ']';
        $control = match ($type) {
            'text' => '<input type="text" class="form-control btn-touch" id="' . $id . '" name="' . $name . '" value="' . e((string) ($v ?? '')) . '" maxlength="120" placeholder="' . e((string) $opt) . '">',
            'number' => '<input type="number" class="form-control btn-touch" id="' . $id . '" name="' . $name . '" value="' . e((string) ($v ?? '')) . '" min="0" step="any" placeholder="' . e((string) $opt) . '">',
            'select' => '<select class="form-select btn-touch" id="' . $id . '" name="' . $name . '"><option value="">Any</option>'
                . implode('', array_map(static fn ($k, $l): string => '<option value="' . e((string) $k) . '"' . ((string) $v === (string) $k ? ' selected' : '') . '>' . e((string) $l) . '</option>', array_keys($opt), $opt)) . '</select>',
            'days' => '<select class="form-select btn-touch" id="' . $id . '" name="' . $name . '"><option value="">Any time</option>'
                . implode('', array_map(static fn ($k, $l): string => '<option value="' . (int) $k . '"' . ((string) $v === (string) $k ? ' selected' : '') . '>' . e((string) $l) . '</option>', array_keys($opt), $opt)) . '</select>',
            'bool' => '<div class="form-check form-switch pt-2"><input class="form-check-input" type="checkbox" role="switch" id="' . $id . '" name="' . $name . '" value="1"' . ($v ? ' checked' : '') . '><label class="form-check-label" for="' . $id . '">Only these</label></div>',
            'owner' => '<div class="row g-2"><div class="col-12 col-sm-5"><select class="form-select btn-touch" id="' . $id . '" name="' . $name . '" aria-label="Owner"><option value="">Anyone</option>'
                . (in_array('me', $opt, true) ? '<option value="me"' . ($v === 'me' ? ' selected' : '') . '>Mine (whoever opens it)</option>' : '')
                . (in_array('none', $opt, true) ? '<option value="none"' . ($v === 'none' ? ' selected' : '') . '>Nobody\'s</option>' : '') . '</select></div>'
                . '<div class="col-12 col-sm-7">' . picker_field(['id' => 'filter-field-owner-person', 'name' => 'filter[owner_id]', 'source' => 'owner', 'value' => $v !== null && ctype_digit((string) $v) ? $v : null,
                    'title' => 'A person or an agent', 'placeholder' => 'or one person…', 'labelledby' => 'filter-row-label-' . $n]) . '</div></div>',
            'picker' => picker_field(['id' => $id, 'name' => $name, 'source' => (string) $opt, 'value' => $v, 'labelledby' => 'filter-row-label-' . $n, 'placeholder' => 'Any']),
        };
        $html .= '<div class="col-12 col-md-6" id="filter-row-' . $n . '"><label class="form-label" id="filter-row-label-' . $n . '" for="' . ($type === 'owner' ? $id : $id) . '">' . e($label) . '</label>' . $control . '</div>';
        $n++;
    }
    return $html;
}
