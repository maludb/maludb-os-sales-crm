<?php
declare(strict_types=1);
/**
 * Action `bulk_assign` (log bulk.assign {kind, count, filters?}; confirm; PAUSES FOR AN AGENT — other; records.bulk): `kind`, `owner` (a member), and what to act on — `records[]` or `list` or `filters`, one of
 * them, up to the bulk_sync_rows setting (more is refused: "Narrow the selection or use a list"). One transaction. A record the caller may not change refuses the whole action, naming it.
 */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_once dirname(__DIR__, 2) . '/app/features/bulk/select.php';
require_once dirname(__DIR__, 2) . '/app/features/bulk/write.php';
crm_handler_begin();
require_right('records.bulk');
$pdo = db();
$kind = record_kind_from_request(array_keys(FILTER_KINDS));
$errors = [];
if (!req_has('owner')) { $errors['owner'] = 'Say who gets them.'; }
$owner = crm_ref($pdo, 'owner', null, 'SELECT 1 FROM mcp_members WHERE member_id = :id', 'owner', $errors, false);
if ($errors !== []) { crm_refuse_fields($errors); }
$out = crm_guard($pdo, static function () use ($pdo, $kind, $owner): array {
    $sel = bulk_select($pdo, $kind, $_POST);
    bulk_require_editable($pdo, $kind, $sel['ids']);
    $pdo->beginTransaction();
    $changed = bulk_assign($pdo, $kind, $sel['ids'], $owner);
    log_activity($pdo, 'bulk.assign', null, null, ['after' => ['kind' => $kind, 'count' => count($changed), 'selected' => count($sel['ids']), 'source' => $sel['source'], 'list_id' => $sel['list'], 'filters' => $sel['filters'],
        'owner_member_id' => $owner, 'record_ids' => $changed]]);
    $pdo->commit();
    return [$sel, $changed];
});
[$sel, $changed] = $out;
bulk_done('assign', $kind, $sel, count($changed), 'Gave ' . count($changed) . ' ' . RECORD_KINDS[$kind]['noun'] . (count($changed) === 1 ? '' : 's') . ' to ' . member_name($pdo, $owner), ['changed' => $changed, 'owner_member_id' => $owner]);
