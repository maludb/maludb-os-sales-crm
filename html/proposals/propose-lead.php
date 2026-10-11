<?php
declare(strict_types=1);
/**
 * Action `lead_qualify_propose` (log `proposal.lead_qualify`): suggest a lead's qualification — a score from 0 to 100 and the reasons in short lines, and perhaps who should own it — for the lead's owner (the manager when it has
 * none) to accept in one click. lead, score, reasons[] (up to 8 lines of 200 characters), owner (a suggestion; shown, not applied). A converted lead needs none.
 */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_once dirname(__DIR__, 2) . '/app/features/records/queries.php';
require_once dirname(__DIR__, 2) . '/app/features/leads/queries.php';
require_once dirname(__DIR__, 2) . '/app/features/proposals/write.php';
crm_handler_begin();
require_right('leads.write');
$pdo = db();
$me = (int) current_member_id();
if (!proposals_enabled($pdo)) {
    refuse(422, 'Proposals are switched off in the agents\' settings.');
}
$lead = require_record($pdo, 'lead', request_integer('lead'));
$cur = one_row($pdo, 'SELECT converted_at, status FROM leads WHERE id = :id', ['id' => $lead['id']]);
if ($cur['converted_at'] !== null) {
    refuse(422, 'That lead is converted already — it needs no qualification.');
}
$errors = [];
$score = crm_int('score', null, 0, 100, 'The score', $errors);
if (!req_has('score') || $score === null) { $errors['score'] ??= 'The score is required (0 to 100).'; }
$reasons = request_list('reasons') ?? [];
if (count($reasons) > 8) { $errors['reasons'] = 'Give up to 8 reasons.'; }
foreach ($reasons as $r) { if (mb_strlen($r) > 200) { $errors['reasons'] = 'A reason is up to 200 characters.'; } }
$suggest = null;
if (req_has('owner') && (string) req_val('owner') !== '') {
    $suggest = proposal_person($pdo, request_integer('owner'));
    if ($suggest === null) { $errors['owner'] = 'That person is not here.'; }
}
if ($errors !== []) { crm_refuse_fields($errors); }
$for = proposal_person($pdo, $lead['owner_member_id'] === null ? null : (int) $lead['owner_member_id']) ?? proposal_manager_for($pdo, null);
if ($for === null) {
    refuse(422, 'No one is here to decide this — the lead has no owner and no manager is admitted.');
}
$id = crm_guard($pdo, static function () use ($pdo, $me, $lead, $score, $reasons, $suggest, $for): int {
    $pdo->beginTransaction();
    $id = propose($pdo, 'lead_qualify', ['lead_id' => (int) $lead['id'], 'for_member_id' => $for, 'title' => 'Qualify ' . $lead['name'] . ': ' . $score . ' of 100',
        'payload' => ['score' => $score, 'reasons' => $reasons] + ($suggest === null ? [] : ['owner_member_id' => $suggest])], $me);
    log_activity($pdo, 'proposal.lead_qualify', 'assistant_proposal', $id, ['lead_id' => (int) $lead['id'], 'after' => ['for_member_id' => $for, 'score' => $score, 'reasons' => count($reasons), 'suggested_owner' => $suggest]]);
    $pdo->commit();
    return $id;
});
crm_done('Proposed qualifying ' . $lead['name'] . ' at ' . $score, $id, crm_land('/proposals'), 'proposalChanged');
