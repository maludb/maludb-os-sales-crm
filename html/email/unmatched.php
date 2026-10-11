<?php
declare(strict_types=1);
/**
 * /email/unmatched (screen `email-unmatched`; the `emails_unmatched` tool): the mail that matched no contact, and the strangers' mail that was proposed as a lead — Make lead ·
 * Attach to contact (the picker) · Ignore on each, by the caller's right. Also the doors to the log form, the templates and the caller's own drafts. email.log opens it.
 */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_once dirname(__DIR__, 2) . '/app/features/email/present.php';
require_login();
require_right('email.log');
$pdo = db();
$page = max(1, request_integer('page') ?? 1);
$status = in_array($s = request_string('status'), ['unmatched', 'proposed_lead'], true) ? $s : null;
$list = unmatched_emails($pdo, $page, $status);
$me = (int) current_member_id();
$drafts = $pdo->prepare("SELECT o.id, o.subject, o.to_address::text AS to_address, o.status, o.error, o.created_at, c.name AS contact_name FROM outbound_email o LEFT JOIN contacts c ON c.id = o.contact_id
                          WHERE o.sender_member_id = :me AND o.status IN ('queued', 'failed') AND o.campaign_id IS NULL ORDER BY o.created_at DESC LIMIT 10");
$drafts->execute(['me' => $me]);
$drafts = $drafts->fetchAll();
log_screen_view($pdo, 'email-unmatched');
if (wants_json()) {
    respond_screen(['rows' => array_map('present_unmatched_row', $list['rows']), 'total' => $list['total'], 'page' => $list['page'], 'pages' => $list['pages'],
        'drafts' => array_map(static fn (array $d): array => ['draft_id' => (int) $d['id'], 'subject' => $d['subject'], 'to' => $d['contact_name'], 'status' => $d['status']], $drafts)]);
}
render_screen('Email', view('email/unmatched.php', ['list' => $list, 'drafts' => $drafts, 'status' => $status, 'tz' => member_timezone(), 'me' => $me,
    'may' => ['attach' => has_right('contacts.write'), 'lead' => has_right('leads.write'), 'compose' => has_right('email.send') || has_right('email.log'), 'templates' => true],
    'notice' => crm_notice($_GET['notice'] ?? null, ['attached' => ['success', 'Put the email on the contact.'], 'ignored' => ['success', 'Ignored.']])]),
    ['activeNav' => 'email', 'screen' => 'email-unmatched', 'entity' => 'email']);
