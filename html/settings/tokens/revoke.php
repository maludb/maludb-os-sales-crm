<?php
declare(strict_types=1);
/** Action `token_revoke` (log `token.revoke`; confirm). The token's owner only — another person's answers 404. People only. */
require_once dirname(__DIR__, 3) . '/app/bootstrap.php';
require_once dirname(__DIR__, 3) . '/app/features/settings/queries.php';
require_post();
require_login();
verify_csrf();
require_human();
$pdo = db();
$me = (int) current_member_id();
$id = request_integer('token') ?? request_integer('id');
$row = $id === null ? null : find_my_token($pdo, $me, $id);
if ($row === null || $row['revoked_at'] !== null || !revoke_token($pdo, $me, $id)) {
    refuse(404, 'Token not found.');
}
log_activity($pdo, 'token.revoke', 'mcp_access_token', $id, ['token_id' => $id, 'before' => ['label' => $row['label'], 'scope' => $row['scope']]]);
emit_action_status(true, ['did' => 'Revoked the token "' . $row['label'] . '"', 'record_id' => $id, 'refresh' => 'tokenChanged']);
saved_go('/settings/tokens/', 'tokenChanged');
