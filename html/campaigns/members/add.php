<?php
declare(strict_types=1);
/**
 * Action `campaign_member_add` (log campaign.member_add — campaigns.manage or contacts.write): `campaign`, one of `lead` / `contact` — or `leads[]` / `contacts[]` — and a `status` (planned by default; planned,
 * sent, opened, responded, attended, converted). Only records the caller may see are added; one already in the campaign keeps its status. Several at once: one log row with the count.
 */
require_once dirname(__DIR__, 3) . '/app/bootstrap.php';
require_once dirname(__DIR__, 3) . '/app/features/records/queries.php';
require_once dirname(__DIR__, 3) . '/app/features/campaigns/queries.php';
require_once dirname(__DIR__, 3) . '/app/features/campaigns/write.php';
crm_handler_begin();
require_any_right('campaigns.manage|contacts.write');
$pdo = db();
$c = request_integer('campaign');
$camp = $c === null ? null : find_campaign($pdo, $c);
if ($camp === null) { refuse(404, 'Campaign not found.'); }
$errors = [];
$leads = member_ids_from_post('leads', 'lead');
$contacts = member_ids_from_post('contacts', 'contact');
if ($leads === [] && $contacts === []) { $errors['contact'] = 'Say who to add: a contact or a lead.'; }
$status = crm_choice('status', 'planned', CAMPAIGN_MEMBER_SETTABLE, 'The status', $errors, false) ?? 'planned';
if (count($leads) + count($contacts) > 500) { $errors['contacts'] = 'Add up to 500 at a time; a list adds more.'; }
if ($errors !== []) { crm_refuse_fields($errors); }
$r = crm_guard($pdo, static function () use ($pdo, $c, $leads, $contacts, $status): array {
    $pdo->beginTransaction();
    $r = campaign_member_add($pdo, $c, $leads, $contacts, $status);
    if ($r['missing'] !== []) { throw new DomainException('Not found.'); }
    log_activity($pdo, 'campaign.member_add', 'campaign', $c, ['after' => ['added' => count($r['added']), 'already' => $r['already'], 'status' => $status, 'leads' => count($leads), 'contacts' => count($contacts)]]);
    $pdo->commit();
    return $r;
});
$n = count($r['added']);
crm_done($n === 0 ? 'Already in the campaign' : 'Added ' . $n . ($n === 1 ? ' person' : ' people') . ' to ' . $camp['name'], $c, crm_land(return_path('/campaigns/' . $c), 'member_added'), 'campaignChanged', ['added' => $r['added'], 'already' => $r['already']]);

/** The ids a request names for one kind: `leads[]` / `contacts[]` (a form or an agent) and the single `lead` / `contact`. */
function member_ids_from_post(string $many, string $one): array
{
    $ids = request_list($many) ?? [];
    if (($v = req_val($one)) !== null && $v !== '') { $ids[] = $v; }
    foreach ($ids as $x) { if (!ctype_digit((string) $x)) { crm_refuse_fields([$one => 'Choose the ' . $one . ' from the list.']); } }
    return array_values(array_unique(array_map('intval', $ids)));
}
