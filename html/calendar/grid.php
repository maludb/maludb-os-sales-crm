<?php
declare(strict_types=1);
/**
 * /calendar/grid — the calendar's region alone (#calendar-grid, the same query as the page): navigation (prev · today · next), a view change, a who
 * or kinds change and activityChanged swap it in. Nothing logged: a re-render is not a visit. JSON: the window's rows.
 */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_once dirname(__DIR__, 2) . '/app/features/calendar/present.php';
require_login();
require_right('calendar.read');
$pdo = db();
$cal = calendar_rows($pdo, calendar_view_from_request(), request_string('at'), request_string('who', 'me'), calendar_kinds_from_request());
if (wants_json()) {
    respond_screen(present_calendar($cal));
}
header('Vary: HX-Request');
echo calendar_region_html($cal);
