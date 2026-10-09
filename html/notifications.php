<?php
declare(strict_types=1);
/** /notifications — the person's in-app notifications, unread first (screen `notifications`; ?unread=1 = only the unread; ?count=1 = the bell's partial). The slices write them through crm_notify(). */
require_once dirname(__DIR__) . '/app/bootstrap.php';
require_once dirname(__DIR__) . '/app/features/settings/queries.php';
require_once dirname(__DIR__) . '/app/features/settings/present.php';
require_login();
$pdo = db();
if (request_string('count') === '1') {                                                       // the bell (Pattern A, no log)
    header('Vary: HX-Request');
    echo view('notifications/partials/bell.php', ['unread' => (int) $pdo->query('SELECT count(*) FROM mcp_notifications WHERE read_at IS NULL')->fetchColumn()]);
    exit;
}
$unreadOnly = request_bool('unread');
$rows = find_my_notifications($pdo, (int) current_member_id(), $unreadOnly);
log_screen_view($pdo, 'notifications');
if (wants_json()) {
    respond_screen(['unread_only' => $unreadOnly, 'notifications' => array_map('present_notification', $rows)]);
}
render_screen('Notifications', view('notifications/page.php', ['rows' => $rows, 'tz' => member_timezone(), 'unreadOnly' => $unreadOnly]), ['activeNav' => 'notifications', 'screen' => 'notifications']);
