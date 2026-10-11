<?php /** The calendar's nav (#calendar-nav): prev · today · next, the period's title, the four views — each a link that works without JavaScript and swaps the
 * region over HTMX (the URL pushed). Data: cal */
$go = static function (array $over, string $html, string $class, string $id, string $extra = '') use ($cal): string {
    $page = calendar_url($cal, $over);
    return '<a href="' . e($page) . '" class="' . e($class) . '" id="' . e($id) . '" hx-get="' . e(calendar_url($cal, $over, '/calendar/grid')) . '" hx-target="#calendar-grid" hx-swap="outerHTML" hx-push-url="' . e($page) . '"' . $extra . '>' . $html . '</a>';
}; ?>
<div class="d-flex flex-wrap align-items-center gap-2 mb-2" id="calendar-nav">
    <div class="btn-group" role="group" aria-label="Move">
        <?= $go(['at' => $cal['prev']], '<i class="feather-chevron-left"></i>', 'btn btn-light btn-touch', 'calendar-prev', ' aria-label="Earlier"') ?>
        <?= $go(['at' => $cal['today']], 'Today', 'btn btn-light btn-touch', 'calendar-today') ?>
        <?= $go(['at' => $cal['next']], '<i class="feather-chevron-right"></i>', 'btn btn-light btn-touch', 'calendar-next', ' aria-label="Later"') ?>
    </div>
    <h6 class="mb-0 me-auto" id="calendar-title"><?= e($cal['title']) ?><?= $cal['who']['kind'] !== 'me' ? ' <span class="fs-12 text-muted fw-normal" id="calendar-whose">· ' . e($cal['who']['label']) . '</span>' : '' ?></h6>
    <div class="btn-group" role="group" aria-label="View" id="calendar-views">
        <?php foreach (CALENDAR_VIEWS as $v => $label): ?>
            <?= $go(['view' => $v, 'at' => $cal['at']], e($label), 'btn btn-sm btn-touch ' . ($cal['view'] === $v ? 'btn-primary active' : 'btn-light'), 'calendar-view-' . $v, $cal['view'] === $v ? ' aria-current="true"' : '') ?>
        <?php endforeach; ?>
    </div>
</div>
