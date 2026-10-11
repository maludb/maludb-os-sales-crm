<?php
declare(strict_types=1);
/** /contacts (screen `contact-list`; params q, account, lifecycle, owner, role, opt_out, tag, untouched_days, view, page): our contacts as cards. JSON: the cards and the total. */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_once dirname(__DIR__, 2) . '/app/features/records/queries.php';
require_once dirname(__DIR__, 2) . '/app/features/accounts/queries.php';
require_once dirname(__DIR__, 2) . '/app/features/contacts/queries.php';
require_once dirname(__DIR__, 2) . '/app/features/contacts/present.php';
require_once dirname(__DIR__, 2) . '/app/features/bulk/select.php';
require_login();
require_right('records.read');
$pdo = db();
$filters = contact_filters_from_request($pdo);
$page = max(1, request_integer('page') ?? 1);
$result = find_contacts($pdo, $filters, $page);
log_screen_view($pdo, 'contact-list');
if (wants_json()) {
    respond_screen(['filters' => $filters, 'page' => $page, 'total' => $result['total'], 'more' => $page * CONTACT_PAGE < $result['total'], 'contacts' => array_map('present_contact_card', $result['rows'])]);
}
$notices = ['deleted' => ['success', 'Deleted. It can be restored for a while.']];
render_screen('Contacts', view('contacts/index.php', ['result' => $result, 'filters' => $filters, 'page' => $page, 'owners' => find_owners($pdo), 'views' => saved_views_of($pdo, 'contact'),
    'account_name' => $filters['account'] !== null ? account_label($pdo, (int) $filters['account']) : null, 'may' => ['create' => has_right('contacts.write')],
    'notice' => $notices[$_GET['notice'] ?? ''] ?? bulk_notice(), 'bulk' => bulk_enabled(), 'back' => here_url()]), ['activeNav' => 'contacts', 'screen' => 'contact-list', 'entity' => 'contact']);
