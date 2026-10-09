<?php /** An account card (account-list). Data: a, back. Name, domain, kind, owner, city, last touched, the next step's dot, open deals (n · value). */
$aid = (int) $a['account_id'];
$colourWord = ['overdue' => 'Overdue', 'today' => 'Due today', 'scheduled' => 'Next step planned', 'none' => 'No next step'];
$nc = (string) ($a['next_colour'] ?? 'none'); ?>
<div class="col-md-6 col-xl-4"><div class="card h-100 mb-0" id="account-card-<?= $aid ?>"><div class="card-body d-flex flex-column">
    <div class="d-flex align-items-start gap-2">
        <span class="avatar-text avatar-md rounded flex-shrink-0"><?= e(mb_strtoupper(mb_substr((string) $a['name'], 0, 1))) ?></span>
        <div class="min-w-0 flex-grow-1">
            <div class="fw-semibold fs-14 text-truncate"><?php if ($a['restricted']): ?><i class="feather-lock me-1 text-warning" title="Restricted"></i><?php endif; ?><?= hx_link(with_back('/accounts/' . $aid, $back), e($a['name']), 'text-dark', 'id="account-card-' . $aid . '-link"') ?></div>
            <div class="fs-12 text-muted text-truncate"><?= e(trim(($a['domain'] ?? '') . ($a['city'] ? ' · ' . $a['city'] : ''), ' ·') ?: ($a['industry'] ?? '')) ?></div>
        </div>
        <span class="badge bg-soft-<?= e(ACCOUNT_KIND_BADGE[$a['kind']] ?? 'secondary') ?> text-<?= e($a['kind'] === 'other' ? 'dark' : (ACCOUNT_KIND_BADGE[$a['kind']] ?? 'secondary')) ?> flex-shrink-0"><?= e(ACCOUNT_KINDS[$a['kind']] ?? $a['kind']) ?></span>
    </div>
    <div class="d-flex flex-wrap gap-2 mt-2 fs-12 align-items-center">
        <span class="next-chip next-<?= e($nc) ?>" id="account-card-<?= $aid ?>-next"><?= e($colourWord[$nc] ?? $nc) ?></span>
        <span class="text-muted"><?= (int) $a['open_deals_count'] ?> open deal<?= (int) $a['open_deals_count'] === 1 ? '' : 's' ?><?= (int) $a['open_deals_count'] > 0 ? ' · ' . e(money($a['open_deals_value'])) : '' ?></span>
    </div>
    <div class="fs-11 text-muted mt-auto pt-2"><?= e((string) ($a['owner_name'] ?? 'No owner')) ?> · <?= $a['last_touched_at'] ? 'touched ' . e(format_ts($a['last_touched_at'], member_timezone(), 'M j')) : 'never touched' ?></div>
</div></div></div>
