<?php /** A report's filter bar: the period presets as links and two dates, then the report's own controls. Data: report (name, filters), options (pipelines, owners, teams), has_period */
$name = $report['name']; $f = $report['filters']; $base = report_url($name); $sel = static fn ($a, $b): string => (string) $a === (string) $b ? ' selected' : '';
$carry = static function (array $over) use ($f, $name): array {                        // the report's own filters stay when a preset is chosen
    $keep = array_diff_key($f, ['from' => 1, 'to' => 1, 'preset' => 1]);
    return array_merge($keep, $over);
};
$preset = $f['preset'] ?? null; ?>
<form method="get" action="<?= e($base) ?>" hx-get="<?= e($base) ?>" hx-target="#page-content" hx-push-url="true" class="mb-3" id="report-filters">
    <?php if ($has_period): ?>
    <div class="d-flex flex-wrap gap-1 mb-2" id="report-presets">
        <?php foreach (REPORT_PRESETS as $k => $label): $p = periods($k); $on = isset($f['from']) && $f['from'] === $p['from'] && $f['to'] === $p['to'] && ($preset === null || $preset === $k); ?>
            <?= hx_link(report_url($name, $carry(['preset' => $k])), e($label), 'btn btn-touch btn-sm ' . ($on ? 'btn-primary' : 'btn-light'), 'id="report-preset-' . e($k) . '"') ?>
        <?php endforeach; ?>
        <span class="btn btn-touch btn-sm disabled <?= ($preset === 'custom') ? 'btn-primary' : 'btn-light' ?>" id="report-preset-custom" aria-disabled="true">Custom</span>
    </div>
    <?php endif; ?>
    <div class="row g-2 align-items-end">
        <?php if ($has_period): ?>
        <div class="col-6 col-md-2"><label class="form-label fs-11 mb-0" for="report-filter-from">From</label><input type="date" name="from" id="report-filter-from" class="form-control btn-touch" value="<?= e((string) ($f['from'] ?? '')) ?>"></div>
        <div class="col-6 col-md-2"><label class="form-label fs-11 mb-0" for="report-filter-to">To</label><input type="date" name="to" id="report-filter-to" class="form-control btn-touch" value="<?= e((string) ($f['to'] ?? '')) ?>"></div>
        <?php endif; ?>
        <?php if ($name === 'funnel'): ?>
        <div class="col-6 col-md-3"><label class="form-label fs-11 mb-0" for="report-filter-pipeline">Pipeline</label><select name="pipeline" id="report-filter-pipeline" class="form-select btn-touch">
            <?php foreach ($options['pipelines'] as $p): ?><option value="<?= (int) $p['pipeline_id'] ?>"<?= $sel($f['pipeline'] ?? '', $p['pipeline_id']) ?>><?= e($p['name']) ?></option><?php endforeach; ?></select></div>
        <div class="col-6 col-md-3"><label class="form-label fs-11 mb-0" for="report-filter-owner">Owner</label><select name="owner" id="report-filter-owner" class="form-select btn-touch"><option value="">Anyone</option>
            <?php foreach ($options['owners'] as $o): ?><option value="<?= (int) $o['member_id'] ?>"<?= $sel($f['owner'] ?? '', $o['member_id']) ?>><?= e($o['display_name']) ?></option><?php endforeach; ?></select></div>
        <?php elseif ($name === 'win_rate'): ?>
        <div class="col-6 col-md-3"><label class="form-label fs-11 mb-0" for="report-filter-by">By</label><select name="by" id="report-filter-by" class="form-select btn-touch">
            <?php foreach (['seller' => 'Seller', 'source' => 'Source', 'pipeline' => 'Pipeline'] as $k => $l): ?><option value="<?= $k ?>"<?= $sel($f['by'] ?? 'seller', $k) ?>><?= $l ?></option><?php endforeach; ?></select></div>
        <?php elseif ($name === 'lost_reasons'): ?>
        <div class="col-6 col-md-3"><label class="form-label fs-11 mb-0" for="report-filter-by">By</label><select name="by" id="report-filter-by" class="form-select btn-touch">
            <?php foreach (['reason' => 'Reason', 'seller' => 'Reason and seller'] as $k => $l): ?><option value="<?= $k ?>"<?= $sel($f['by'] ?? 'reason', $k) ?>><?= $l ?></option><?php endforeach; ?></select></div>
        <?php elseif ($name === 'campaigns'): ?>
        <div class="col-6 col-md-3"><label class="form-label fs-11 mb-0" for="report-filter-status">Status</label><select name="status" id="report-filter-status" class="form-select btn-touch"><option value="">Any status</option>
            <?php foreach (CAMPAIGN_STATUSES as $k => $l): ?><option value="<?= $k ?>"<?= $sel($f['status'] ?? '', $k) ?>><?= $l ?></option><?php endforeach; ?></select></div>
        <?php elseif ($name === 'leaderboard'): ?>
        <div class="col-6 col-md-3"><label class="form-label fs-11 mb-0" for="report-filter-month">Month</label><input type="month" name="month" id="report-filter-month" class="form-control btn-touch" value="<?= e(substr((string) ($f['month'] ?? ''), 0, 7)) ?>"></div>
        <?php elseif ($name === 'forecast'): ?>
        <div class="col-6 col-md-2"><label class="form-label fs-11 mb-0" for="report-filter-by">Rows</label><select name="by" id="report-filter-by" class="form-select btn-touch">
            <?php foreach (['seller' => 'Sellers', 'stage' => 'Stages'] as $k => $l): ?><option value="<?= $k ?>"<?= $sel($f['by'] ?? 'seller', $k) ?>><?= $l ?></option><?php endforeach; ?></select></div>
        <div class="col-6 col-md-2"><label class="form-label fs-11 mb-0" for="report-filter-months">Months</label><select name="months" id="report-filter-months" class="form-select btn-touch">
            <?php foreach ([1, 3, 6, 12] as $n): ?><option value="<?= $n ?>"<?= $sel($f['months'] ?? 6, $n) ?>><?= $n ?> month<?= $n === 1 ? '' : 's' ?></option><?php endforeach; ?></select></div>
        <?php endif; ?>
        <?php if (in_array($name, ['activity', 'leaderboard', 'forecast'], true)): ?>
        <div class="col-6 col-md-3"><label class="form-label fs-11 mb-0" for="report-filter-team">Team</label><select name="team" id="report-filter-team" class="form-select btn-touch"><option value="">Everyone</option>
            <?php foreach ($options['teams'] as $t): ?><option value="<?= (int) $t['department_id'] ?>"<?= $sel($f['team'] ?? '', $t['department_id']) ?>><?= e($t['name']) ?></option><?php endforeach; ?></select></div>
        <?php endif; ?>
        <div class="col-6 col-md-2"><button type="submit" class="btn btn-light btn-touch w-100" id="report-filter-btn"><i class="feather-filter me-1"></i>Show</button></div>
    </div>
</form>
