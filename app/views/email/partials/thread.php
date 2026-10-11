<?php /** The conversation an email belongs to (#email-thread): the messages that answer it or that it answers, oldest first. Data: thread, tz, back */ ?>
<div class="card mb-3" id="email-thread"><div class="card-header"><h5 class="card-title mb-0">The conversation</h5></div>
<?php if ($thread === []): ?><div class="card-body fs-12 text-muted" id="email-thread-empty">Nothing else is on this thread.</div>
<?php else: ?><div class="list-group list-group-flush">
    <?php foreach ($thread as $t): $tid = (int) $t['email_id']; ?>
    <div class="list-group-item d-flex align-items-center gap-2" id="email-thread-<?= e($t['direction']) ?>-<?= $tid ?>">
        <span class="badge bg-soft-<?= $t['direction'] === 'in' ? 'info text-info' : 'primary text-primary' ?>"><?= $t['direction'] === 'in' ? 'received' : 'sent' ?></span>
        <div class="min-w-0 flex-grow-1"><div class="fw-semibold text-truncate"><?= hx_link(with_back('/email/' . $t['direction'] . '/' . $tid, $back), e((string) ($t['subject'] ?: '(no subject)')), 'text-dark') ?></div>
            <div class="fs-11 text-muted text-truncate"><?= e((string) ($t['from_name'] ?: $t['from_address'])) ?> · <?= e(format_ts($t['at'], $tz, 'M j, Y g:i A')) ?></div></div>
    </div>
    <?php endforeach; ?></div><?php endif; ?>
</div>
