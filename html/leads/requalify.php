<?php
declare(strict_types=1);
/** Action `lead_requalify` (log lead.requalify — the edit rule): an unqualified lead back to working (its reason cleared). */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_once dirname(__DIR__, 2) . '/app/features/records/queries.php';
require_once dirname(__DIR__, 2) . '/app/features/accounts/queries.php';
require_once dirname(__DIR__, 2) . '/app/features/contacts/queries.php';
require_once dirname(__DIR__, 2) . '/app/features/leads/queries.php';
require_once dirname(__DIR__, 2) . '/app/features/leads/write.php';
crm_handler_begin();
$pdo = db();
$id = request_integer('lead') ?? request_integer('id');
$row = require_record($pdo, 'lead', $id);
require_right('leads.write');
require_editable($pdo, 'lead', $row);
$cur = lead_current($pdo, $id);
if ($cur['status'] !== 'unqualified') { refuse(422, 'Only an unqualified lead is requalified.'); }
crm_guard($pdo, static function () use ($pdo, $id): void {
    $pdo->beginTransaction();
    lead_requalify($pdo, $id);
    log_activity($pdo, 'lead.requalify', 'lead', $id, ['before' => ['status' => 'unqualified'], 'after' => ['status' => 'working']]);
    $pdo->commit();
});
crm_done('The lead is back in work', $id, crm_land(return_path('/leads/' . $id), 'requalified'), 'leadChanged');
