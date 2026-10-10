<?php
declare(strict_types=1);
/** /leads/inbox (screen `lead-inbox`): Proposed (from the mailbox and the form — Accept · Dismiss) and Unowned (Assign · Take), with a bulk bar. */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_once dirname(__DIR__, 2) . '/app/features/records/queries.php';
require_once dirname(__DIR__, 2) . '/app/features/accounts/queries.php';
require_once dirname(__DIR__, 2) . '/app/features/contacts/queries.php';
require_once dirname(__DIR__, 2) . '/app/features/leads/queries.php';
require_once dirname(__DIR__, 2) . '/app/features/leads/write.php';
require_login();
require_right('records.read');
$pdo = db();
$box = inbox($pdo);
log_screen_view($pdo, 'lead-inbox');
if (wants_json()) {
    $p = static fn (array $r): array => ['lead_id' => (int) $r['lead_id'], 'label' => $r['label'], 'email' => $r['email'], 'company' => $r['company'], 'source' => $r['source'], 'created_at' => json_ts($r['created_at'])];
    respond_screen(['proposed' => array_map(static fn (array $r): array => $p($r) + ['subject' => $r['email_subject'], 'message' => $r['message'] !== null ? mb_substr((string) $r['message'], 0, 300) : null], $box['proposed']),
        'unowned' => array_map($p, $box['unowned'])]);
}
$notices = ['accepted' => ['success', 'Accepted into the book.'], 'dismissed' => ['success', 'Dismissed. It can be restored for a while.'], 'assigned' => ['success', 'Assigned.']];
render_screen('Lead inbox', view('leads/inbox.php', ['box' => $box, 'tz' => member_timezone(), 'may' => ['write' => has_right('leads.write'), 'assign' => has_right('leads.assign') || has_right('leads.write'), 'assign_any' => has_right('leads.assign')], 'me' => (int) current_member_id(),
    'notice' => $notices[$_GET['notice'] ?? ''] ?? null]), ['activeNav' => 'leads', 'screen' => 'lead-inbox', 'entity' => 'lead']);
