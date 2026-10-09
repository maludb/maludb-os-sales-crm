<?php
declare(strict_types=1);

/** /sso/logout — the kernel's sign-out notice (§2): end every session of the member; 204 either way. */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    header('Allow: POST');
    http_response_code(405);
    exit;
}
$pdo = db();
$notice = (string) ($_POST['notice'] ?? '');
if ($notice === '' && ($body = json_decode((string) file_get_contents('php://input'), true)) && is_array($body)) {
    $notice = (string) ($body['notice'] ?? '');
}
$memberId = $notice === '' ? null : verify_sso_logout_notice($notice, app_key());
if ($memberId === null) {
    log_activity($pdo, 'member.sign_out.refused', null, null, ['actor_member_id' => null, 'source' => 'application', 'after' => ['reason' => 'notice']]);
} else {
    $ended = end_member_sessions($pdo, $memberId, 'kernel');
    log_activity($pdo, 'member.sign_out', 'member', $memberId, ['actor_member_id' => $memberId, 'source' => 'application', 'after' => ['by' => 'kernel', 'sessions' => $ended]]);
}
header_remove('Set-Cookie');
http_response_code(204);
exit;
