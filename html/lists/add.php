<?php
declare(strict_types=1);
/**
 * Action `list_add` (log list.add — its owner, or lists.shared): `list` (a static one) and `record` (or `records[]`) of the list's kind. Only records the caller may see go in; one already there stays. The
 * list's count is the database's.
 */
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
if ($ids === []) { $errors['record'] = 'Say which ' . RECORD_KINDS[$l['record_kind']]['noun'] . ' to add.'; }
if (count($ids) > bulk_limit($pdo)) { $errors['records'] = 'Add up to ' . number_format(bulk_limit($pdo)) . ' at a time.'; }
if ($errors !== []) { crm_refuse_fields($errors); }
$r = crm_guard($pdo, static function () use ($pdo, $id, $l, $ids): array {
    $pdo->beginTransaction();
    $k = RECORD_KINDS[$l['record_kind']];
    $vis = $pdo->prepare("SELECT {$k['id']} FROM {$k['view']} WHERE {$k['id']} = ANY (CAST(:ids AS bigint[]))");
    $vis->execute(['ids' => pg_array_literal($ids)]);
    if (count($vis->fetchAll()) !== count($ids)) { throw new DomainException('Not found.'); }
    $r = list_add($pdo, $id, $ids);
    log_activity($pdo, 'list.add', 'list', $id, ['after' => ['record_kind' => $l['record_kind'], 'added' => $r['added'], 'already' => $r['already'], 'record_ids' => array_slice($ids, 0, 200)]]);
    $pdo->commit();
    return $r;
});
crm_done($r['added'] === 0 ? 'Already in the list' : 'Added ' . $r['added'] . ' to ' . $l['name'], $id, crm_land(return_path('/lists/' . $id), 'added'), 'listChanged', $r);
