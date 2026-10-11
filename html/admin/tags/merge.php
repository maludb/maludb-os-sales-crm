<?php
declare(strict_types=1);
/** Action `tag_merge` (log tag.merge; confirm; tags.manage): `tag` (the one that goes) into `into` (the one that stays) — every record that carried the first carries the second, once; the counts follow; the first is deleted. Answers the surviving tag. */
require_once dirname(__DIR__, 3) . '/app/bootstrap.php';
require_once dirname(__DIR__, 3) . '/app/features/tags/queries.php';
require_once dirname(__DIR__, 3) . '/app/features/tags/write.php';
crm_handler_begin();
require_right('tags.manage');
$pdo = db();
$from = request_integer('tag') ?? request_integer('id');
$into = request_integer('into');
if ($from === null) { refuse(404, 'Tag not found.'); }
if ($into === null) { crm_refuse_fields(['into' => 'Choose the tag it merges into.']); }
$r = crm_guard($pdo, static function () use ($pdo, $from, $into): array {
    $pdo->beginTransaction();
    $r = tag_merge($pdo, $from, $into);
    log_activity($pdo, 'tag.merge', 'tag', $into, ['before' => ['tag_id' => $from, 'label' => $r['from']], 'after' => ['tag_id' => $into, 'label' => $r['into'], 'moved' => $r['moved'], 'already' => $r['already']]]);
    $pdo->commit();
    return $r;
});
crm_done('Merged ' . $r['from'] . ' into ' . $r['into'] . ' (' . $r['moved'] . ' record' . ($r['moved'] === 1 ? '' : 's') . ' moved)', $into, crm_land('/admin/tags', 'merged', 'tag-' . $into), 'tagChanged');
