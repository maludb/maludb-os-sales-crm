<?php /** The team view's legend: a colour per person, the same map the chips use. Data: colours */ ?>
<div class="d-flex flex-wrap gap-3 mt-2 fs-12" id="calendar-legend" aria-label="Who is who">
    <?php foreach ($colours as $mid => $c): ?><span class="d-inline-flex align-items-center gap-1" id="calendar-legend-<?= (int) $mid ?>"><span class="cal-legend-dot" style="--owner: <?= e($c['colour']) ?>"></span><?= e($c['name']) ?></span><?php endforeach; ?>
</div>
