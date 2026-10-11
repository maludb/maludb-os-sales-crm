<?php
declare(strict_types=1);
/**
 * Action `duplicate_propose` (log `proposal.duplicate`): point a manager at two records that look like one. kind (lead, account or contact), a, b (two records the proposer may see), matched_on (email, domain, phone or
 * name), for (a manager; the manager of the first record's owner's department by default). A pair a person said is not a duplicate, and a pair already proposed in any status, is refused — it is not asked twice.
 */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_once dirname(__DIR__, 2) . '/app/features/records/queries.php';
require_once dirname(__DIR__, 2) . '/app/features/duplicates/queries.php';
require_once dirname(__DIR__, 2) . '/app/features/proposals/write.php';
crm_handler_begin();
require_right('records.read');
$pdo = db();
$me = (int) current_member_id();
if (!proposals_enabled($pdo)) {
    refuse(422, 'Proposals are switched off in the agents\' settings.');
}
$errors = [];
$kind = crm_choice('kind', null, array_keys(DUPLICATE_KINDS), 'The kind', $errors, false);
if (!req_has('kind') || $kind === null) { $errors['kind'] ??= 'The kind is required.'; }
$matched = crm_choice('matched_on', null, ['email', 'domain', 'phone', 'name'], 'What matched', $errors, false);
if (!req_has('matched_on') || $matched === null) { $errors['matched_on'] ??= 'Say what matched: email, domain, phone or name.'; }
$a = request_integer('a');
$b = request_integer('b');
if ($a === null || $b === null) { $errors['a'] = 'Name the two records (a and b).'; }
elseif ($a === $b) { $errors['b'] = 'A record is not a duplicate of itself.'; }
if ($errors !== []) { crm_refuse_fields($errors); }
$ra = require_record($pdo, $kind, min($a, $b));
$rb = require_record($pdo, $kind, max($a, $b));
[$a, $b] = [min($a, $b), max($a, $b)];
if (one_value($pdo, 'SELECT 1 FROM duplicate_dismissals WHERE record_kind = :k AND a_id = :a AND b_id = :b', ['k' => $kind, 'a' => $a, 'b' => $b]) !== null) {
    refuse(422, 'A person said that pair is not a duplicate.');
}
if (duplicate_proposed($pdo, $kind, $a, $b)) {
    refuse(422, 'That pair was proposed already.');
}
$forGiven = request_integer('for');
$for = $forGiven !== null ? proposal_person($pdo, $forGiven) : proposal_manager_for($pdo, $ra['owner_member_id'] === null ? null : (int) $ra['owner_member_id']);
if ($for === null || !(bool) one_value($pdo, "SELECT crm_member_roles(:m) && ARRAY['manager', 'admin']", ['m' => $for])) {
    refuse(422, $forGiven !== null ? 'A duplicate goes to a manager, and that person is not one.' : 'No manager is admitted here to decide this.');
}
$names = [duplicate_name($kind, duplicate_record($pdo, $kind, $a) ?? []), duplicate_name($kind, duplicate_record($pdo, $kind, $b) ?? [])];
$id = crm_guard($pdo, static function () use ($pdo, $me, $kind, $a, $b, $matched, $for, $names): int {
    $pdo->beginTransaction();
    $id = propose($pdo, 'duplicate', [$kind . '_id' => $a, 'for_member_id' => $for, 'title' => 'Possible duplicate ' . $kind . 's: ' . $names[0] . ' and ' . $names[1],
        'payload' => ['kind' => $kind, 'other_id' => $b, 'matched_on' => $matched]], $me);
    log_activity($pdo, 'proposal.duplicate', 'assistant_proposal', $id, [$kind . '_id' => $a, 'after' => ['kind' => $kind, 'a' => $a, 'b' => $b, 'matched_on' => $matched, 'for_member_id' => $for]]);
    $pdo->commit();
    return $id;
});
crm_done('Proposed that ' . $names[0] . ' and ' . $names[1] . ' are one', $id, crm_land('/proposals'), 'proposalChanged');
