<?php
declare(strict_types=1);
/** /campaigns (screen `campaign-list`; params status, kind, owner, q, page): campaigns as cards with what they brought. JSON: the campaigns with their results and the total. */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_once dirname(__DIR__, 2) . '/app/features/records/queries.php';
require_once dirname(__DIR__, 2) . '/app/features/campaigns/queries.php';
require_once dirname(__DIR__, 2) . '/app/features/campaigns/present.php';
require_login();
require_right('records.read');
$pdo = db();
$filters = campaign_filters_from_request();
$page = max(1, request_integer('page') ?? 1);
$result = find_campaigns($pdo, $filters, $page);
log_screen_view($pdo, 'campaign-list');
if (wants_json()) {
    respond_screen(['filters' => $filters, 'page' => $page, 'total' => $result['total'], 'more' => $page * CAMPAIGN_PAGE < $result['total'], 'campaigns' => array_map(static fn (array $c): array => present_campaign($c), $result['rows'])]);
}
$notices = ['deleted' => ['success', 'Deleted. It can be restored for a while.']];
render_screen('Campaigns', view('campaigns/index.php', ['result' => $result, 'filters' => $filters, 'page' => $page, 'owners' => find_owners($pdo), 'may' => ['create' => has_right('campaigns.manage')],
    'notice' => $notices[$_GET['notice'] ?? ''] ?? null, 'back' => here_url()]), ['activeNav' => 'campaigns', 'screen' => 'campaign-list', 'entity' => 'campaign']);
