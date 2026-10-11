<?php /** A pipeline's stages, sortable (admin-pipelines; id stage-list-{pipeline}; the answer of stage_reorder from the screen). Data: p (a pipeline of pipelines_with_stages) */
$order = array_values(array_map(static fn (array $s): int => $s['id'], array_filter($p['stages'], static fn (array $s): bool => $s['kind'] === 'open' && $s['archived_at'] === null)));
$archivedPipeline = $p['archived_at'] !== null; ?>
<ul class="list-group list-group-flush stage-list" id="stage-list-<?= (int) $p['id'] ?>" data-pipeline="<?= (int) $p['id'] ?>"<?= $archivedPipeline ? '' : ' data-stage-sortable' ?>>
    <?php if ($p['stages'] === []): ?><li class="list-group-item text-muted fs-12" id="stage-list-<?= (int) $p['id'] ?>-empty">No stages yet.</li><?php endif; ?>
    <?php foreach ($p['stages'] as $s) { $i = array_search($s['id'], $order, true); echo view('admin/partials/stage-row.php', ['s' => $s, 'p' => $p, 'order' => $order, 'i' => $i === false ? null : $i]); } ?>
</ul>
