<?php
declare(strict_types=1);
/**
 * /pipeline/{pipeline}/list (screen `deal-list`; the board's params plus status (open by default; won, lost, all), group (stage or owner) and sort): the same
 * deals as a table, its columns the saved view's (DEAL_LIST_DEFAULT_COLUMNS without one). JSON: the rows (present_deal_card).
 */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_once dirname(__DIR__, 2) . '/app/features/records/queries.php';
require_once dirname(__DIR__, 2) . '/app/features/accounts/queries.php';
require_once dirname(__DIR__, 2) . '/app/features/deals/queries.php';
require_once dirname(__DIR__, 2) . '/app/features/deals/present.php';
require_once dirname(__DIR__, 2) . '/app/features/views/queries.php';
require_once dirname(__DIR__, 2) . '/app/features/leads/queries.php';
require_login();
require_right('records.read');
$pdo = db();
$pipeline = pipeline_from_request($pdo);
$filters = board_filters_from_request($pdo);
$sort = $filters['sort'] ?? 'close';
$rows = find_board_deals($pdo, $pipeline['pipeline_id'], $filters, $sort);
$view = $filters['view'] !== null ? find_saved_view($pdo, $filters['view']) : null;
$columns = array_values(array_intersect($view !== null && $view['columns'] !== [] ? $view['columns'] : DEAL_LIST_DEFAULT_COLUMNS, array_keys(DEAL_LIST_COLUMNS)));
if ($columns === []) { $columns = DEAL_LIST_DEFAULT_COLUMNS; }
$group = $filters['group'] ?? 'stage';
$groups = [];
foreach ($rows as $d) {
    $k = $group === 'owner' ? (string) ($d['owner_name'] ?? 'Unowned') : (string) $d['stage_name'];
    $groups[$k] ??= ['name' => $k, 'rows' => [], 'value' => 0.0, 'weighted' => 0.0];
    $groups[$k]['rows'][] = $d;
    if ($d['currency'] === base_currency($pdo)) { $groups[$k]['value'] += (float) $d['value']; $groups[$k]['weighted'] += (float) $d['weighted_value']; }
}
if ($group === 'stage') {
    $order = array_flip(array_map(static fn ($s) => $s['name'], pipeline_stages($pdo, $pipeline['pipeline_id'])));
    uksort($groups, static fn ($a, $b) => ($order[$a] ?? 999) <=> ($order[$b] ?? 999));
}
log_screen_view($pdo, 'deal-list');
if (wants_json()) {
    respond_screen(['pipeline' => ['pipeline_id' => $pipeline['pipeline_id'], 'key' => $pipeline['key'], 'name' => $pipeline['name']], 'filters' => $filters, 'group' => $group, 'sort' => $sort,
        'columns' => $columns, 'total' => count($rows), 'deals' => array_map('present_deal_card', $rows)]);
}
render_screen($pipeline['name'] . ' — list', view('pipeline/list.php', ['pipeline' => $pipeline, 'groups' => array_values($groups), 'total' => count($rows), 'filters' => $filters, 'columns' => $columns,
    'group' => $group, 'sort' => $sort, 'pipelines' => find_pipelines($pdo), 'views' => my_saved_views($pdo, 'deal'), 'owners' => find_owners($pdo), 'teams' => find_teams($pdo),
    'sources' => find_sources($pdo, false), 'back' => here_url(), 'currency' => base_currency($pdo),
    'may' => ['create' => has_right('deals.write'), 'save_view' => true, 'share_view' => has_right('reports.team')], 'notice' => crm_notice($_GET['notice'] ?? null, ['view_saved' => ['success', 'Saved the view.']])]),
    ['activeNav' => 'pipeline', 'screen' => 'deal-list', 'entity' => 'deal']);
