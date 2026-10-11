<?php
declare(strict_types=1);
/**
 * /activities (screen `activity-list`; params who me|<member>|team:<department>|all, state open|today|overdue|done|all, kind (a comma list), from, to,
 * account, contact, deal, lead, q, view, page): activities as a list — mine by default, everyone's when a record is named — open by when, done most
 * recent first; each row its kind, subject, when, record, owner, state and Done. Saved views of activities as chips; Save this view. JSON: the
 * find_activities resolver (rows, total).
 */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_once dirname(__DIR__, 2) . '/app/features/activities/present.php';
require_once dirname(__DIR__, 2) . '/app/features/views/queries.php';
require_login();
require_right('records.read');
$pdo = db();
$view = null;
if (($vid = request_integer('view')) !== null) {
    $view = find_saved_view($pdo, $vid);
    if ($view === null || $view['record_kind'] !== 'activity') { refuse(404, 'Saved view not found.'); }
}
$filters = activity_filters_from_request($pdo, $view);
$page = max(1, request_integer('page') ?? 1);
$result = find_activities($pdo, $filters, $page);
log_screen_view($pdo, 'activity-list');
if (wants_json()) {
    respond_screen(['rows' => array_map('present_activity_row', $result['rows']), 'total' => $result['total'], 'page' => $result['page'], 'pages' => $result['pages'],
        'filters' => ['who' => $filters['who']['key'], 'state' => $filters['state'], 'kinds' => $filters['kinds'], 'from' => $filters['from'], 'to' => $filters['to'],
                      'account' => $filters['account'], 'contact' => $filters['contact'], 'deal' => $filters['deal'], 'lead' => $filters['lead'], 'q' => $filters['q']]]);
}
$record = null;
foreach (['deal' => ['mcp_deals', 'deal_id', 'title'], 'contact' => ['mcp_contacts', 'contact_id', 'name'], 'account' => ['mcp_accounts', 'account_id', 'name'], 'lead' => ['mcp_leads', 'lead_id', 'label']] as $k => [$v, $col, $name]) {
    if ($filters[$k] !== null) { $record = [$k, $filters[$k], (string) (one_value($pdo, "SELECT $name FROM $v WHERE $col = :id", ['id' => $filters[$k]]) ?? '#' . $filters[$k])]; break; }
}
render_screen('Activities', view('activities/index.php', ['result' => $result, 'filters' => $filters, 'record' => $record, 'views' => my_saved_views($pdo, 'activity'), 'view' => $view,
    'teams' => find_live_departments($pdo), 'people' => find_owners($pdo), 'tz' => member_timezone(), 'back' => here_url(), 'me' => (int) current_member_id(),
    'may' => ['create' => has_right('activities.write'), 'share_view' => has_right('reports.team')],
    'notice' => crm_notice($_GET['notice'] ?? null, ['deleted' => ['success', 'Deleted the activity. It can be restored for a while.'], 'view_saved' => ['success', 'Saved the view.']])]),
    ['activeNav' => 'activities', 'screen' => 'activity-list', 'entity' => 'activity']);
