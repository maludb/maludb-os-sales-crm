<?php /** Custom fields (screen `admin-fields`; ids fields-list, field-{id}, field-form). Data: kind, list (fields($kind)), edit (the field being changed or null), counts (live fields per kind), pipelines ([{pipeline_id, name}] for deals), notice */
$names = [];
foreach ($pipelines as $p) { $names[(int) $p['pipeline_id']] = $p['name']; }
$editing = $edit !== null;
$v = $edit ?? ['id' => null, 'key' => '', 'label' => '', 'field_type' => 'text', 'options_list' => [], 'pipeline_ids' => [], 'required_to_win' => false, 'sort_order' => 100, 'archived_at' => null, 'in_use' => 0];
$typeLocked = $editing && $v['in_use'] > 0; ?>
<?= view('shared/header.php', ['id' => 'admin-fields', 'title' => 'Custom fields', 'crumbs' => [['Today', '/'], ['Admin', '/admin'], ['Custom fields', null]]]) ?>
<div class="main-content" id="admin-fields-content">
    <?= view('shared/notice.php', ['notice' => $notice]) ?>
    <div class="d-flex flex-wrap gap-1 mb-3" id="fields-kinds">
        <?php foreach (FIELD_KINDS as $k => $label): ?><?= hx_link('/admin/fields?kind=' . $k, e($label) . ' <span class="opacity-75">' . (int) $counts[$k] . '</span>', 'btn btn-touch btn-sm ' . ($k === $kind ? 'btn-primary' : 'btn-light'), 'id="fields-kind-' . $k . '"') ?><?php endforeach; ?>
    </div>
    <div class="row g-3">
        <div class="col-xl-7">
            <div class="card mb-0" id="fields-card"><div class="card-header"><h5 class="card-title mb-0"><?= e(FIELD_KINDS[$kind]) ?> — custom fields</h5></div>
                <div class="card-body p-0" id="fields-list">
                    <?php if ($list === []): ?><div class="text-center text-muted py-4 fs-12" id="fields-empty">No custom fields for <?= e(strtolower(FIELD_KINDS[$kind])) ?> yet. Add one beside.</div><?php endif; ?>
                    <?php foreach ($list as $f) { echo view('admin/partials/field-row.php', ['f' => $f, 'kind' => $kind, 'pipelines' => $names]); } ?>
                </div></div>
        </div>
        <div class="col-xl-5">
            <div class="card mb-0" id="field-form-card"><div class="card-header"><h5 class="card-title mb-0"><?= $editing ? 'Change ' . e($v['label']) : 'Add a field' ?></h5></div><div class="card-body">
                <form method="post" action="/admin/fields/save.php" hx-post="/admin/fields/save.php" hx-target="#flash" id="field-form"><?= csrf_field() ?>
                    <input type="hidden" name="applies_to" value="<?= e($kind) ?>"><?php if ($editing): ?><input type="hidden" name="field" value="<?= (int) $v['id'] ?>"><?php endif; ?>
                    <input type="hidden" name="pipelines_present" value="1">
                    <div class="mb-3"><label class="form-label" for="field-form-field-label">Label</label>
                        <input type="text" class="form-control btn-touch" id="field-form-field-label" name="label" value="<?= e($v['label']) ?>" maxlength="60" required placeholder="Contract term"></div>
                    <div class="mb-3"><label class="form-label" for="field-form-field-key">Key</label>
                        <input type="text" class="form-control btn-touch" id="field-form-field-key" name="key" value="<?= e($v['key']) ?>" maxlength="40" pattern="[a-z][a-z0-9_]*" placeholder="made from the label" <?= $editing ? 'readonly' : '' ?>>
                        <div class="form-text"><?= $editing ? 'A key never changes — the values are stored under it.' : 'Lower-case letters, digits and underscores. It cannot be changed later.' ?></div></div>
                    <div class="mb-3"><label class="form-label" for="field-form-field-type">Type</label>
                        <select class="form-select btn-touch" id="field-form-field-type" name="field_type" <?= $typeLocked ? 'disabled' : '' ?>><?php foreach (FIELD_TYPES as $t => $l): ?><option value="<?= e($t) ?>" <?= $v['field_type'] === $t ? 'selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select>
                        <?php if ($typeLocked): ?><input type="hidden" name="field_type" value="<?= e($v['field_type']) ?>"><div class="form-text">Values are stored on <?= (int) $v['in_use'] ?> record<?= $v['in_use'] === 1 ? '' : 's' ?>, so the type stays.</div><?php endif; ?></div>
                    <div class="mb-3"><label class="form-label" for="field-form-field-options">Choices</label>
                        <textarea class="form-control" id="field-form-field-options" name="options" rows="4" placeholder="One choice a line"><?= e(implode("\n", $v['options_list'])) ?></textarea>
                        <div class="form-text">For "One choice" and "Several choices" only — one a line, no commas.</div></div>
                    <?php if ($kind === 'deal'): ?>
                    <div class="mb-3"><div class="form-label" id="field-form-pipelines-label">Shown for these pipelines</div>
                        <div class="d-flex flex-wrap gap-2" role="group" aria-labelledby="field-form-pipelines-label">
                        <?php foreach ($pipelines as $p): $pid = (int) $p['pipeline_id']; ?>
                            <input type="checkbox" class="btn-check" name="pipelines[]" value="<?= $pid ?>" id="field-form-pipeline-<?= $pid ?>" <?= in_array($pid, $v['pipeline_ids'], true) ? 'checked' : '' ?> autocomplete="off">
                            <label class="btn btn-outline-secondary btn-sm btn-touch" for="field-form-pipeline-<?= $pid ?>"><?= e($p['name']) ?></label>
                        <?php endforeach; ?></div><div class="form-text">None chosen means every pipeline.</div></div>
                    <div class="mb-3"><input type="hidden" name="required_to_win" value="no"><div class="form-check form-switch"><input class="form-check-input" type="checkbox" role="switch" name="required_to_win" value="yes" id="field-form-field-win" <?= $v['required_to_win'] ? 'checked' : '' ?>>
                        <label class="form-check-label" for="field-form-field-win">A deal needs it to be won</label></div></div>
                    <?php endif; ?>
                    <div class="mb-3"><label class="form-label" for="field-form-field-order">Order</label>
                        <input type="number" class="form-control btn-touch" id="field-form-field-order" name="sort_order" value="<?= (int) $v['sort_order'] ?>" min="0"></div>
                    <?php if ($editing): ?><div class="mb-3"><input type="hidden" name="archived" value="no"><div class="form-check form-switch"><input class="form-check-input" type="checkbox" role="switch" name="archived" value="yes" id="field-form-field-archived" <?= $v['archived_at'] !== null ? 'checked' : '' ?>>
                        <label class="form-check-label" for="field-form-field-archived">Archived — off the forms; the values already stored stay</label></div></div><?php endif; ?>
                    <div class="d-flex gap-2"><button type="submit" class="btn btn-primary btn-touch flex-grow-1" id="field-form-save-btn"><?= $editing ? 'Save' : 'Add the field' ?></button>
                        <?php if ($editing): ?><?= hx_link('/admin/fields?kind=' . rawurlencode($kind), 'Cancel', 'btn btn-light btn-touch', 'id="field-form-cancel-btn"') ?><?php endif; ?></div>
                </form>
            </div></div>
        </div>
    </div>
</div>
