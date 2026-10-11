<?php
declare(strict_types=1);
/**
 * Action `bulk_list_add` (log bulk.list_add {kind, count, filters?}; confirm — lists.own, and the list's owner or lists.shared): `list` (the static list to add to; the bar names it `target_list`), `kind` (the list's),
 * and what to act on — `records[]` or `filters` (not a list: `list` is the destination here). Records already in the list stay.
 */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_once dirname(__DIR__, 2) . '/app/features/bulk/select.php';
require_once dirname(__DIR__, 2) . '/app/features/bulk/write.php';
crm_handler_begin();
require_right('lists.own');
$pdo = db();
$kind = record_kind_from_request(array_keys(FILTER_KINDS));
$dest = request_integer('target_list') ?? request_integer('list');
$l = $dest === null ? null : find_list($pdo, $dest);
if ($l === null) { refuse($dest === null ? 422 : 404, $dest === null ? 'Choose the list to add to.' : 'List not found.'); }
if (!list_may($l)['edit']) { refuse(403, 'Only ' . ($l['owner_name'] ?? 'its owner') . ', or someone who keeps shared lists, changes this list.'); }
if ($l['kind'] !== 'static') { refuse(422, 'A smart list has no members of its own; change its filters.'); }
if ($l['record_kind'] !== $kind) { refuse(422, 'That list holds ' . strtolower(LIST_RECORD_KINDS[$l['record_kind']]) . ', not ' . strtolower(FILTER_KINDS[$kind]) . '.'); }
$input = $_POST;
unset($input['target_list']);
if (!isset($_POST['target_list'])) { unset($input['list']); }                          // an agent's `list` is the destination; the bar names it target_list and may send `list` as the source (a list's own page)
[$sel, $r] = crm_guard($pdo, static function () use ($pdo, $kind, $dest, $input): array {
    $sel = bulk_select($pdo, $kind, $input);
    $pdo->beginTransaction();
    $r = list_add($pdo, $dest, $sel['ids']);
    log_activity($pdo, 'bulk.list_add', null, null, ['after' => ['kind' => $kind, 'count' => $r['added'], 'selected' => count($sel['ids']), 'already' => $r['already'], 'source' => $sel['source'], 'filters' => $sel['filters'], 'list_id' => $dest]]);
    $pdo->commit();
    return [$sel, $r];
});
bulk_done('list', $kind, $sel, $r['added'], 'Added ' . $r['added'] . ' to ' . $l['name'] . ($r['already'] > 0 ? ' (' . $r['already'] . ' were already in it)' : ''), ['added' => $r['added'], 'already' => $r['already'], 'list_id' => $dest]);
