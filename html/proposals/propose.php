<?php
declare(strict_types=1);
/**
 * Action `next_step_propose` (log `proposal.next_step`): suggest a next step on a deal that has none, for its owner to accept in one click (an agent's job; a person may too). deal, kind (call, email,
 * meeting, task, deadline), subject, at (a time in the seller's zone), body (what to say — up to 2000 characters, never logged), for (the seller; the deal's owner by default). One open step per deal; a deal that has
 * a next step, or is closed, is refused in words; so is any proposing while the Assistant's proposals are switched off.
 */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_once dirname(__DIR__, 2) . '/app/features/records/queries.php';
require_once dirname(__DIR__, 2) . '/app/features/activities/queries.php';
require_once dirname(__DIR__, 2) . '/app/features/proposals/write.php';
crm_handler_begin();
require_right('activities.write');
$pdo = db();
$me = (int) current_member_id();
if (!proposals_enabled($pdo)) {
    refuse(422, 'Proposals are switched off in the agents\' settings.');
}
$row = require_record($pdo, 'deal', request_integer('deal'));
$deal = one_row($pdo, 'SELECT deal_id, number, title, owner_member_id, status, next_activity_at FROM mcp_deals WHERE deal_id = :id', ['id' => $row['id']]);
if ($deal['status'] !== 'open') {
    refuse(422, $deal['number'] . ' is closed — it needs no next step.');
}
if ($deal['next_activity_at'] !== null) {
    refuse(422, $deal['number'] . ' has a next step already.');
}
$errors = [];
$kind = crm_choice('kind', null, ['call', 'email', 'meeting', 'task', 'deadline'], 'The kind', $errors, false);
if (!req_has('kind') || $kind === null) { $errors['kind'] ??= 'The kind is required.'; }
$subject = crm_text('subject', null, 200, 'The subject', $errors, true);
$body = crm_text('body', null, 2000, 'What to say', $errors);
$forGiven = request_integer('for');
$for = proposal_person($pdo, $forGiven ?? ($deal['owner_member_id'] === null ? null : (int) $deal['owner_member_id']));
if ($for === null) {
    $errors['for'] = $forGiven === null ? 'That deal has no owner — say who the step is for.' : 'That person is not here.';
}
$tz = $for === null ? 'UTC' : (string) (one_value($pdo, 'SELECT timezone FROM members WHERE id = :m', ['m' => $for]) ?: 'UTC');
$at = activity_time(req_val('at'), $tz, '09:00');
if (!req_has('at') || $at === null) { $errors['at'] ??= 'The time is required.'; }
elseif ($at === false) { $errors['at'] = 'The time is a date and an hour like 2026-10-14 10:00.'; }
if ($errors !== []) { crm_refuse_fields($errors); }
$id = crm_guard($pdo, static function () use ($pdo, $me, $deal, $kind, $subject, $body, $for, $at): int {
    $pdo->beginTransaction();
    $id = propose($pdo, 'next_step', ['deal_id' => (int) $deal['deal_id'], 'for_member_id' => $for, 'title' => 'Next step on ' . $deal['number'] . ': ' . $subject,
        'payload' => ['kind' => $kind, 'subject' => $subject, 'at' => $at] + ($body === null ? [] : ['body' => $body])], $me);
    log_activity($pdo, 'proposal.next_step', 'assistant_proposal', $id, ['deal_id' => (int) $deal['deal_id'], 'after' => ['for_member_id' => $for, 'kind' => $kind, 'at' => $at, 'subject' => $subject]]);
    $pdo->commit();
    return $id;
});
crm_done('Proposed a ' . $kind . ' on ' . $deal['number'] . ' for ' . (member_name($pdo, $for) ?? 'its owner'), $id, crm_land('/proposals'), 'proposalChanged');
