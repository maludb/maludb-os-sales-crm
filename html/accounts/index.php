<?php
declare(strict_types=1);
/** /accounts (screen `account-list`; params q, kind, owner, industry, country, tag, untouched_days, view, page): our accounts as cards. JSON: the cards and the total. */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_once dirname(__DIR__, 2) . '/app/features/records/queries.php';
require_once dirname(__DIR__, 2) . '/app/features/accounts/queries.php';
require_once dirname(__DIR__, 2) . '/app/features/accounts/present.php';
require_login();
require_right('records.read');
$pdo = db();
$filters = account_filters_from_request($pdo);
$page = max(1, request_integer('page') ?? 1);
$result = find_accounts($pdo, $filters, $page);
log_screen_view($pdo, 'account-list');
if (wants_json()) {
    respond_screen(['filters' => $filters, 'page' => $page, 'total' => $result['total'], 'more' => $page * ACCOUNT_PAGE < $result['total'], 'accounts' => array_map('present_account_card', $result['rows'])]);
}
$notices = ['deleted' => ['success', 'Deleted. It can be restored for a while.']];
render_screen('Accounts', view('accounts/index.php', ['result' => $result, 'filters' => $filters, 'page' => $page, 'owners' => find_owners($pdo), 'views' => saved_views_of($pdo, 'account'),
    'may' => ['create' => has_right('accounts.write')], 'notice' => $notices[$_GET['notice'] ?? ''] ?? null, 'back' => here_url()]), ['activeNav' => 'accounts', 'screen' => 'account-list', 'entity' => 'account']);
