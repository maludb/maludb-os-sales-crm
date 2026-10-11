<?php
declare(strict_types=1);
/** Action `campaign_member_status` (log campaign.member_status — campaigns.manage): `campaign`, `contact` (or `lead`), `status` (planned, sent, opened, responded, attended, converted). A response stamps when it came. */
require_once dirname(__DIR__, 3) . '/app/bootstrap.php';
require_once dirname(__DIR__, 3) . '/app/features/records/queries.php';
require_once dirname(__DIR__, 3) . '/app/features/campaigns/queries.php';
require_once dirname(__DIR__, 3) . '/app/features/campaigns/write.php';
crm_handler_begin();
require_right('campaigns.manage');
$pdo = db();
$c = request_integer('campaign');
$camp = $c === null ? null : find_campaign($pdo, $c);
if ($camp === null) { refuse(404, 'Campaign not found.'); }
$errors = [];
$contact = request_integer('contact');
$lead = request_integer('lead');
if ($contact === null && $lead === null) { $errors['contact'] = 'Say whose status: a contact or a lead.'; }
$status = crm_choice('status', null, CAMPAIGN_MEMBER_SETTABLE, 'The status', $errors, false);
if ($status === null && !isset($errors['status'])) { $errors['status'] = 'Say the new status.'; }
if ($errors !== []) { crm_refuse_fields($errors); }
$prev = crm_guard($pdo, static function () use ($pdo, $c, $lead, $contact, $status): string {
    $pdo->beginTransaction();
    $prev = campaign_member_status($pdo, $c, $lead, $contact, $status);
    log_activity($pdo, 'campaign.member_status', 'campaign', $c, array_filter(['contact_id' => $contact, 'lead_id' => $lead]) + ['before' => ['status' => $prev], 'after' => ['status' => $status]]);
    $pdo->commit();
    return $prev;
});
crm_done($prev === $status ? 'Already ' . $status : 'Marked ' . $status, $c, crm_land(return_path('/campaigns/' . $c), 'member_changed'), 'campaignChanged', ['previous' => $prev]);
