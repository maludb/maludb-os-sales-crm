<?php /** The month (six weeks from the Monday on or before the 1st): a cell a day (#cal-day-{date}) with at most four chips, then "+N more" opening the day;
 * the day's number opens the day; + schedules one that day (always shown on a phone). Each cell's body is a drop target (calendar.js): a drop keeps the
 * time of day. Data: cal, colours, may */
$names = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];
$dayLink = static fn (string $date): string => calendar_url($cal, ['view' => 'day', 'at' => $date]); ?>
<div class="cal-month card mb-0" id="calendar-month" role="grid" aria-label="<?= e($cal['title']) ?>">
    <?php foreach ($names as $n): ?><div class="cal-dow" role="columnheader"><?= $n ?></div><?php endforeach; ?>
    <?php foreach ($cal['days'] as $d): $date = $d['date']; $more = count($d['rows']) - CALENDAR_DAY_CHIPS; ?>
    <div class="cal-day<?= $d['in_month'] ? '' : ' cal-out' ?><?= $d['today'] ? ' cal-today' : '' ?><?= $d['weekend'] ? ' cal-weekend' : '' ?>" id="cal-day-<?= e($date) ?>" data-date="<?= e($date) ?>" role="gridcell">
        <div class="cal-day-head"><a href="<?= e($dayLink($date)) ?>" class="cal-day-num" hx-get="<?= e(calendar_url($cal, ['view' => 'day', 'at' => $date], '/calendar/grid')) ?>" hx-target="#calendar-grid" hx-swap="outerHTML" hx-push-url="<?= e($dayLink($date)) ?>"><?= (int) substr($date, 8, 2) ?></a>
            <?php if ($may['create'] && nav_built('/activities/form.php')): ?><?= hx_link(with_back('/activities/new?at=' . $date, calendar_url($cal)), '<i class="feather-plus"></i>', 'cal-new', 'id="cal-new-' . e($date) . '" aria-label="New on ' . e($date) . '"') ?><?php endif; ?></div>
        <div class="cal-day-body" data-cal-drop data-date="<?= e($date) ?>">
            <?php foreach (array_slice($d['rows'], 0, CALENDAR_DAY_CHIPS) as $r) { echo view('calendar/partials/chip.php', ['r' => $r, 'cal' => $cal, 'colours' => $colours, 'may' => $may]); } ?>
        </div>
        <?php if ($more > 0): ?><a href="<?= e($dayLink($date)) ?>" class="cal-more" id="cal-more-<?= e($date) ?>" hx-get="<?= e(calendar_url($cal, ['view' => 'day', 'at' => $date], '/calendar/grid')) ?>" hx-target="#calendar-grid" hx-swap="outerHTML" hx-push-url="<?= e($dayLink($date)) ?>">+<?= $more ?> more</a><?php endif; ?>
    </div>
    <?php endforeach; ?>
</div>
