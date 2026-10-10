<?php /** The columns region (#board-columns): the open stages, then Won and Lost collapsed (a drop navigates to the form). Polled every 30 s — never mid-drag —
 * and on dealChanged. At 375 a stage chooser scrolls the region to a column. Data: pipeline, board, filters, back */
$query = board_query($filters);
$cols = '/pipeline/' . rawurlencode((string) $pipeline['key']) . '/columns' . ($query === '' ? '' : '?' . $query);
$open = array_map(static fn ($c) => $c['stage'], $board['stages']);
$currency = $board['currency']; ?>
<div id="board-columns" class="board-columns-wrap" hx-get="<?= e($cols) ?>" hx-trigger="every 30s[!document.body.hasAttribute('data-dragging')], dealChanged from:body" hx-swap="outerHTML" data-query="<?= e($query) ?>" data-pipeline="<?= (int) $pipeline['pipeline_id'] ?>">
    <div class="d-md-none mb-2"><label class="visually-hidden" for="board-stage-chooser">Go to a stage</label>
        <select class="form-select btn-touch" id="board-stage-chooser" onchange="var c=document.getElementById('board-col-'+this.value);if(c){c.scrollIntoView({behavior:'smooth',inline:'start',block:'nearest'});}">
            <?php foreach ($board['stages'] as $c): ?><option value="<?= (int) $c['stage']['stage_id'] ?>"><?= e($c['stage']['name']) ?> (<?= (int) $c['count'] ?>)</option><?php endforeach; ?></select></div>
    <div class="board-columns" id="board-columns-scroller">
        <?php foreach ($board['stages'] as $c) { echo view('pipeline/partials/column.php', ['c' => $c, 'stages' => $open, 'pipeline' => $pipeline, 'query' => $query, 'back' => $back, 'currency' => $currency]); } ?>
        <?php foreach (['won' => ['success', 'feather-award'], 'lost' => ['danger', 'feather-x-circle']] as $kind => [$tone, $icon]): $s = $board['closed'][$kind]; if ($s === null) { continue; } $sid = (int) $s['stage_id']; ?>
        <section class="board-col board-col-closed board-col-<?= $kind ?>" id="board-col-<?= $sid ?>" data-stage-id="<?= $sid ?>" data-closed="<?= $kind ?>">
            <header class="board-col-head" id="board-col-<?= $sid ?>-head" style="--stage: var(--bs-<?= $tone ?>)"><div class="fw-semibold"><i class="<?= $icon ?> me-1 text-<?= $tone ?>"></i><?= e($s['name']) ?></div>
                <div class="fs-11 text-muted" id="board-col-<?= $sid ?>-month"><?= (int) $s['count'] ?> this month · <?= e(money($s['value'], $currency)) ?></div></header>
            <div class="board-col-body board-drop-closed" data-board-droppable data-closed="<?= $kind ?>" id="board-col-<?= $sid ?>-body"><div class="fs-11 text-muted text-center py-3 board-drop-hint">Drop a deal here to mark it <?= $kind ?>.</div></div>
        </section>
        <?php endforeach; ?>
    </div>
</div>
