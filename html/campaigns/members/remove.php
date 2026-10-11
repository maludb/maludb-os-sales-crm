<?php
declare(strict_types=1);
/** Action `campaign_member_remove` (log campaign.member_remove — campaigns.manage): `campaign`, `contact` (or `lead`). The person stays; only the membership goes. */
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
$contact = request_integer('contact');
$lead = request_integer('lead');
if ($contact === null && $lead === null) { crm_refuse_fields(['contact' => 'Say who to take out: a contact or a lead.']); }
$was = crm_guard($pdo, static function () use ($pdo, $c, $lead, $contact): bool {
    $pdo->beginTransaction();
    $was = campaign_member_remove($pdo, $c, $lead, $contact);
    if (!$was) { throw new DomainException('That is not a member of this campaign.'); }
    log_activity($pdo, 'campaign.member_remove', 'campaign', $c, array_filter(['contact_id' => $contact, 'lead_id' => $lead]));
    $pdo->commit();
    return $was;
});
crm_done('Taken out of the campaign', $c, crm_land(return_path('/campaigns/' . $c), 'member_removed'), 'campaignChanged', ['undo' => 'campaign_member_add']);
