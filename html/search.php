<?php
declare(strict_types=1);
/**
 * /search — the one box's results grouped by kind with the words highlighted (screen `search`; params: q (2–200 characters), kinds[] (lead,
 * account, contact, deal), page). Every search is logged `search.keyword` with the query cut at 120 characters and the count (sso-shell.md).
 * The wall is crm_search()'s. JSON: the rows and the total.
 */
require_once dirname(__DIR__) . '/app/bootstrap.php';
require_once dirname(__DIR__) . '/app/features/search/queries.php';
require_once dirname(__DIR__) . '/app/features/search/present.php';
require_login();
require_right('records.read');
$pdo = db();
$q = mb_substr(trim(request_string('q')), 0, 200);
$kinds = $_GET['kinds'] ?? $_POST['kinds'] ?? [];
$kinds = is_array($kinds) ? $kinds : explode(',', (string) $kinds);
$kinds = array_values(array_unique(array_intersect(array_map(static fn ($k): string => trim((string) $k), $kinds), array_keys(SEARCH_KINDS))));
$page = max(1, request_integer('page') ?? 1);
$result = ['rows' => [], 'total' => 0];
$tooShort = $q !== '' && mb_strlen($q) < 2;
if ($q !== '' && !$tooShort) {
    $result = search($pdo, $q, $kinds, $page);
    log_activity($pdo, 'search.keyword', null, null, ['screen' => 'search', 'after' => ['q' => mb_substr($q, 0, 120), 'kinds' => $kinds, 'count' => $result['total'], 'page' => $page]]);
}
log_screen_view($pdo, 'search');
if (wants_json()) {
    if ($tooShort) {
        respond_invalid(['q' => 'Type at least two characters.']);
    }
    respond_screen(['q' => $q, 'kinds' => $kinds, 'page' => $page, 'total' => $result['total'], 'more' => $page * SEARCH_PAGE < $result['total'],
        'rows' => array_map('present_search_row', $result['rows'])]);
}
render_screen($q !== '' ? 'Search: ' . mb_substr($q, 0, 40) : 'Search', view('search/index.php', ['q' => $q, 'kinds' => $kinds, 'page' => $page, 'result' => $result, 'tooShort' => $tooShort]),
    ['activeNav' => 'search', 'screen' => 'search']);
