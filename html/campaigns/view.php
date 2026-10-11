<?php
declare(strict_types=1);
/**
 * /campaigns/{id} (screen `campaign-view`; params status, page): the results tiles (crm_campaign_results), its email (what is queued, sent and failed, and the form that queues it), the members with their status
 * (Add members by the picker or from a list), the leads and deals it produced. Notes below.
 */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_once dirname(__DIR__, 2) . '/app/features/records/queries.php';
require_once dirname(__DIR__, 2) . '/app/features/campaigns/queries.php';
require_once dirname(__DIR__, 2) . '/app/features/campaigns/present.php';
require_once dirname(__DIR__, 2) . '/app/features/lists/queries.php';
require_once dirname(__DIR__, 2) . '/app/features/leads/queries.php';
require_once dirname(__DIR__, 2) . '/app/features/pipeline/queries.php';
require_once dirname(__DIR__, 2) . '/app/features/notes/queries.php';
require_login();
require_right('records.read');
$pdo = db();
$id = request_integer('id');
require_record($pdo, 'campaign', $id);
$c = find_campaign($pdo, $id);
$status = isset(CAMPAIGN_MEMBER_STATUSES[$s = request_string('status')]) ? $s : null;
$page = max(1, request_integer('page') ?? 1);
$results = campaign_results($pdo, $id);
$members = campaign_members($pdo, $id, $status, $page);
$counts = campaign_member_counts($pdo, $id);
$batches = campaign_email_batches($pdo, $id);
$produced = campaign_produced($pdo, $id);
$may = ['manage' => has_right('campaigns.manage'), 'add' => has_right('campaigns.manage') || has_right('contacts.write'), 'email' => has_right('campaigns.manage') && has_right('email.send'), 'manager' => is_manager()];
log_screen_view($pdo, 'campaign-view');
if (wants_json()) {
    respond_screen(['campaign' => present_campaign($c, $results), 'member_counts' => (object) $counts, 'members' => array_map('present_campaign_member', $members['rows']), 'members_total' => $members['total'], 'page' => $page,
        'email' => ['queued' => $batches['queued'], 'sent' => $batches['sent'], 'failed' => $batches['failed']],
        'leads' => array_map(static fn (array $l): array => ['lead_id' => (int) $l['lead_id'], 'name' => $l['label'], 'status' => $l['status']], $produced['leads']),
        'deals' => array_map(static fn (array $d): array => ['deal_id' => (int) $d['deal_id'], 'number' => $d['number'], 'title' => $d['title'], 'value' => (float) $d['value'], 'currency' => $d['currency'], 'status' => $d['status']], $produced['deals']), 'may' => $may]);
}
$notices = ['created' => ['success', 'Added the campaign.'], 'updated' => ['success', 'Saved.'], 'member_added' => ['success', 'Added to the campaign.'], 'member_changed' => ['success', 'Saved.'], 'member_removed' => ['success', 'Taken out of the campaign.'],
    'email_queued' => ['success', 'Queued. The worker sends the messages within a minute or two.']];
render_screen($c['name'], view('campaigns/view.php', ['c' => $c, 'results' => $results, 'members' => $members, 'counts' => $counts, 'batches' => $batches, 'produced' => $produced, 'status' => $status, 'page' => $page, 'may' => $may,
    'notes' => find_notes($pdo, 'campaign', $id), 'me' => (int) current_member_id(), 'tz' => member_timezone(), 'notice' => $notices[$_GET['notice'] ?? ''] ?? null, 'back' => here_url()]),
    ['activeNav' => 'campaigns', 'screen' => 'campaign-view', 'entity' => 'campaign', 'recordId' => (string) $id]);
