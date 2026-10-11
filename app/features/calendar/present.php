<?php
declare(strict_types=1);

/** The calendar's JSON shape (the `calendar` screen and tool; whitelisted). */
require_once __DIR__ . '/queries.php';

function present_calendar(array $cal): array
{
    $out = ['view' => $cal['view'], 'at' => $cal['at'], 'from' => json_ts($cal['from']), 'to' => json_ts($cal['to']), 'title' => $cal['title'], 'today' => $cal['today'], 'timezone' => $cal['tz'],
        'who' => ['kind' => $cal['who']['kind'], 'key' => $cal['who']['key'], 'label' => $cal['who']['label']], 'kinds' => $cal['kinds'], 'prev' => $cal['prev'], 'next' => $cal['next'],
        'rows' => array_map('present_calendar_row', $cal['rows'])];
    if ($cal['agenda'] !== null) {
        $out['agenda'] = array_map(static fn (array $rows): array => array_map(static fn (array $r): int => (int) $r['activity_id'], $rows), $cal['agenda']);
    } else {
        $out['days'] = array_map(static fn (array $d): array => ['date' => $d['date'], 'in_month' => $d['in_month'], 'today' => $d['today'],
            'activity_ids' => array_map(static fn (array $r): int => (int) $r['activity_id'], $d['rows'])], $cal['days']);
    }
    return $out;
}

/**
 * The calendar's region (#calendar-grid): the nav (prev · today · next, the title, the four views), the view's grid and the team's legend — the page,
 * /calendar/grid (navigation, a view change, activityChanged) and the drag's answer all render it, so they never differ.
 */
function calendar_region_html(array $cal): string
{
    $grid = match ($cal['view']) { 'week' => 'grid-week', 'day' => 'grid-day', 'agenda' => 'agenda', default => 'grid-month' };
    $colours = $cal['who']['kind'] === 'team' ? calendar_colours($cal['rows']) : [];
    $may = ['create' => has_right('activities.write'), 'drag' => has_right('activities.write') || has_right('calendar.team')];
    return '<div id="calendar-grid" class="calendar-region" hx-get="' . e(calendar_url($cal, ['at' => $cal['at']], '/calendar/grid')) . '" hx-trigger="activityChanged from:body" hx-swap="outerHTML"'
        . ' data-view="' . e($cal['view']) . '" data-at="' . e($cal['at']) . '" data-query="' . e(substr(calendar_url($cal, ['at' => $cal['at']], ''), 1)) . '" data-tz="' . e($cal['tz']) . '">'
        . view('calendar/partials/nav.php', ['cal' => $cal])
        . view('calendar/partials/' . $grid . '.php', ['cal' => $cal, 'colours' => $colours, 'may' => $may])
        . ($colours !== [] ? view('calendar/partials/legend.php', ['colours' => $colours]) : '')
        . '</div>';
}
