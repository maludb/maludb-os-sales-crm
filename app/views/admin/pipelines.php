<?php /** Pipelines and stages (screen `admin-pipelines`; ids pipelines-list, pipeline-{id}, stage-list-{pipeline}, stage-row-{id}). Data: live, archived (pipelines_with_stages), notice */ ?>
<?= view('shared/header.php', ['id' => 'admin-pipelines', 'title' => 'Pipelines', 'crumbs' => [['Today', '/'], ['Admin', '/admin'], ['Pipelines', null]],
    'action' => hx_link('/admin/pipelines/new', '<i class="feather-plus me-1"></i>Add a pipeline', 'btn btn-primary btn-touch', 'id="pipeline-add-btn"')]) ?>
<div class="main-content" id="admin-pipelines-content">
    <?= view('shared/notice.php', ['notice' => $notice]) ?>
    <div id="pipelines-list">
    <?php foreach ($live as $p): $id = (int) $p['id']; ?>
        <div class="card mb-3" id="pipeline-<?= $id ?>">
            <div class="card-header d-flex flex-wrap align-items-center gap-2">
                <div class="min-w-0 flex-grow-1"><h5 class="card-title mb-0"><?= e($p['name']) ?>
                    <?php if ($p['is_default']): ?><span class="badge bg-soft-primary text-primary ms-1" id="pipeline-<?= $id ?>-default">default</span><?php endif; ?></h5>
                    <div class="fs-12 text-muted"><code><?= e($p['key']) ?></code><?= $p['currency'] ? ' · ' . e($p['currency']) : '' ?><?= $p['rotting_days'] !== null ? ' · rots after ' . (int) $p['rotting_days'] . ' days' : '' ?> · <?= (int) $p['deals_open'] ?> open deal<?= (int) $p['deals_open'] === 1 ? '' : 's' ?></div></div>
                <div class="d-flex gap-2">
                    <?= hx_link('/admin/pipelines/' . $id . '/stages/new', '<i class="feather-plus me-1"></i>Add a stage', 'btn btn-light btn-touch', 'id="pipeline-' . $id . '-add-stage-btn"') ?>
                    <?= hx_link('/admin/pipelines/' . $id . '/edit', '<i class="feather-edit-2 me-1"></i>Edit', 'btn btn-light btn-touch', 'id="pipeline-' . $id . '-edit-btn"') ?>
                </div>
            </div>
            <div class="card-body p-0"><?= view('admin/partials/stage-list.php', ['p' => $p]) ?></div>
        </div>
    <?php endforeach; ?>
    <?php if ($live === []): ?><div class="card"><div class="card-body text-center text-muted" id="pipelines-empty">No pipeline yet.</div></div><?php endif; ?>
    </div>
    <?php if ($archived !== []): ?>
    <h6 class="text-muted mt-4 mb-2" id="pipelines-archived-title">Archived</h6>
    <div id="pipelines-archived">
    <?php foreach ($archived as $p): $id = (int) $p['id']; ?>
        <div class="card mb-2" id="pipeline-<?= $id ?>"><div class="card-body d-flex flex-wrap align-items-center gap-2 py-2">
            <div class="min-w-0 flex-grow-1"><span class="fw-semibold"><?= e($p['name']) ?></span> <span class="badge bg-soft-dark text-dark" id="pipeline-<?= $id ?>-archived">archived</span>
                <div class="fs-12 text-muted"><code><?= e($p['key']) ?></code> · <?= (int) $p['deals_all'] ?> deal<?= (int) $p['deals_all'] === 1 ? '' : 's' ?> keep it · <?= count($p['stages']) ?> stage<?= count($p['stages']) === 1 ? '' : 's' ?></div></div>
            <form method="post" action="/admin/pipelines/save.php" hx-post="/admin/pipelines/save.php" hx-target="#page-content" hx-swap="innerHTML"><?= csrf_field() ?>
                <input type="hidden" name="pipeline" value="<?= $id ?>"><input type="hidden" name="archived" value="no">
                <button type="submit" class="btn btn-light btn-touch" id="pipeline-<?= $id ?>-restore-btn"><i class="feather-rotate-ccw me-1"></i>Restore</button></form>
            <?= hx_link('/admin/pipelines/' . $id . '/edit', 'Edit', 'btn btn-light btn-touch', 'id="pipeline-' . $id . '-edit-btn"') ?>
        </div></div>
    <?php endforeach; ?>
    </div>
    <?php endif; ?>
</div>
