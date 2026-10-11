<?php /** A lead card (lead-list). Data: l, back. Label, status, source, owner, the score ring, last touched, the next step's dot. */
$lid = (int) $l['lead_id'];
$nc = (string) ($l['next_colour'] ?? 'none');
$words = ['overdue' => 'Overdue', 'today' => 'Due today', 'scheduled' => 'Next step planned', 'none' => 'No next step'];
$score = $l['score'] !== null ? (int) $l['score'] : null; ?>
<div class="col-md-6 col-xl-4"><div class="card h-100 mb-0" id="lead-card-<?= $lid ?>"><div class="card-body d-flex flex-column">
    <div class="d-flex align-items-start gap-2">
        <?= !empty($bulk) ? bulk_check('lead', $lid, true, (string) $l['label']) : '' ?>
        <span class="lead-score flex-shrink-0<?= $score === null ? ' lead-score-none' : '' ?>" style="--score: <?= (int) ($score ?? 0) ?>" title="<?= $score === null ? 'Not scored' : 'Score ' . $score ?>" id="lead-card-<?= $lid ?>-score"><span><?= $score === null ? '–' : $score ?></span></span>
        <div class="min-w-0 flex-grow-1">
            <div class="fw-semibold fs-14 text-truncate"><?php if ($l['restricted']): ?><i class="feather-lock me-1 text-warning" title="Restricted"></i><?php endif; ?><?= hx_link(with_back('/leads/' . $lid, $back), e($l['label']), 'text-dark', 'id="lead-card-' . $lid . '-link"') ?></div>
            <div class="fs-12 text-muted text-truncate"><?= e(trim(($l['title'] ?? '') . ($l['email'] ? ' · ' . $l['email'] : ''), ' ·')) ?: '&nbsp;' ?></div>
        </div>
        <span class="badge bg-soft-<?= e(LEAD_STATUS_BADGE[$l['status']] ?? 'secondary') ?> text-<?= e(LEAD_STATUS_BADGE[$l['status']] ?? 'secondary') ?> flex-shrink-0"><?= e(LEAD_STATUSES[$l['status']] ?? $l['status']) ?></span>
    </div>
    <div class="d-flex flex-wrap gap-1 mt-2 fs-12 align-items-center">
        <span class="next-chip next-<?= e($nc) ?>"><?= e($words[$nc] ?? $nc) ?></span>
        <?php if ($l['source']): ?><span class="badge bg-soft-secondary text-secondary"><?= e($l['source']) ?></span><?php endif; ?>
    </div>
    <div class="fs-11 text-muted mt-auto pt-2"><?= e((string) ($l['owner_name'] ?? 'Unowned')) ?> · <?= $l['last_touched_at'] ? 'touched ' . e(format_ts($l['last_touched_at'], member_timezone(), 'M j')) : 'added ' . e(format_ts($l['created_at'], member_timezone(), 'M j')) ?></div>
</div></div></div>
