<?php
declare(strict_types=1);
/**
 * Action `bulk_update` (log bulk.update {kind, count, filters?}; confirm; PAUSES FOR AN AGENT — other; records.bulk): `kind`, `field` (one column of the whitelist — source, campaign, country, title, role,
 * preferred_channel, kind, industry, employees, expected_close_date, by kind — or custom:<key>), `value` (empty clears it where it may be cleared), and what to act on — `records[]` or `list` or `filters`.
 */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_once dirname(__DIR__, 2) . '/app/features/bulk/select.php';
require_once dirname(__DIR__, 2) . '/app/features/bulk/write.php';
crm_handler_begin();
require_right('records.bulk');
$pdo = db();
$kind = record_kind_from_request(array_keys(FILTER_KINDS));
$errors = [];
$field = req_val('field') ?? '';
if ($field === '') { $errors['field'] = 'Say which field to set.'; }
elseif (!array_key_exists($field, bulk_field_choices($pdo, $kind))) { $errors['field'] = 'A ' . RECORD_KINDS[$kind]['noun'] . '\'s ' . str_replace('_', ' ', $field) . ' cannot be set in bulk. Choose one of: ' . implode(', ', array_keys(bulk_field_choices($pdo, $kind))) . '.'; }
if (!req_has('value')) { $errors['value'] = 'Say the new value (empty clears it).'; }
$value = req_val('value');
if ($errors === [] && !str_starts_with($field, 'custom:')) {
    try { bulk_field_value($pdo, $kind, $field, $value); } catch (DomainException $e) { $errors['value'] = $e->getMessage(); }
}
if ($errors !== []) { crm_refuse_fields($errors); }
[$sel, $changed] = crm_guard($pdo, static function () use ($pdo, $kind, $field, $value): array {
    $sel = bulk_select($pdo, $kind, $_POST);
    bulk_require_editable($pdo, $kind, $sel['ids']);
    $pdo->beginTransaction();
    $changed = bulk_update($pdo, $kind, $sel['ids'], $field, $value);
    log_activity($pdo, 'bulk.update', null, null, ['after' => ['kind' => $kind, 'count' => count($changed), 'selected' => count($sel['ids']), 'source' => $sel['source'], 'list_id' => $sel['list'], 'filters' => $sel['filters'],
        'field' => $field, 'value_set' => $value !== null && trim($value) !== '', 'record_ids' => $changed]]);
    $pdo->commit();
    return [$sel, $changed];
});
bulk_done('update', $kind, $sel, count($changed), 'Set ' . str_replace('_', ' ', $field) . ' on ' . count($changed) . ' ' . RECORD_KINDS[$kind]['noun'] . (count($changed) === 1 ? '' : 's'), ['changed' => $changed, 'field' => $field]);
