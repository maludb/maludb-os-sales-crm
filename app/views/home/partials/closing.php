<?php /** My deals closing this month: count, value, weighted — in the base currency (others counted apart, design (a)). Data: s */ $c = $s['closing']; ?>
<div class="card mb-3 home-tile" id="home-closing"><div class="card-body">
    <div class="fs-12 text-muted mb-1">Closing this month</div>
    <div class="d-flex flex-wrap align-items-baseline gap-3">
        <div><div class="home-tile-figure" id="home-closing-value"><?= e(money($c['value'], $s['currency'])) ?></div><div class="fs-11 text-muted"><?= (int) $c['count'] ?> deal<?= $c['count'] === 1 ? '' : 's' ?></div></div>
        <div><div class="fw-semibold" id="home-closing-weighted"><?= e(money($c['weighted'], $s['currency'])) ?></div><div class="fs-11 text-muted">weighted</div></div>
    </div>
    <?php if ($c['other_currency'] > 0): ?><div class="fs-11 text-muted mt-1" id="home-closing-other"><?= (int) $c['other_currency'] ?> in another currency, not added in.</div><?php endif; ?>
    <?php if ($c['count'] > 0): ?><div class="mt-2"><?= hx_link($s['board'] . '?owner=me&closing=month', 'See them on the board', 'fs-12 fw-semibold', 'id="home-closing-link"') ?></div><?php endif; ?>
</div></div>
