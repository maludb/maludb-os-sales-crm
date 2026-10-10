<?php /** Saved views (saved-views): mine and the shared ones as cards by kind — name, scope, layout, the filters as chips; Run · Edit · Delete. Data: views, edit, may_share, notice */
$byKind = [];
foreach ($views as $v) { $byKind[$v['record_kind']][] = $v; } ?>
<?= view('shared/header.php', ['id' => 'saved-views', 'title' => 'Saved views', 'crumbs' => [['Today', '/'], ['Saved views', null]], 'back' => [pipeline_board_url(), 'the board']]) ?>
<div class="main-content" id="saved-views">
    <?= view('shared/notice.php', ['notice' => $notice]) ?>
    <div class="fs-12 text-muted mb-3">A view keeps a screen's filters, sort and columns under a name. Save one from the board or a list with “Save this view”.</div>
    <?php if ($views === []): ?><div class="card"><div class="card-body text-center text-muted py-4" id="saved-views-empty">No saved views yet.</div></div><?php endif; ?>
    <?php foreach ($byKind as $kind => $rows): ?>
        <h6 class="mt-3 mb-2" id="saved-views-kind-<?= e($kind) ?>"><?= e(SAVED_VIEW_KINDS[$kind] ?? ucfirst($kind)) ?></h6>
        <div class="row g-3">
        <?php foreach ($rows as $v): $vid = $v['saved_view_id']; $mine = saved_view_mine($v); ?>
            <div class="col-md-6 col-xl-4"><div class="card h-100 mb-0" id="saved-view-<?= $vid ?>"><div class="card-body d-flex flex-column">
                <div class="d-flex align-items-start gap-2"><span class="avatar-text avatar-md rounded"><i class="<?= $v['layout'] === 'board' ? 'feather-trello' : ($v['layout'] === 'forecast' ? 'feather-trending-up' : 'feather-list') ?>"></i></span>
                    <div class="min-w-0 flex-grow-1"><div class="fw-semibold text-truncate"><?= e($v['name']) ?></div>
                        <div class="fs-11 text-muted"><?= $v['is_system'] ? 'Built in' : ($v['scope'] === 'personal' ? 'Only me' : 'Everyone here') ?> · <?= e(SAVED_VIEW_LAYOUTS[$v['layout']] ?? $v['layout']) ?></div></div></div>
                <div class="d-flex flex-wrap gap-1 mt-2"><?php foreach (saved_view_chips(db(), $v['filters']) as $chip): ?><span class="badge bg-soft-info text-info"><?= e($chip) ?></span><?php endforeach; ?></div>
                <div class="d-flex gap-1 mt-auto pt-2">
                    <?= hx_link(saved_view_url(db(), $v), '<i class="feather-play me-1"></i>Run', 'btn btn-sm btn-primary btn-touch', 'id="saved-view-' . $vid . '-run"') ?>
                    <?php if ($mine): ?><?= hx_link('/views?edit=' . $vid . '#saved-view-' . $vid, 'Edit', 'btn btn-sm btn-light btn-touch', 'id="saved-view-' . $vid . '-edit"') ?>
                    <form method="post" action="/views/delete.php" hx-post="/views/delete.php" hx-target="#flash" hx-confirm="Delete the view <?= e($v['name']) ?>?" class="ms-auto"><?= csrf_field() ?><input type="hidden" name="saved_view" value="<?= $vid ?>">
                        <button type="submit" class="btn btn-sm btn-light text-danger btn-touch" id="saved-view-<?= $vid ?>-delete" aria-label="Delete"><i class="feather-trash-2"></i></button></form><?php endif; ?>
                </div>
                <?php if ($edit === $vid && $mine): ?><div class="border-top mt-2 pt-2" id="saved-view-<?= $vid ?>-edit-form"><?= view('views/form.php', ['v' => $v, 'kind' => $v['record_kind'], 'filters' => $v['filters'], 'layout' => $v['layout'], 'sort' => $v['sort_by'], 'columns' => $v['columns'], 'may_share' => $may_share, 'return_to' => '/views', 'prefix' => 'saved-view-' . $vid . '-edit']) ?></div><?php endif; ?>
            </div></div></div>
        <?php endforeach; ?>
        </div>
    <?php endforeach; ?>
</div>
