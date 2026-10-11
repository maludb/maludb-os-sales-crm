<?php
declare(strict_types=1);
/**
 * /calendar (screen `calendar`; params view month|week|day|agenda, at a date, who me|<member>|team:<department>, kinds a comma list): the activities of
 * me, a colleague (calendar.read) or a team (calendar.team: a colour per person, a legend) in four views drawn by the server from ONE query
 * (crm_calendar). The region #calendar-grid is /calendar/grid — re-rendered on prev · today · next, a view change and activityChanged. The view: as
 * asked (remembered for the session), else the session's, else the agenda on a phone (the Sec-CH-Viewport-Width hint) and the month on a wide screen.
 * A chip opens the activity; a cell's + schedules one there; a drag reschedules (calendar.js). JSON: the window's rows (the `calendar` tool's shape).
 */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_once dirname(__DIR__, 2) . '/app/features/calendar/present.php';
require_login();
require_right('calendar.read');
$pdo = db();
header('Accept-CH: Sec-CH-Viewport-Width, Viewport-Width');
header('Vary: Sec-CH-Viewport-Width');
$cal = calendar_rows($pdo, calendar_view_from_request(), request_string('at'), request_string('who', 'me'), calendar_kinds_from_request());
log_screen_view($pdo, 'calendar');
if (wants_json()) {
    respond_screen(present_calendar($cal));
}
[$teams, $people] = calendar_whos($pdo);
render_screen('Calendar', view('calendar/index.php', ['cal' => $cal, 'teams' => $teams, 'people' => $people, 'auto' => !isset($_GET['view']) && !isset($_SESSION['calendar_view']),
    'may' => ['create' => has_right('activities.write'), 'feed' => has_right('calendar.own')],
    'notice' => crm_notice($_GET['notice'] ?? null, ['rescheduled' => ['success', 'Moved.'], 'created' => ['success', 'Scheduled.'], 'logged' => ['success', 'Logged.'], 'updated' => ['success', 'Saved.'], 'done' => ['success', 'Done.']])]), ['activeNav' => 'calendar', 'screen' => 'calendar', 'entity' => 'activity']);
