<?php
declare(strict_types=1);
/** /leads (screen `lead-list`; params q, status, owner (me, none or an id), source, campaign, untouched_days, tag, view, page): the leads in work as cards (proposed ones are the inbox's). */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_once dirname(__DIR__, 2) . '/app/features/records/queries.php';
require_once dirname(__DIR__, 2) . '/app/features/accounts/queries.php';
require_once dirname(__DIR__, 2) . '/app/features/contacts/queries.php';
require_once dirname(__DIR__, 2) . '/app/features/leads/queries.php';
require_once dirname(__DIR__, 2) . '/app/features/leads/write.php';
require_once dirname(__DIR__, 2) . '/app/features/leads/present.php';
require_once dirname(__DIR__, 2) . '/app/features/bulk/select.php';
require_login();
require_right('records.read');
$pdo = db();
$filters = lead_filters_from_request($pdo);
$page = max(1, request_integer('page') ?? 1);
$result = find_leads($pdo, $filters, $page);
log_screen_view($pdo, 'lead-list');
if (wants_json()) {
    respond_screen(['filters' => $filters, 'page' => $page, 'total' => $result['total'], 'more' => $page * LEAD_PAGE < $result['total'], 'inbox' => inbox_count($pdo), 'leads' => array_map('present_lead_card', $result['rows'])]);
}
$notices = ['deleted' => ['success', 'Deleted. It can be restored for a while.']];
render_screen('Leads', view('leads/index.php', ['result' => $result, 'filters' => $filters, 'page' => $page, 'owners' => find_owners($pdo), 'sources' => find_sources($pdo, false), 'views' => saved_views_of($pdo, 'lead'),
    'inbox' => inbox_count($pdo), 'may' => ['create' => has_right('leads.write'), 'import' => has_right('imports.own')], 'notice' => $notices[$_GET['notice'] ?? ''] ?? bulk_notice(), 'bulk' => bulk_enabled(), 'back' => here_url()]),
    ['activeNav' => 'leads', 'screen' => 'lead-list', 'entity' => 'lead']);
