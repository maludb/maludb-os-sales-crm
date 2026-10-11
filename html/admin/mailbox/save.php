<?php
declare(strict_types=1);
/**
 * Action `mailbox_save` (log mailbox.save {address, provider, webhook_secret_ref — the key's NAME, never a value, poll_enabled, active}; pauses for an agent as `other`; people only; mailbox.manage): `mailbox` changes the logging
 * mailbox, none makes it. address (an email address), display_name, provider malumail|imap, webhook_secret_ref (the NAME of the config/.env key that holds the webhook secret, like MAILBOX_CRM_WEBHOOK_SECRET — a value shaped
 * like anything else is refused), signature, poll_enabled, active. A field left out stays as it was.
 */
require_once dirname(__DIR__, 3) . '/app/bootstrap.php';
require_once dirname(__DIR__, 3) . '/app/features/mailbox/write.php';
crm_handler_begin();
require_human();
require_right('mailbox.manage');
$pdo = db();
$id = request_integer('mailbox') ?? request_integer('id');
$cur = mailbox($pdo);
if ($id !== null && ($cur === null || $cur['id'] !== $id)) { refuse(404, 'Mailbox not found.'); }
if ($id === null && $cur !== null) { $id = $cur['id']; }                         // the application logs through one mailbox: no id means that one
$cur = $id === null ? null : $cur;
$errors = [];
$address = crm_email('address', $cur['address'] ?? null, 'The address', $errors);
if ($address === null && !isset($errors['address'])) { $errors['address'] = 'The address is required.'; }
$ref = crm_text('webhook_secret_ref', $cur['webhook_secret_ref'] ?? null, 64, 'The secret\'s key name', $errors);
if ($ref !== null && req_has('webhook_secret_ref') && ($why = mailbox_secret_ref_error($ref)) !== null) { $errors['webhook_secret_ref'] = $why; $ref = $cur['webhook_secret_ref'] ?? null; }
$f = ['address' => $address, 'display_name' => crm_text('display_name', $cur['display_name'] ?? null, 80, 'The name', $errors, true), 'provider' => crm_choice('provider', $cur['provider'] ?? 'malumail', ['malumail', 'imap'], 'The provider', $errors, false),
      'webhook_secret_ref' => $ref, 'signature' => crm_text('signature', $cur['signature'] ?? null, 2000, 'The signature', $errors), 'poll_enabled' => crm_yes('poll_enabled', $cur['poll_enabled'] ?? true),
      'active' => crm_yes('active', $cur['active'] ?? true)];
if ($cur === null && $f['display_name'] === null && !isset($errors['display_name'])) { $errors['display_name'] = 'The name is required.'; }
if ($errors !== []) { crm_refuse_fields($errors); }
$r = crm_guard($pdo, static function () use ($pdo, $id, $f): array {
    $pdo->beginTransaction();
    $r = mailbox_save($pdo, $id, $f);
    log_activity($pdo, 'mailbox.save', 'mailbox', $r['id'], ['before' => $r['before'], 'after' => $r['after'] + ['address' => $f['address']]]);
    $pdo->commit();
    return $r;
});
crm_done(($id === null ? 'Set up the mailbox ' : 'Saved the mailbox ') . $f['address'], $r['id'], crm_land('/admin/mailbox', $id === null ? 'created' : 'saved'), 'mailboxChanged');
