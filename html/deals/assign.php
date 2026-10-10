<?php
declare(strict_types=1);
/**
 * Action `deal_assign` (log deal.assign per deal {owner_member_id before → after} — deals.any, or the owner giving their own away): `deal` or `deals[]`, `owner`
 * (a member; blank = unowned, a manager's only). Every deal is checked before any changes: one the caller may not give away refuses the lot, naming it.
 */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_once dirname(__DIR__, 2) . '/app/features/deals/write.php';
crm_handler_begin();
require_any_right('deals.any|deals.write');
$pdo = db();
$me = (int) current_member_id();
$ids = request_list('deals') ?? [];
if (($one = request_integer('deal') ?? request_integer('id')) !== null) { $ids[] = (string) $one; }
$ids = array_values(array_unique(array_map('intval', array_filter($ids, static fn ($v) => ctype_digit((string) $v)))));
if ($ids === []) { crm_refuse_fields(['deal' => 'Say which deal.']); }
if (count($ids) > 500) { crm_refuse_fields(['deals' => 'Up to 500 deals at a time.']); }
$errors = [];
if (!req_has('owner')) { $errors['owner'] = 'Say who gets it.'; }
$owner = crm_ref($pdo, 'owner', null, 'SELECT 1 FROM mcp_members WHERE member_id = :id', 'owner', $errors);
if ($owner === null && !isset($errors['owner']) && !is_manager()) { $errors['owner'] = 'Only a manager leaves a deal unowned.'; }
if ($errors !== []) { crm_refuse_fields($errors); }
$titles = [];
foreach ($ids as $id) {
    $row = require_record($pdo, 'deal', $id);
    if (!is_manager() && (int) ($row['owner_member_id'] ?? 0) !== $me) {
        $who = $row['owner_member_id'] === null ? 'nobody' : (string) member_name($pdo, (int) $row['owner_member_id']);
        refuse(403, 'Only a manager reassigns ' . $row['name'] . ' — it is ' . $who . '\'s.');
    }
    $titles[$id] = $row['name'];
}
$prev = crm_guard($pdo, static function () use ($pdo, $ids, $owner): array {
    $pdo->beginTransaction();
    $prev = deal_assign($pdo, $ids, $owner);
    foreach ($prev as $id => $before) {
        if ($before === $owner) { continue; }
        log_activity($pdo, 'deal.assign', 'deal', $id, ['deal_id' => $id, 'before' => ['owner_member_id' => $before], 'after' => ['owner_member_id' => $owner]]);
    }
    $pdo->commit();
    return $prev;
});
$name = $owner === null ? 'nobody' : (string) member_name($pdo, $owner);
$did = count($ids) === 1 ? 'Gave ' . reset($titles) . ' to ' . $name : 'Gave ' . count($ids) . ' deals to ' . $name;
crm_done($did, count($ids) === 1 ? $ids[0] : null, crm_land(return_path(count($ids) === 1 ? '/deals/' . $ids[0] : pipeline_board_url()), 'assigned'), 'dealChanged', ['deal_ids' => $ids]);
