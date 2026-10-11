<?php
declare(strict_types=1);
/** Action `source_save` (log source.save; sources.manage): `source` changes an existing lead source, none adds one. name (up to 60), kind inbound|outbound, active yes|no, sort_order. A field left out stays as it was. */
require_once dirname(__DIR__, 3) . '/app/bootstrap.php';
require_once dirname(__DIR__, 3) . '/app/features/vocab/write.php';
crm_handler_begin();
require_right('sources.manage');
$pdo = db();
$id = request_integer('source') ?? request_integer('id');
$cur = $id === null ? null : source_one($pdo, $id);
if ($id !== null && $cur === null) { refuse(404, 'Source not found.'); }
$errors = [];
$f = ['name' => crm_text('name', $cur['name'] ?? null, 60, 'The name', $errors, true), 'kind' => crm_choice('kind', $cur['kind'] ?? 'inbound', ['inbound', 'outbound'], 'The kind', $errors, false),
      'active' => crm_yes('active', $cur['active'] ?? true), 'sort_order' => crm_int('sort_order', $cur['sort_order'] ?? 100, 0, 100000, 'The order', $errors)];
if ($cur === null && $f['name'] === null && !isset($errors['name'])) { $errors['name'] = 'The name is required.'; }
if ($errors !== []) { crm_refuse_fields($errors); }
$r = crm_guard($pdo, static function () use ($pdo, $id, $f): array {
    $pdo->beginTransaction();
    $r = source_save($pdo, $id, $f);
    log_activity($pdo, 'source.save', 'lead_source', $r['id'], ['before' => $r['before'], 'after' => $r['after'] + ['name' => $f['name']]]);
    $pdo->commit();
    return $r;
});
crm_done(($id === null ? 'Added the source ' : 'Saved the source ') . $f['name'], $r['id'], crm_land('/admin/sources', $id === null ? 'created' : 'saved', 'source-row-' . $r['id']), 'sourceChanged');
