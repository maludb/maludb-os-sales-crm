<?php
declare(strict_types=1);
/**
 * Action `email_make_lead` (log email.make_lead — leads.write; undo record_delete): `email` (an unmatched or proposed inbound email). A lead from its sender, mine: the
 * proposed lead the mailbox made is accepted; with none, one is made (the Mailbox source, the first lines of the message). The email lands on the lead as a received
 * email. record_id is the lead.
 */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_once dirname(__DIR__, 2) . '/app/features/email/write.php';
crm_handler_begin();
require_right('leads.write');
$pdo = db();
$me = (int) current_member_id();
$id = request_integer('email') ?? request_integer('id');
$e = $id === null ? null : find_email($pdo, 'in', $id);
if ($e === null) { refuse(404, 'Email not found.'); }
if (!in_array($e['match_status'], ['unmatched', 'proposed_lead'], true)) { refuse(422, 'That email is matched already.'); }
[$lead, $made] = crm_guard($pdo, static function () use ($pdo, $id, $me): array {
    $pdo->beginTransaction();
    [$lead, $made] = email_make_lead_write($pdo, $id, $me);
    log_activity($pdo, 'email.make_lead', 'lead', $lead, ['lead_id' => $lead, 'after' => ['email_id' => $id, 'made' => $made, 'accepted_proposed' => !$made]]);
    $pdo->commit();
    return [$lead, $made];
});
crm_done(($made ? 'Made a lead of ' : 'Took the proposed lead from ') . ($e['from_name'] ?: $e['from_address']), $lead, crm_land(return_path('/leads/' . $lead), 'created'), 'leadChanged, emailChanged', ['undo' => 'record_delete']);
