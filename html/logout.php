<?php
declare(strict_types=1);

/** Sales CRM's own sign-out: this session only, then back to the launcher. POST + CSRF, a full navigation. */
require_once dirname(__DIR__) . '/app/bootstrap.php';

require_post();
verify_csrf();
$pdo = db();
$memberId = current_member_id();
if ($memberId !== null) {
    log_activity($pdo, 'member.sign_out', 'member', $memberId, ['after' => ['by' => 'member']]);
}
end_session($pdo, 'member');
header('Cache-Control: no-store');
header('Location: ' . launcher_url(), true, 302);
exit;
