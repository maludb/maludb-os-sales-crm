<?php
declare(strict_types=1);
/**
 * Action `key_rotate` (log `key.rotate`; confirm): a new key for the same person, label and limits — the OLD one keeps working for the overlap (24 hours by default) so a deployment can switch over, then expires.
 * The caller's own live key only; another person's answers 404. The new key is shown once. People only.
 */
require_once dirname(__DIR__, 3) . '/app/bootstrap.php';
require_once dirname(__DIR__, 3) . '/app/features/keys/write.php';
crm_handler_begin();
require_human();
$pdo = db();
$me = (int) current_member_id();
$id = request_integer('api_key') ?? request_integer('key') ?? request_integer('id');
$old = $id === null ? null : find_key($pdo, $id);
if ($old === null || (int) $old['member_id'] !== $me || !key_is_live($old)) {
    refuse(404, 'Key not found.');
}
$new = crm_guard($pdo, static function () use ($pdo, $old, $id): array {
    $pdo->beginTransaction();
    $k = key_rotate($pdo, $id);
    log_activity($pdo, 'key.rotate', 'mcp_access_token', $k['id'], ['token_id' => $k['id'], 'after' => ['label' => $old['label'], 'rotated_from' => $id, 'overlap_hours' => (int) one_value($pdo, 'SELECT crm_setting_int(\'key_rotation_overlap_hours\')')]]);
    $pdo->commit();
    return $k;
});
emit_action_status(true, ['did' => 'Rotated the key "' . $old['label'] . '" — the new one is shown once; the old one works for a day more', 'record_id' => $new['id'], 'refresh' => 'tokenChanged']);
if (wants_json()) {
    respond_saved(['did' => 'Rotated the key "' . $old['label'] . '"', 'record_id' => $new['id'], 'key' => $new['raw'], 'location' => '/settings/tokens/#key-' . $new['id'], 'refresh' => 'tokenChanged']);
}
$_SESSION['minted_key'] = ['id' => $new['id'], 'raw' => $new['raw'], 'label' => (string) $old['label'], 'what' => 'rotated API key'];
saved_go('/settings/tokens/', 'tokenChanged');
