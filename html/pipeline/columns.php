<?php
declare(strict_types=1);
/**
 * /pipeline/{pipeline}/columns — the board's columns region alone (Pattern A, the same query as the page): polled every 30 s and on dealChanged, and
 * the answer to a drag (html/deals/move.php renders it for an HTMX move from the board). Nothing logged: a poll is not a visit. JSON: the board.
 */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_once dirname(__DIR__, 2) . '/app/features/records/queries.php';
require_once dirname(__DIR__, 2) . '/app/features/accounts/queries.php';
require_once dirname(__DIR__, 2) . '/app/features/deals/queries.php';
require_once dirname(__DIR__, 2) . '/app/features/deals/present.php';
require_once dirname(__DIR__, 2) . '/app/features/pipeline/present.php';
require_login();
require_right('records.read');
$pdo = db();
$pipeline = pipeline_from_request($pdo);
$filters = board_filters_from_request($pdo);
$board = board_columns($pdo, $pipeline['pipeline_id'], $filters);
if (wants_json()) {
    respond_screen(present_board($pipeline, $board));
}
header('Vary: HX-Request');
echo view('pipeline/partials/columns.php', ['pipeline' => $pipeline, 'board' => $board, 'filters' => $filters, 'back' => '/pipeline/' . rawurlencode((string) $pipeline['key']) . '/board' . (($q = board_query($filters)) !== '' ? '?' . $q : '')]);
