<?php
declare(strict_types=1);
/**
 * Action `deal_reopen` (log deal.reopen {from_stage, to_stage, stage_name} — deals.any; an agent's pauses for approval, category other): a won or lost deal back
 * to the open stage it left (or the pipeline's last open stage); the database clears the close stamps and the reason. A Rep's is refused.
 */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_once dirname(__DIR__, 2) . '/app/features/deals/write.php';
crm_handler_begin();
require_right('deals.any');
$pdo = db();
$id = request_integer('deal') ?? request_integer('id');
require_record($pdo, 'deal', $id);
$cur = deal_current($pdo, $id);
if ($cur['status'] === 'open') { refuse(422, 'The deal is open — nothing to reopen.'); }
$m = deal_guard($pdo, static function () use ($pdo, $id, $cur): array {
    $pdo->beginTransaction();
    $m = deal_reopen($pdo, $id);
    log_activity($pdo, 'deal.reopen', 'deal', $id, ['deal_id' => $id, 'account_id' => $cur['account_id'], 'contact_id' => $cur['primary_contact_id'], 'before' => ['status' => $cur['status']], 'after' => $m + ['status' => 'open']]);
    $pdo->commit();
    return $m;
});
crm_done('Reopened ' . $cur['title'] . ' in ' . $m['stage_name'], $id, crm_land(return_path('/deals/' . $id), 'reopened'), 'dealChanged');
