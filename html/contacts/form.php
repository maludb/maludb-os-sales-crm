<?php
declare(strict_types=1);
/**
 * /contacts/new and /contacts/{id}/edit (screens `contact-add`, `contact-edit`; prefill params `account`, `email`, `first_name`, `last_name`). ?dupes=1 answers
 * the duplicate warning alone (by an exact address — every address of every contact — and by a name like it; only what the caller may see; nothing logged).
 */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_once dirname(__DIR__, 2) . '/app/features/records/queries.php';
require_once dirname(__DIR__, 2) . '/app/features/accounts/queries.php';
require_once dirname(__DIR__, 2) . '/app/features/contacts/queries.php';
require_once dirname(__DIR__, 2) . '/app/features/contacts/write.php';
require_once dirname(__DIR__, 2) . '/app/features/custom_fields/queries.php';
require_once dirname(__DIR__, 2) . '/app/features/custom_fields/form.php';
require_login();
$pdo = db();
$id = request_integer('id');
$policy = duplicates_policy($pdo);
if (request_string('dupes') === '1') {
    require_right('records.read');
    header('Vary: HX-Request');
    echo view('shared/duplicate-warning.php', ['rows' => record_duplicates($pdo, 'contact', ['email' => request_string('email'), 'first_name' => request_string('first_name'), 'last_name' => request_string('last_name')], request_integer('except')),
        'kind' => 'contact', 'policy' => $policy]);
    exit;
}
$c = null;
if ($id === null) {
    require_right('contacts.write');
    $values = array_merge(array_fill_keys(CONTACT_COLUMNS, null), ['custom_values' => [], 'is_primary' => false, 'do_not_call' => false]);
    foreach (['first_name', 'last_name'] as $k) { $values[$k] = mb_substr(request_string($k), 0, 100) ?: null; }
    $values['email'] = filter_var(request_string('email'), FILTER_VALIDATE_EMAIL) ?: null;
    $acc = request_integer('account');
    $values['account_id'] = $acc !== null && account_label($pdo, $acc) !== null ? $acc : null;
    $values['owner_member_id'] = (int) current_member_id();
} else {
    $row = require_record($pdo, 'contact', $id);
    require_editable($pdo, 'contact', $row);
    $c = find_contact($pdo, $id);
    $values = contact_current($pdo, $id);
}
$sources = $pdo->query('SELECT source_id, name FROM mcp_lead_sources WHERE active ORDER BY sort_order, name')->fetchAll();
$screen = $id === null ? 'contact-add' : 'contact-edit';
log_screen_view($pdo, $screen);
if (wants_json()) {
    respond_screen(['contact_id' => $id, 'values' => $values, 'sources' => $sources, 'roles' => CONTACT_ROLES, 'channels' => CONTACT_CHANNELS, 'custom_fields' => custom_field_defs($pdo, 'contact'), 'duplicates_policy' => $policy]);
}
$restriction = null;
if ($id !== null && has_right('records.restrict')) {
    $restriction = ['kind' => 'contact', 'id' => $id, 'row' => $c, 'departments' => find_live_departments($pdo), 'back' => '/contacts/' . $id . '/edit'];
}
render_screen($id === null ? 'New contact' : 'Edit ' . ($c['name'] ?: $c['email']), view('contacts/form.php', ['c' => $c, 'values' => $values, 'sources' => $sources, 'policy' => $policy,
    'custom_html' => custom_fields_section($pdo, 'contact', $values['custom_values'], 'contact-form'), 'restriction' => $restriction, 'may_tag' => has_right('tags.apply')]),
    ['activeNav' => 'contacts', 'screen' => $screen, 'entity' => 'contact', 'recordId' => $id === null ? '' : (string) $id]);
