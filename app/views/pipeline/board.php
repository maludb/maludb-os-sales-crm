<?php /** The board (screen `board`). Data: pipeline, board, filters, pipelines, views, owners, teams, sources, may, notice, back */
$action = $may['create'] ? hx_link('/deals/new?pipeline=' . (int) $pipeline['pipeline_id'], '<i class="feather-plus me-1"></i>New deal', 'btn btn-primary btn-touch', 'id="board-new-deal-btn"') : ''; ?>
<?= view('shared/header.php', ['id' => 'board', 'title' => $pipeline['name'], 'crumbs' => [['Today', '/'], ['Pipeline', null]], 'action' => $action]) ?>
<div class="main-content" id="board">
    <?= view('shared/notice.php', ['notice' => $notice]) ?>
    <?= view('pipeline/partials/view-switch.php', ['pipeline' => $pipeline, 'pipelines' => $pipelines, 'page' => 'board', 'filters' => $filters]) ?>
    <?= view('pipeline/partials/filters.php', ['pipeline' => $pipeline, 'page' => 'board', 'filters' => $filters, 'owners' => $owners, 'teams' => $teams, 'sources' => $sources, 'views' => $views, 'may' => $may]) ?>
    <?= view('pipeline/partials/columns.php', ['pipeline' => $pipeline, 'board' => $board, 'filters' => $filters, 'back' => $back]) ?>
</div>
