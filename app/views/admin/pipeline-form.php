<?php /** A pipeline's form (admin-pipeline-add / admin-pipeline-edit; ids pipeline-form, field ids pipeline-form-field-*). Data: p (null on add), base_currency */
$editing = $p !== null;
$id = $editing ? (int) $p['id'] : null;
$archived = $editing && $p['archived_at'] !== null; ?>
<?= view('shared/header.php', ['id' => 'pipeline-form', 'title' => $editing ? 'Edit ' . $p['name'] : 'New pipeline', 'crumbs' => [['Today', '/'], ['Admin', '/admin'], ['Pipelines', '/admin/pipelines'], [$editing ? 'Edit' : 'New', null]],
    'back' => ['/admin/pipelines', 'Pipelines']]) ?>
<div class="main-content" id="pipeline-form-content">
    <form method="post" action="/admin/pipelines/save.php" hx-post="/admin/pipelines/save.php" hx-target="#flash" id="pipeline-form">
        <?= csrf_field() ?>
        <?php if ($editing): ?><input type="hidden" name="pipeline" value="<?= $id ?>"><?php endif; ?>
        <div class="card mb-3"><div class="card-body"><div class="row g-3">
            <div class="col-md-6"><label class="form-label" for="pipeline-form-field-name">Name</label>
                <input type="text" class="form-control btn-touch" id="pipeline-form-field-name" name="name" value="<?= e((string) ($p['name'] ?? '')) ?>" maxlength="80" required autofocus></div>
            <div class="col-md-6"><label class="form-label" for="pipeline-form-field-key">Key</label>
                <input type="text" class="form-control btn-touch" id="pipeline-form-field-key" name="key" value="<?= e((string) ($p['key'] ?? '')) ?>" maxlength="40" pattern="[a-z][a-z0-9_]*" placeholder="made from the name">
                <div class="form-text">Lower-case letters, digits and underscores. It is in the board's address: <code>/pipeline/<?= e((string) ($p['key'] ?? 'key')) ?>/board</code>.</div></div>
            <div class="col-md-4"><label class="form-label" for="pipeline-form-field-currency">Currency</label>
                <input type="text" class="form-control btn-touch" id="pipeline-form-field-currency" name="currency" value="<?= e((string) ($p['currency'] ?? '')) ?>" maxlength="3" placeholder="<?= e($base_currency) ?>">
                <div class="form-text">Blank is the business's (<?= e($base_currency) ?>).</div></div>
            <div class="col-md-4"><label class="form-label" for="pipeline-form-field-rotting">A deal rots after (days)</label>
                <input type="number" class="form-control btn-touch" id="pipeline-form-field-rotting" name="rotting_days" value="<?= e((string) ($p['rotting_days'] ?? '')) ?>" min="1" max="365" placeholder="the settings'">
                <div class="form-text">In one stage; a stage can set its own.</div></div>
            <div class="col-md-4"><label class="form-label" for="pipeline-form-field-position">Order</label>
                <input type="number" class="form-control btn-touch" id="pipeline-form-field-position" name="position" value="<?= e((string) ($p['position'] ?? '')) ?>" min="0" placeholder="last">
                <div class="form-text">Pipelines are listed by it, lowest first.</div></div>
            <div class="col-12"><input type="hidden" name="is_default" value="no"><div class="form-check form-switch">
                <input class="form-check-input" type="checkbox" role="switch" name="is_default" value="yes" id="pipeline-form-field-default" <?= $editing && $p['is_default'] ? 'checked' : '' ?>>
                <label class="form-check-label" for="pipeline-form-field-default">The default pipeline — a new deal and a converted lead start here</label></div></div>
            <?php if ($editing): ?><div class="col-12"><input type="hidden" name="archived" value="no"><div class="form-check form-switch">
                <input class="form-check-input" type="checkbox" role="switch" name="archived" value="yes" id="pipeline-form-field-archived" <?= $archived ? 'checked' : '' ?>>
                <label class="form-check-label" for="pipeline-form-field-archived">Archived — off the board and out of the pickers; its deals keep it</label></div>
                <div class="form-text">The default pipeline, the last live one, and one holding open deals cannot be archived.</div></div><?php endif; ?>
        </div></div></div>
        <div class="admin-save-bar d-flex gap-2 mb-3">
            <button type="submit" class="btn btn-primary btn-touch flex-grow-1" id="pipeline-form-save-btn"><?= $editing ? 'Save' : 'Add the pipeline' ?></button>
            <?= hx_link('/admin/pipelines', 'Cancel', 'btn btn-light btn-touch', 'id="pipeline-form-cancel-btn"') ?>
        </div>
    </form>
    <?php if ($editing): ?>
    <div class="card mb-3" id="pipeline-delete-card"><div class="card-body">
        <h6 class="mb-1">Delete this pipeline</h6>
        <?php if ($p['deals_all'] > 0): ?>
            <div class="fs-12 text-muted" id="pipeline-delete-refused"><?= (int) $p['deals_all'] ?> deal<?= (int) $p['deals_all'] === 1 ? ' is' : 's are' ?> in this pipeline, so it can only be archived — switch Archived on above.</div>
        <?php elseif ($p['is_default']): ?>
            <div class="fs-12 text-muted" id="pipeline-delete-refused">The default pipeline cannot be deleted — make another the default first.</div>
        <?php else: ?>
            <div class="fs-12 text-muted mb-2">No deal has ever been in it. Deleting it deletes its <?= count($p['stages']) ?> stage<?= count($p['stages']) === 1 ? '' : 's' ?>.</div>
            <form method="post" action="/admin/pipelines/delete.php" hx-post="/admin/pipelines/delete.php" hx-confirm="Delete the pipeline <?= e($p['name']) ?> and its stages?" hx-target="#page-content" hx-swap="innerHTML"><?= csrf_field() ?>
                <input type="hidden" name="pipeline" value="<?= $id ?>"><button type="submit" class="btn btn-light-danger btn-touch" id="pipeline-delete-btn"><i class="feather-trash-2 me-1"></i>Delete the pipeline</button></form>
        <?php endif; ?>
    </div></div>
    <?php endif; ?>
</div>
