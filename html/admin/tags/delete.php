<?php
declare(strict_types=1);
/** Action `tag_delete` (log tag.delete {label, removed}; confirm; pauses for an agent as `deletion`; tags.manage): the tag is taken off every record, then deleted. */
require_once dirname(__DIR__, 3) . '/app/bootstrap.php';
require_once dirname(__DIR__, 3) . '/app/features/tags/queries.php';
require_once dirname(__DIR__, 3) . '/app/features/tags/write.php';
crm_handler_begin();
require_right('tags.manage');
$pdo = db();
$id = request_integer('tag') ?? request_integer('id');
if ($id === null) { refuse(404, 'Tag not found.'); }
$r = crm_guard($pdo, static function () use ($pdo, $id): array {
    $pdo->beginTransaction();
    $r = tag_delete($pdo, $id);
    log_activity($pdo, 'tag.delete', 'tag', $id, ['before' => ['label' => $r['label'], 'removed' => $r['removed']]]);
    $pdo->commit();
    return $r;
});
crm_done('Deleted the tag ' . $r['label'] . ' (taken off ' . $r['removed'] . ' record' . ($r['removed'] === 1 ? '' : 's') . ')', $id, crm_land('/admin/tags', 'deleted'), 'tagChanged');
