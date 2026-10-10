<?php
declare(strict_types=1);
/** Action `duplicate_dismiss` (log duplicate.dismiss — records.merge, or the edit rule on both): `kind`, `a`, `b` — "not a duplicate", the pair is not shown again. */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_once dirname(__DIR__, 2) . '/app/features/records/queries.php';
require_once dirname(__DIR__, 2) . '/app/features/duplicates/queries.php';
require_once dirname(__DIR__, 2) . '/app/features/duplicates/present.php';
require_once dirname(__DIR__, 2) . '/app/features/duplicates/write.php';
crm_handler_begin();
$pdo = db();
$kind = record_kind_from_request(array_keys(DUPLICATE_KINDS));
$a = request_integer('a');
$b = request_integer('b');
if ($a === null || $b === null || $a === $b) { crm_refuse_fields(['b' => 'Say the two records of the pair.']); }
$ra = require_record($pdo, $kind, $a);
$rb = require_record($pdo, $kind, $b);
if (!has_right('records.merge') && !(record_editable($pdo, $kind, $ra) && record_editable($pdo, $kind, $rb))) {
    refuse(403, 'You may not decide about these two — a manager, or whoever may change both, can.');
}
$new = crm_guard($pdo, static function () use ($pdo, $kind, $a, $b): bool {
    $pdo->beginTransaction();
    $new = duplicate_dismiss($pdo, $kind, $a, $b, (int) current_member_id());
    if ($new) { log_activity($pdo, 'duplicate.dismiss', $kind, min($a, $b), [$kind . '_id' => min($a, $b), 'after' => ['kind' => $kind, 'a' => min($a, $b), 'b' => max($a, $b)]]); }
    $pdo->commit();
    return $new;
});
crm_done($new ? 'Not a duplicate — the pair will not be shown again' : 'That pair was dismissed before', min($a, $b), crm_land(return_path('/duplicates?kind=' . $kind), 'dismissed'), 'duplicateChanged', ['pair' => [min($a, $b), max($a, $b)]]);
