<?php
declare(strict_types=1);
/** Action `notification_read` (log `notification.read`): mark one of my notifications read (`notification`), or — given none — every unread one. Own rows only. */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_once dirname(__DIR__, 2) . '/app/features/settings/queries.php';
require_post();
require_login();
verify_csrf();
$pdo = db();
$me = (int) current_member_id();
$id = request_integer('notification');
$pdo->beginTransaction();
$n = mark_notifications_read($pdo, $me, $id);
log_activity($pdo, 'notification.read', $id === null ? null : 'notification', $id, ['after' => ['count' => $n, 'all' => $id === null]]);
$pdo->commit();
emit_action_status(true, ['did' => $id === null ? 'Marked ' . $n . ' notification' . ($n === 1 ? '' : 's') . ' read' : ($n === 1 ? 'Marked the notification read' : 'That notification was already read'),
    'record_id' => $id, 'count' => $n, 'refresh' => 'notificationChanged']);
if (is_htmx_request() && !wants_json()) {
    hx_trigger('notificationChanged');                                                        // the bell re-fetches itself beside the page
}
saved_go('/notifications', 'notificationChanged');
