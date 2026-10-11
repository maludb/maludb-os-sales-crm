<?php
declare(strict_types=1);
/**
 * Action `email_ignore` (log email.ignore — leads.write): `email` (an unmatched or proposed inbound email) — hidden from the unmatched list. Nothing else changes: a lead it
 * proposed stays in the lead inbox for a person to accept or dismiss.
 */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_once dirname(__DIR__, 2) . '/app/features/email/write.php';
crm_handler_begin();
require_right('leads.write');
$pdo = db();
$id = request_integer('email') ?? request_integer('id');
$e = $id === null ? null : find_email($pdo, 'in', $id);
if ($e === null) { refuse(404, 'Email not found.'); }
if (!in_array($e['match_status'], ['unmatched', 'proposed_lead'], true)) { refuse(422, 'Only an unmatched email is ignored.'); }
crm_guard($pdo, static function () use ($pdo, $id, $e): void {
    $pdo->beginTransaction();
    email_ignore_write($pdo, $id);
    log_activity($pdo, 'email.ignore', 'inbound_email', $id, ['before' => ['match_status' => $e['match_status']], 'after' => ['match_status' => 'ignored']]);
    $pdo->commit();
});
crm_done('Ignored the email from ' . ($e['from_name'] ?: $e['from_address']), $id, crm_land(return_path('/email/unmatched'), 'ignored'), 'emailChanged');
