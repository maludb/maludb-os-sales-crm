<?php
declare(strict_types=1);
/**
 * Action `key_revoke` (log `key.revoke`; confirm): stops a key at once. The owner's own key, or any key for a holder of tokens.manage (a lead-capture key included); a key the caller may not see
 * answers 404 (the view is the wall). People only. `return_to` (a local path) says where to land — the admin's keys screen (slice 9) revokes through this same handler.
 */
require_once dirname(__DIR__, 3) . '/app/bootstrap.php';
require_once dirname(__DIR__, 3) . '/app/features/keys/write.php';
crm_handler_begin();
require_human();
$pdo = db();
$me = (int) current_member_id();
$id = request_integer('api_key') ?? request_integer('key') ?? request_integer('id');
$k = $id === null ? null : find_key($pdo, $id);
if ($k === null || $k['revoked_at'] !== null) {
    refuse(404, 'Key not found.');
}
if ((int) $k['member_id'] !== $me && !has_right('tokens.manage')) {
    refuse(404, 'Key not found.');
}
crm_guard($pdo, static function () use ($pdo, $k, $id): void {
    $pdo->beginTransaction();
    key_revoke($pdo, $id);
    log_activity($pdo, 'key.revoke', 'mcp_access_token', $id, ['token_id' => $id, 'before' => ['label' => $k['label'], 'member_id' => (int) $k['member_id'], 'lead_capture' => $k['lead_source_id'] !== null]]);
    $pdo->commit();
});
crm_done('Revoked the key "' . $k['label'] . '"', $id, crm_land(return_path('/settings/tokens/'), null, 'key-' . $id), 'tokenChanged');
