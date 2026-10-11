<?php
declare(strict_types=1);
/**
 * /email/compose (screen `email-compose`; params contact, deal, account, template, activity, draft, and reply — an email being answered): to write to a contact.
 * To as chips from the record's people (the ones who opted out marked and not offered), a template (the record picker, with the merge filled live by one HTMX
 * refresh of #compose-fill; plain links without JavaScript), the subject, the body (Markdown), files from the record, the meeting's ICS when `activity` names one;
 * Save draft (email_draft) · Send (email_send). A draft is its sender's alone. The merge fields are filled for the first person; with several To's the ones about
 * the contact stay as typed and are filled for each when it goes. JSON: the people, the fields, the merge notes, the files.
 */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_once dirname(__DIR__, 2) . '/app/features/email/present.php';
require_once dirname(__DIR__, 2) . '/app/features/email/inbound.php';
require_once dirname(__DIR__, 2) . '/app/features/deals/queries.php';
require_any_right('email.send|email.log');
$pdo = db();
$me = (int) current_member_id();
$draftId = request_integer('draft');
$draft = $draftId === null ? null : find_draft($pdo, $draftId);
if ($draftId !== null && $draft === null) { refuse(404, 'That draft is not here.'); }
$rec = static function (string $k, string $view, string $col, string $noun) use ($pdo): ?int {
    $v = request_integer($k);
    if ($v !== null && one_value($pdo, "SELECT 1 FROM $view WHERE $col = :id", ['id' => $v]) === null) { refuse(404, ucfirst($noun) . ' not found.'); }
    return $v;
};
$contact = $rec('contact', 'mcp_contacts', 'contact_id', 'contact');
$deal = $rec('deal', 'mcp_deals', 'deal_id', 'deal');
$account = $rec('account', 'mcp_accounts', 'account_id', 'account');
$activity = $rec('activity', 'mcp_activities', 'activity_id', 'activity');
$replyTo = request_integer('reply');
$reply = $replyTo === null ? null : find_email($pdo, 'in', $replyTo);
if ($replyTo !== null && $reply === null) { refuse(404, 'Email not found.'); }
$chosen = array_values(array_filter(array_map('intval', array_filter((array) ($_GET['contacts'] ?? []), 'ctype_digit'))));
if ($draft !== null) {
    $deal ??= $draft['deal_id'] === null ? null : (int) $draft['deal_id'];
    $activity ??= $draft['activity_id'] === null ? null : (int) $draft['activity_id'];
    $replyIn = $draft['in_reply_to'];
    if ($chosen === []) {
        $chosen = $draft['contact_id'] === null ? [] : [(int) $draft['contact_id']];
        foreach (pg_text_array((string) $draft['cc_addresses']) as $addr) { $cid = email_contact_for_address($pdo, $addr); if ($cid !== null) { $chosen[] = $cid; } }
    }
}
if ($reply !== null) {
    $deal ??= $reply['deal_id'] === null ? null : (int) $reply['deal_id'];
    if ($chosen === [] && $contact === null) {
        $chosen = array_map('intval', array_slice(pg_int_array((string) $reply['matched_contact_ids']), 0, 1));
        if ($chosen === [] && $reply['from_address'] !== null) { $cid = email_contact_for_address($pdo, (string) $reply['from_address']); if ($cid !== null) { $chosen = [$cid]; } }
    }
}
$people = compose_contacts($pdo, $contact, $deal, $account, $chosen);
$selected = array_values(array_map(static fn (array $p): int => $p['contact_id'], array_filter($people, static fn (array $p): bool => $p['selected'])));
// The meeting whose ICS goes along: one the caller may use.
$meeting = null;
if ($activity !== null) {
    $a = find_activity($pdo, $activity);
    if ($a === null || $a['kind'] !== 'meeting') { refuse(422, 'Only a meeting has an invitation to send.'); }
    if (!activity_may($a)['edit']) { refuse(403, 'Only the one who made the meeting, who does it, or a manager sends its invitation.'); }
    $meeting = ['activity_id' => $activity, 'subject' => $a['subject'], 'at' => json_ts($a['starts_at'] ?? $a['due_at'])];
    $deal ??= $a['deal_id'] === null ? null : (int) $a['deal_id'];
}
// The template, filled for the first person (several To's: their own fields stay for the send).
$tplId = request_integer('template') ?? ($draft['template_id'] ?? null);
$tpl = $tplId === null ? null : find_email_template($pdo, (int) $tplId);
if (request_integer('template') !== null && ($tpl === null || $tpl['archived_at'] !== null)) { refuse(404, 'Template not found.'); }
$ctx = merge_context($pdo, count($selected) === 1 ? $selected[0] : null, $deal, $account, $activity, $me);
$perPerson = count($selected) > 1;
if ($perPerson) { $ctx['contact.first_name'] = null; $ctx['contact.name'] = null; }
if (request_integer('template') !== null) {
    $subject = merge_render((string) $tpl['subject'], $ctx)['text'];
    $body = merge_render((string) $tpl['body'], $ctx)['text'];
} elseif ($draft !== null && !isset($_GET['subject'])) {
    $subject = (string) $draft['subject'];
    $body = (string) $draft['body_text'];
} else {
    $subject = mb_substr(trim((string) ($_GET['subject'] ?? '')), 0, 300);
    $body = mb_substr((string) ($_GET['body'] ?? ''), 0, 100000);
}
if ($subject === '' && $reply !== null) { $subject = 'Re: ' . trim((string) preg_replace('/^\s*((re|aw|sv|antw):\s*)+/i', '', (string) $reply['subject'])); }
if ($subject === '' && $meeting !== null) { $subject = 'Invitation: ' . $meeting['subject']; }
// What is still to fill, in words.
$notes = ['unknown' => [], 'when_sent' => [], 'missing' => []];
foreach (merge_leftovers($subject, $body) as $m) {
    $f = strtolower(trim($m, '{} '));
    if (!array_key_exists($f, MERGE_FIELDS)) { $notes['unknown'][] = $m; }
    elseif ($perPerson && str_starts_with($f, 'contact.')) { $notes['when_sent'][] = $m; }
    else { $notes['missing'][] = $m; }
}
$filled = $tpl !== null && request_integer('template') !== null ? merge_render((string) $tpl['subject'] . "\n" . (string) $tpl['body'], $ctx)['filled'] : [];
// The files the record carries, and the ones already on the draft.
$files = [];
$onDraft = $draft === null ? [] : find_attachments($pdo, 'outbound_email', (int) $draft['id']);
$seen = [];
foreach ($onDraft as $f) { $files[] = $f + ['attached' => true]; $seen[$f['filename'] . '|' . $f['byte_size']] = true; }
$ticked = array_map('intval', array_filter((array) ($_GET['attachments'] ?? []), 'ctype_digit'));
if ($deal !== null && $account === null) {
    $dacc = one_value($pdo, 'SELECT account_id FROM mcp_deals WHERE deal_id = :d', ['d' => $deal]);
    $account = $dacc === null || $dacc === false ? null : (int) $dacc;
}
foreach ([['deal', $deal], ['contact', $selected[0] ?? $contact], ['account', $account]] as [$kind, $rid]) {
    if ($rid === null) { continue; }
    foreach (find_attachments($pdo, $kind, (int) $rid, 30) as $f) {
        $key = $f['filename'] . '|' . $f['byte_size'];
        if (isset($seen[$key])) { continue; }
        $seen[$key] = true;
        $files[] = $f + ['attached' => in_array((int) $f['attachment_id'], $ticked, true)];
    }
}
$mailbox = email_logging_mailbox($pdo);
$templates = array_map(static fn (array $t): array => ['template_id' => (int) $t['template_id'], 'name' => $t['name'], 'is_shared' => (bool) $t['is_shared']], find_email_templates($pdo, [], 8)['rows']);
$back = safe_local_path($_GET['back'] ?? null) ?? ($deal !== null ? '/deals/' . $deal : ($contact !== null ? '/contacts/' . $contact : ($account !== null ? '/accounts/' . $account : '/email/unmatched')));
log_screen_view($pdo, 'email-compose');
$state = ['contacts' => $people, 'draft_id' => $draft === null ? null : (int) $draft['id'], 'deal_id' => $deal, 'template_id' => $tpl === null ? null : (int) $tpl['template_id'], 'activity_id' => $activity,
    'reply_to' => $replyTo, 'subject' => $subject, 'body' => $body, 'merge' => ['filled' => $filled, 'unknown' => $notes['unknown'], 'missing' => $notes['missing'], 'when_sent' => $notes['when_sent']],
    'files' => $files, 'meeting' => $meeting, 'mailbox' => $mailbox['address'] ?? null, 'templates' => $templates];
if (wants_json()) { respond_screen(present_compose($state)); }
render_screen($draft !== null ? 'Edit draft' : 'New email', view('email/compose.php', ['s' => $state, 'notes' => $notes, 'filled' => $filled, 'back' => $back, 'account' => $account, 'contact' => $contact,
    'may_send' => has_right('email.send'), 'tpl' => $tpl, 'tpl_label' => $tpl === null ? null : $tpl['name'], 'perPerson' => $perPerson, 'notice' => crm_notice($_GET['notice'] ?? null, ['draft' => ['success', 'Draft saved.']])]),
    ['activeNav' => 'email', 'screen' => 'email-compose', 'entity' => 'email']);
