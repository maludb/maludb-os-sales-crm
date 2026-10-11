<?php
declare(strict_types=1);
/** Action `list_delete` (log list.delete; confirm; PAUSES FOR AN AGENT — deletion): `list` — its owner, or lists.shared. The list goes to the tombstone; the records in it are untouched. */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_once dirname(__DIR__, 2) . '/app/features/records/queries.php';
require_once dirname(__DIR__, 2) . '/app/features/lists/queries.php';
require_once dirname(__DIR__, 2) . '/app/features/lists/write.php';
crm_handler_begin();
require_right('lists.own');
$pdo = db();
$id = request_integer('list') ?? request_integer('id');
$l = $id === null ? null : find_list($pdo, $id);
if ($l === null) { refuse(404, 'List not found.'); }
if (!list_may($l)['edit']) { refuse(403, 'Only ' . ($l['owner_name'] ?? 'its owner') . ', or someone who keeps shared lists, deletes this list.'); }
crm_guard($pdo, static function () use ($pdo, $id, $l): void {
    $pdo->beginTransaction();
    list_delete($pdo, $id);
    log_activity($pdo, 'list.delete', 'list', $id, ['before' => ['name' => $l['name'], 'kind' => $l['kind'], 'record_kind' => $l['record_kind'], 'members' => (int) $l['member_count']]]);
    $pdo->commit();
});
crm_done('Deleted the list ' . $l['name'], $id, crm_land(return_path('/lists'), 'deleted'), 'listChanged');
