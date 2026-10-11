<?php /** A contact card (contact-list). Data: c, back. Name, title, account, lifecycle, owner, last touched, the next step's dot, opt-out marks. */
$cid = (int) $c['contact_id'];
$colourWord = ['overdue' => 'Overdue', 'today' => 'Due today', 'scheduled' => 'Next step planned', 'none' => 'No next step'];
$nc = (string) ($c['next_colour'] ?? 'none');
$name = $c['name'] !== '' ? $c['name'] : (string) $c['email']; ?>
<div class="col-md-6 col-xl-4"><div class="card h-100 mb-0" id="contact-card-<?= $cid ?>"><div class="card-body d-flex flex-column">
    <div class="d-flex align-items-start gap-2">
        <?= !empty($bulk) ? bulk_check('contact', $cid, true, $name) : '' ?>
        <span class="avatar-text avatar-md rounded-circle flex-shrink-0"><?= e(mb_strtoupper(mb_substr($name, 0, 1))) ?></span>
        <div class="min-w-0 flex-grow-1">
            <div class="fw-semibold fs-14 text-truncate"><?php if ($c['restricted']): ?><i class="feather-lock me-1 text-warning" title="Restricted"></i><?php endif; ?><?= hx_link(with_back('/contacts/' . $cid, $back), e($name), 'text-dark', 'id="contact-card-' . $cid . '-link"') ?></div>
            <div class="fs-12 text-muted text-truncate"><?= e(trim(($c['title'] ?? '') . ($c['account_name'] ? ' · ' . $c['account_name'] : ''), ' ·')) ?: '&nbsp;' ?></div>
        </div>
        <span class="badge bg-soft-<?= e(CONTACT_LIFECYCLE_BADGE[$c['lifecycle']] ?? 'secondary') ?> text-<?= e(CONTACT_LIFECYCLE_BADGE[$c['lifecycle']] ?? 'secondary') ?> flex-shrink-0"><?= e(CONTACT_LIFECYCLES[$c['lifecycle']] ?? $c['lifecycle']) ?></span>
    </div>
    <div class="d-flex flex-wrap gap-1 mt-2 fs-12 align-items-center">
        <span class="next-chip next-<?= e($nc) ?>"><?= e($colourWord[$nc] ?? $nc) ?></span>
        <?php if ($c['email_opt_out']): ?><span class="badge text-bg-danger" id="contact-card-<?= $cid ?>-no-email">No email</span><?php endif; ?>
        <?php if ($c['sms_opt_out']): ?><span class="badge text-bg-danger" id="contact-card-<?= $cid ?>-no-texts">No texts</span><?php endif; ?>
        <?php if ($c['is_primary']): ?><span class="badge bg-soft-primary text-primary">Primary</span><?php endif; ?>
    </div>
    <div class="fs-11 text-muted mt-auto pt-2"><?= e((string) ($c['owner_name'] ?? 'No owner')) ?> · <?= $c['last_touched_at'] ? 'touched ' . e(format_ts($c['last_touched_at'], member_timezone(), 'M j')) : 'never touched' ?></div>
</div></div></div>
