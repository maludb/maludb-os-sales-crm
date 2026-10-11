<?php
declare(strict_types=1);
/**
 * /accounts/{id} (screen `account-view`; param tab: overview, contacts, deals, activities, emails, notes, tickets, history). The wall: an account the caller
 * may not see answers the same 404 as a missing one. JSON: get_account's shape with the tabs' rows.
 */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_once dirname(__DIR__, 2) . '/app/features/records/queries.php';
require_once dirname(__DIR__, 2) . '/app/features/accounts/queries.php';
require_once dirname(__DIR__, 2) . '/app/features/accounts/present.php';
require_once dirname(__DIR__, 2) . '/app/features/contacts/queries.php';
require_once dirname(__DIR__, 2) . '/app/features/notes/queries.php';
require_once dirname(__DIR__, 2) . '/app/features/attachments/store.php';
require_once dirname(__DIR__, 2) . '/app/features/attachments/queries.php';
require_once dirname(__DIR__, 2) . '/app/features/tags/queries.php';
require_once dirname(__DIR__, 2) . '/app/features/timeline/queries.php';
require_once dirname(__DIR__, 2) . '/app/features/timeline/present.php';
require_once dirname(__DIR__, 2) . '/app/features/custom_fields/queries.php';
require_once dirname(__DIR__, 2) . '/app/features/activity/queries.php';
require_login();
require_right('records.read');
$pdo = db();
$id = request_integer('id');
$row = require_record($pdo, 'account', $id);
$a = find_account($pdo, $id);
$me = (int) current_member_id();
$tz = member_timezone();
$summary = account_summary($pdo, $id);
$tags = record_tags($pdo, 'account', $id);
$customValues = json_decode((string) $a['custom_values'], true) ?: [];
$custom = custom_values_display($pdo, 'account', $customValues);
$tab = in_array($t = request_string('tab'), ['overview', 'contacts', 'deals', 'activities', 'emails', 'notes', 'tickets', 'history'], true) ? $t : 'overview';
$editable = record_editable($pdo, 'account', $row);
$data = [
    'a' => $a, 'summary' => $summary, 'tags' => $tags, 'custom' => $custom, 'tab' => $tab, 'tz' => $tz, 'me' => $me,
    'contacts' => account_contacts($pdo, $id), 'deals' => account_deals($pdo, $id), 'activities' => record_activities($pdo, 'account', $id),
    'emails' => record_emails($pdo, 'account', $id), 'notes' => find_notes($pdo, 'account', $id), 'files' => find_attachments($pdo, 'account', $id),
    'timeline' => timeline($pdo, 'account', $id, 100), 'tickets' => k7_account_read($pdo, 'helpdesk', 'tickets_by_organization', $id, ['domain' => $a['domain']]),
    'ledger' => k7_account_read($pdo, 'gl', 'customer_by_crm_account', $id),
    'max_mb' => (int) round(attachment_max_bytes($pdo) / 1048576),
    'may' => ['edit' => $editable, 'tag' => $editable && has_right('tags.apply'), 'restrict' => has_right('records.restrict'), 'delete' => $editable, 'note' => has_right('records.read'),
              'manager' => is_manager(), 'contact' => has_right('contacts.write'), 'deal' => has_right('deals.write'), 'log' => has_right('activities.write'), 'email' => has_right('email.send')],
    'notice' => crm_notice($_GET['notice'] ?? null, ['created' => ['success', 'Added the account.'], 'email_sent' => ['success', 'Sent.'], 'email_partial' => ['warning', 'Sent to some of them; the rest did not go — open the draft to send it again.'], 'email_logged' => ['success', 'Logged the email.'], 'updated' => ['success', 'Saved.'], 'restricted' => ['warning', 'Restricted: only the owner, the team named and the managers see it now.'],
        'unrestricted' => ['success', 'Everyone here sees it again.'], 'restored' => ['success', 'Restored.'], 'note_added' => ['success', 'Added the note.'], 'attached' => ['success', 'Attached the file.'],
        'primary' => ['success', 'Changed the primary contact.'], 'note_deleted' => ['success', 'Deleted the note.'], 'detached' => ['success', 'Deleted the file.']]),
];
log_screen_view($pdo, 'account-view');
if (wants_json()) {
    respond_screen(['account' => present_account($a, $summary, $tags, $custom), 'tab' => $tab,
        'contacts' => array_map(static fn (array $c): array => ['contact_id' => (int) $c['contact_id'], 'name' => $c['name'], 'title' => $c['title'], 'email' => $c['email'], 'role' => $c['role'], 'is_primary' => (bool) $c['is_primary']], $data['contacts']),
        'deals' => array_map(static fn (array $d): array => ['deal_id' => (int) $d['deal_id'], 'number' => $d['number'], 'title' => $d['title'], 'stage_name' => $d['stage_name'], 'status' => $d['status'], 'value' => (float) $d['value'], 'currency' => $d['currency'], 'expected_close_date' => $d['expected_close_date']], $data['deals']),
        'activities' => array_map(static fn (array $x): array => ['activity_id' => (int) $x['activity_id'], 'kind' => $x['kind'], 'subject' => $x['subject'], 'at' => json_ts($x['at']), 'state' => $x['state']], array_merge($data['activities']['open'], $data['activities']['done'])),
        'emails' => array_map(static fn (array $m): array => ['direction' => $m['direction'], 'email_id' => (int) $m['email_id'], 'at' => json_ts($m['at']), 'subject' => $m['subject']], $data['emails']),
        'notes' => array_map(static fn (array $n): array => ['note_id' => (int) $n['note_id'], 'member_id' => $n['member_id'] === null ? null : (int) $n['member_id'], 'body' => $n['body'], 'created_at' => json_ts($n['created_at'])], $data['notes']),
        'files' => array_map(static fn (array $f): array => ['attachment_id' => (int) $f['attachment_id'], 'filename' => $f['filename'], 'mime_type' => $f['mime_type'], 'byte_size' => (int) $f['byte_size'], 'url' => '/attachments/' . (int) $f['attachment_id']], $data['files']),
        'tickets' => ['status' => $data['tickets']['status'], 'rows' => array_map(static fn (array $t): array => array_intersect_key($t, array_flip(['number', 'subject', 'status', 'priority', 'updated_at', 'url'])), array_slice($data['tickets']['rows'], 0, 25))], 'history' => array_map('present_timeline_row', $data['timeline']), 'may' => $data['may']]);
}
render_screen($a['name'], view('accounts/view.php', $data), ['activeNav' => 'accounts', 'screen' => 'account-view', 'entity' => 'account', 'recordId' => (string) $id]);
