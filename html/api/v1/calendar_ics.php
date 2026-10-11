<?php
declare(strict_types=1);
/**
 * GET /api/v1/calendar.ics?token= — the person's private calendar feed (activities-calendar.md §5; txtSchedules' door): the token in the query is the
 * authority (48 hex; only its SHA-256 is kept); a wrong, replaced or revoked token and a person no longer here answer the same 404; at most 60 opens a
 * minute per token (429). Their own activities in the settings' window, one VEVENT each, text/calendar. Every open logged `feed.read` with the member —
 * never the token.
 */
define('CRM_API_RAW', true);            // the feed answers text/calendar, not JSON
require_once dirname(__DIR__, 3) . '/app/api/bootstrap.php';
require_once dirname(__DIR__, 3) . '/app/features/activities/queries.php';
require_once dirname(__DIR__, 3) . '/app/features/calendar/ics.php';
api_require_get();
$GLOBALS['__public_door'] = 'api';
$token = (string) ($_GET['token'] ?? '');
$pdo = db();
$feed = feed_for_token($pdo, $token);
if ($feed === null) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    echo "Not found.\n";
    exit;
}
if (!feed_rate_ok($token)) {
    http_response_code(429);
    header('Retry-After: 60');
    header('Content-Type: text/plain; charset=utf-8');
    echo "Too many requests: try again in a minute.\n";
    exit;
}
$ics = feed_render($pdo, $feed['member_id']);
log_activity($pdo, 'feed.read', 'member', $feed['member_id'], ['actor_member_id' => $feed['member_id'], 'source' => 'api', 'after' => ['events' => substr_count($ics, "BEGIN:VEVENT\r\n")]]);
header('Content-Type: text/calendar; charset=utf-8');
header('Content-Disposition: inline; filename="sales-crm.ics"');
header('Cache-Control: private, max-age=300');
echo $ics;
