<?php /** A record's activities as an agenda: open (overdue first), then the last done; Schedule once slice 4 has shipped. Data: kind, id, activities {open, done}, may, tz */
$words = ['overdue' => 'Overdue', 'today' => 'Today', 'scheduled' => 'Planned', 'done' => 'Done', 'none' => '—'];
$built = nav_built('/activities/view.php');
$row = static function (array $r) use ($words, $built, $tz, $kind, $id): string {
    $aid = (int) $r['activity_id'];
    $subject = e((string) $r['subject']);
    return '<div class="list-group-item today-row d-flex align-items-center gap-2" id="' . e($kind) . '-activity-' . $aid . '"><i class="' . e(activity_kind_icon((string) $r['kind'])) . ' text-muted"></i>'
        . '<div class="min-w-0 flex-grow-1"><div class="fw-semibold text-truncate">' . ($built ? hx_link(with_back('/activities/' . $aid, '/' . $kind . 's/' . $id . '?tab=activities'), $subject, 'text-dark') : $subject) . '</div>'
        . '<div class="fs-11 text-muted">' . e(format_ts($r['done_at'] ?? $r['at'], $tz, 'D M j, g:i A')) . ($r['outcome'] ? ' · ' . e((string) $r['outcome']) : '') . '</div></div>'
        . '<span class="next-chip next-' . e((string) $r['state']) . '">' . e($words[$r['state']] ?? (string) $r['state']) . '</span></div>';
}; ?>
<div class="d-flex justify-content-between align-items-center mb-2"><h6 class="mb-0">Activities</h6>
    <?php if (!empty($may['log']) && nav_built('/activities/form.php')): ?><?= hx_link('/activities/new?' . $kind . '=' . (int) $id, '<i class="feather-clock me-1"></i>Schedule', 'btn btn-light btn-touch', 'id="' . e($kind) . '-schedule-tab-btn"') ?><?php endif; ?></div>
<div class="card mb-3" id="<?= e($kind) ?>-agenda-open"><div class="card-header"><h6 class="card-title mb-0">Coming up</h6></div>
    <?php if ($activities['open'] === []): ?><div class="card-body fs-12 text-muted" id="<?= e($kind) ?>-agenda-open-empty">Nothing planned — no next step.</div>
    <?php else: ?><div class="list-group list-group-flush"><?php foreach ($activities['open'] as $r) { echo $row($r); } ?></div><?php endif; ?></div>
<div class="card" id="<?= e($kind) ?>-agenda-done"><div class="card-header"><h6 class="card-title mb-0">Done lately</h6></div>
    <?php if ($activities['done'] === []): ?><div class="card-body fs-12 text-muted">Nothing done yet.</div>
    <?php else: ?><div class="list-group list-group-flush"><?php foreach ($activities['done'] as $r) { echo $row($r); } ?></div><?php endif; ?></div>
