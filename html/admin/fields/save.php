<?php
declare(strict_types=1);
/**
 * Action `custom_field_save` (log field.save {applies_to, key, label, field_type, …}; pauses for an agent as `other`; fields.manage): `field` changes an existing definition, none adds one. applies_to lead|account|contact|deal|activity and
 * key (a slug, up to 40) are fixed once made; label (up to 60); field_type text|number|date|select|multi_select|checkbox|url|email|money|member (a field with values keeps its type); options[] (a line each — a choice field needs them);
 * pipelines[] and required_to_win (deals only); sort_order; archived (hidden from the forms, its values stay). A field left out stays as it was.
 */
require_once dirname(__DIR__, 3) . '/app/bootstrap.php';
require_once dirname(__DIR__, 3) . '/app/features/custom_fields/write.php';
crm_handler_begin();
require_right('fields.manage');
$pdo = db();
$id = request_integer('field') ?? request_integer('id');
$cur = $id === null ? null : field_one($pdo, $id);
if ($id !== null && $cur === null) { refuse(404, 'Field not found.'); }
$errors = [];
$kind = crm_choice('applies_to', $cur['applies_to'] ?? null, array_keys(FIELD_KINDS), 'The record kind', $errors, false);
if ($cur === null && $kind === null && !isset($errors['applies_to'])) { $errors['applies_to'] = 'Say which record the field is for.'; }
$key = $cur['key'] ?? null;
if ($cur === null || req_has('key')) {
    $given = strtolower((string) req_val('key'));
    if ($cur === null && $given === '' && req_has('label')) { $given = strtolower(trim((string) preg_replace('/[^a-z0-9]+/i', '_', (string) req_val('label')), '_')); }
    if ($cur === null || $given !== '') {
        if (!preg_match('/^[a-z][a-z0-9_]{0,39}$/', $given)) { $errors['key'] = 'The key is lower-case letters, digits and underscores, starting with a letter (up to 40).'; }
        else { $key = $given; }
    }
}
$f = ['applies_to' => $kind, 'key' => $key, 'label' => crm_text('label', $cur['label'] ?? null, 60, 'The label', $errors, true), 'field_type' => crm_choice('field_type', $cur['field_type'] ?? 'text', array_keys(FIELD_TYPES), 'The type', $errors, false),
      'sort_order' => crm_int('sort_order', $cur['sort_order'] ?? 100, 0, 100000, 'The order', $errors), 'required_to_win' => crm_yes('required_to_win', $cur['required_to_win'] ?? false), 'archived' => crm_yes('archived', $cur !== null && $cur['archived_at'] !== null)];
if ($cur === null && $f['label'] === null && !isset($errors['label'])) { $errors['label'] = 'The label is required.'; }
if (req_has('options')) {
    $o = $_POST['options'];
    $f['options'] = array_values(array_filter(array_map('trim', is_array($o) ? $o : preg_split('/\R|;/', (string) $o)), static fn ($x): bool => $x !== ''));
}
if (req_has('pipelines') || req_has('pipelines_present')) {
    $f['pipelines'] = array_map('intval', array_filter(request_list('pipelines') ?? [], 'ctype_digit'));
}
if ($errors !== []) { crm_refuse_fields($errors); }
$r = crm_guard($pdo, static function () use ($pdo, $id, $f): array {
    $pdo->beginTransaction();
    $r = field_save($pdo, $id, $f);
    log_activity($pdo, 'field.save', 'custom_field', $r['id'], ['before' => $r['before'], 'after' => $r['after'] + ['applies_to' => $r['applies_to'] ?? $f['applies_to'], 'key' => $r['key'] ?? $f['key'], 'label' => $f['label']]]);
    $pdo->commit();
    return $r;
});
crm_done(($id === null ? 'Added the field ' : 'Saved the field ') . $f['label'], $r['id'], crm_land('/admin/fields?kind=' . rawurlencode((string) ($r['applies_to'] ?? $f['applies_to'])), $id === null ? 'created' : 'saved', 'field-' . $r['id']), 'fieldChanged');
