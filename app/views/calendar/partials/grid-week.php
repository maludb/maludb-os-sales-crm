<?php /** The week (Monday to Sunday) — and the day, one column of the same grid: a strip of all-day items above, then the hours 07–19 as rows; meetings,
 * calls and lunches as blocks as long as they last, tasks and deadlines as chips at their time; an item outside the hours sits in the first or the last
 * row. Every hour cell (#cal-slot-{date}-{hour}) is a drop target and has a + for a new one at that hour. Scrolls sideways inside itself on a phone.
 * Data: cal, colours, may */
$hours = range(CALENDAR_FIRST_HOUR, CALENDAR_LAST_HOUR);
$cols = count($cal['days']);
$fmt = static fn (int $h): string => ($h % 12 === 0 ? 12 : $h % 12) . ($h < 12 ? 'am' : 'pm'); ?>
<div class="cal-week-scroll card mb-0" id="calendar-<?= $cols === 1 ? 'day' : 'week' ?>">
<div class="cal-week" style="--cols: <?= $cols ?>" role="grid" aria-label="<?= e($cal['title']) ?>">
    <div class="cal-hour-label"></div>
    <?php foreach ($cal['days'] as $d): ?>
        <div class="cal-week-head<?= $d['today'] ? ' cal-today' : '' ?>" id="cal-day-<?= e($d['date']) ?>" data-date="<?= e($d['date']) ?>" role="columnheader">
            <?php if ($cols > 1): ?><a href="<?= e(calendar_url($cal, ['view' => 'day', 'at' => $d['date']])) ?>" hx-get="<?= e(calendar_url($cal, ['view' => 'day', 'at' => $d['date']], '/calendar/grid')) ?>" hx-target="#calendar-grid" hx-swap="outerHTML" hx-push-url="<?= e(calendar_url($cal, ['view' => 'day', 'at' => $d['date']])) ?>"><?= e(date('D j', strtotime($d['date']))) ?></a>
            <?php else: ?><?= e(date('l', strtotime($d['date']))) ?><?php endif; ?></div>
    <?php endforeach; ?>
    <div class="cal-hour-label fs-11 text-muted">All day</div>
    <?php foreach ($cal['days'] as $d): ?>
        <div class="cal-allday" data-cal-drop data-date="<?= e($d['date']) ?>" data-all-day="1" id="cal-allday-<?= e($d['date']) ?>">
            <?php foreach ($d['allday'] as $r) { echo view('calendar/partials/chip.php', ['r' => $r, 'cal' => $cal, 'colours' => $colours, 'may' => $may]); } ?></div>
    <?php endforeach; ?>
    <?php foreach ($hours as $h): ?>
        <div class="cal-hour-label fs-11 text-muted"><?= $fmt($h) ?></div>
        <?php foreach ($cal['days'] as $d): $at = $d['date'] . 'T' . sprintf('%02d', $h) . ':00'; ?>
            <div class="cal-slot<?= $d['today'] ? ' cal-today' : '' ?>" data-cal-drop data-date="<?= e($d['date']) ?>" data-hour="<?= $h ?>" id="cal-slot-<?= e($d['date']) ?>-<?= $h ?>">
                <?php foreach ($d['timed'] as $r) { if ((int) $r['hour'] === $h) { echo view('calendar/partials/chip.php', ['r' => $r, 'cal' => $cal, 'colours' => $colours, 'may' => $may, 'block' => in_array($r['kind'], ACTIVITY_TIMED, true)]); } } ?>
                <?php if ($may['create'] && nav_built('/activities/form.php')): ?><?= hx_link(with_back('/activities/new?at=' . $at, calendar_url($cal)), '<i class="feather-plus"></i>', 'cal-new', 'aria-label="New at ' . e($at) . '"') ?><?php endif; ?>
            </div>
        <?php endforeach; ?>
    <?php endforeach; ?>
</div>
</div>
