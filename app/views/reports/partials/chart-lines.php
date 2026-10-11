<?php /** Lines over the months as inline SVG. Data: spec (a lines chart), currency */
$g = chart_lines_layout($spec, $currency);
$id = 'report-chart-svg'; ?>
<figure class="rpt-chart m-0" id="report-chart-figure">
<svg viewBox="0 0 <?= $g['w'] ?> <?= $g['h'] ?>" width="100%" role="img" aria-labelledby="<?= $id ?>-title <?= $id ?>-desc" class="rpt-svg" id="<?= $id ?>" data-chart="lines">
    <title id="<?= $id ?>-title"><?= e($spec['title']) ?></title>
    <desc id="<?= $id ?>-desc"><?= e(chart_desc($spec, $currency)) ?></desc>
    <?php foreach ($g['ticks'] as $t): ?>
        <line class="rpt-grid" x1="<?= $g['left'] ?>" y1="<?= $t['y'] ?>" x2="<?= $g['right'] ?>" y2="<?= $t['y'] ?>" />
        <text class="rpt-tick" x="<?= $g['left'] - 6 ?>" y="<?= $t['y'] + 4 ?>" text-anchor="end"><?= e($t['text']) ?></text>
    <?php endforeach; ?>
    <?php foreach ($g['xs'] as $x): ?><text class="rpt-tick" x="<?= $x['x'] ?>" y="<?= $g['bottom'] + 16 ?>" text-anchor="middle"><?= e($x['text']) ?></text><?php endforeach; ?>
    <?php foreach ($g['lines'] as $l): ?>
        <path class="rpt-line <?= e($l['class']) ?><?= $l['dashed'] ? ' rpt-dashed' : '' ?>" d="<?= e($l['d']) ?>" />
        <?php foreach ($l['points'] as $p): ?><circle class="rpt-dot <?= e($l['class']) ?>" cx="<?= $p['x'] ?>" cy="<?= $p['y'] ?>" r="3.5"><title><?= e($p['text']) ?></title></circle><?php endforeach; ?>
    <?php endforeach; ?>
</svg>
<ul class="rpt-legend" id="report-chart-legend">
    <?php foreach ($g['lines'] as $l): ?><li><span class="rpt-swatch <?= e($l['class']) ?><?= $l['dashed'] ? ' rpt-swatch-dashed' : '' ?>"></span><?= e($l['label']) ?></li><?php endforeach; ?>
</ul>
</figure>
