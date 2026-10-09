<?php /** Today's activities: overdue first, then today's, each a chip in its state and a link to its record. Data: s, tz */
$words = ['overdue' => 'Overdue', 'today' => 'Today', 'scheduled' => 'Later', 'done' => 'Done', 'none' => '—'];
?>
<div class="card mb-3" id="home-today">
    <div class="card-header d-flex align-items-center justify-content-between">
        <h5 class="card-title mb-0">Today</h5>
        <?php if ($s['overdue_count'] > 0): ?><span class="next-chip next-overdue" id="home-overdue-count"><?= (int) $s['overdue_count'] ?> overdue</span><?php endif; ?>
    </div>
    <?php if ($s['today'] === []): ?>
        <div class="card-body fs-12 text-muted" id="home-today-empty">Nothing due today. <?= $s['may']['log'] ? hx_link('/calendar', 'Open the calendar', 'fw-semibold') . ' to plan the week.' : '' ?></div>
    <?php else: ?>
        <div class="list-group list-group-flush" id="home-today-list">
        <?php foreach ($s['today'] as $r): $aid = (int) $r['activity_id']; $state = (string) $r['state']; ?>
            <div class="list-group-item today-row d-flex align-items-center gap-2" id="home-today-<?= $aid ?>">
                <i class="<?= e(activity_kind_icon((string) $r['kind'])) ?> text-muted flex-shrink-0" aria-label="<?= e($r['kind']) ?>"></i>
                <div class="min-w-0 flex-grow-1">
                    <div class="today-subject fw-semibold<?= $state === 'done' ? ' text-muted text-decoration-line-through' : '' ?>"><?= hx_link(with_back(today_record_url($r), '/'), e((string) $r['subject']), 'text-dark') ?></div>
                    <div class="fs-11 text-muted text-truncate"><?= e(format_ts($r['at'], $tz, 'g:i A')) ?><?= $r['record_name'] ? ' · ' . e((string) $r['record_name']) : '' ?></div>
                </div>
                <span class="next-chip next-<?= e($state) ?> flex-shrink-0"><?= e($words[$state] ?? $state) ?></span>
            </div>
        <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>
