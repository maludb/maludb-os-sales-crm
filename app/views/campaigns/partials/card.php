<?php /** A campaign card (campaign-list). Data: c (a find_campaigns row), back. Name, kind, status, dates, what it brought (members, leads, deals, won), the budget's cost per lead. */
$cid = (int) $c['campaign_id'];
$cur = $c['currency'] ?: base_currency(db());
$badge = CAMPAIGN_STATUS_BADGE[$c['status']] ?? 'secondary'; ?>
<div class="col-md-6 col-xl-4"><div class="card h-100 mb-0" id="campaign-card-<?= $cid ?>"><div class="card-body d-flex flex-column">
    <div class="d-flex align-items-start gap-2">
        <span class="avatar-text avatar-md rounded flex-shrink-0"><i class="feather-flag"></i></span>
        <div class="min-w-0 flex-grow-1">
            <div class="fw-semibold fs-14 text-truncate"><?= hx_link(with_back('/campaigns/' . $cid, $back), e($c['name']), 'text-dark', 'id="campaign-card-' . $cid . '-link"') ?></div>
            <div class="fs-12 text-muted text-truncate"><?= e(CAMPAIGN_KINDS[$c['kind']] ?? $c['kind']) ?><?= campaign_dates_words($c) !== '' ? ' · ' . e(campaign_dates_words($c)) : '' ?></div>
        </div>
        <span class="badge bg-soft-<?= e($badge) ?> text-<?= e($badge) ?> flex-shrink-0" id="campaign-card-<?= $cid ?>-status"><?= e(CAMPAIGN_STATUSES[$c['status']] ?? $c['status']) ?></span>
    </div>
    <div class="row g-2 mt-2 text-center fs-12">
        <div class="col-3"><div class="fw-semibold" id="campaign-card-<?= $cid ?>-members"><?= (int) $c['members'] ?></div><div class="text-muted fs-11">members</div></div>
        <div class="col-3"><div class="fw-semibold"><?= (int) $c['leads'] ?></div><div class="text-muted fs-11">leads</div></div>
        <div class="col-3"><div class="fw-semibold"><?= (int) $c['deals'] ?></div><div class="text-muted fs-11">deals</div></div>
        <div class="col-3"><div class="fw-semibold"><?= (int) $c['won'] ?></div><div class="text-muted fs-11">won</div></div>
    </div>
    <div class="fs-12 mt-2 text-muted">
        <?= $c['budget'] !== null ? 'Budget ' . e(money($c['budget'], $cur)) : 'No budget' ?><?= $c['cost_per_lead'] !== null ? ' · ' . e(money($c['cost_per_lead'], $cur)) . ' a lead' : '' ?><?= (float) $c['won_value'] > 0 ? ' · won ' . e(money($c['won_value'], base_currency(db()))) : '' ?>
    </div>
    <div class="fs-11 text-muted mt-auto pt-2"><?= e((string) ($c['owner_name'] ?? 'No owner')) ?></div>
</div></div></div>
