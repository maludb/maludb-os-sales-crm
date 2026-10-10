<?php
declare(strict_types=1);
/** Action `deal_contact_remove` (log deal.contact_remove {contact_id, role} — the edit rule): `deal`, `contact`. Never the primary — set another first. */
require_once dirname(__DIR__, 3) . '/app/bootstrap.php';
require_once dirname(__DIR__, 3) . '/app/features/deals/write.php';
crm_handler_begin();
$pdo = db();
$dealId = request_integer('deal') ?? request_integer('id');
$row = require_record($pdo, 'deal', $dealId);
require_editable($pdo, 'deal', $row);
$contact = request_integer('contact');
$link = $contact === null ? null : one_row($pdo, 'SELECT role, is_primary FROM deal_contacts WHERE deal_id = :d AND contact_id = :c', ['d' => $dealId, 'c' => $contact]);
if ($link === null) { refuse(404, 'That person is not on the deal.'); }
if ($link['is_primary']) { crm_refuse_fields(['contact' => 'That is the primary contact — make someone else primary first.']); }
$name = (string) one_value($pdo, 'SELECT name FROM contacts WHERE id = :c', ['c' => $contact]);
crm_guard($pdo, static function () use ($pdo, $dealId, $contact, $link): void {
    $pdo->beginTransaction();
    deal_contact_remove($pdo, $dealId, $contact);
    log_activity($pdo, 'deal.contact_remove', 'deal', $dealId, ['deal_id' => $dealId, 'contact_id' => $contact, 'before' => ['contact_id' => $contact, 'role' => $link['role']]]);
    $pdo->commit();
});
crm_done('Took ' . $name . ' off the deal', $dealId, crm_land(return_path('/deals/' . $dealId), 'contact_removed', 'deal-contacts'), 'dealChanged');
