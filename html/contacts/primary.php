<?php
declare(strict_types=1);
/** Action `contact_set_primary` (log contact.primary — the edit rule): the contact becomes their account's primary contact; the one before loses it. */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_once dirname(__DIR__, 2) . '/app/features/records/queries.php';
require_once dirname(__DIR__, 2) . '/app/features/contacts/write.php';
crm_handler_begin();
$pdo = db();
$id = request_integer('contact') ?? request_integer('id');
$row = require_record($pdo, 'contact', $id);
require_editable($pdo, 'contact', $row);
if ($row['account_id'] === null) {
    crm_refuse_fields(['contact' => 'Only a contact at an account can be its primary contact.']);
}
$before = one_value($pdo, 'SELECT id FROM contacts WHERE account_id = :a AND is_primary AND deleted_at IS NULL', ['a' => (int) $row['account_id']]);
crm_guard($pdo, static function () use ($pdo, $id, $row, $before): void {
    $pdo->beginTransaction();
    contact_set_primary($pdo, $id);
    log_activity($pdo, 'contact.primary', 'contact', $id, ['account_id' => (int) $row['account_id'], 'before' => ['primary_contact_id' => $before === null ? null : (int) $before], 'after' => ['primary_contact_id' => $id, 'name' => $row['name']]]);
    $pdo->commit();
});
crm_done($row['name'] . ' is now the primary contact', $id, crm_land(return_path('/accounts/' . (int) $row['account_id'] . '#account-tab-contacts'), 'primary'), 'contactChanged');
