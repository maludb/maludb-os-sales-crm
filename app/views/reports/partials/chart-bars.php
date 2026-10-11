<?php /** Horizontal bars, grouped or stacked, as inline SVG (charts.php draws the geometry). Data: spec (a bars chart), currency */
$g = chart_bars_layout($spec, $currency);
$id = 'report-chart-svg';
$multi = count($spec['series']) > 1; ?>
<figure class="rpt-chart m-0" id="report-chart-figure">
<svg viewBox="0 0 <?= $g['w'] ?> <?= $g['h'] ?>" width="100%" role="img" aria-labelledby="<?= $id ?>-title <?= $id ?>-desc" class="rpt-svg" id="<?= $id ?>" data-chart="bars">
    <title id="<?= $id ?>-title"><?= e($spec['title']) ?></title>
    <desc id="<?= $id ?>-desc"><?= e(chart_desc($spec, $currency)) ?></desc>
    <line class="rpt-axis" x1="0.5" y1="2" x2="0.5" y2="<?= $g['h'] - 2 ?>" />
    <?php foreach ($g['rows'] as $ri => $row): ?>
        <g id="report-chart-row-<?= $ri ?>">
            <text class="rpt-label" x="2" y="<?= $row['y'] ?>"><?= e(mb_strlen($row['label']) > 44 ? mb_substr($row['label'], 0, 43) . '…' : $row['label']) ?><title><?= e($row['label']) ?></title></text>
            <?php foreach ($row['bars'] as $b): ?>
                <rect class="rpt-bar <?= e($b['class']) ?>" x="<?= $b['x'] + 1 ?>" y="<?= $b['y'] ?>" width="<?= max(0.0, $b['w']) ?>" height="<?= $b['h'] ?>" rx="2"><title><?= e($row['label'] . ': ' . $b['text']) ?></title></rect>
                <?php if (!$g['stacked']): ?><text class="rpt-value" x="<?= round($b['w'] + 6, 1) ?>" y="<?= $b['y'] + $b['h'] - 1 ?>"><?= e($b['value_text']) ?></text><?php endif; ?>
            <?php endforeach; ?>
            <?php if ($g['stacked']): ?><text class="rpt-value" x="<?= round($row['total_x'] + 6, 1) ?>" y="<?= $row['bars'][0]['y'] + $row['bars'][0]['h'] - 1 ?>"><?= e($row['total_text']) ?></text><?php endif; ?>
            <?php if ($row['mark'] !== null): ?><line class="rpt-mark" x1="<?= $row['mark']['x'] + 1 ?>" y1="<?= $row['mark']['y1'] ?>" x2="<?= $row['mark']['x'] + 1 ?>" y2="<?= $row['mark']['y2'] ?>"><title><?= e($row['mark']['text']) ?></title></line><?php endif; ?>
        </g>
    <?php endforeach; ?>
</svg>
<?php if ($g['cut'] > 0): ?><figcaption class="fs-11 text-muted">The first <?= CHART_MAX_CATEGORIES ?> are drawn; the table has all <?= count($spec['categories']) ?>.</figcaption><?php endif; ?>
<?php if ($multi || isset($spec['marks'])): ?>
<ul class="rpt-legend" id="report-chart-legend">
    <?php foreach ($spec['series'] as $si => $s): ?><li><span class="rpt-swatch <?= e(chart_series_class($s, $si)) ?>"></span><?= e($s['label']) ?></li><?php endforeach; ?>
    <?php if (isset($spec['marks'])): ?><li><span class="rpt-swatch-mark"></span><?= e($spec['marks']['label']) ?></li><?php endif; ?>
</ul>
<?php endif; ?>
</figure>
