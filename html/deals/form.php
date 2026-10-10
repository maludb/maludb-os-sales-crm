<?php
declare(strict_types=1);
/**
 * /deals/new and /deals/{id}/edit (screens `deal-add`, `deal-edit`; prefill params account, contact, pipeline, title, value, close). New from an account's page or a
 * contact's arrives prefilled: the account (a contact's account when only the contact is named), the primary contact, a title "<account> — new business".
 * JSON: the values and the choices (pipelines, stages, sources, reasons, custom fields).
 */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_once dirname(__DIR__, 2) . '/app/features/deals/write.php';
require_once dirname(__DIR__, 2) . '/app/features/custom_fields/form.php';
require_once dirname(__DIR__, 2) . '/app/features/leads/queries.php';
require_login();
$pdo = db();
$id = request_integer('id');
$d = null;
if ($id === null) {
    require_right('deals.write');
    $values = array_merge(array_fill_keys(DEAL_COLUMNS, null), ['custom_values' => [], 'value' => '', 'value_from_products' => false, 'status' => 'open']);
    $p = request_integer('pipeline');
    $pipeline = pipeline_from_request($pdo, $p === null ? 'default' : (string) $p);
    $values['pipeline_id'] = $pipeline['pipeline_id'];
    $values['stage_id'] = ($s = one_value($pdo, "SELECT stage_id FROM mcp_stages WHERE pipeline_id = :p AND kind = 'open' AND archived_at IS NULL ORDER BY position LIMIT 1", ['p' => $pipeline['pipeline_id']])) === null ? null : (int) $s;
    $contact = request_integer('contact');
    if ($contact !== null && ($c = one_row($pdo, 'SELECT contact_id, account_id FROM mcp_contacts WHERE contact_id = :c', ['c' => $contact])) !== null) {
        $values['primary_contact_id'] = (int) $c['contact_id'];
        $values['account_id'] = $c['account_id'] === null ? null : (int) $c['account_id'];
    }
    $account = request_integer('account');
    if ($account !== null && one_value($pdo, 'SELECT 1 FROM mcp_accounts WHERE account_id = :a', ['a' => $account]) !== null) { $values['account_id'] = $account; }
    $accountName = $values['account_id'] === null ? null : account_label($pdo, (int) $values['account_id']);
    $values['title'] = mb_substr(request_string('title'), 0, 200) ?: ($accountName !== null ? $accountName . ' — new business' : null);
    $v = str_replace([',', ' '], '', request_string('value'));
    $values['value'] = is_numeric($v) && (float) $v >= 0 ? $v : '';
    $close = request_date('close');
    $values['expected_close_date'] = is_string($close) ? $close : null;
    $values['owner_member_id'] = (int) current_member_id();
} else {
    $row = require_record($pdo, 'deal', $id);
    require_editable($pdo, 'deal', $row);
    $d = find_deal($pdo, $id);
    $values = deal_current($pdo, $id);
}
$pipelines = find_pipelines($pdo);
$stages = array_values(array_filter(pipeline_stages($pdo, (int) $values['pipeline_id']), static fn ($s) => $s['kind'] === 'open' || $s['stage_id'] === (int) $values['stage_id']));
$sources = find_sources($pdo, true);
$screen = $id === null ? 'deal-add' : 'deal-edit';
log_screen_view($pdo, $screen);
if (wants_json()) {
    respond_screen(['deal_id' => $id, 'values' => $values, 'pipelines' => $pipelines, 'stages' => array_map(static fn ($s) => ['stage_id' => $s['stage_id'], 'name' => $s['name'], 'kind' => $s['kind'], 'required_fields' => $s['required_fields']], $stages),
        'sources' => $sources, 'custom_fields' => custom_field_defs($pdo, 'deal', (int) $values['pipeline_id']), 'currency' => (string) ($values['currency'] ?? base_currency($pdo))]);
}
$restriction = null;
if ($id !== null && has_right('records.restrict')) {
    $restriction = ['kind' => 'deal', 'id' => $id, 'row' => $d, 'departments' => find_live_departments($pdo), 'back' => '/deals/' . $id . '/edit'];
}
render_screen($id === null ? 'New deal' : 'Edit ' . $d['title'], view('deals/form.php', ['d' => $d, 'values' => $values, 'pipelines' => $pipelines, 'stages' => $stages, 'sources' => $sources,
    'custom_html' => custom_fields_section($pdo, 'deal', $values['custom_values'], 'deal-form', [], (int) $values['pipeline_id']), 'restriction' => $restriction, 'may_tag' => has_right('tags.apply'),
    'may_owner' => $id === null || is_manager() || (int) ($values['owner_member_id'] ?? 0) === (int) current_member_id(), 'currency' => (string) ($values['currency'] ?? base_currency($pdo)),
    'focus' => request_string('focus')]), ['activeNav' => 'pipeline', 'screen' => $screen, 'entity' => 'deal', 'recordId' => $id === null ? '' : (string) $id]);
