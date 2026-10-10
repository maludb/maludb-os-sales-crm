<?php
declare(strict_types=1);
/** /leads/new and /leads/{id}/edit (screens `lead-add`, `lead-edit`; prefill params first_name, last_name, company, email, source, campaign). A converted lead has no edit form. */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_once dirname(__DIR__, 2) . '/app/features/records/queries.php';
require_once dirname(__DIR__, 2) . '/app/features/accounts/queries.php';
require_once dirname(__DIR__, 2) . '/app/features/contacts/queries.php';
require_once dirname(__DIR__, 2) . '/app/features/leads/queries.php';
require_once dirname(__DIR__, 2) . '/app/features/leads/write.php';
require_once dirname(__DIR__, 2) . '/app/features/custom_fields/queries.php';
require_once dirname(__DIR__, 2) . '/app/features/custom_fields/form.php';
require_login();
$pdo = db();
$id = request_integer('id');
$l = null;
if ($id === null) {
    require_right('leads.write');
    $values = array_merge(array_fill_keys(LEAD_COLUMNS, null), ['custom_values' => []]);
    foreach (['first_name' => 100, 'last_name' => 100, 'company' => 200] as $k => $max) { $values[$k] = mb_substr(request_string($k), 0, $max) ?: null; }
    $values['email'] = filter_var(request_string('email'), FILTER_VALIDATE_EMAIL) ?: null;
    $values['source_id'] = request_integer('source') ?? (int) (one_value($pdo, "SELECT source_id FROM mcp_lead_sources WHERE name = 'Outbound'") ?: 0) ?: null;
    $values['campaign_id'] = request_integer('campaign');
} else {
    $row = require_record($pdo, 'lead', $id);
    require_editable($pdo, 'lead', $row);
    $l = find_lead($pdo, $id);
    if ($l['converted_at'] !== null) { refuse(422, 'A converted lead is read-only — change its account, contact or deal instead.'); }
    $values = lead_current($pdo, $id);
}
$screen = $id === null ? 'lead-add' : 'lead-edit';
log_screen_view($pdo, $screen);
if (wants_json()) {
    respond_screen(['lead_id' => $id, 'values' => $values, 'sources' => find_sources($pdo), 'custom_fields' => custom_field_defs($pdo, 'lead')]);
}
$restriction = $id !== null && has_right('records.restrict') ? ['kind' => 'lead', 'id' => $id, 'row' => $l, 'departments' => find_live_departments($pdo), 'back' => '/leads/' . $id . '/edit'] : null;
render_screen($id === null ? 'New lead' : 'Edit ' . $l['label'], view('leads/form.php', ['l' => $l, 'values' => $values, 'sources' => find_sources($pdo), 'may_tag' => has_right('tags.apply'),
    'custom_html' => custom_fields_section($pdo, 'lead', $values['custom_values'], 'lead-form'), 'restriction' => $restriction]),
    ['activeNav' => 'leads', 'screen' => $screen, 'entity' => 'lead', 'recordId' => $id === null ? '' : (string) $id]);
