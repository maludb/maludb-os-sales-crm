<?php
declare(strict_types=1);
/** Action `list_remove` (log list.remove — its owner, or lists.shared): `list` (a static one) and `record` (or `records[]`). The records stay; only their place in the list goes. */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_once dirname(__DIR__, 2) . '/app/features/records/queries.php';
require_once dirname(__DIR__, 2) . '/app/features/lists/queries.php';
require_once dirname(__DIR__, 2) . '/app/features/lists/write.php';
require_once dirname(__DIR__, 2) . '/app/features/bulk/select.php';
crm_handler_begin();
require_right('lists.own');
$pdo = db();
$id = request_integer('list') ?? request_integer('id');
$l = $id === null ? null : find_list($pdo, $id);
if ($l === null) { refuse(404, 'List not found.'); }
if (!list_may($l)['edit']) { refuse(403, 'Only ' . ($l['owner_name'] ?? 'its owner') . ', or someone who keeps shared lists, changes this list.'); }
$ids = bulk_ids_from($_POST['records'] ?? []);
if (($one = request_integer('record')) !== null) { $ids[] = $one; $ids = array_values(array_unique($ids)); }
$errors = [];
if ($l['kind'] !== 'static') { $errors['list'] = 'A smart list has no members of its own; change its filters.'; }
if ($ids === []) { $errors['record'] = 'Say which ' . RECORD_KINDS[$l['record_kind']]['noun'] . ' to take out.'; }
if ($errors !== []) { crm_refuse_fields($errors); }
$n = crm_guard($pdo, static function () use ($pdo, $id, $l, $ids): int {
    $pdo->beginTransaction();
    $n = list_remove($pdo, $id, $ids);
    log_activity($pdo, 'list.remove', 'list', $id, ['after' => ['record_kind' => $l['record_kind'], 'removed' => $n, 'record_ids' => array_slice($ids, 0, 200)]]);
    $pdo->commit();
    return $n;
});
crm_done($n === 0 ? 'None of them was in the list' : 'Took ' . $n . ' out of ' . $l['name'], $id, crm_land(return_path('/lists/' . $id), 'removed'), 'listChanged', ['removed' => $n, 'undo' => 'list_add']);
