<?php
declare(strict_types=1);
/**
 * Action `email_attach_contact` (log email.attach — contacts.write): `email` (an unmatched or proposed inbound email), `contact` (whose address the sender is). The sender's
 * address is added to the contact's addresses, a lead the email had proposed is dismissed, and the matcher runs on the email again: it lands on the contact (and the deal
 * its thread names). An address another contact holds is refused naming whose it is.
 */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_once dirname(__DIR__, 2) . '/app/features/email/write.php';
crm_handler_begin();
require_right('contacts.write');
$pdo = db();
$id = request_integer('email') ?? request_integer('id');
$e = $id === null ? null : find_email($pdo, 'in', $id);
if ($e === null) { refuse(404, 'Email not found.'); }
if (!in_array($e['match_status'], ['unmatched', 'proposed_lead'], true)) { refuse(422, 'That email is matched already.'); }
$errors = [];
$cid = crm_ref($pdo, 'contact', null, 'SELECT 1 FROM mcp_contacts WHERE contact_id = :id', 'contact', $errors, false);
if ($errors !== []) { crm_refuse_fields($errors); }
$row = require_record($pdo, 'contact', $cid);
require_editable($pdo, 'contact', $row);
$result = crm_guard($pdo, static function () use ($pdo, $id, $cid): string {
    $pdo->beginTransaction();
    $r = email_attach_write($pdo, $id, $cid);
    if ($r !== 'matched') { throw new DomainException('That email could not be put on ' . 'that contact.'); }
    log_activity($pdo, 'email.attach', 'inbound_email', $id, ['contact_id' => $cid, 'after' => ['contact_id' => $cid, 'result' => $r]]);
    $pdo->commit();
    return $r;
});
crm_done('Put the email on ' . $row['name'] . ' and kept the address for next time', $id, crm_land(return_path('/email/unmatched'), 'attached'), 'emailChanged, activityChanged', ['contact_id' => $cid]);
