<?php
declare(strict_types=1);
/**
 * Action `sms_send` (log `sms.send`, no words; pauses for an agent as `external_send`; confirm): a text to a CONTACT. Refused with `no_sender` until the kernel's K28 lands — the kernel texts members, not the people outside
 * the business (agents-k7-api.md divergence 1) — but registered now so the approval policy exists on day one. contact, body (up to 300 characters). The contact must be one the caller may see; an opt-out is refused by name.
 */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_once dirname(__DIR__, 2) . '/app/features/records/queries.php';
require_once dirname(__DIR__, 2) . '/app/features/notify/send.php';
crm_handler_begin();
require_right('email.send');
$pdo = db();
$me = (int) current_member_id();
$contact = require_record($pdo, 'contact', request_integer('contact'));
$errors = [];
$body = crm_text('body', null, SMS_BODY_MAX, 'The text', $errors, true);
if ($errors !== []) { crm_refuse_fields($errors); }
if ((bool) one_value($pdo, 'SELECT sms_opt_out FROM contacts WHERE id = :id', ['id' => $contact['id']])) {
    refuse(422, $contact['name'] . ' has opted out of texts.');
}
$r = sms_send($pdo, (int) $contact['id'], (string) $body, $me);
log_activity($pdo, 'sms.send', 'contact', (int) $contact['id'], ['contact_id' => (int) $contact['id'], 'after' => ['outcome' => $r['sent'] ? 'sent' : 'refused', 'code' => $r['code'], 'length' => mb_strlen((string) $body)]]);
if (!$r['sent']) {
    refuse(422, $r['message']);
}
crm_done('Texted ' . $contact['name'], (int) $contact['id'], crm_land('/contacts/' . (int) $contact['id']), 'contactChanged');
