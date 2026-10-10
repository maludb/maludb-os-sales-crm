<?php
declare(strict_types=1);
/**
 * /pipeline/{pipeline}/forecast (screen `forecast`; params by (seller or stage), months (1–12, the setting's by default), team): crm_forecast() as a grid —
 * value / weighted per month, the won so far, the quota line under each seller. A quota is the seller's own to see, or anyone's with reports.team.
 * A cell links to the list filtered by that seller and month. JSON: the grid.
 */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_once dirname(__DIR__, 2) . '/app/features/records/queries.php';
require_once dirname(__DIR__, 2) . '/app/features/accounts/queries.php';
require_once dirname(__DIR__, 2) . '/app/features/deals/queries.php';
require_once dirname(__DIR__, 2) . '/app/features/pipeline/present.php';
require_once dirname(__DIR__, 2) . '/app/features/views/queries.php';
require_login();
require_right('records.read');
$pdo = db();
$pipeline = pipeline_from_request($pdo);
$by = request_string('by') === 'stage' ? 'stage' : 'seller';
$months = request_integer('months');
$months = $months === null ? (int) one_value($pdo, "SELECT crm_setting_int('forecast_months')") : max(1, min(12, $months));
$team = request_integer('team');
$grid = forecast_grid($pdo, $pipeline['pipeline_id'], $months, $by, $team);
if (!has_right('reports.team')) {
    $me = (int) current_member_id();
    foreach ($grid['rows'] as &$r) { if ($by === 'seller' && $r['id'] !== $me) { $r['quota'] = array_map(static fn () => null, $r['quota']); } }
    unset($r);
    foreach ($grid['totals'] as &$t) { $t['quota'] = null; }
    unset($t);
}
log_screen_view($pdo, 'forecast');
if (wants_json()) {
    respond_screen(['pipeline' => ['pipeline_id' => $pipeline['pipeline_id'], 'key' => $pipeline['key'], 'name' => $pipeline['name']], 'months' => $months, 'team' => $team, 'currency' => base_currency($pdo), 'forecast' => present_forecast($grid)]);
}
render_screen($pipeline['name'] . ' — forecast', view('pipeline/forecast.php', ['pipeline' => $pipeline, 'grid' => $grid, 'by' => $by, 'months' => $months, 'team' => $team, 'teams' => find_teams($pdo),
    'pipelines' => find_pipelines($pdo), 'views' => my_saved_views($pdo, 'deal'), 'currency' => base_currency($pdo), 'back' => here_url(), 'filters' => board_filters_from_request($pdo),
    'may' => ['create' => has_right('deals.write'), 'save_view' => true, 'share_view' => has_right('reports.team')]]),
    ['activeNav' => 'pipeline', 'screen' => 'forecast', 'entity' => 'deal']);
