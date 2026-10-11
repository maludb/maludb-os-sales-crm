<?php
declare(strict_types=1);
/**
 * Action `email_private_set` (log email.private {direction, private}; undo itself — the sender, or a manager): `email` (an inbound or outbound email id), `direction` (in or
 * out), `private` (yes or no). A private email — and the activities it made — is seen, body and all, only by the one who sent it and the managers (the view's rule);
 * to everyone else it does not exist. The sender of an email a contact wrote is nobody (it came from outside): only a manager marks that one.
 */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_once dirname(__DIR__, 2) . '/app/features/email/write.php';
crm_handler_begin();
$pdo = db();
$errors = [];
$dir = crm_choice('direction', null, ['in', 'out'], 'The direction', $errors, false);
if ($dir === null && !isset($errors['direction'])) { $errors['direction'] = 'Say which way the email went: in or out.'; }
if (!req_has('private')) { $errors['private'] = 'Say yes or no for private.'; }
if ($errors !== []) { crm_refuse_fields($errors); }
$private = crm_yes('private');
$id = request_integer('email') ?? request_integer('id');
$e = $id === null ? null : find_email($pdo, $dir, $id);
if ($e === null) { refuse(404, 'Email not found.'); }
if (!email_may($e)['private_set']) { refuse(403, 'Only the one who sent it, or a manager, can make an email private or public.'); }
if ((bool) $e['is_private'] === $private) { crm_done('Nothing to change', $id, crm_land(return_path('/email/' . $dir . '/' . $id))); }
crm_guard($pdo, static function () use ($pdo, $dir, $id, $private, $e): void {
    $pdo->beginTransaction();
    email_set_private($pdo, $dir, $id, $private);
    log_activity($pdo, 'email.private', $dir === 'in' ? 'inbound_email' : 'outbound_email', $id, array_filter(['deal_id' => $e['deal_id']], static fn ($v) => $v !== null)
        + ['before' => ['private' => !$private], 'after' => ['direction' => $dir, 'private' => $private]]);
    $pdo->commit();
});
crm_done($private ? 'Made the email private — only its sender and the managers see it now' : 'Made the email public again', $id, crm_land(return_path('/email/' . $dir . '/' . $id), $private ? 'private' : 'public'), 'emailChanged, activityChanged',
    ['undo' => 'email_private_set']);
