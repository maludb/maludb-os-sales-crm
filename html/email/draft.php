<?php
declare(strict_types=1);
/**
 * Action `email_draft` (log email.draft — email.send or email.log; agents may): save an email as a DRAFT — an outbound_email row still to send, its sender's alone —
 * or change one of mine (`draft`). `contact` (or `contacts[]`: every address must be allowed — an opted-out contact is refused by name; the first is To, the others
 * go out as their own messages when it is sent), `deal`, `template` (its merge fields filled: the compose screen does it; a text with fields left is filled for each
 * person at the send), `subject`, `body` (Markdown), `attachments[]` (attachment ids), `activity` (a meeting — its ICS goes along), `reply` (an email answered).
 * record_id is the draft. Nothing is sent.
 */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_once dirname(__DIR__, 2) . '/app/features/email/write.php';
crm_handler_begin();
require_any_right('email.send|email.log');
$pdo = db();
$me = (int) current_member_id();
$draftId = request_integer('draft');
$draft = $draftId === null ? null : find_draft($pdo, $draftId);
if ($draftId !== null && $draft === null) { refuse(404, 'That draft is not here.'); }
$errors = [];
$f = email_collect_fields($pdo, $draft, $errors);
if ($errors !== []) { crm_refuse_fields($errors); }
$id = crm_guard($pdo, static function () use ($pdo, $draftId, $f, $me): int {
    $pdo->beginTransaction();
    $id = email_draft_save($pdo, $draftId, $f, $me);
    log_activity($pdo, 'email.draft', 'outbound_email', $id, array_filter(['contact_id' => $f['recipients'][0]['contact_id'], 'deal_id' => $f['deal_id']], static fn ($v) => $v !== null)
        + ['after' => ['recipients' => count($f['recipients']), 'template_id' => $f['template_id'], 'activity_id' => $f['activity_id'], 'attachments' => count($f['attachments'] ?? []), 'changed' => $draftId !== null]]);
    $pdo->commit();
    return $id;
});
$back = return_path('/email/unmatched');
crm_done('Saved the draft to ' . $f['recipients'][0]['name'] . (count($f['recipients']) > 1 ? ' and ' . (count($f['recipients']) - 1) . ' more' : ''), $id,
    crm_land('/email/compose?draft=' . $id . '&back=' . rawurlencode($back), 'draft'), 'emailChanged', ['draft_id' => $id]);
