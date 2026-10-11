<?php /** Overview: the fields in two columns, the custom fields, the ledger panel, the next step card. Data: a, summary, custom, ledger, tz, may */
$aid = (int) $a['account_id'];
$field = static fn (string $label, ?string $value, string $id) => '<div class="col-sm-6 mb-2" id="account-field-' . $id . '"><div class="fs-11 text-muted">' . e($label) . '</div><div class="text-break">' . ($value === null || $value === '' ? '<span class="text-muted">—</span>' : e($value)) . '</div></div>';
$next = $summary['next']; $words = ['overdue' => 'Overdue', 'today' => 'Today', 'scheduled' => 'Planned']; ?>
<div class="row g-3">
    <div class="col-lg-8">
        <div class="card mb-3" id="account-overview-fields"><div class="card-body"><div class="row">
            <?= $field('Kind', ACCOUNT_KINDS[$a['kind']] ?? $a['kind'], 'kind') ?>
            <?= $field('Owner', $a['owner_name'], 'owner') ?>
            <?= $field('Website', $a['website'], 'website') ?>
            <?= $field('Domain', $a['domain'], 'domain') ?>
            <?= $field('Industry', $a['industry'], 'industry') ?>
            <?= $field('Size', $a['employees'] ? $a['employees'] . ' people' : null, 'employees') ?>
            <?= $field('Annual revenue', $a['annual_revenue'] !== null ? money($a['annual_revenue'], $a['currency'] ?: null) : null, 'annual_revenue') ?>
            <?= $field('Phone', $a['phone'], 'phone') ?>
            <?= $field('Email', $a['email'], 'email') ?>
            <?= $field('City', trim(implode(', ', array_filter([$a['city'], $a['region'], $a['country']]))), 'city') ?>
            <?= $field('Billing address', $a['billing_address'], 'billing_address') ?>
            <?= $field('Shipping address', $a['shipping_address'], 'shipping_address') ?>
            <div class="col-sm-6 mb-2" id="account-field-parent"><div class="fs-11 text-muted">Parent account</div><div><?= $a['parent_account_id'] !== null && $a['parent_name'] !== null ? hx_link(with_back('/accounts/' . (int) $a['parent_account_id'], '/accounts/' . $aid), e($a['parent_name']), 'fw-semibold') : '<span class="text-muted">—</span>' ?></div></div>
            <?= $field('Source', $a['source_name'], 'source') ?>
            <?php if ($a['description']): ?><div class="col-12 mb-2" id="account-field-description"><div class="fs-11 text-muted">About</div><div class="text-break" style="white-space: pre-wrap"><?= e($a['description']) ?></div></div><?php endif; ?>
            <?php foreach ($custom as $label => $value): ?><?= $field($label, $value, 'custom-' . md5($label)) ?><?php endforeach; ?>
        </div></div></div>
    </div>
    <div class="col-lg-4">
        <div class="card mb-3" id="account-next-step"><div class="card-body">
            <div class="fs-12 text-muted mb-1">Next step</div>
            <?php if ($next === null): ?>
                <div class="d-flex align-items-center gap-2"><span class="next-chip next-none">No next step</span></div>
                <div class="fs-12 text-muted mt-1">Plan the next call or meeting so this account does not go quiet.</div>
            <?php else: ?>
                <div class="d-flex align-items-center gap-2"><i class="<?= e(activity_kind_icon((string) $next['kind'])) ?> text-muted"></i><span class="fw-semibold text-truncate"><?= e($next['subject']) ?></span></div>
                <div class="d-flex align-items-center gap-2 mt-1"><span class="next-chip next-<?= e($next['state']) ?>"><?= e($words[$next['state']] ?? $next['state']) ?></span><span class="fs-12 text-muted"><?= e(format_ts($next['at'], $tz, 'D M j, g:i A')) ?></span></div>
            <?php endif; ?>
        </div></div>
        <div class="card mb-3" id="account-numbers"><div class="card-body">
            <div class="d-flex justify-content-between fs-12 mb-1"><span class="text-muted">Contacts</span><span class="fw-semibold"><?= (int) $summary['contacts'] ?></span></div>
            <div class="d-flex justify-content-between fs-12 mb-1"><span class="text-muted">Open deals</span><span class="fw-semibold"><?= (int) $a['open_deals_count'] ?> · <?= e(money($a['open_deals_value'])) ?></span></div>
            <div class="d-flex justify-content-between fs-12 mb-1"><span class="text-muted">Won</span><span class="fw-semibold"><?= e(money($a['won_deals_value'])) ?></span></div>
            <div class="d-flex justify-content-between fs-12"><span class="text-muted">Last touched</span><span class="fw-semibold"><?= $a['last_touched_at'] ? e(format_ts($a['last_touched_at'], $tz, 'M j, Y')) : 'never' ?></span></div>
        </div></div>
        <div class="card mb-3" id="account-ledger"><div class="card-body">
            <div class="fs-12 text-muted mb-1">In the ledger</div>
            <?php if ($ledger['status'] === 'ok' && $ledger['rows'] !== []): $l = $ledger['rows'][0]; ?>
                <div class="fw-semibold" id="account-ledger-name"><?= e((string) ($l['name'] ?? '')) ?></div><div class="fs-12" id="account-ledger-balance">Balance <?= e(money($l['balance'] ?? 0, $l['currency'] ?? null)) ?></div>
            <?php else: ?>
                <div class="fs-12 text-muted" id="account-ledger-none"><?= match ($ledger['status']) { 'no_connection' => 'The General Ledger is not connected to Sales CRM. When it is, a won deal\'s customer and their balance show here.', 'unavailable' => 'The General Ledger did not answer just now, so the balance is not shown. Try again in a minute.', default => 'Not a customer in the ledger yet.' } ?></div>
            <?php endif; ?>
        </div></div>
    </div>
</div>
