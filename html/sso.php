<?php
declare(strict_types=1);

/**
 * /sso — the receiver of the kernel's hand-off token (sign-on-and-directory.md §1). In order:
 * both signatures, expiry, audience, the single-use nonce, the mirror from the claims, the
 * session, the log. Any failure answers ONE page and never says which check failed.
 */
require_once dirname(__DIR__) . '/app/bootstrap.php';

$pdo = db();
$token = (string) ($_GET['token'] ?? '');
$claimsRaw = (string) ($_GET['claims'] ?? '');
$requestId = request_id();

$refuse = static function (string $reason, ?int $memberId = null) use ($pdo, $requestId): never {
    log_activity($pdo, 'member.sign_on.refused', 'member', $memberId,
        ['actor_member_id' => null, 'request_id' => $requestId, 'after' => ['reason' => $reason]]);
    http_response_code(403);
    header('Cache-Control: no-store');
    echo view('sso/refused.php', []);
    exit;
};

$verified = $token === '' ? null : verify_sso_token($token, app_key());
if ($verified === null) {
    $refuse('token');
}
[$memberId, $nonce] = $verified;
$claims = $claimsRaw === '' ? null : verify_sso_claims($claimsRaw);
if ($claims === null || (int) ($claims['member_id'] ?? 0) !== $memberId) {
    $refuse('claims', $memberId);
}
if (($claims['status'] ?? 'active') !== 'active') {
    $refuse('status', $memberId);
}
$capability = $claims['capability'] ?? null;
if (!in_array($capability, ['read', 'write', 'admin'], true)) {
    $refuse('capability', $memberId);
}

// Single use: the nonce is kept until the token would have expired anyway.
$expiresAt = (int) explode('.', $token)[1];
$claimed = $pdo->prepare('INSERT INTO sso_nonces (nonce, member_id, expires_at) VALUES (:n, :m, to_timestamp(:e)) ON CONFLICT (nonce) DO NOTHING');
$claimed->execute(['n' => $nonce, 'm' => $memberId, 'e' => $expiresAt]);
if ($claimed->rowCount() !== 1) {
    $refuse('replay', $memberId);
}
$pdo->exec("DELETE FROM sso_nonces WHERE expires_at < now() - interval '1 hour'");

// The mirror, from the claims — the only place a capability is written.
$pdo->beginTransaction();
try {
    mirror_apply_member($pdo, [
        'id' => $memberId,
        'member_kind' => 'human',
        'display_name' => $claims['display_name'] ?? ('Member #' . $memberId),
        'email' => $claims['email'] ?? null,
        'business_role' => $claims['business_role'] ?? 'user',
        'is_external' => !empty($claims['is_external']),
        'status' => 'active',
        'departments' => is_array($claims['departments'] ?? null) ? $claims['departments'] : [],
    ] + (isset($claims['job_title']) ? ['job_title' => $claims['job_title']] : [])
      + (isset($claims['phone']) ? ['phone' => $claims['phone']] : [])
      + (isset($claims['timezone']) ? ['timezone' => $claims['timezone']] : []), $capability, true);
    // The roles the kernel says they hold here (kernel db/145). A kernel from before roles sends none:
    // the capability alone then decides, as it did (crm_has_right()).
    if (array_key_exists('roles', $claims)) {
        mirror_apply_roles($pdo, $memberId, is_array($claims['roles']) ? $claims['roles'] : []);
    }
    $pdo->commit();
} catch (Throwable $e) {
    $pdo->rollBack();
    error_log('sso mirror: ' . $e->getMessage());
    $refuse('mirror', $memberId);
}

// The claims omit job title / phone / timezone; the feed fills them within a minute. Until then a
// fresh mirror row would blank a known value — mirror_apply_member() only writes what it is given
// on conflict, and the three keys above are given only when the kernel sends them.

// The session: fresh id, member, fresh CSRF token, listed for the kernel's sign-out notice.
session_regenerate_id(true);
$_SESSION = ['member_id' => $memberId, 'csrf_token' => bin2hex(random_bytes(32)), 'signed_on_at' => time()];
session_open($pdo, $memberId, session_id());
db_apply_context($pdo);
log_activity($pdo, 'member.sign_on', 'member', $memberId, ['request_id' => $requestId, 'after' => ['capability' => $capability, 'roles' => $claims['roles'] ?? null]]);

header('Cache-Control: no-store');
header('Location: /', true, 302);
exit;
