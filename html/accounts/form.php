<?php
declare(strict_types=1);
/**
 * /accounts/new and /accounts/{id}/edit (screens `account-add`, `account-edit`; prefill params `name`, `domain`). ?dupes=1 answers the duplicate
 * warning alone (Pattern A, on blur: by domain and by a name like it — only what the caller may see; nothing logged). JSON: the values and the choices.
 */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_once dirname(__DIR__, 2) . '/app/features/records/queries.php';
require_once dirname(__DIR__, 2) . '/app/features/accounts/queries.php';
require_once dirname(__DIR__, 2) . '/app/features/accounts/write.php';
require_once dirname(__DIR__, 2) . '/app/features/custom_fields/queries.php';
require_once dirname(__DIR__, 2) . '/app/features/custom_fields/form.php';
require_login();
$pdo = db();
$id = request_integer('id');
if (request_string('dupes') === '1') {
    require_right('records.read');
    header('Vary: HX-Request');
    echo view('shared/duplicate-warning.php', ['rows' => record_duplicates($pdo, 'account', ['name' => request_string('name'), 'website' => request_string('website'), 'domain' => request_string('domain')], request_integer('except')),
        'kind' => 'account', 'policy' => 'warn']);
    exit;
}
$a = null;
if ($id === null) {
    require_right('accounts.write');
    $values = array_merge(array_fill_keys(ACCOUNT_COLUMNS, null), ['custom_values' => []]);
    $values['name'] = mb_substr(request_string('name'), 0, 200) ?: null;
    $values['domain'] = domain_of(null, request_string('domain'));
    $values['kind'] = 'prospect';
    $values['owner_member_id'] = (int) current_member_id();
} else {
    $row = require_record($pdo, 'account', $id);
    require_editable($pdo, 'account', $row);
    $a = find_account($pdo, $id);
    $values = account_current($pdo, $id);
}
$kinds = $id === null ? ['prospect', 'partner', 'other'] : array_values(array_unique(['prospect', 'partner', 'other', 'former', (string) $values['kind']]));
$sources = $pdo->query('SELECT source_id, name FROM mcp_lead_sources WHERE active ORDER BY sort_order, name')->fetchAll();
$screen = $id === null ? 'account-add' : 'account-edit';
log_screen_view($pdo, $screen);
if (wants_json()) {
    respond_screen(['account_id' => $id, 'values' => $values, 'kinds' => $kinds, 'sources' => $sources, 'custom_fields' => custom_field_defs($pdo, 'account'), 'employee_bands' => EMPLOYEE_BANDS,
        'duplicates' => $id === null ? [] : record_duplicates($pdo, 'account', $values, $id)]);
}
$restriction = null;
if ($id !== null && has_right('records.restrict')) {
    $restriction = ['kind' => 'account', 'id' => $id, 'row' => $a + ['owner_member_id' => $a['owner_member_id']], 'departments' => find_live_departments($pdo), 'back' => '/accounts/' . $id . '/edit'];
}
render_screen($id === null ? 'New account' : 'Edit ' . $a['name'], view('accounts/form.php', ['a' => $a, 'values' => $values, 'kinds' => $kinds, 'sources' => $sources,
    'custom_html' => custom_fields_section($pdo, 'account', $values['custom_values'], 'account-form'), 'restriction' => $restriction, 'may_tag' => has_right('tags.apply'),
    'dupes_rows' => []]), ['activeNav' => 'accounts', 'screen' => $screen, 'entity' => 'account', 'recordId' => $id === null ? '' : (string) $id]);
