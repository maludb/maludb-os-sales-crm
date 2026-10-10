<?php
declare(strict_types=1);
/**
 * Actions `deal_create` (no `deal`; log deal.create — deals.write) and `deal_update` (`deal` + any field; log deal.update — the edit rule). Fields: title, account,
 * primary_contact, pipeline (the default), stage (an id or a name; the pipeline's first open stage), value, currency, expected_close_date, owner (the caller by
 * default), source, campaign, description, tags[] (create), custom_values; update adds probability_override (0–100 or blank for the stage's) and
 * value_from_products (yes / no). A field left out stays as it was. The database refuses a stage whose required fields the deal lacks — the fields named.
 * Won and Lost are their forms' (deal_won, deal_lost), never a stage set here.
 */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_once dirname(__DIR__, 2) . '/app/features/deals/write.php';
require_once dirname(__DIR__, 2) . '/app/features/tags/queries.php';
require_once dirname(__DIR__, 2) . '/app/features/tags/write.php';
crm_handler_begin();
$pdo = db();
$me = (int) current_member_id();
$id = request_integer('deal') ?? request_integer('id');
if ($id === null) {
    require_right('deals.write');
    $cur = array_merge(array_fill_keys(DEAL_COLUMNS, null), ['custom_values' => [], 'value' => '0', 'value_from_products' => false, 'status' => 'open']);
} else {
    $row = require_record($pdo, 'deal', $id);
    require_editable($pdo, 'deal', $row);
    $cur = deal_current($pdo, $id);
}
$errors = [];
$f = [];
$f['title'] = crm_text('title', $cur['title'], 200, 'The title', $errors, true);
if ($id === null && $f['title'] === null && !isset($errors['title'])) { $errors['title'] = 'The title is required.'; }
$f['account_id'] = crm_ref($pdo, 'account', $cur['account_id'], 'SELECT 1 FROM mcp_accounts WHERE account_id = :id', 'account', $errors);
$f['primary_contact_id'] = crm_ref($pdo, 'primary_contact', $cur['primary_contact_id'], 'SELECT 1 FROM mcp_contacts WHERE contact_id = :id', 'contact', $errors);
// The pipeline: given, else the deal's, else the default. The stage: an id or a name within it; a new pipeline without a stage → its first open stage.
$f['pipeline_id'] = crm_ref($pdo, 'pipeline', $cur['pipeline_id'], 'SELECT 1 FROM mcp_pipelines WHERE pipeline_id = :id AND archived_at IS NULL', 'pipeline', $errors, false)
    ?? (int) pipeline_from_request($pdo, 'default')['pipeline_id'];
$stage = $cur['stage_id'];
$pipelineChanged = $f['pipeline_id'] !== $cur['pipeline_id'];
$askedStage = req_has('stage') && (string) req_val('stage') !== '' ? stage_resolve($pdo, $f['pipeline_id'], (string) req_val('stage')) : null;
if ($askedStage !== null && $pipelineChanged && $cur['pipeline_id'] !== null && (int) $askedStage['pipeline_id'] === $cur['pipeline_id']) {
    unset($_POST['stage']);                                   // the form still names the old pipeline's stage: the new pipeline's first open stage
}
if (req_has('stage') && (string) req_val('stage') !== '') {
    $s = $askedStage;
    if ($s === null || (int) $s['pipeline_id'] !== $f['pipeline_id']) { $errors['stage'] = 'Choose a stage of the deal\'s pipeline.'; }
    elseif ($s['archived_at'] !== null) { $errors['stage'] = 'That stage is archived.'; }
    elseif ($s['kind'] !== 'open' && (int) $s['stage_id'] !== (int) $cur['stage_id']) { $errors['stage'] = $s['kind'] === 'won' ? 'Mark it won with the Won form.' : 'Mark it lost with the Lost form — it asks for the reason.'; }
    else { $stage = (int) $s['stage_id']; }
} elseif ($stage === null || $pipelineChanged) {
    $first = one_value($pdo, "SELECT stage_id FROM mcp_stages WHERE pipeline_id = :p AND kind = 'open' AND archived_at IS NULL ORDER BY position LIMIT 1", ['p' => $f['pipeline_id']]);
    $stage = $first === null ? null : (int) $first;
    if ($stage === null) { $errors['pipeline'] = 'That pipeline has no open stage.'; }
}
if ($id !== null && $cur['status'] !== 'open' && $f['pipeline_id'] !== $cur['pipeline_id']) { $errors['pipeline'] = 'Reopen the deal before moving it to another pipeline.'; }
$f['stage_id'] = $stage;
if (req_has('value')) {
    $v = str_replace([',', ' '], '', (string) req_val('value'));
    if ($v === '') { $f['value'] = '0'; }
    elseif (!is_numeric($v) || (float) $v < 0 || (float) $v >= 1e12) { $errors['value'] = 'The value is an amount of 0 or more.'; $f['value'] = $cur['value']; }
    else { $f['value'] = $v; }
} else {
    $f['value'] = $cur['value'];
}
$currency = crm_text('currency', $cur['currency'], 3, 'The currency', $errors);
if ($currency !== null && !preg_match('/^[A-Za-z]{3}$/', $currency)) { $errors['currency'] = 'The currency is a three-letter code like USD.'; }
$f['currency'] = $currency === null ? null : strtoupper($currency);
$f['value_from_products'] = crm_yes('value_from_products', $cur['value_from_products']);
$f['probability_override'] = crm_int('probability_override', $cur['probability_override'], 0, 100, 'The probability', $errors, true);
if (req_has('expected_close_date')) {
    $d = (string) req_val('expected_close_date');
    if ($d === '') { $f['expected_close_date'] = null; }
    elseif (($dt = DateTimeImmutable::createFromFormat('!Y-m-d', $d)) === false || $dt->format('Y-m-d') !== $d) { $errors['expected_close_date'] = 'The close date is a date (YYYY-MM-DD).'; $f['expected_close_date'] = $cur['expected_close_date']; }
    else { $f['expected_close_date'] = $d; }
} else {
    $f['expected_close_date'] = $cur['expected_close_date'];
}
$f['owner_member_id'] = crm_ref($pdo, 'owner', $id === null ? $me : $cur['owner_member_id'], 'SELECT 1 FROM mcp_members WHERE member_id = :id', 'owner', $errors);
if ($id !== null && $f['owner_member_id'] !== $cur['owner_member_id'] && !is_manager() && $cur['owner_member_id'] !== $me) { $errors['owner'] = 'Only a manager gives away someone else\'s deal.'; }
$f['source_id'] = crm_ref($pdo, 'source', $cur['source_id'], 'SELECT 1 FROM mcp_lead_sources WHERE source_id = :id', 'source', $errors);
$f['campaign_id'] = crm_ref($pdo, 'campaign', $cur['campaign_id'], 'SELECT 1 FROM mcp_campaigns WHERE campaign_id = :id', 'campaign', $errors);
$f['description'] = crm_text('description', $cur['description'], 10000, 'The description', $errors);
$custom = custom_values_from_request($pdo, 'deal', $cur['custom_values'], $f['pipeline_id']);
$f['custom_values'] = $custom ?? $cur['custom_values'];
$tags = $id === null ? (request_list('tags') ?? []) : [];
if ($tags !== [] && !has_right('tags.apply')) { $errors['tags'] = 'You may not tag this.'; }
if ($errors !== []) {
    crm_refuse_fields($errors);
}
if ($id === null) {
    $f = array_filter($f, static fn ($v) => $v !== null);
    $newId = deal_guard($pdo, static function () use ($pdo, $f, $me, $tags): int {
        $pdo->beginTransaction();
        $newId = deal_save($pdo, null, $f, $me);
        $labels = [];
        foreach ($tags as $t) { [$tid, $label] = tag_ensure($pdo, $t); tag_apply($pdo, 'deal', [$newId], $tid); $labels[] = $label; }
        $d = deal_current($pdo, $newId);
        log_activity($pdo, 'deal.create', 'deal', $newId, ['deal_id' => $newId, 'account_id' => $d['account_id'], 'contact_id' => $d['primary_contact_id'],
            'after' => ['number' => $d['number'], 'title' => $d['title'], 'pipeline_id' => $d['pipeline_id'], 'stage_id' => $d['stage_id'], 'value' => (float) $d['value'], 'currency' => $d['currency'],
                'expected_close_date' => $d['expected_close_date'], 'owner_member_id' => $d['owner_member_id'], 'tags' => $labels]]);
        $pdo->commit();
        return $newId;
    });
    crm_done('Added the deal ' . $f['title'], $newId, crm_land(return_path('/deals/' . $newId), 'created'), 'dealChanged');
}
$diff = crm_diff($cur, array_intersect_key($f, $cur));
if ($diff['after'] !== []) {
    deal_guard($pdo, static function () use ($pdo, $id, $diff, $f): void {
        $pdo->beginTransaction();
        deal_save($pdo, $id, $diff['after'], (int) current_member_id());
        $b = $diff['before'];
        $a = $diff['after'];
        $saved = deal_current($pdo, $id);
        foreach (array_keys($a) as $k) { if (array_key_exists($k, $saved) && $k !== 'custom_values' && $k !== 'description') { $a[$k] = $saved[$k]; } }
        foreach (['description', 'custom_values'] as $k) { if (array_key_exists($k, $a)) { $b[$k] = '(changed)'; $a[$k] = $k === 'custom_values' ? array_keys($f['custom_values']) : '(changed)'; } }
        log_activity($pdo, 'deal.update', 'deal', $id, ['deal_id' => $id, 'account_id' => $saved['account_id'], 'contact_id' => $saved['primary_contact_id'], 'before' => $b, 'after' => $a + ['title' => $saved['title']]]);
        $pdo->commit();
    });
}
crm_done($diff['after'] === [] ? 'Nothing to change' : 'Saved the deal ' . $f['title'], $id, crm_land(return_path('/deals/' . $id), 'updated'), 'dealChanged');
