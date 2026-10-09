<?php /** A record's History (crm_timeline): newest first, in words. Data: rows (timeline()), tz, prefix, more_url? */ $p = $prefix ?? 'record'; ?>
<div class="card mb-3" id="<?= e($p) ?>-timeline"><div class="card-header"><h5 class="card-title mb-0">History</h5></div>
    <div class="list-group list-group-flush" id="<?= e($p) ?>-timeline-list">
        <?php if ($rows === []): ?><div class="list-group-item text-muted fs-12" id="<?= e($p) ?>-timeline-empty">Nothing yet.</div><?php endif; ?>
        <?php foreach ($rows as $r): [$icon, $sentence, $url] = timeline_line($r); ?>
            <div class="list-group-item fs-12 d-flex gap-2" id="timeline-row-<?= e($r['item_kind']) ?>-<?= (int) $r['item_id'] ?>">
                <i class="<?= e($icon) ?> text-muted mt-1 flex-shrink-0"></i>
                <div class="min-w-0"><div class="text-break"><?= $url !== null && nav_built(preg_replace('#/\d+$#', '/view.php', $url)) ? hx_link($url, e($sentence), 'text-dark') : e($sentence) ?></div>
                    <div class="text-muted fs-11"><?= e(format_ts($r['at'], $tz, 'M j, Y g:i A')) ?><?= !empty($r['actor_is_agent']) ? ' · <span class="badge bg-soft-info text-info">agent</span>' : '' ?></div></div>
            </div>
        <?php endforeach; ?>
    </div>
    <?php if (!empty($more_url)): ?><div class="card-footer"><?= hx_link($more_url, 'Older', 'fs-12 fw-semibold') ?></div><?php endif; ?>
</div>
