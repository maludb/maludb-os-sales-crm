<?php /** The pipeline chooser and Board · List · Forecast, the filters carried. Data: pipeline, pipelines, page (board|list|forecast), filters */
$here = $page; ?>
<div class="d-flex flex-wrap gap-2 align-items-center mb-3" id="board-switch">
    <div class="dropdown" id="board-pipeline">
        <button type="button" class="btn btn-light btn-touch dropdown-toggle" data-bs-toggle="dropdown" aria-expanded="false" id="board-pipeline-btn"><i class="feather-columns me-1"></i><?= e($pipeline['name']) ?></button>
        <div class="dropdown-menu" id="board-pipeline-menu">
            <?php foreach ($pipelines as $p): ?><?= hx_link(pipeline_url($p, $here, $filters), e($p['name']) . ($p['is_default'] ? ' <span class="badge bg-soft-secondary text-secondary ms-1">default</span>' : ''), 'dropdown-item' . ((int) $p['pipeline_id'] === $pipeline['pipeline_id'] ? ' active' : ''), 'id="board-pipeline-' . (int) $p['pipeline_id'] . '"') ?><?php endforeach; ?>
        </div>
    </div>
    <div class="btn-group" role="group" aria-label="View" id="board-views">
        <?php foreach (['board' => ['feather-trello', 'Board'], 'list' => ['feather-list', 'List'], 'forecast' => ['feather-trending-up', 'Forecast']] as $k => [$icon, $label]): ?>
            <?= hx_link(pipeline_url($pipeline, $k, $k === 'forecast' ? [] : $filters), '<i class="' . $icon . ' me-1"></i>' . $label, 'btn btn-touch ' . ($here === $k ? 'btn-primary' : 'btn-light'), 'id="board-view-' . $k . '"' . ($here === $k ? ' aria-current="page"' : '')) ?>
        <?php endforeach; ?>
    </div>
</div>
