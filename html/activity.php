<?php
declare(strict_types=1);
/**
 * /activity — the activity trail the caller may see (screen `activity`; params: lead | account | contact | deal (int), action, period): their own
 * rows, or one record's history (mcp_activity_log decides what they may see of it; the slices' record pages link here). Pattern B on
 * #activity-results. JSON: the rows with their sentences.
 */
require_once dirname(__DIR__) . '/app/bootstrap.php';
require_once dirname(__DIR__) . '/app/features/activity/queries.php';
require_login();
$pdo = db();
$record = null;
foreach (ACTIVITY_KEYS as $k) { if (($v = request_integer($k)) !== null) { $record = [$k, $v]; break; } }
$filters = ['own' => $record === null, 'action' => request_string('action'), 'since' => request_integer('period') ?? request_integer('since')];
if ($record !== null) { $filters[$record[0]] = $record[1]; }
if (!preg_match('/^[a-z_]*(\.[a-z_]+)*\.?$/', $filters['action'])) { $filters['action'] = ''; }
if (!in_array($filters['since'], [1, 7, 30, 90], true)) { $filters['since'] = null; }
$page = max(1, (int) (request_integer('page_no') ?? 1));
$result = find_my_activity($pdo, (int) current_member_id(), $page, $filters);
$rows = $result['rows'];
foreach ($rows as &$r) { $r['sentence'] = activity_sentence($r); }
unset($r);
$query = array_filter([($record[0] ?? 'x') => $record[1] ?? null, 'action' => $filters['action'], 'period' => $filters['since']], static fn ($v) => $v !== null && $v !== '');
log_screen_view($pdo, 'activity');
if (wants_json()) {
    respond_screen(['rows' => array_map('present_activity_row', $rows), 'page' => $page, 'more' => $result['more'], 'filters' => $query]);
}
$data = ['rows' => $rows, 'page' => $page, 'more' => $result['more'], 'filters' => $filters, 'query' => $query, 'tz' => member_timezone(), 'record' => $record];
$resultsHtml = view('activity/partials/rows.php', $data);
if (is_htmx_request() && ($_SERVER['HTTP_HX_TARGET'] ?? '') === 'activity-results') {
    header('Vary: HX-Request');
    echo $resultsHtml;
    exit;
}
render_screen('My activity', view('activity/page.php', $data + ['resultsHtml' => $resultsHtml]), ['activeNav' => 'activity', 'screen' => 'activity', 'entity' => $record !== null ? $record[0] : '', 'recordId' => $record !== null ? (string) $record[1] : '']);
