<?php
declare(strict_types=1);
/** Action `lead_dismiss_proposed` (log lead.dismiss — leads.write; PAUSES FOR AN AGENT — deletion): a proposed lead to the tombstone (record_restore brings it back). */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_once dirname(__DIR__, 2) . '/app/features/records/queries.php';
require_once dirname(__DIR__, 2) . '/app/features/accounts/queries.php';
require_once dirname(__DIR__, 2) . '/app/features/contacts/queries.php';
require_once dirname(__DIR__, 2) . '/app/features/leads/queries.php';
require_once dirname(__DIR__, 2) . '/app/features/leads/write.php';
crm_handler_begin();
require_right('leads.write');
$pdo = db();
$id = request_integer('lead') ?? request_integer('id');
$row = require_record($pdo, 'lead', $id);
if (!(bool) one_value($pdo, 'SELECT is_proposed FROM leads WHERE id = :id', ['id' => $id])) { refuse(422, 'Only a proposed lead is dismissed; delete a lead in the book instead.'); }
crm_guard($pdo, static function () use ($pdo, $id, $row): void {
    $pdo->beginTransaction();
    lead_dismiss($pdo, $id);
    log_activity($pdo, 'lead.dismiss', 'lead', $id, ['after' => ['label' => $row['name'], 'tombstone' => true]]);
    $pdo->commit();
});
crm_done('Dismissed ' . $row['name'], $id, crm_land(return_path('/leads/inbox'), 'dismissed'), 'leadChanged', ['undo' => 'record_restore']);
