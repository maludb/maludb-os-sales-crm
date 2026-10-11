<?php
declare(strict_types=1);
/**
 * Actions `campaign_create` (no `campaign`; log campaign.create) and `campaign_update` (`campaign` + any field of campaign_create; log campaign.update) — campaigns.manage. A field left out stays as it
 * was; an emptied one is cleared. The database refuses an end before the start and a negative budget.
 */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_once dirname(__DIR__, 2) . '/app/features/records/queries.php';
require_once dirname(__DIR__, 2) . '/app/features/campaigns/queries.php';
require_once dirname(__DIR__, 2) . '/app/features/campaigns/write.php';
crm_handler_begin();
require_right('campaigns.manage');
$pdo = db();
$me = (int) current_member_id();
$id = request_integer('campaign') ?? request_integer('id');
if ($id === null) {
    $cur = array_fill_keys(CAMPAIGN_COLUMNS, null);
    $cur['kind'] = 'other';
    $cur['status'] = 'planned';
} else {
    require_record($pdo, 'campaign', $id);
    $cur = campaign_current($pdo, $id);
}
$errors = [];
$f = [];
$f['name'] = crm_text('name', $cur['name'], 200, 'The name', $errors, true);
if ($id === null && $f['name'] === null && !isset($errors['name'])) { $errors['name'] = 'The name is required.'; }
$f['kind'] = crm_choice('kind', $cur['kind'], array_keys(CAMPAIGN_KINDS), 'The kind', $errors, false);
$f['status'] = crm_choice('status', $cur['status'], array_keys(CAMPAIGN_STATUSES), 'The status', $errors, false);
foreach (['starts_on' => 'The start date', 'ends_on' => 'The end date'] as $k => $label) {
    $f[$k] = $cur[$k];
    if (req_has($k)) {
        $v = (string) req_val($k);
        if ($v === '') { $f[$k] = null; }
        elseif (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $v, $m) || !checkdate((int) $m[2], (int) $m[3], (int) $m[1])) { $errors[$k] = $label . ' is year-month-day, like 2026-11-30.'; }
        else { $f[$k] = $v; }
    }
}
if ($f['starts_on'] !== null && $f['ends_on'] !== null && $f['ends_on'] < $f['starts_on']) { $errors['ends_on'] = 'The end date is on or after the start date.'; }
$f['budget'] = $cur['budget'];
if (req_has('budget')) {
    $b = str_replace([',', ' '], '', (string) req_val('budget'));
    if ($b === '') { $f['budget'] = null; }
    elseif (!is_numeric($b) || (float) $b < 0) { $errors['budget'] = 'The budget is an amount of 0 or more.'; }
    else { $f['budget'] = $b; }
}
$currency = crm_text('currency', $cur['currency'], 3, 'The currency', $errors);
if ($currency !== null && !preg_match('/^[A-Za-z]{3}$/', $currency)) { $errors['currency'] = 'The currency is a three-letter code like USD.'; }
$f['currency'] = $currency === null ? null : strtoupper($currency);
$f['expected_leads'] = crm_int('expected_leads', $cur['expected_leads'] === null ? null : (int) $cur['expected_leads'], 0, 10000000, 'The expected leads', $errors, true);
$f['utm_campaign'] = crm_text('utm_campaign', $cur['utm_campaign'], 120, 'The utm campaign', $errors);
$f['owner_member_id'] = crm_ref($pdo, 'owner', $id === null ? $me : $cur['owner_member_id'], 'SELECT 1 FROM mcp_members WHERE member_id = :id', 'owner', $errors);
$f['description'] = crm_text('description', $cur['description'], 5000, 'The description', $errors);
if ($errors !== []) { crm_refuse_fields($errors); }
if ($id === null) {
    $newId = crm_guard($pdo, static function () use ($pdo, $f, $me): int {
        $pdo->beginTransaction();
        $nid = campaign_save($pdo, null, array_filter($f, static fn ($v) => $v !== null), $me);
        log_activity($pdo, 'campaign.create', 'campaign', $nid, ['after' => ['name' => $f['name'], 'kind' => $f['kind'], 'status' => $f['status'], 'owner_member_id' => $f['owner_member_id'], 'budget' => $f['budget']]]);
        $pdo->commit();
        return $nid;
    });
    crm_done('Added the campaign ' . $f['name'], $newId, crm_land('/campaigns/' . $newId, 'created'), 'campaignChanged');
}
$diff = crm_diff($cur, $f);
if ($diff['after'] !== []) {
    crm_guard($pdo, static function () use ($pdo, $id, $diff, $f): void {
        $pdo->beginTransaction();
        campaign_save($pdo, $id, $diff['after'], (int) current_member_id());
        $b = $diff['before'];
        $a = $diff['after'];
        foreach (['description'] as $k) { if (array_key_exists($k, $a)) { $b[$k] = '(changed)'; $a[$k] = '(changed)'; } }
        log_activity($pdo, 'campaign.update', 'campaign', $id, ['before' => $b, 'after' => $a + ['name' => $f['name']]]);
        $pdo->commit();
    });
}
crm_done($diff['after'] === [] ? 'Nothing to change' : 'Saved the campaign ' . $f['name'], $id, crm_land(return_path('/campaigns/' . $id), 'updated'), 'campaignChanged');
