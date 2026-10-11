<?php
declare(strict_types=1);
/**
 * /admin/mailbox (screen `admin-mailbox`; mailbox.manage): the mailbox the application logs email through — its address, provider, the NAME of the environment key that holds the webhook secret (never the secret), the signature, polling and
 * on/off — with its health (the last webhook, the last poll, the last failure, what arrived this week, what waits to be matched) and the one manual step that connects it at MaluMail. JSON: the same, with no secret.
 */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_once dirname(__DIR__, 2) . '/app/features/mailbox/write.php';
require_login();
require_right('mailbox.manage');
$pdo = db();
$m = mailbox($pdo);
log_screen_view($pdo, 'admin-mailbox');
if (wants_json()) {
    respond_screen(['mailbox' => present_mailbox($m), 'health' => mailbox_summary($pdo), 'webhook_url' => app_url('/channels/malumail/mail')]);
}
$notices = ['saved' => ['success', 'Saved the mailbox.'], 'created' => ['success', 'Set up the mailbox. Now do the manual step below at MaluMail.']];
render_screen('Logging mailbox', view('admin/mailbox.php', ['m' => $m, 'health' => mailbox_summary($pdo), 'webhook_url' => app_url('/channels/malumail/mail'), 'tz' => member_timezone(),
    'notice' => crm_notice($_GET['notice'] ?? null, $notices)]), ['activeNav' => 'admin', 'screen' => 'admin-mailbox', 'entity' => 'mailbox']);
