<?php
declare(strict_types=1);
/**
 * GET /leads/{id}/convert — screen `lead-convert`: what the conversion will find and make, before it does it (leads-and-conversion.md §3).
 * POST — action `lead_convert` (log lead.convert {account_id, contact_id, deal_id, account_made, contact_made} — leads.convert and the edit rule): `account` / `contact` (an
 * existing record — found when omitted; `account_new` / `contact_new` yes forces a new one only when nothing is found), `make_deal` (yes by default), `pipeline`, `deal_title`,
 * `value`, `close_date`. crm_lead_convert() does it in one transaction; the answer names the three; record_id is the deal, else the contact, else the account.
 */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_once dirname(__DIR__, 2) . '/app/features/records/queries.php';
require_once dirname(__DIR__, 2) . '/app/features/accounts/queries.php';
require_once dirname(__DIR__, 2) . '/app/features/contacts/queries.php';
require_once dirname(__DIR__, 2) . '/app/features/leads/queries.php';
require_once dirname(__DIR__, 2) . '/app/features/leads/write.php';
require_login();
$pdo = db();
$id = request_integer('lead') ?? request_integer('id');
$row = require_record($pdo, 'lead', $id);
$lead = find_lead($pdo, $id);
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    require_right('leads.convert');
    require_editable($pdo, 'lead', $row);
    if ($lead['converted_at'] !== null) { redirect('/leads/' . $id . '?notice=converted'); }
    if ($lead['status'] === 'unqualified') { refuse(422, 'An unqualified lead is not converted; requalify it first.'); }
    $preview = convert_preview($pdo, $id);
    $pipelines = $pdo->query('SELECT pipeline_id, name, currency FROM mcp_pipelines WHERE archived_at IS NULL ORDER BY position, name')->fetchAll();
    log_screen_view($pdo, 'lead-convert');
    if (wants_json()) {
        respond_screen(['lead_id' => $id, 'label' => $lead['label'], 'preview' => $preview, 'pipelines' => $pipelines]);
    }
    render_screen('Convert ' . $lead['label'], view('leads/convert.php', ['lead' => $lead, 'preview' => $preview, 'pipelines' => $pipelines]),
        ['activeNav' => 'leads', 'screen' => 'lead-convert', 'entity' => 'lead', 'recordId' => (string) $id]);
    exit;
}
crm_handler_begin();
require_right('leads.convert');
require_editable($pdo, 'lead', $row);
if ($lead['converted_at'] !== null) { refuse(422, 'The lead was converted already.'); }
$errors = [];
$choices = [];
$choices['account'] = crm_ref($pdo, 'account', null, 'SELECT 1 FROM mcp_accounts WHERE account_id = :id', 'account', $errors);
$choices['contact'] = crm_ref($pdo, 'contact', null, 'SELECT 1 FROM mcp_contacts WHERE contact_id = :id', 'contact', $errors);
$choices['make_deal'] = crm_yes('make_deal', true);
$choices['pipeline'] = crm_ref($pdo, 'pipeline', null, 'SELECT 1 FROM mcp_pipelines WHERE pipeline_id = :id AND archived_at IS NULL', 'pipeline', $errors);
$choices['deal_title'] = crm_text('deal_title', null, 200, 'The deal\'s title', $errors);
if (req_has('value') && (string) req_val('value') !== '') {
    $v = str_replace([',', ' '], '', (string) req_val('value'));
    if (!is_numeric($v) || (float) $v < 0) { $errors['value'] = 'The value is an amount of 0 or more.'; } else { $choices['value'] = $v; }
}
if (req_has('close_date') && (string) req_val('close_date') !== '') {
    $d = DateTimeImmutable::createFromFormat('!Y-m-d', (string) req_val('close_date'));
    if ($d === false || $d->format('Y-m-d') !== (string) req_val('close_date')) { $errors['close_date'] = 'The close date is a date (YYYY-MM-DD).'; } else { $choices['close_date'] = (string) req_val('close_date'); }
}
if ($errors !== []) { crm_refuse_fields($errors); }
$r = crm_guard($pdo, static function () use ($pdo, $id, $choices): array {
    $pdo->beginTransaction();
    $r = lead_convert($pdo, $id, $choices, (int) current_member_id());
    log_activity($pdo, 'lead.convert', 'lead', $id, ['account_id' => $r['account_id'], 'contact_id' => $r['contact_id'], 'deal_id' => $r['deal_id'], 'after' => $r]);
    $pdo->commit();
    return $r;
});
$names = array_filter([$r['account_id'] !== null ? ($r['account_made'] ? 'made' : 'found') . ' the account ' . account_label($pdo, $r['account_id']) : null,
    $r['contact_id'] !== null ? ($r['contact_made'] ? 'made' : 'found') . ' the contact ' . contact_label($pdo, $r['contact_id']) : null,
    $r['deal_id'] !== null ? 'made a deal' : null]);
$land = $r['deal_id'] !== null && nav_built('/deals/view.php') ? '/deals/' . $r['deal_id'] : ($r['contact_id'] !== null ? '/contacts/' . $r['contact_id'] : '/accounts/' . $r['account_id']);
crm_done('Converted: ' . implode(', ', $names), $r['deal_id'] ?? $r['contact_id'] ?? $r['account_id'], crm_land($land, 'converted'), 'leadChanged', ['converted' => $r]);
