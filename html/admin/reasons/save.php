<?php
declare(strict_types=1);
/** Action `lost_reason_save` (log reason.save; sources.manage): `lost_reason` changes an existing reason, none adds one. kind lead|deal (fixed once made), label (up to 60), active yes|no, sort_order. A field left out stays as it was. */
require_once dirname(__DIR__, 3) . '/app/bootstrap.php';
require_once dirname(__DIR__, 3) . '/app/features/vocab/write.php';
crm_handler_begin();
require_right('sources.manage');
$pdo = db();
$id = request_integer('lost_reason') ?? request_integer('reason') ?? request_integer('id');
$cur = $id === null ? null : reason_one($pdo, $id);
if ($id !== null && $cur === null) { refuse(404, 'Reason not found.'); }
$errors = [];
$f = ['kind' => crm_choice('kind', $cur['kind'] ?? null, ['lead', 'deal'], 'The kind', $errors, false), 'label' => crm_text('label', $cur['label'] ?? null, 60, 'The reason', $errors, true),
      'active' => crm_yes('active', $cur['active'] ?? true), 'sort_order' => crm_int('sort_order', $cur['sort_order'] ?? 100, 0, 100000, 'The order', $errors)];
if ($cur === null && $f['kind'] === null && !isset($errors['kind'])) { $errors['kind'] = 'Say whether it is a lead reason or a deal reason.'; }
if ($cur === null && $f['label'] === null && !isset($errors['label'])) { $errors['label'] = 'The reason is required.'; }
if ($errors !== []) { crm_refuse_fields($errors); }
$r = crm_guard($pdo, static function () use ($pdo, $id, $f): array {
    $pdo->beginTransaction();
    $r = reason_save($pdo, $id, $f);
    log_activity($pdo, 'reason.save', 'lost_reason', $r['id'], ['before' => $r['before'], 'after' => $r['after'] + ['label' => $f['label']]]);
    $pdo->commit();
    return $r;
});
crm_done(($id === null ? 'Added the reason ' : 'Saved the reason ') . $f['label'], $r['id'], crm_land('/admin/sources', $id === null ? 'created' : 'saved', 'reason-row-' . $r['id']), 'reasonChanged');
