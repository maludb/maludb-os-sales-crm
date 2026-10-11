<?php
declare(strict_types=1);
/**
 * Action `proposal_accept` (log `proposal.accept`; one click): the proposal's person, or a manager, takes it. A next step becomes THEIR activity (source `proposal`, linked back — `result_activity_id`), a qualification sets
 * the lead's score, a close date sets the date, the other kinds are acknowledged (a duplicate sends the person on to the pair). What the kind changes is judged by the walls as the decider: the record must be visible and the
 * write right and the edit rule held — a proposal never lets someone change what they could not change themselves.
 */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_once dirname(__DIR__, 2) . '/app/features/records/queries.php';
require_once dirname(__DIR__, 2) . '/app/features/proposals/write.php';
require_once dirname(__DIR__, 2) . '/app/features/proposals/present.php';
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
    refuse(403, 'This proposal is for ' . (member_name($pdo, $p['for_member_id'] === null ? null : (int) $p['for_member_id']) ?? 'someone else') . ' — only they or a manager may take it.');
}
if ($p['status'] !== 'proposed') {
    refuse(422, 'That proposal was decided already (' . strtolower(PROPOSAL_STATUS[$p['status']][0] ?? $p['status']) . ').');
}
switch ($p['kind']) {
    case 'next_step':
        require_right('activities.write');
        require_record($pdo, 'deal', $p['deal_id'] === null ? null : (int) $p['deal_id']);
        break;
    case 'lead_qualify':
        $lead = require_record($pdo, 'lead', $p['lead_id'] === null ? null : (int) $p['lead_id']);
        require_editable($pdo, 'lead', $lead);
        break;
    case 'close_date':
        $deal = require_record($pdo, 'deal', $p['deal_id'] === null ? null : (int) $p['deal_id']);
        require_editable($pdo, 'deal', $deal);
        break;
    default:
        break;
}
$aid = crm_guard($pdo, static function () use ($pdo, $p, $me, $id): ?int {
    $pdo->beginTransaction();
    $aid = proposal_accept($pdo, $id, $me);
    log_activity($pdo, 'proposal.accept', 'assistant_proposal', $id, array_filter(['deal_id' => $p['deal_id'], 'lead_id' => $p['lead_id'], 'contact_id' => $p['contact_id'], 'account_id' => $p['account_id']], static fn ($v) => $v !== null)
        + ['after' => ['kind' => $p['kind'], 'for_member_id' => $p['for_member_id'] === null ? null : (int) $p['for_member_id'], 'result_activity_id' => $aid]]);
    $pdo->commit();
    return $aid;
});
$land = '/proposals';
if ($p['kind'] === 'duplicate') {
    $pay = proposal_payload($p);
    $land = '/duplicates?kind=' . rawurlencode((string) ($pay['kind'] ?? 'contact'));
}
crm_done($aid === null ? 'Accepted the proposal' : 'Accepted — scheduled it for you', $id, crm_land($land, $p['kind'] === 'duplicate' ? null : 'accepted'), 'proposalChanged', ['result_activity_id' => $aid]);
