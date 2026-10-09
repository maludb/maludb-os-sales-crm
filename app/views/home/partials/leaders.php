<?php /** The leaderboard's top three this month (crm_leaderboard, db/015). Data: s */ $leaders = $s['manager']['leaders']; ?>
<div class="card mb-3" id="home-leaders"><div class="card-header"><h5 class="card-title mb-0">Leading this month</h5></div>
    <?php if ($leaders === []): ?>
        <div class="card-body fs-12 text-muted" id="home-leaders-empty">No deals won yet this month.</div>
    <?php else: ?>
        <div class="list-group list-group-flush" id="home-leaders-list">
        <?php foreach ($leaders as $i => $l): ?>
            <div class="list-group-item d-flex align-items-center justify-content-between today-row" id="home-leader-<?= (int) $l['member_id'] ?>">
                <span class="min-w-0 text-truncate"><span class="text-muted me-2"><?= $i + 1 ?>.</span><?= e((string) $l['display_name']) ?></span>
                <span class="fw-semibold text-nowrap"><?= e(money($l['won_value'], $s['currency'])) ?><span class="fs-11 text-muted fw-normal"> · <?= (int) $l['won_count'] ?></span></span>
            </div>
        <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>
