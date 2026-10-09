<?php
declare(strict_types=1);
/**
 * / — Today (screen `home`; sso-shell.md; design §9): my activities due and overdue, my deals closing this month, the flags (no next step,
 * rotting) as links to the board filtered, replies since yesterday, the morning note; a manager's forecast tile and the leaderboard's top
 * three; a new person's one button, Add your first lead. JSON: present_home() — whitelisted.
 */
require_once dirname(__DIR__) . '/app/bootstrap.php';
require_once dirname(__DIR__) . '/app/features/home/queries.php';
require_login();
require_right('records.read');
$pdo = db();
$me = (int) current_member_id();
$tz = member_timezone();
$s = home_summary($pdo, $me);
log_screen_view($pdo, 'home');
if (wants_json()) {
    respond_screen(present_home($s, $tz));
}
render_screen('Today', view('home/index.php', ['s' => $s, 'tz' => $tz]), ['activeNav' => 'home', 'screen' => 'home']);
