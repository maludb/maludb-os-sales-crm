<?php
declare(strict_types=1);
/** /leads/{id} (screen `lead-view`): the fields, the score, the duplicates, the three records once converted, notes and files, the timeline; Convert · Unqualify · Assign. */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_once dirname(__DIR__, 2) . '/app/features/records/queries.php';
require_once dirname(__DIR__, 2) . '/app/features/accounts/queries.php';
require_once dirname(__DIR__, 2) . '/app/features/contacts/queries.php';
require_once dirname(__DIR__, 2) . '/app/features/leads/queries.php';
require_once dirname(__DIR__, 2) . '/app/features/leads/write.php';
require_once dirname(__DIR__, 2) . '/app/features/leads/present.php';
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
$row = require_record($pdo, 'lead', $id);
$l = find_lead($pdo, $id);
$tags = record_tags($pdo, 'lead', $id);
$custom = custom_values_display($pdo, 'lead', json_decode((string) $l['custom_values'], true) ?: []);
$dupes = lead_duplicates($pdo, $id);
$editable = record_editable($pdo, 'lead', $row);
$open = $l['converted_at'] === null;
$data = ['l' => $l, 'tags' => $tags, 'custom' => $custom, 'dupes' => $dupes, 'tz' => member_timezone(), 'me' => (int) current_member_id(),
    'notes' => find_notes($pdo, 'lead', $id), 'files' => find_attachments($pdo, 'lead', $id), 'activities' => record_activities($pdo, 'lead', $id), 'timeline' => timeline($pdo, 'lead', $id, 100),
    'reasons' => find_lead_reasons($pdo), 'max_mb' => (int) round(attachment_max_bytes($pdo) / 1048576),
    'may' => ['edit' => $editable && $open, 'convert' => $editable && $open && has_right('leads.convert') && $l['status'] !== 'unqualified', 'tag' => $editable && has_right('tags.apply'),
        'assign' => $open && (has_right('leads.assign') || ($editable && has_right('leads.write'))), 'assign_any' => has_right('leads.assign'), 'note' => true, 'manager' => is_manager(),
        'restrict' => has_right('records.restrict'), 'log' => has_right('activities.write'), 'proposed' => (bool) $l['is_proposed'] && has_right('leads.write')],
    'notice' => crm_notice($_GET['notice'] ?? null, ['created' => ['success', 'Added the lead.'], 'updated' => ['success', 'Saved.'], 'qualified' => ['success', 'Saved the score and status.'],
        'unqualified' => ['warning', 'Unqualified. Requalify brings it back.'], 'requalified' => ['success', 'Back in work.'], 'assigned' => ['success', 'Assigned.'], 'converted' => ['success', 'Converted.'],
        'note_added' => ['success', 'Added the note.'], 'attached' => ['success', 'Attached the file.'], 'restored' => ['success', 'Restored.'], 'restricted' => ['warning', 'Restricted.'], 'unrestricted' => ['success', 'Open to everyone again.']]),
];
log_screen_view($pdo, 'lead-view');
if (wants_json()) {
    respond_screen(['lead' => present_lead($l, $tags, $custom, $dupes), 'history' => array_map('present_timeline_row', $data['timeline']), 'may' => $data['may']]);
}
render_screen($l['label'], view('leads/view.php', $data), ['activeNav' => 'leads', 'screen' => 'lead-view', 'entity' => 'lead', 'recordId' => (string) $id]);
