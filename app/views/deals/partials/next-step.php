<?php /** The next step card: the open activity soonest, in the four colours, or "No next step" with Schedule (slice 4). Data: d, next, tz, may */
$id = (int) $d['deal_id'];
$nc = (string) ($d['next_colour'] ?? 'none'); ?>
<div class="card mb-3" id="deal-next-step"><div class="card-body">
    <div class="fs-11 text-muted mb-1">Next step</div>
    <?php if ($next === null): ?>
        <div class="d-flex align-items-center gap-2"><span class="next-chip next-none" id="deal-next-step-none">No next step</span>
            <?php if ($d['status'] === 'open' && $may['log'] && nav_built('/activities/form.php')): ?><?= hx_link('/activities/new?deal=' . $id, 'Schedule one', 'btn btn-sm btn-light btn-touch ms-auto', 'id="deal-next-step-schedule"') ?><?php endif; ?></div>
    <?php else: ?>
        <div class="d-flex align-items-center gap-2"><span class="avatar-text avatar-md rounded"><i class="<?= e(activity_kind_icon((string) $next['kind'])) ?>"></i></span>
            <div class="min-w-0"><div class="fw-semibold text-truncate" id="deal-next-step-subject"><?= nav_built('/activities/view.php') ? hx_link('/activities/' . (int) $next['activity_id'], e($next['subject']), 'text-dark') : e($next['subject']) ?></div>
                <span class="next-chip next-<?= e($nc) ?>" id="deal-next-step-when"><?= $next['at'] ? e(format_ts($next['at'], $tz)) : 'No time set' ?></span></div></div>
    <?php endif; ?>
</div></div>
