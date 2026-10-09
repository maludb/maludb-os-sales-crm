<?php
declare(strict_types=1);
/** Action `notifications_read_all` (log `notification.read_all`): every unread notice of mine read at once; `all` = yes (the command bar's confirmation word; a form sends it). Answers the count. */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_once dirname(__DIR__, 2) . '/app/features/settings/queries.php';
require_post();
require_login();
verify_csrf();
$pdo = db();
$me = (int) current_member_id();
$all = strtolower(request_string('all', 'yes'));
if (!in_array($all, ['yes', '1', 'true', 'on'], true)) {
    refuse(422, 'Say yes to mark every unread notice read.');
}
$pdo->beginTransaction();
$n = mark_notifications_read($pdo, $me, null);
log_activity($pdo, 'notification.read_all', null, null, ['after' => ['count' => $n]]);
$pdo->commit();
emit_action_status(true, ['did' => 'Marked ' . $n . ' notification' . ($n === 1 ? '' : 's') . ' read', 'record_id' => $n, 'count' => $n, 'refresh' => 'notificationChanged']);
if (is_htmx_request() && !wants_json()) {
    hx_trigger('notificationChanged');
}
saved_go('/notifications', 'notificationChanged');
