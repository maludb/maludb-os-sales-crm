<?php
declare(strict_types=1);
/** / — home. Phase 0: a placeholder that proves the sign-on landed (who you are, the roles the kernel sent); the shell and the home screen are Phase 2 (docs/build-specs/sso-shell.md). */
require_once dirname(__DIR__) . '/app/bootstrap.php';
require_login();
$pdo = db();
$me = current_member();
log_screen_view($pdo, 'home');
$roles = $pdo->query('SELECT r.role_key, r.name FROM crm_roles r WHERE r.role_key = ANY (crm_member_roles(app_current_member_id())) ORDER BY r.sort_order')->fetchAll();
if (wants_json()) {
    respond_screen(['member_id' => (int) $me['id'], 'display_name' => $me['display_name'], 'roles' => array_column($roles, 'role_key'), 'phase' => 'Phase 0: the shell is Phase 2']);
}
echo view('home/placeholder.php', ['me' => $me, 'roles' => $roles]);
