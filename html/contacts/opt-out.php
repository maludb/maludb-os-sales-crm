<?php
declare(strict_types=1);
/**
 * Action `contact_opt_out` (log contact.opt_out — the edit rule): `channel` email or sms, `reason` (up to 200 characters), `undo` yes lifts it.
 * An opted-out contact is never emailed or texted by Sales CRM (crm_email_allowed, slice 5); logging what they send stays allowed.
 */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_once dirname(__DIR__, 2) . '/app/features/records/queries.php';
require_once dirname(__DIR__, 2) . '/app/features/contacts/write.php';
crm_handler_begin();
$pdo = db();
$id = request_integer('contact') ?? request_integer('id');
$row = require_record($pdo, 'contact', $id);
require_editable($pdo, 'contact', $row);
$errors = [];
$channel = crm_choice('channel', null, ['email', 'sms'], 'The channel', $errors, false);
if ($channel === null && !isset($errors['channel'])) { $errors['channel'] = 'Say which channel: email or sms.'; }
$reason = crm_text('reason', null, 200, 'The reason', $errors);
$undo = crm_yes('undo', false);
if ($errors !== []) {
    crm_refuse_fields($errors);
}
$col = $channel === 'sms' ? 'sms_opt_out' : 'email_opt_out';
$was = (bool) one_value($pdo, "SELECT $col FROM contacts WHERE id = :id", ['id' => $id]);
crm_guard($pdo, static function () use ($pdo, $id, $row, $channel, $reason, $undo, $was): void {
    $pdo->beginTransaction();
    contact_opt_out($pdo, $id, $channel, $reason, $undo);
    log_activity($pdo, 'contact.opt_out', 'contact', $id, ['account_id' => $row['account_id'], 'before' => ['channel' => $channel, 'opted_out' => $was], 'after' => ['channel' => $channel, 'opted_out' => !$undo, 'reason_given' => $reason !== null]]);
    $pdo->commit();
});
$words = $channel === 'sms' ? 'texts' : 'email';
crm_done($undo ? $row['name'] . ' may get ' . $words . ' again' : $row['name'] . ' gets no ' . $words . ' from us', $id, crm_land(return_path('/contacts/' . $id), $undo ? 'opt_in' : 'opt_out'), 'contactChanged');
