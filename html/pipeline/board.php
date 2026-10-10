<?php
declare(strict_types=1);
/**
 * /pipeline/{pipeline}/board (screen `board`; the pipeline by id or key; params owner, team, close, tag, source, campaign, min_value, max_value, no_next_step,
 * rotting, q, view): a column per open stage, Won and Lost as drop targets with the month's totals. The columns region is /pipeline/{p}/columns — polled every
 * 30 s and on dealChanged. JSON: the board (present_board).
 */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_once dirname(__DIR__, 2) . '/app/features/records/queries.php';
require_once dirname(__DIR__, 2) . '/app/features/accounts/queries.php';
require_once dirname(__DIR__, 2) . '/app/features/deals/queries.php';
require_once dirname(__DIR__, 2) . '/app/features/deals/present.php';
require_once dirname(__DIR__, 2) . '/app/features/pipeline/present.php';
require_once dirname(__DIR__, 2) . '/app/features/views/queries.php';
require_once dirname(__DIR__, 2) . '/app/features/leads/queries.php';
require_login();
require_right('records.read');
$pdo = db();
$pipeline = pipeline_from_request($pdo);
$filters = board_filters_from_request($pdo);
$board = board_columns($pdo, $pipeline['pipeline_id'], $filters);
log_screen_view($pdo, 'board');
if (wants_json()) {
    respond_screen(present_board($pipeline, $board) + ['filters' => $filters]);
}
render_screen($pipeline['name'] . ' — board', view('pipeline/board.php', ['pipeline' => $pipeline, 'board' => $board, 'filters' => $filters, 'pipelines' => find_pipelines($pdo),
    'views' => my_saved_views($pdo, 'deal'), 'owners' => find_owners($pdo), 'teams' => find_teams($pdo), 'sources' => find_sources($pdo, false), 'back' => here_url(),
    'may' => ['create' => has_right('deals.write'), 'save_view' => true, 'share_view' => has_right('reports.team')],
    'notice' => crm_notice($_GET['notice'] ?? null, ['moved' => ['success', 'Moved.'], 'view_saved' => ['success', 'Saved the view.']])]),
    ['activeNav' => 'pipeline', 'screen' => 'board', 'entity' => 'deal']);
