<?php /** One open stage's column: the head (name and colour, count, value, weighted, the WIP warning, rotting), the cards, the foot on a long column.
 * Data: c (board_columns' entry), stages, pipeline, query, back, currency */
$s = $c['stage'];
$sid = (int) $s['stage_id']; ?>
<section class="board-col" id="board-col-<?= $sid ?>" data-stage-id="<?= $sid ?>" data-stage-name="<?= e($s['name']) ?>">
    <header class="board-col-head" id="board-col-<?= $sid ?>-head" style="--stage: <?= e((string) ($s['colour'] ?? '#6c757d')) ?>">
        <div class="d-flex align-items-center gap-1"><span class="fw-semibold text-truncate"><?= e($s['name']) ?></span><span class="badge bg-soft-secondary text-secondary" id="board-col-<?= $sid ?>-count"><?= (int) $c['count'] ?></span>
            <?php if ($c['over_wip']): ?><span class="badge text-bg-warning" id="board-col-<?= $sid ?>-wip" title="More than <?= (int) $s['wip_limit'] ?> deals here">WIP <?= (int) $s['wip_limit'] ?></span><?php endif; ?>
            <span class="ms-auto fs-11 text-muted"><?= (int) $s['probability'] ?>%</span></div>
        <div class="fs-11 text-muted" id="board-col-<?= $sid ?>-totals"><span class="fw-semibold text-dark" id="board-col-<?= $sid ?>-value"><?= e(money($c['value'], $currency)) ?></span> · <span id="board-col-<?= $sid ?>-weighted"><?= e(money($c['weighted'], $currency)) ?> weighted</span>
            <?php if ($c['other_currency'] > 0): ?> · +<?= (int) $c['other_currency'] ?> in other currencies<?php endif; ?>
            <?php if ($c['rotting'] > 0): ?> · <span class="text-danger" id="board-col-<?= $sid ?>-rotting"><?= (int) $c['rotting'] ?> rotting</span><?php endif; ?></div>
    </header>
    <div class="board-col-body" data-board-droppable data-stage-id="<?= $sid ?>" id="board-col-<?= $sid ?>-body">
        <?php foreach ($c['deals'] as $d) { echo view('pipeline/partials/card.php', ['d' => $d, 'stages' => $stages, 'pipeline' => $pipeline, 'query' => $query, 'back' => $back]); } ?>
        <?php if ($c['deals'] === []): ?><div class="board-col-empty fs-11 text-muted text-center py-3">No deals here.</div><?php endif; ?>
    </div>
    <?php if ($c['count'] > 6): ?><footer class="board-col-foot fs-11 text-muted" id="board-col-<?= $sid ?>-foot"><?= (int) $c['count'] ?> deals · <?= e(money($c['value'], $currency)) ?></footer><?php endif; ?>
</section>
