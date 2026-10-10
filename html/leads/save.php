<?php
declare(strict_types=1);
/**
 * Actions `lead_create` (no `lead`; log lead.create — leads.write) and `lead_update` (`lead` + any field of lead_create; log lead.update — the edit rule;
 * a converted lead is read-only). A company, a person or an email is required. The source is Outbound when none is given; the owner is the rules'
 * (crm_assign_lead, on insert) when none is given — none matching leaves it in the inbox's unowned list. A lead's message is never logged.
 */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_once dirname(__DIR__, 2) . '/app/features/records/queries.php';
require_once dirname(__DIR__, 2) . '/app/features/accounts/queries.php';
require_once dirname(__DIR__, 2) . '/app/features/contacts/queries.php';
require_once dirname(__DIR__, 2) . '/app/features/leads/queries.php';
require_once dirname(__DIR__, 2) . '/app/features/leads/write.php';
require_once dirname(__DIR__, 2) . '/app/features/custom_fields/queries.php';
require_once dirname(__DIR__, 2) . '/app/features/tags/queries.php';
require_once dirname(__DIR__, 2) . '/app/features/tags/write.php';
crm_handler_begin();
$pdo = db();
$me = (int) current_member_id();
$id = request_integer('lead') ?? request_integer('id');
if ($id === null) {
    require_right('leads.write');
    $cur = array_merge(array_fill_keys(LEAD_COLUMNS, null), ['custom_values' => [], 'utm' => [], 'converted_at' => null]);
} else {
    $row = require_record($pdo, 'lead', $id);
    require_editable($pdo, 'lead', $row);
    $cur = lead_current($pdo, $id);
    if ($cur['converted_at'] !== null) {
        refuse(422, 'A converted lead is read-only — change its account, contact or deal instead.');
    }
}
$errors = [];
$f = [];
foreach (['first_name' => [100, 'The first name'], 'last_name' => [100, 'The last name'], 'company' => [200, 'The company'], 'title' => [150, 'The title'], 'phone' => [40, 'The phone'],
          'website' => [300, 'The website'], 'address' => [500, 'The address'], 'city' => [120, 'The city'], 'region' => [120, 'The region'], 'message' => [2000, 'The message']] as $k => [$max, $label]) {
    $f[$k] = crm_text($k, $cur[$k], $max, $label, $errors);
}
$f['email'] = crm_email('email', $cur['email'], 'The email', $errors);
$country = crm_text('country', $cur['country'], 2, 'The country', $errors);
if ($country !== null && !preg_match('/^[A-Za-z]{2}$/', $country)) { $errors['country'] = 'The country is a two-letter code like US or DE.'; }
$f['country'] = $country === null ? null : strtoupper($country);
$outbound = $id === null ? (int) (one_value($pdo, "SELECT source_id FROM mcp_lead_sources WHERE name = 'Outbound'") ?: 0) : null;
$f['source_id'] = crm_ref($pdo, 'source', $id === null ? ($outbound ?: null) : $cur['source_id'], 'SELECT 1 FROM mcp_lead_sources WHERE source_id = :id', 'source', $errors);
$f['campaign_id'] = crm_ref($pdo, 'campaign', $cur['campaign_id'], 'SELECT 1 FROM mcp_campaigns WHERE campaign_id = :id', 'campaign', $errors);
$ownerGiven = req_has('owner');
$f['owner_member_id'] = crm_ref($pdo, 'owner', $cur['owner_member_id'], 'SELECT 1 FROM mcp_members WHERE member_id = :id', 'owner', $errors);
$custom = custom_values_from_request($pdo, 'lead', $cur['custom_values']);
$f['custom_values'] = $custom ?? $cur['custom_values'];
if ($f['first_name'] === null && $f['last_name'] === null && $f['company'] === null && $f['email'] === null && $errors === []) {
    $errors['company'] = 'A lead needs a company, a name or an email address.';
}
$tags = $id === null ? (request_list('tags') ?? []) : [];
if ($tags !== [] && !has_right('tags.apply')) { $errors['tags'] = 'You may not tag this.'; }
if ($errors !== []) {
    crm_refuse_fields($errors);
}
if ($id === null) {
    $ins = array_filter($f, static fn ($v) => $v !== null && $v !== []);
    if (!$ownerGiven) { unset($ins['owner_member_id']); }                    // the rules decide (the trigger)
    $newId = crm_guard($pdo, static function () use ($pdo, $ins, $me, $tags): int {
        $pdo->beginTransaction();
        $newId = lead_save($pdo, null, $ins, $me);
        $labels = [];
        foreach ($tags as $t) { [$tid, $tl] = tag_ensure($pdo, $t); tag_apply($pdo, 'lead', [$newId], $tid); $labels[] = $tl; }
        $saved = lead_current($pdo, $newId);
        log_activity($pdo, 'lead.create', 'lead', $newId, ['after' => ['label' => (string) lead_label($pdo, $newId), 'email' => $saved['email'], 'company' => $saved['company'], 'source_id' => $saved['source_id'],
            'campaign_id' => $saved['campaign_id'], 'owner_member_id' => $saved['owner_member_id'], 'owner_by_rules' => !array_key_exists('owner_member_id', $ins), 'tags' => $labels]]);
        $pdo->commit();
        return $newId;
    });
    $owner = one_value($pdo, 'SELECT owner_member_id FROM leads WHERE id = :id', ['id' => $newId]);
    crm_done('Added the lead ' . lead_label($pdo, $newId) . ($owner === null ? ' — unowned, in the inbox' : ''), $newId, crm_land('/leads/' . $newId, 'created'), 'leadChanged',
        ['owner_member_id' => $owner === null ? null : (int) $owner]);
}
$diff = crm_diff(array_intersect_key($cur, $f), $f);
if ($diff['after'] !== []) {
    crm_guard($pdo, static function () use ($pdo, $id, $diff, $f): void {
        $pdo->beginTransaction();
        lead_save($pdo, $id, $diff['after'], (int) current_member_id());
        $b = $diff['before'];
        $a = $diff['after'];
        foreach (['message', 'address'] as $k) { if (array_key_exists($k, $a)) { $b[$k] = '(changed)'; $a[$k] = '(changed)'; } }
        if (array_key_exists('custom_values', $a)) { $b['custom_values'] = '(changed)'; $a['custom_values'] = array_keys($f['custom_values']); }
        log_activity($pdo, 'lead.update', 'lead', $id, ['before' => $b, 'after' => $a + ['label' => (string) lead_label($pdo, $id)]]);
        $pdo->commit();
    });
}
crm_done($diff['after'] === [] ? 'Nothing to change' : 'Saved the lead ' . lead_label($pdo, $id), $id, crm_land(return_path('/leads/' . $id), 'updated'), 'leadChanged');
