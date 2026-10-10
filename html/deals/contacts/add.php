<?php
declare(strict_types=1);
/**
 * Actions `deal_contact_add` (log deal.contact_add) and — through contacts/save.php — `deal_contact_update` (log deal.contact_update): `deal`, `contact`, role
 * (CONTACT_ROLES; the contact's own when omitted on add), is_primary (yes makes them the deal's primary contact). The edit rule on the deal.
 */
require_once dirname(__DIR__, 3) . '/app/bootstrap.php';
require_once dirname(__DIR__, 3) . '/app/features/deals/write.php';
crm_handler_begin();
$pdo = db();
$updating = basename($_SERVER['SCRIPT_FILENAME'] ?? '') === 'save.php' || ($GLOBALS['__deal_contact_update'] ?? false);
$dealId = request_integer('deal') ?? request_integer('id');
$row = require_record($pdo, 'deal', $dealId);
require_editable($pdo, 'deal', $row);
$errors = [];
$contact = crm_ref($pdo, 'contact', null, 'SELECT 1 FROM mcp_contacts WHERE contact_id = :id', 'contact', $errors, false);
if (!req_has('contact')) { $errors['contact'] = 'Say who.'; }
if ($errors !== []) { crm_refuse_fields($errors); }
$link = one_row($pdo, 'SELECT role, is_primary FROM deal_contacts WHERE deal_id = :d AND contact_id = :c', ['d' => $dealId, 'c' => $contact]);
if ($updating && $link === null) { refuse(404, 'That person is not on the deal.'); }
if (!$updating && $link !== null) { refuse(422, 'That person is on the deal already — change their role instead.'); }
if (req_has('role') && (string) req_val('role') === '') { unset($_POST['role']); }    // "their usual role"
$defaultRole = $link['role'] ?? (string) (one_value($pdo, 'SELECT role FROM contacts WHERE id = :c', ['c' => $contact]) ?? 'other');
$role = crm_choice('role', in_array($defaultRole, array_keys(CONTACT_ROLES), true) ? $defaultRole : 'other', array_keys(CONTACT_ROLES), 'The role', $errors, false) ?? 'other';
$primary = crm_yes('is_primary', (bool) ($link['is_primary'] ?? false));
if ($link !== null && $link['is_primary'] && !$primary) { $errors['is_primary'] = 'Make someone else the primary contact first.'; }
if ($errors !== []) { crm_refuse_fields($errors); }
$name = (string) one_value($pdo, 'SELECT name FROM contacts WHERE id = :c', ['c' => $contact]);
crm_guard($pdo, static function () use ($pdo, $dealId, $contact, $role, $primary, $link, $updating): void {
    $pdo->beginTransaction();
    deal_contact_set($pdo, $dealId, $contact, $role, $primary);
    $after = ['contact_id' => $contact, 'role' => $role, 'is_primary' => $primary];
    log_activity($pdo, $updating ? 'deal.contact_update' : 'deal.contact_add', 'deal', $dealId, ['deal_id' => $dealId, 'contact_id' => $contact,
        'before' => $link === null ? null : ['role' => $link['role'], 'is_primary' => (bool) $link['is_primary']], 'after' => $after]);
    $pdo->commit();
});
crm_done(($updating ? 'Saved ' : 'Added ') . $name . ($primary ? ' as the primary contact' : ' as ' . strtolower(CONTACT_ROLES[$role])), $dealId, crm_land(return_path('/deals/' . $dealId), $updating ? 'contact_saved' : 'contact_added', 'deal-contacts'), 'dealChanged');
