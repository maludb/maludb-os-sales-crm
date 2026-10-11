<?php
declare(strict_types=1);

/**
 * The calendar's one query (activities-calendar.md §1): crm_calendar() over the window a view draws — a month (six weeks from the Monday on or
 * before the 1st), a week (Monday to Sunday), a day, the agenda (overdue, today, tomorrow, the week) — for me, a colleague or a team, in the
 * caller's zone. The page, the grid partial and the `calendar` tool all read it.
 */
require_once __DIR__ . '/../activities/queries.php';

const CALENDAR_VIEWS = ['month' => 'Month', 'week' => 'Week', 'day' => 'Day', 'agenda' => 'Agenda'];
/** The week and day views' hours as rows (an item outside them sits in the first or the last). */
const CALENDAR_FIRST_HOUR = 7;
const CALENDAR_LAST_HOUR = 19;
/** The month view's chips per day before "+N more". */
const CALENDAR_DAY_CHIPS = 4;

/**
 * The view's window and rows: {view, at, who, kinds, from, to, rows, days, title, prev, next, today, agenda}. `days` (month, week, day): one entry per
 * date in the caller's zone {date, in_month, today, weekend, rows, allday, timed} — timed rows carry the hour row they sit in and their length in minutes.
 */
function calendar_rows(PDO $pdo, string $view, string $at, string|array $who, array $kinds): array
{
    $tz = member_timezone();
    $zone = new DateTimeZone($tz);
    $who = is_array($who) ? $who : activity_who($pdo, $who);
    $view = array_key_exists($view, CALENDAR_VIEWS) ? $view : 'month';
    $today = new DateTimeImmutable('today', $zone);
    $anchor = preg_match('/^\d{4}-\d{2}-\d{2}$/', $at) && ($d = DateTimeImmutable::createFromFormat('!Y-m-d', $at, $zone)) !== false ? $d : $today;
    $kinds = array_values(array_intersect($kinds, array_keys(ACTIVITY_KINDS)));
    $member = $who['kind'] === 'team' ? null : $who['member'];
    $base = ['view' => $view, 'at' => $anchor->format('Y-m-d'), 'who' => $who, 'kinds' => $kinds, 'today' => $today->format('Y-m-d'), 'tz' => $tz];
    if ($view === 'agenda') {
        $ag = agenda($pdo, $member, $who['team'], $kinds, $anchor->format('Y-m-d'));
        return $base + ['from' => $anchor->modify('-90 days')->format(DATE_ATOM), 'to' => $anchor->modify('+7 days')->format(DATE_ATOM), 'agenda' => $ag, 'days' => [],
            'rows' => array_merge($ag['overdue'], $ag['today'], $ag['tomorrow'], $ag['week']),
            'title' => $anchor->format('Y-m-d') === $base['today'] ? 'Agenda' : 'Agenda from ' . $anchor->format('M j'),
            'prev' => $anchor->modify('-7 days')->format('Y-m-d'), 'next' => $anchor->modify('+7 days')->format('Y-m-d')];
    }
    [$from, $count, $title, $prev, $next] = match ($view) {
        'month' => [($first = $anchor->modify('first day of this month'))->modify('-' . ((int) $first->format('N') - 1) . ' days'), 42, $anchor->format('F Y'),
                    $first->modify('-1 month')->format('Y-m-d'), $first->modify('+1 month')->format('Y-m-d')],
        'week' => [($mon = $anchor->modify('-' . ((int) $anchor->format('N') - 1) . ' days')), 7, calendar_week_title($mon),
                   $mon->modify('-7 days')->format('Y-m-d'), $mon->modify('+7 days')->format('Y-m-d')],
        default => [$anchor, 1, $anchor->format('l, M j, Y'), $anchor->modify('-1 day')->format('Y-m-d'), $anchor->modify('+1 day')->format('Y-m-d')],
    };
    $to = $from->modify('+' . $count . ' days');
    $rows = calendar_query($pdo, $from, $to, $member, $who['team'], $kinds);
    $days = [];
    for ($i = 0; $i < $count; $i++) {
        $d = $from->modify('+' . $i . ' days');
        $days[$d->format('Y-m-d')] = ['date' => $d->format('Y-m-d'), 'in_month' => $view !== 'month' || $d->format('m') === $anchor->format('m'), 'today' => $d->format('Y-m-d') === $base['today'],
            'weekend' => (int) $d->format('N') >= 6, 'rows' => [], 'allday' => [], 'timed' => []];
    }
    foreach ($rows as $r) {
        $start = (new DateTimeImmutable($r['starts_at']))->setTimezone($zone);
        $key = $start->format('Y-m-d');
        if (!isset($days[$key])) {
            continue;
        }
        $r['local_time'] = $start->format('H:i');
        $days[$key]['rows'][] = $r;
        if ($r['all_day']) {
            $days[$key]['allday'][] = $r;
        } else {
            $end = (new DateTimeImmutable($r['ends_at']))->setTimezone($zone);
            $r['hour'] = max(CALENDAR_FIRST_HOUR, min(CALENDAR_LAST_HOUR, (int) $start->format('G')));
            $r['minutes'] = max(0, (int) round(($end->getTimestamp() - $start->getTimestamp()) / 60));
            $days[$key]['timed'][] = $r;
        }
    }
    return $base + ['from' => $from->format(DATE_ATOM), 'to' => $to->format(DATE_ATOM), 'rows' => $rows, 'days' => array_values($days), 'agenda' => null,
        'title' => $title, 'prev' => $prev, 'next' => $next];
}

/** "Oct 12 – 18, 2026" (a week inside a month), "Sep 28 – Oct 4, 2026", "Dec 28, 2026 – Jan 3, 2027". */
function calendar_week_title(DateTimeImmutable $mon): string
{
    $sun = $mon->modify('+6 days');
    if ($mon->format('Y') !== $sun->format('Y')) {
        return $mon->format('M j, Y') . ' – ' . $sun->format('M j, Y');
    }
    return $mon->format('M j') . ' – ' . ($mon->format('m') === $sun->format('m') ? $sun->format('j') : $sun->format('M j')) . ', ' . $sun->format('Y');
}

/** The calendar's URL with its state (and changes): /calendar or /calendar/grid. */
function calendar_url(array $cal, array $over = [], string $base = '/calendar'): string
{
    $q = array_merge(['view' => $cal['view'], 'at' => $cal['at'], 'who' => $cal['who']['key'], 'kinds' => $cal['kinds'] === [] ? null : implode(',', $cal['kinds'])], $over);
    if (($q['who'] ?? null) === 'me') { unset($q['who']); }
    if (($q['at'] ?? null) === $cal['today'] && !array_key_exists('at', $over)) { unset($q['at']); }
    $s = http_build_query(array_filter($q, static fn ($v) => $v !== null && $v !== ''));
    return $base . ($s === '' ? '' : '?' . $s);
}

/**
 * The view a request asks for: `view` when it says (and the session remembers a choice made by hand); else the session's choice; else the agenda on a
 * narrow screen (the Sec-CH-Viewport-Width hint under 768) and the month on a wide one.
 */
function calendar_view_from_request(): string
{
    $v = (string) ($_GET['view'] ?? '');
    if (array_key_exists($v, CALENDAR_VIEWS)) {
        if (($_GET['auto'] ?? '') !== '1' && session_status() === PHP_SESSION_ACTIVE) {
            $_SESSION['calendar_view'] = $v;
        }
        return $v;
    }
    if (isset($_SESSION['calendar_view']) && array_key_exists((string) $_SESSION['calendar_view'], CALENDAR_VIEWS)) {
        return (string) $_SESSION['calendar_view'];
    }
    $w = (int) ($_SERVER['HTTP_SEC_CH_VIEWPORT_WIDTH'] ?? $_SERVER['HTTP_VIEWPORT_WIDTH'] ?? 0);
    return $w > 0 && $w < 768 ? 'agenda' : 'month';
}

/** The kinds filter from the request (`kinds`: a comma list or kinds[]). */
function calendar_kinds_from_request(): array
{
    $k = $_GET['kinds'] ?? [];
    return array_values(array_intersect(is_array($k) ? $k : explode(',', (string) $k), array_keys(ACTIVITY_KINDS)));
}

/** The people and teams the caller may open the calendar of: [teams (calendar.team), people (calendar.read)]. */
function calendar_whos(PDO $pdo): array
{
    $me = (int) current_member_id();
    $teams = has_right('calendar.team') ? $pdo->query('SELECT DISTINCT d.department_id, d.name FROM mcp_departments d JOIN department_members dm ON dm.department_id = d.department_id AND dm.left_at IS NULL
                                                         WHERE d.archived_at IS NULL ORDER BY d.name')->fetchAll() : [];
    $people = [];
    if (has_right('calendar.read')) {
        $st = $pdo->prepare("SELECT member_id, display_name, member_kind FROM mcp_members WHERE member_id <> :me ORDER BY (member_kind <> 'human'), display_name");
        $st->execute(['me' => $me]);
        $people = $st->fetchAll();
    }
    return [$teams, $people];
}

/** A person's initials for a chip on the team view. */
function calendar_initials(?string $name): string
{
    $parts = preg_split('/\s+/', trim((string) $name)) ?: [];
    $out = '';
    foreach (array_slice($parts, 0, 2) as $p) { $out .= mb_strtoupper(mb_substr($p, 0, 1)); }
    return $out === '' ? '?' : $out;
}

/** The team view's colours, one per person in the order they first appear (the legend reads the same map). */
function calendar_colours(array $rows): array
{
    $palette = ['#3454d1', '#8e44ad', '#16a085', '#d35400', '#c0392b', '#2c3e50', '#27ae60', '#e67e22', '#2980b9', '#7f8c8d'];
    $out = [];
    foreach ($rows as $r) {
        $m = (int) ($r['owner_member_id'] ?? 0);
        if (!isset($out[$m])) {
            $out[$m] = ['colour' => $palette[count($out) % count($palette)], 'name' => (string) ($r['owner_name'] ?? 'Unowned')];
        }
    }
    return $out;
}
