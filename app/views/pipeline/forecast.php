<?php /** The forecast (screen `forecast`): sellers (or stages) by month of expected close — value / weighted, the won so far, the quota under each seller; totals.
 * A cell opens the list of that seller's deals closing that month. Data: pipeline, grid, by, months, team, teams, pipelines, views, currency, may, back, filters */
$base = '/pipeline/' . rawurlencode((string) $pipeline['key']) . '/forecast';
$q = static fn (array $over) => $base . '?' . http_build_query(array_filter(array_merge(['by' => $by, 'months' => $months, 'team' => $team], $over), static fn ($v) => $v !== null && $v !== ''));
$monthName = static fn (string $m) => (new DateTimeImmutable($m . '-01'))->format('M Y');
$cellLink = static function (array $row, string $m) use ($pipeline, $by): string {
    $f = ['status' => 'all'];
    if ($by === 'seller') { $f['owner'] = (string) $row['id']; }
    return pipeline_url($pipeline, 'list', $f, ['close_month' => $m]);
}; ?>
<?= view('shared/header.php', ['id' => 'forecast', 'title' => $pipeline['name'], 'crumbs' => [['Today', '/'], ['Pipeline', pipeline_url($pipeline, 'board')], ['Forecast', null]]]) ?>
<div class="main-content" id="forecast">
    <?= view('pipeline/partials/view-switch.php', ['pipeline' => $pipeline, 'pipelines' => $pipelines, 'page' => 'forecast', 'filters' => []]) ?>
    <form method="get" action="<?= e($base) ?>" hx-get="<?= e($base) ?>" hx-target="#page-content" hx-push-url="true" class="row g-2 align-items-end mb-3" id="forecast-filters">
        <div class="col-6 col-md-3"><label class="form-label fs-11 mb-0" for="forecast-filter-by">Rows</label><select name="by" class="form-select btn-touch" id="forecast-filter-by">
            <option value="seller"<?= $by === 'seller' ? ' selected' : '' ?>>Sellers</option><option value="stage"<?= $by === 'stage' ? ' selected' : '' ?>>Stages</option></select></div>
        <div class="col-6 col-md-3"><label class="form-label fs-11 mb-0" for="forecast-filter-months">Months</label><select name="months" class="form-select btn-touch" id="forecast-filter-months">
            <?php foreach ([3, 6, 12] as $n): ?><option value="<?= $n ?>"<?= $months === $n ? ' selected' : '' ?>><?= $n ?> months</option><?php endforeach; ?></select></div>
        <div class="col-6 col-md-3"><label class="form-label fs-11 mb-0" for="forecast-filter-team">Team</label><select name="team" class="form-select btn-touch" id="forecast-filter-team">
            <option value="">Everyone</option><?php foreach ($teams as $t): ?><option value="<?= (int) $t['department_id'] ?>"<?= $team === (int) $t['department_id'] ? ' selected' : '' ?>><?= e($t['name']) ?></option><?php endforeach; ?></select></div>
        <div class="col-6 col-md-3"><button type="submit" class="btn btn-light btn-touch w-100" id="forecast-filter-btn">Show</button></div>
    </form>
    <div class="fs-12 text-muted mb-2">Each cell: the open value · weighted, and what is won. In <?= e($currency) ?>; a deal in another currency is left out.</div>
    <div class="card"><div class="table-responsive"><table class="table mb-0 forecast-grid" id="forecast-grid">
        <thead><tr><th class="fs-11"><?= $by === 'seller' ? 'Seller' : 'Stage' ?></th><?php foreach ($grid['months'] as $m): ?><th class="fs-11 text-nowrap text-end"><?= e($monthName($m)) ?></th><?php endforeach; ?><th class="fs-11 text-end">Total</th></tr></thead>
        <tbody>
        <?php foreach ($grid['rows'] as $r): ?>
            <tr id="forecast-row-<?= (int) $r['id'] ?>"><th class="fs-12 text-nowrap"><?= e($r['name']) ?></th>
                <?php foreach ($grid['months'] as $m): $c = $r['cells'][$m] ?? ['open' => 0, 'weighted' => 0, 'won' => 0, 'deals' => 0]; ?>
                    <td class="text-end fs-12" id="forecast-cell-<?= (int) $r['id'] ?>-<?= e($m) ?>"><?php if ($c['deals'] > 0): ?><?= hx_link($cellLink($r, $m), e(money_short($c['open'], $currency)) . ' · ' . e(money_short($c['weighted'], $currency)), 'text-dark text-nowrap') ?>
                        <?php if ($c['won'] > 0): ?><div class="text-success fs-11 text-nowrap"><?= e(money_short($c['won'], $currency)) ?> won</div><?php endif; ?><?php else: ?><span class="text-muted">—</span><?php endif; ?>
                        <?php if (($r['quota'][$m] ?? null) !== null): ?><div class="fs-11 text-muted text-nowrap forecast-quota">quota <?= e(money_short($r['quota'][$m], $currency)) ?></div><?php endif; ?></td>
                <?php endforeach; ?>
                <td class="text-end fs-12 fw-semibold text-nowrap"><?= e(money_short($r['sum']['open'], $currency)) ?> · <?= e(money_short($r['sum']['weighted'], $currency)) ?></td></tr>
        <?php endforeach; ?>
        </tbody>
        <tfoot><tr><th class="fs-12">Total</th><?php $all = ['open' => 0, 'weighted' => 0]; foreach ($grid['months'] as $m): $t = $grid['totals'][$m] ?? ['open' => 0, 'weighted' => 0, 'won' => 0, 'quota' => null]; $all['open'] += $t['open']; $all['weighted'] += $t['weighted']; ?>
            <td class="text-end fs-12 fw-semibold text-nowrap" id="forecast-total-<?= e($m) ?>"><?= e(money_short($t['open'], $currency)) ?> · <?= e(money_short($t['weighted'], $currency)) ?><?php if ($t['won'] > 0): ?><div class="text-success fs-11"><?= e(money_short($t['won'], $currency)) ?> won</div><?php endif; ?>
                <?php if ($t['quota'] !== null): ?><div class="fs-11 text-muted">quota <?= e(money_short($t['quota'], $currency)) ?></div><?php endif; ?></td><?php endforeach; ?>
            <td class="text-end fs-12 fw-semibold text-nowrap"><?= e(money_short($all['open'], $currency)) ?> · <?= e(money_short($all['weighted'], $currency)) ?></td></tr></tfoot>
    </table></div></div>
</div>
