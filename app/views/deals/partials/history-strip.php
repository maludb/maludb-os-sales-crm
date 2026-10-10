<?php /** The stage history strip: each stage the deal has been in and for how long, the current one running. Data: strip, tz */ ?>
<div class="card mb-3" id="deal-history-strip"><div class="card-body py-2"><div class="fs-11 text-muted mb-1">Time in each stage</div>
    <div class="d-flex flex-wrap gap-2">
        <?php foreach ($strip as $i => $h): ?>
            <div class="history-step<?= $h['current'] ? ' history-step-current' : '' ?>" id="deal-history-step-<?= $i ?>" title="Entered <?= e(format_ts($h['entered_at'], $tz)) ?><?= $h['by'] ? ' by ' . e($h['by']) : '' ?>">
                <div class="fs-12 fw-semibold text-truncate"><?= e((string) $h['stage']) ?></div><div class="fs-11 text-muted"><?= e(duration_words($h['seconds'])) ?><?= $h['current'] ? ' so far' : '' ?></div></div>
            <?php if ($i < count($strip) - 1): ?><i class="feather-chevron-right text-muted align-self-center"></i><?php endif; ?>
        <?php endforeach; ?>
    </div></div></div>
