<?php
declare(strict_types=1);
/** Action `dispatch_cancel` (log `dispatch.cancel`; people only, `agents.settings`): a dispatch still waiting (sent, or waiting for approval) is called off — refused, in the words "Cancelled by NAME". One already answered, failed or cancelled is refused in words. */
require_once dirname(__DIR__, 3) . '/app/bootstrap.php';
require_once dirname(__DIR__, 3) . '/app/features/agents/write.php';
crm_handler_begin();
require_human();
require_right('agents.settings');
$pdo = db();
$me = (int) current_member_id();
$id = request_integer('dispatch') ?? request_integer('id');
$d = $id === null ? null : find_dispatch($pdo, $id);
if ($d === null) {
    refuse(404, 'Dispatch not found.');
}
if (!in_array($d['status'], ['sent', 'awaiting_approval'], true)) {
    refuse(422, 'That dispatch is over already (' . strtolower(dispatch_status_words($d)[0]) . ') — there is nothing to cancel.');
}
crm_guard($pdo, static function () use ($pdo, $d, $me, $id): void {
    $pdo->beginTransaction();
    if (!dispatch_cancel($pdo, $id, $me)) {
        throw new DomainException('That dispatch is over already — there is nothing to cancel.');
    }
    log_activity($pdo, 'dispatch.cancel', 'agent_dispatch', $id, ['after' => ['kind' => $d['kind'], 'agent_member_id' => (int) $d['agent_member_id']]]);
    $pdo->commit();
});
crm_done('Cancelled the dispatch', $id, crm_land('/admin/agents', 'cancelled'), 'agentChanged');
