<?php /** A record's emails: subject, direction, date (never the body here; a private one is the view's to hide). Data: prefix, emails, tz, back */ $built = nav_built('/email/view.php'); ?>
<div class="card" id="<?= e($prefix) ?>-emails">
<?php if ($emails === []): ?>
    <div class="card-body fs-12 text-muted" id="<?= e($prefix) ?>-emails-empty">No emails logged yet. Copy the logging mailbox on an email and it lands here.</div>
<?php else: ?>
    <div class="list-group list-group-flush">
    <?php foreach ($emails as $m): $mid = (int) $m['email_id']; $subject = e((string) ($m['subject'] ?: '(no subject)')); ?>
        <div class="list-group-item today-row d-flex align-items-center gap-2" id="<?= e($prefix) ?>-email-<?= e($m['direction']) ?>-<?= $mid ?>">
            <?php $draft = $m['direction'] === 'out' && $m['status'] !== 'sent'; ?>
            <span class="badge bg-soft-<?= $m['direction'] === 'in' ? 'info text-info' : ($draft ? 'secondary text-secondary' : 'primary text-primary') ?>"><?= $m['direction'] === 'in' ? 'received' : ($draft ? 'draft' : 'sent') ?></span>
            <div class="min-w-0 flex-grow-1"><div class="fw-semibold text-truncate"><?php if ($m['is_private']): ?><i class="feather-lock me-1 text-muted"></i><?php endif; ?><?= $built ? hx_link(with_back('/email/' . $m['direction'] . '/' . $mid, $back), $subject, 'text-dark') : $subject ?></div>
                <div class="fs-11 text-muted text-truncate"><?= e((string) ($m['from_name'] ?: $m['from_address'])) ?> · <?= e(format_ts($m['at'], $tz, 'M j, Y g:i A')) ?></div></div>
        </div>
    <?php endforeach; ?>
    </div>
<?php endif; ?>
</div>
