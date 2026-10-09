<?php /** Replies since yesterday on my deals and to my contacts (the logging mailbox, slice 5 fills it). Data: s, tz */ ?>
<div class="card mb-3" id="home-replies"><div class="card-header"><h5 class="card-title mb-0">Replies since yesterday</h5></div>
    <?php if ($s['replies'] === []): ?>
        <div class="card-body fs-12 text-muted" id="home-replies-empty">No replies yet. Emails your contacts send, logged through the BCC mailbox, appear here.</div>
    <?php else: ?>
        <div class="list-group list-group-flush" id="home-replies-list">
        <?php foreach ($s['replies'] as $m): $mid = (int) $m['email_id']; ?>
            <div class="list-group-item today-row" id="home-reply-<?= $mid ?>">
                <div class="fw-semibold text-truncate"><?= hx_link(with_back('/email/in/' . $mid, '/'), e((string) ($m['subject'] ?: '(no subject)')), 'text-dark') ?></div>
                <div class="fs-11 text-muted text-truncate"><?= e((string) ($m['from_name'] ?: $m['from_address'])) ?> · <?= e(format_ts($m['at'], $tz, 'M j, g:i A')) ?><?= $m['deal_title'] ? ' · ' . e((string) $m['deal_title']) : '' ?></div>
            </div>
        <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>
