<?php
declare(strict_types=1);
/**
 * Action `signature_save` (log `settings.signature`: the length before and after — never the words): the words under the emails I send from Sales
 * CRM (members.email_signature; slice 5's compose appends it). Up to 1000 characters; blank clears it. People only — an agent signs as itself.
 */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_once dirname(__DIR__, 2) . '/app/features/settings/queries.php';
require_post();
require_login();
verify_csrf();
require_human();
$pdo = db();
$me = (int) current_member_id();
if (!array_key_exists('signature', $_POST)) {
    refuse(422, 'Say what your signature should be, or leave it blank to clear it.');
}
$text = str_replace("\r\n", "\n", trim((string) $_POST['signature']));
if (mb_strlen($text) > 1000) {
    refuse(422, 'A signature is up to 1000 characters.');
}
$pdo->beginTransaction();
$before = my_signature($pdo, $me);
save_signature($pdo, $me, $text);
log_activity($pdo, 'settings.signature', 'member', $me, ['before' => ['length' => mb_strlen($before)], 'after' => ['length' => mb_strlen($text)]]);
$pdo->commit();
emit_action_status(true, ['did' => $text === '' ? 'Cleared your signature' : 'Saved your signature', 'record_id' => $me, 'refresh' => 'prefsChanged']);
saved_go('/settings/?notice=signature_saved', 'prefsChanged');
