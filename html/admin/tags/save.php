<?php
declare(strict_types=1);
/** Action `tag_save` (log tag.save {label before and after}; tags.manage): `tag` and its new `label` (up to 60 characters) — the slug follows, the records are re-indexed under the new name, a saved view or smart list filtering by the old name follows. A name another tag has is a merge, not a rename. */
require_once dirname(__DIR__, 3) . '/app/bootstrap.php';
require_once dirname(__DIR__, 3) . '/app/features/tags/queries.php';
require_once dirname(__DIR__, 3) . '/app/features/tags/write.php';
crm_handler_begin();
require_right('tags.manage');
$pdo = db();
$id = request_integer('tag') ?? request_integer('id');
$cur = $id === null ? null : one_row($pdo, 'SELECT id, label FROM tags WHERE id = :id', ['id' => $id]);
if ($cur === null) { refuse(404, 'Tag not found.'); }
$errors = [];
$label = crm_text('label', $cur['label'], 60, 'The name', $errors, true);
if ($errors !== []) { crm_refuse_fields($errors); }
$r = crm_guard($pdo, static function () use ($pdo, $id, $label): array {
    $pdo->beginTransaction();
    $r = tag_save($pdo, $id, (string) $label);
    if ($r['after'] !== []) {
        log_activity($pdo, 'tag.save', 'tag', $id, ['before' => $r['before'], 'after' => $r['after'] + ['reindexed' => $r['reindexed']]]);
    }
    $pdo->commit();
    return $r;
});
crm_done($r['after'] === [] ? 'Nothing to change' : 'Renamed the tag to ' . $label, $id, crm_land('/admin/tags', 'saved', 'tag-' . $id), 'tagChanged');
