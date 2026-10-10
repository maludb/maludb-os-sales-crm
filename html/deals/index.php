<?php
declare(strict_types=1);
/**
 * /deals — deals across pipelines are the board's and the list's: a browser goes to the default pipeline's board. JSON (the find_deals resolver): q, status
 * (open; won, lost, all), owner (me, none, an id), account, contact, pipeline, stage, page — the rows with the wall applied.
 */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_once dirname(__DIR__, 2) . '/app/features/deals/queries.php';
require_once dirname(__DIR__, 2) . '/app/features/deals/present.php';
require_login();
require_right('records.read');
$pdo = db();
if (!wants_json()) {
    redirect(pipeline_board_url());
}
$f = board_filters_from_request($pdo);
$f['status'] = $f['status'] ?? 'open';
foreach (['account', 'contact', 'pipeline', 'stage'] as $k) { $f[$k] = request_integer($k); }
$page = max(1, request_integer('page') ?? 1);
$r = find_deals($pdo, $f, $page);
respond_screen(['filters' => $f, 'page' => $page, 'total' => $r['total'], 'more' => $page * DEAL_PAGE < $r['total'],
    'deals' => array_map(static fn (array $d): array => present_deal_card($d + ['position' => 0, 'missing_next' => [], 'tags' => []]), $r['rows'])]);
