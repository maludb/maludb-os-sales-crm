<?php
declare(strict_types=1);
/**
 * Action `notification` (log `notification.send`; the body is NEVER logged): a notice to a person — the bell and, by their preferences, an email or a text. member, kind (morning_note, proposal, mention, digest), title
 * (up to 200 characters), body (Markdown, up to 4000), deal, contact, activity (records the notice points at — the SENDER must be able to open them). An agent sends to a seller or a manager, a manager to anyone, a Rep to
 * themselves; the Assistant's morning note goes once a day to each person.
 */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_once dirname(__DIR__, 2) . '/app/features/records/queries.php';
require_once dirname(__DIR__, 2) . '/app/features/notify/write.php';
crm_handler_begin();
$pdo = db();
$me = (int) current_member_id();
$to = request_integer('member');
if ($to === null) {
    crm_refuse_fields(['member' => 'Say who the notice is for.']);
}
if (($deny = notify_authorize($pdo, $to)) !== null) {
    refuse($deny[0], $deny[1]);
}
$errors = [];
$kind = crm_choice('kind', null, array_keys(NOTIFY_KINDS), 'The kind', $errors, false);
if (!req_has('kind') || $kind === null) { $errors['kind'] ??= 'The kind is required.'; }
$title = crm_text('title', null, 200, 'The title', $errors, true);
$body = crm_text('body', null, 4000, 'The body', $errors);
$links = [];
foreach (['deal', 'contact', 'activity'] as $l) {
    $links[$l] = request_integer($l);
}
if ($errors !== []) { crm_refuse_fields($errors); }
$nid = crm_guard($pdo, static function () use ($pdo, $to, $kind, $title, $body, $links, $me): int {
    $pdo->beginTransaction();
    $nid = notify($pdo, $to, (string) $kind, (string) $title, $body, $links);
    log_activity($pdo, 'notification.send', 'notification', $nid, array_filter(['deal_id' => $links['deal'], 'contact_id' => $links['contact']], static fn ($v) => $v !== null)
        + ['after' => ['member_id' => $to, 'kind' => $kind, 'deal_id' => $links['deal'], 'contact_id' => $links['contact'], 'activity_id' => $links['activity'], 'has_body' => $body !== null]]);
    $pdo->commit();
    return $nid;
});
crm_done('Sent the ' . strtolower(NOTIFY_KINDS[$kind]) . ' to ' . (member_name($pdo, $to) ?? 'them'), $nid, crm_land('/notifications'), 'notificationChanged');
