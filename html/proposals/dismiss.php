<?php
declare(strict_types=1);
/** Action `proposal_dismiss` (log `proposal.dismiss`): the proposal's person, or a manager, sets it aside. A dismissed proposal is not made again (the worker looks at every status before it proposes). */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_once dirname(__DIR__, 2) . '/app/features/records/queries.php';
require_once dirname(__DIR__, 2) . '/app/features/proposals/write.php';
crm_handler_begin();
require_right('records.read');
$pdo = db();
$me = (int) current_member_id();
$id = request_integer('proposal') ?? request_integer('id');
$p = $id === null ? null : find_proposal($pdo, $id);
if ($p === null) {
    refuse(404, 'Proposal not found.');
}
if (!proposal_may_decide($p)) {
    refuse(403, 'This proposal is for ' . (member_name($pdo, $p['for_member_id'] === null ? null : (int) $p['for_member_id']) ?? 'someone else') . ' — only they or a manager may set it aside.');
}
if ($p['status'] !== 'proposed') {
    refuse(422, 'That proposal was decided already (' . strtolower(PROPOSAL_STATUS[$p['status']][0] ?? $p['status']) . ').');
}
crm_guard($pdo, static function () use ($pdo, $p, $me, $id): void {
    $pdo->beginTransaction();
    proposal_dismiss($pdo, $id, $me);
    log_activity($pdo, 'proposal.dismiss', 'assistant_proposal', $id, array_filter(['deal_id' => $p['deal_id'], 'lead_id' => $p['lead_id'], 'contact_id' => $p['contact_id'], 'account_id' => $p['account_id']], static fn ($v) => $v !== null)
        + ['after' => ['kind' => $p['kind'], 'for_member_id' => $p['for_member_id'] === null ? null : (int) $p['for_member_id']]]);
    $pdo->commit();
});
crm_done('Dismissed the proposal', $id, crm_land('/proposals', 'dismissed'), 'proposalChanged');
