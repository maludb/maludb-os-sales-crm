<?php /** A campaign's results tiles (#campaign-results). Data: c, results (crm_campaign_results), counts (member status => n) */
$cur = $c['currency'] ?: base_currency(db());
$base = base_currency(db());
$tile = static fn (string $id, string $label, string $value, string $sub = '') => '<div class="col-6 col-md-3 col-xl-2"><div class="card mb-0 h-100" id="campaign-result-' . $id . '"><div class="card-body py-2 px-3">'
    . '<div class="fs-11 text-muted">' . e($label) . '</div><div class="fw-semibold fs-16" id="campaign-result-' . $id . '-value">' . e($value) . '</div>' . ($sub !== '' ? '<div class="fs-11 text-muted">' . e($sub) . '</div>' : '') . '</div></div></div>'; ?>
<div class="row g-2 mb-3" id="campaign-results">
    <?= $tile('members', 'Members', (string) (int) $results['members'], $c['expected_leads'] !== null ? 'hoped for ' . (int) $c['expected_leads'] . ' leads' : '') ?>
    <?= $tile('responded', 'Responded', (string) (int) $results['responded']) ?>
    <?= $tile('leads', 'Leads', (string) (int) $results['leads'], (int) $results['converted'] . ' converted') ?>
    <?= $tile('deals', 'Deals', (string) (int) $results['deals'], (int) $results['won'] . ' won') ?>
    <?= $tile('won-value', 'Won', money($results['won_value'], $base), 'open ' . money($results['open_value'], $base)) ?>
    <?= $tile('cost-per-lead', 'Cost a lead', $results['cost_per_lead'] === null ? '—' : money($results['cost_per_lead'], $cur), $c['budget'] !== null ? 'of ' . money($c['budget'], $cur) : 'no budget') ?>
    <?= $tile('cost-per-won', 'Cost a won deal', $results['cost_per_won'] === null ? '—' : money($results['cost_per_won'], $cur)) ?>
</div>
