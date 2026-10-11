<?php /** A report (screens report-*): the filter bar, the headline, the chart, the table, Export CSV. Data: report (report()), options, has_period, may (export, team), back, extra_tile? */
$name = $report['name']; $h = $report['headline']; $tone = $h['tone'] ?? null; $chart = $report['chart'];
$exportFields = $report['filters'] + [];
$exportForm = static function (string $id, string $label, string $icon, bool $later, string $class) use ($name, $exportFields): string {
    $html = '<form method="post" action="/reports/export.php" class="d-inline" id="' . $id . '-form">' . csrf_field() . '<input type="hidden" name="report" value="' . e($name) . '">';
    foreach ($exportFields as $k => $v) {
        if ($v === null || $v === '' || $k === 'preset') { continue; }
        $html .= '<input type="hidden" name="' . e($k) . '" value="' . e((string) $v) . '">';
    }
    if ($later) { $html .= '<input type="hidden" name="later" value="1">'; }
    return $html . '<button type="submit" class="btn ' . $class . ' btn-touch" id="' . $id . '"><i class="' . $icon . ' me-1"></i>' . e($label) . '</button></form>';
}; ?>
<?= view('shared/header.php', ['id' => 'report-' . str_replace('_', '-', $name), 'title' => $report['title'], 'crumbs' => [['Today', '/'], ['Reports', '/reports'], [$report['title'], null]],
    'action' => $may['export'] ? $exportForm('report-export-btn', 'Export CSV', 'feather-download', false, 'btn-primary') : '']) ?>
<div class="main-content" id="report-content" data-report="<?= e($name) ?>">
    <?= view('reports/partials/filters.php', ['report' => $report, 'options' => $options, 'has_period' => $has_period]) ?>
    <div class="fs-12 text-muted mb-2" id="report-period">
        <?= e($report['period']) ?><?php if ($name === 'forecast'): ?> · in <?= e($report['currency']) ?>; a deal in another currency is left out<?php endif; ?>
    </div>
    <div class="row g-3 mb-3">
        <div class="col-12 col-md-4">
            <div class="card h-100 report-card" id="report-headline"><div class="card-body">
                <div class="fs-12 text-muted"><?= e($h['label']) ?></div>
                <div class="report-figure<?= $tone !== null ? ' text-' . e($tone) : '' ?>" id="report-headline-figure"><?= e($h['text']) ?></div>
                <?php if (($h['detail'] ?? '') !== ''): ?><div class="fs-12 text-muted" id="report-headline-detail"><?= e($h['detail']) ?></div><?php endif; ?>
                <?php if ($name === 'cycle_time'): $t = $report['extra']['touches']; ?>
                    <div class="mt-3 pt-2 border-top" id="report-touches"><div class="fs-12 text-muted">Touches to win</div>
                        <div class="fw-semibold" id="report-touches-figure"><?= $t['deals'] > 0 ? e(number_format((float) $t['avg_touches'], 1)) . ' on average' : '—' ?></div>
                        <?php if ($t['deals'] > 0): ?><div class="fs-11 text-muted">median <?= e(number_format((float) $t['median_touches'], 1)) ?> across <?= (int) $t['deals'] ?> won deal<?= $t['deals'] === 1 ? '' : 's' ?></div><?php endif; ?></div>
                <?php endif; ?>
            </div></div>
        </div>
        <div class="col-12 col-md-8">
            <div class="card h-100"><div class="card-body" id="report-chart">
                <?php $hasData = $chart['type'] !== 'none' && (($chart['type'] === 'funnel' && $chart['stages'] !== []) || ($chart['type'] === 'bars' && $chart['categories'] !== []) || ($chart['type'] === 'lines' && $chart['x'] !== []));
                if (!$hasData): ?><div class="text-muted fs-12 py-3" id="report-chart-empty"><?= e($report['note'] !== '' ? $report['note'] : 'Nothing to draw for these filters.') ?></div>
                <?php else: ?>
                    <div class="fs-12 fw-semibold mb-2" id="report-chart-title"><?= e($chart['title']) ?></div>
                    <?= view('reports/partials/chart-' . $chart['type'] . '.php', ['spec' => $chart, 'currency' => $report['currency']]) ?>
                <?php endif; ?>
            </div></div>
        </div>
    </div>
    <?php if ($name === 'forecast'): ?>
        <div class="fs-12 mb-2"><?= hx_link($pipeline_forecast_url, 'Open the grid by pipeline', 'fw-semibold', 'id="report-forecast-grid-link"') ?></div>
    <?php endif; ?>
    <?= view('reports/partials/table.php', ['report' => $report]) ?>
    <?php if ($may['export']): ?>
    <div class="mt-3 d-flex flex-wrap gap-2 align-items-center">
        <?= $exportForm('report-export-later-btn', 'Build it in the background', 'feather-clock', true, 'btn-light') ?>
        <span class="fs-11 text-muted">Ready on the <?= hx_link('/exports', 'Exports') ?> page in a minute or two.</span>
    </div>
    <?php endif; ?>
</div>
