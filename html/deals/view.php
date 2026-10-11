<?php
declare(strict_types=1);
/**
 * /deals/{id} (screen `deal-view`; param tab: overview, activities, emails, notes, history): the header (number, title, account, value · weighted, close, owner;
 * Log call · Schedule · Send email · Won · Lost · ⋯ Edit · Reassign · Restrict · Delete), the STAGE BAR (a click is deal_move; a stage the deal may not enter
 * shows what it lacks), the tabs. A deal behind the wall is the same 404 as a missing one. JSON: get_deal (present_deal).
 */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_once dirname(__DIR__, 2) . '/app/features/deals/write.php';
require_once dirname(__DIR__, 2) . '/app/features/notes/queries.php';
require_once dirname(__DIR__, 2) . '/app/features/attachments/store.php';
require_once dirname(__DIR__, 2) . '/app/features/attachments/queries.php';
require_once dirname(__DIR__, 2) . '/app/features/tags/queries.php';
require_once dirname(__DIR__, 2) . '/app/features/timeline/queries.php';
require_once dirname(__DIR__, 2) . '/app/features/timeline/present.php';
require_once dirname(__DIR__, 2) . '/app/features/activity/queries.php';
require_login();
require_right('records.read');
$pdo = db();
$id = request_integer('id');
$row = require_record($pdo, 'deal', $id);
$d = find_deal($pdo, $id);
$summary = deal_summary($pdo, $id, $d);
$custom = custom_values_display($pdo, 'deal', $d['custom_values'], (int) $d['pipeline_id']);
$tab = in_array($t = request_string('tab'), ['overview', 'activities', 'emails', 'notes', 'history'], true) ? $t : 'overview';
$editable = record_editable($pdo, 'deal', $row);
$me = (int) current_member_id();
$data = [
    'd' => $d, 'summary' => $summary, 'custom' => $custom, 'tab' => $tab, 'tz' => member_timezone(), 'me' => $me, 'tags' => record_tags($pdo, 'deal', $id),
    'activities' => record_activities($pdo, 'deal', $id), 'emails' => record_emails($pdo, 'deal', $id), 'notes' => find_notes($pdo, 'deal', $id), 'files' => find_attachments($pdo, 'deal', $id),
    'timeline' => timeline($pdo, 'deal', $id, 100), 'max_mb' => (int) round(attachment_max_bytes($pdo) / 1048576), 'reasons' => find_deal_reasons($pdo),
    'products_connected' => $editable && products_connected($pdo),
    'may' => ['edit' => $editable, 'tag' => $editable && has_right('tags.apply'), 'restrict' => has_right('records.restrict'), 'delete' => $editable, 'manager' => is_manager(),
              'reopen' => has_right('deals.any'), 'assign' => is_manager() || ($editable && (int) ($d['owner_member_id'] ?? 0) === $me), 'assign_any' => is_manager(),
              'log' => has_right('activities.write'), 'email' => has_right('email.send'), 'note' => true],
    'notice' => crm_notice($_GET['notice'] ?? null, ['created' => ['success', 'Added the deal.'], 'email_sent' => ['success', 'Sent.'], 'email_partial' => ['warning', 'Sent to some of them; the rest did not go — open the draft to send it again.'], 'email_logged' => ['success', 'Logged the email.'], 'converted' => ['success', 'Converted the lead: the account, the contact and this deal.'], 'updated' => ['success', 'Saved.'], 'moved' => ['success', 'Moved.'], 'won' => ['success', 'Won — well done.'],
        'lost' => ['warning', 'Marked lost.'], 'reopened' => ['success', 'Reopened.'], 'assigned' => ['success', 'Reassigned.'], 'product_added' => ['success', 'Added the line.'], 'product_saved' => ['success', 'Saved the line.'],
        'product_removed' => ['success', 'Removed the line.'], 'contact_added' => ['success', 'Added the person.'], 'contact_saved' => ['success', 'Saved.'], 'contact_removed' => ['success', 'Took them off the deal.'],
        'restricted' => ['warning', 'Restricted: only the owner, the team named and the managers see it now.'], 'unrestricted' => ['success', 'Everyone here sees it again.'], 'restored' => ['success', 'Restored.'],
        'note_added' => ['success', 'Added the note.'], 'attached' => ['success', 'Attached the file.'], 'note_deleted' => ['success', 'Deleted the note.'], 'detached' => ['success', 'Deleted the file.']]),
];
log_screen_view($pdo, 'deal-view');
if (wants_json()) {
    respond_screen(['deal' => present_deal($d, $summary, $custom), 'tab' => $tab, 'tags' => $data['tags'],
        'activities' => array_map(static fn (array $x): array => ['activity_id' => (int) $x['activity_id'], 'kind' => $x['kind'], 'subject' => $x['subject'], 'at' => json_ts($x['at']), 'state' => $x['state']], array_merge($data['activities']['open'], $data['activities']['done'])),
        'emails' => array_map(static fn (array $m): array => ['direction' => $m['direction'], 'email_id' => (int) $m['email_id'], 'at' => json_ts($m['at']), 'subject' => $m['subject']], $data['emails']),
        'notes' => array_map(static fn (array $n): array => ['note_id' => (int) $n['note_id'], 'body' => $n['body'], 'created_at' => json_ts($n['created_at'])], $data['notes']),
        'files' => array_map(static fn (array $f): array => ['attachment_id' => (int) $f['attachment_id'], 'filename' => $f['filename'], 'byte_size' => (int) $f['byte_size'], 'url' => '/attachments/' . (int) $f['attachment_id']], $data['files']),
        'history' => array_map('present_timeline_row', $data['timeline']), 'may' => $data['may']]);
}
render_screen($d['title'], view('deals/view.php', $data), ['activeNav' => 'pipeline', 'screen' => 'deal-view', 'entity' => 'deal', 'recordId' => (string) $id]);
