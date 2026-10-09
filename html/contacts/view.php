<?php
declare(strict_types=1);
/** /contacts/{id} (screen `contact-view`; param tab: overview, deals, activities, emails, notes, history). The wall: the same 404 for a contact the caller may not see. JSON: get_contact's shape with the tabs' rows. */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_once dirname(__DIR__, 2) . '/app/features/records/queries.php';
require_once dirname(__DIR__, 2) . '/app/features/accounts/queries.php';
require_once dirname(__DIR__, 2) . '/app/features/contacts/queries.php';
require_once dirname(__DIR__, 2) . '/app/features/contacts/present.php';
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
$row = require_record($pdo, 'contact', $id);
$c = find_contact($pdo, $id);
$tz = member_timezone();
$tags = record_tags($pdo, 'contact', $id);
$custom = custom_values_display($pdo, 'contact', json_decode((string) $c['custom_values'], true) ?: []);
$tab = in_array($t = request_string('tab'), ['overview', 'deals', 'activities', 'emails', 'notes', 'history'], true) ? $t : 'overview';
$editable = record_editable($pdo, 'contact', $row);
$data = [
    'c' => $c, 'tags' => $tags, 'custom' => $custom, 'tab' => $tab, 'tz' => $tz, 'me' => (int) current_member_id(),
    'addresses' => contact_addresses($pdo, $id), 'deals' => contact_deals($pdo, $id), 'next' => next_step_of($pdo, 'contact', $id), 'activities' => record_activities($pdo, 'contact', $id),
    'emails' => record_emails($pdo, 'contact', $id), 'notes' => find_notes($pdo, 'contact', $id), 'files' => find_attachments($pdo, 'contact', $id), 'timeline' => timeline($pdo, 'contact', $id, 100),
    'max_mb' => (int) round(attachment_max_bytes($pdo) / 1048576),
    'may' => ['edit' => $editable, 'tag' => $editable && has_right('tags.apply'), 'restrict' => has_right('records.restrict'), 'note' => has_right('records.read'), 'manager' => is_manager(),
              'log' => has_right('activities.write'), 'email' => has_right('email.send')],
    'notice' => crm_notice($_GET['notice'] ?? null, ['created' => ['success', 'Added the contact.'], 'updated' => ['success', 'Saved.'], 'opt_out' => ['warning', 'Recorded: Sales CRM will not contact them that way.'],
        'opt_in' => ['success', 'They may be contacted that way again.'], 'restricted' => ['warning', 'Restricted: only the owner, the team named and the managers see it now.'],
        'unrestricted' => ['success', 'Everyone here sees it again.'], 'restored' => ['success', 'Restored.'], 'note_added' => ['success', 'Added the note.'], 'attached' => ['success', 'Attached the file.'],
        'note_deleted' => ['success', 'Deleted the note.'], 'detached' => ['success', 'Deleted the file.']]),
];
log_screen_view($pdo, 'contact-view');
if (wants_json()) {
    respond_screen(['contact' => present_contact($c, $data['addresses'], $data['deals'], $data['next'], $tags, $custom), 'tab' => $tab,
        'activities' => array_map(static fn (array $x): array => ['activity_id' => (int) $x['activity_id'], 'kind' => $x['kind'], 'subject' => $x['subject'], 'at' => json_ts($x['at']), 'state' => $x['state']], array_merge($data['activities']['open'], $data['activities']['done'])),
        'emails' => array_map(static fn (array $m): array => ['direction' => $m['direction'], 'email_id' => (int) $m['email_id'], 'at' => json_ts($m['at']), 'subject' => $m['subject']], $data['emails']),
        'notes' => array_map(static fn (array $n): array => ['note_id' => (int) $n['note_id'], 'member_id' => $n['member_id'] === null ? null : (int) $n['member_id'], 'body' => $n['body'], 'created_at' => json_ts($n['created_at'])], $data['notes']),
        'files' => array_map(static fn (array $f): array => ['attachment_id' => (int) $f['attachment_id'], 'filename' => $f['filename'], 'mime_type' => $f['mime_type'], 'byte_size' => (int) $f['byte_size'], 'url' => '/attachments/' . (int) $f['attachment_id']], $data['files']),
        'history' => array_map('present_timeline_row', $data['timeline']), 'may' => $data['may']]);
}
render_screen($c['name'] !== '' ? $c['name'] : (string) $c['email'], view('contacts/view.php', $data), ['activeNav' => 'contacts', 'screen' => 'contact-view', 'entity' => 'contact', 'recordId' => (string) $id]);
