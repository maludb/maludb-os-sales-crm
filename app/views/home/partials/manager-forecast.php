<?php /** A manager's forecast tile: this month for the team (crm_forecast, db/015) — slice 7's reports open it in full. Data: s */ $f = $s['manager']['forecast']; $q = $f['quota'] !== null ? (float) $f['quota'] : null; ?>
<div class="card mb-3 home-tile" id="home-forecast"><div class="card-body">
    <div class="fs-12 text-muted mb-1">The team this month</div>
    <div class="d-flex flex-wrap align-items-baseline gap-3">
        <div><div class="home-tile-figure" id="home-forecast-won"><?= e(money($f['won_value'], $s['currency'])) ?></div><div class="fs-11 text-muted">won</div></div>
        <div><div class="fw-semibold" id="home-forecast-weighted"><?= e(money($f['weighted'], $s['currency'])) ?></div><div class="fs-11 text-muted">weighted open</div></div>
        <?php if ($q !== null && $q > 0): ?><div><div class="fw-semibold" id="home-forecast-quota"><?= (int) round(100 * (float) $f['won_value'] / $q) ?>%</div><div class="fs-11 text-muted">of <?= e(money($q, $s['currency'])) ?></div></div><?php endif; ?>
    </div>
    <div class="mt-2"><?= hx_link(nav_built('/reports/forecast.php') ? '/reports/forecast' : '/reports', 'The forecast', 'fs-12 fw-semibold', 'id="home-forecast-link"') ?></div>
</div></div>
