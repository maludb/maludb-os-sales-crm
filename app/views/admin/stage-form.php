<?php /** A stage's form (admin-stage-add / admin-stage-edit; ids stage-form, field ids stage-form-field-*). Data: s (null on add), pipeline (a pipeline of pipelines_with_stages), choices (key => label of what a stage can require) */
$editing = $s !== null;
$id = $editing ? (int) $s['id'] : null;
$kind = $editing ? $s['kind'] : 'open';
$hasWon = (bool) array_filter($pipeline['stages'], static fn (array $x): bool => $x['kind'] === 'won' && $x['id'] !== $id);
$hasLost = (bool) array_filter($pipeline['stages'], static fn (array $x): bool => $x['kind'] === 'lost' && $x['id'] !== $id);
$kinds = array_filter(STAGE_KINDS, static fn (string $k): bool => $k === 'open' || ($k === 'won' && !$hasWon) || ($k === 'lost' && !$hasLost), ARRAY_FILTER_USE_KEY);
$kindLocked = $editing && $s['deals_all'] > 0;
$has = $s['required_fields'] ?? []; ?>
<?= view('shared/header.php', ['id' => 'stage-form', 'title' => $editing ? 'Edit ' . $s['name'] : 'New stage in ' . $pipeline['name'], 'crumbs' => [['Today', '/'], ['Admin', '/admin'], ['Pipelines', '/admin/pipelines'], [$editing ? 'Edit stage' : 'New stage', null]],
    'back' => ['/admin/pipelines#pipeline-' . $pipeline['id'], $pipeline['name']]]) ?>
<div class="main-content" id="stage-form-content">
    <form method="post" action="/admin/stages/save.php" hx-post="/admin/stages/save.php" hx-target="#flash" id="stage-form">
        <?= csrf_field() ?>
        <input type="hidden" name="pipeline" value="<?= (int) $pipeline['id'] ?>"><?php if ($editing): ?><input type="hidden" name="stage" value="<?= $id ?>"><?php endif; ?>
        <div class="card mb-3"><div class="card-body"><div class="row g-3">
            <div class="col-md-6"><label class="form-label" for="stage-form-field-name">Name</label>
                <input type="text" class="form-control btn-touch" id="stage-form-field-name" name="name" value="<?= e((string) ($s['name'] ?? '')) ?>" maxlength="60" required autofocus></div>
            <div class="col-md-6"><label class="form-label" for="stage-form-field-kind">Kind</label>
                <select class="form-select btn-touch" id="stage-form-field-kind" name="kind" <?= $kindLocked ? 'disabled' : '' ?>><?php foreach ($kinds as $k => $l): ?><option value="<?= e($k) ?>" <?= $kind === $k ? 'selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select>
                <?php if ($kindLocked): ?><input type="hidden" name="kind" value="<?= e($kind) ?>"><div class="form-text">Deals sit in this stage, so it keeps its kind.</div>
                <?php else: ?><div class="form-text">A pipeline has one Won and one Lost stage; the rest are open.</div><?php endif; ?></div>
            <div class="col-md-4"><label class="form-label" for="stage-form-field-probability">Probability (percent)</label>
                <input type="number" class="form-control btn-touch" id="stage-form-field-probability" name="probability" value="<?= e((string) ($s['probability'] ?? '')) ?>" min="0" max="100" placeholder="10">
                <div class="form-text">Won is 100 and Lost is 0. A deal's weighted value follows it.</div></div>
            <div class="col-md-4"><label class="form-label" for="stage-form-field-rotting">Rots after (days)</label>
                <input type="number" class="form-control btn-touch" id="stage-form-field-rotting" name="rotting_days" value="<?= e((string) ($s['rotting_days'] ?? '')) ?>" min="1" max="365" placeholder="the pipeline's">
                <div class="form-text">In this stage.</div></div>
            <div class="col-md-4"><label class="form-label" for="stage-form-field-wip">WIP limit</label>
                <input type="number" class="form-control btn-touch" id="stage-form-field-wip" name="wip_limit" value="<?= e((string) ($s['wip_limit'] ?? '')) ?>" min="1" placeholder="none">
                <div class="form-text">A warning when more deals sit here, never a refusal.</div></div>
            <div class="col-md-4"><label class="form-label" for="stage-form-field-colour">Colour</label>
                <div class="input-group"><input type="color" class="form-control form-control-color btn-touch" id="stage-form-field-colour-picker" value="<?= e($s['colour'] ?? '#6c757d') ?>" aria-label="Pick a colour"
                    oninput="document.getElementById('stage-form-field-colour').value=this.value">
                <input type="text" class="form-control btn-touch" id="stage-form-field-colour" name="colour" value="<?= e((string) ($s['colour'] ?? '')) ?>" maxlength="7" placeholder="#6c757d" pattern="#[0-9a-fA-F]{6}"></div></div>
            <div class="col-md-4"><label class="form-label" for="stage-form-field-position">Order</label>
                <input type="number" class="form-control btn-touch" id="stage-form-field-position" name="position" value="<?= e((string) ($s['position'] ?? '')) ?>" min="0" placeholder="last">
                <div class="form-text">Or drag it on the pipelines screen.</div></div>
            <div class="col-md-4 d-flex align-items-end"><input type="hidden" name="ask_assistant" value="no"><div class="form-check form-switch">
                <input class="form-check-input" type="checkbox" role="switch" name="ask_assistant" value="yes" id="stage-form-field-assistant" <?= $editing && $s['ask_assistant'] ? 'checked' : '' ?>>
                <label class="form-check-label" for="stage-form-field-assistant">Ask the Sales Assistant for a next step when a deal enters</label></div></div>
            <div class="col-12"><div class="form-label" id="stage-form-required-label">A deal must have these to enter this stage</div>
                <input type="hidden" name="required_fields_present" value="1">
                <div class="d-flex flex-wrap gap-2" id="stage-form-required" role="group" aria-labelledby="stage-form-required-label">
                    <?php foreach ($choices as $key => $label): $cid = 'stage-form-required-' . preg_replace('/[^a-z0-9_-]/i', '-', $key); ?>
                        <input type="checkbox" class="btn-check" name="required_fields[]" value="<?= e($key) ?>" id="<?= e($cid) ?>" <?= in_array($key, $has, true) ? 'checked' : '' ?> autocomplete="off">
                        <label class="btn btn-outline-secondary btn-sm btn-touch" for="<?= e($cid) ?>"><?= e($label) ?></label>
                    <?php endforeach; ?>
                </div>
                <div class="form-text">A deal that lacks one is not moved in; the board says which. Open stages only.</div></div>
            <?php if ($editing): ?><div class="col-12"><input type="hidden" name="archived" value="no"><div class="form-check form-switch">
                <input class="form-check-input" type="checkbox" role="switch" name="archived" value="yes" id="stage-form-field-archived" <?= $s['archived_at'] !== null ? 'checked' : '' ?>>
                <label class="form-check-label" for="stage-form-field-archived">Archived — off the board; no deal can move in. Open deals must be moved out first.</label></div></div><?php endif; ?>
        </div></div></div>
        <div class="admin-save-bar d-flex gap-2 mb-3">
            <button type="submit" class="btn btn-primary btn-touch flex-grow-1" id="stage-form-save-btn"><?= $editing ? 'Save' : 'Add the stage' ?></button>
            <?= hx_link('/admin/pipelines#pipeline-' . (int) $pipeline['id'], 'Cancel', 'btn btn-light btn-touch', 'id="stage-form-cancel-btn"') ?>
        </div>
    </form>
    <?php if ($editing && $s['kind'] === 'open'): ?>
    <div class="card mb-3" id="stage-delete-card"><div class="card-body">
        <h6 class="mb-1">Delete this stage</h6>
        <?php if ($s['deals_all'] > 0 || $s['history'] > 0): ?>
            <div class="fs-12 text-muted" id="stage-delete-refused">Deals are in this stage or have passed through it (the funnel reads that), so it can only be archived — switch Archived on above.</div>
        <?php else: ?>
            <form method="post" action="/admin/stages/delete.php" hx-post="/admin/stages/delete.php" hx-confirm="Delete the stage <?= e($s['name']) ?>?" hx-target="#page-content" hx-swap="innerHTML"><?= csrf_field() ?>
                <input type="hidden" name="stage" value="<?= $id ?>"><button type="submit" class="btn btn-light-danger btn-touch" id="stage-delete-btn"><i class="feather-trash-2 me-1"></i>Delete the stage</button></form>
        <?php endif; ?>
    </div></div>
    <?php endif; ?>
</div>
