<?php /** A list's form (list-add / list-edit): name, static or smart, what it holds (chosen once), sharing, and for a smart list the filter builder. Data: l (null on add), values, may */
$editing = $l !== null;
$id = $editing ? (int) $l['list_id'] : null;
$v = $values;
$smart = $v['kind'] === 'smart'; ?>
<?= view('shared/header.php', ['id' => 'list-form', 'title' => $editing ? 'Edit ' . $l['name'] : 'New list', 'crumbs' => [['Today', '/'], ['Lists', '/lists'], [$editing ? 'Edit' : 'New', null]],
    'back' => [$editing ? '/lists/' . $id : '/lists', $editing ? $l['name'] : 'Lists']]) ?>
<div class="main-content" id="list-form-content">
    <?php if (!$editing): ?>
    <div class="mb-2 fs-12 text-muted">What does it hold?</div>
    <div class="d-flex flex-wrap gap-1 mb-3" id="list-form-record-kinds">
        <?php foreach (LIST_RECORD_KINDS as $k => $label): ?><?= hx_link('/lists/new?' . http_build_query(['record_kind' => $k, 'kind' => $v['kind'], 'name' => $v['name']]), e($label), 'btn btn-touch btn-sm ' . ($v['record_kind'] === $k ? 'btn-primary' : 'btn-light'), 'id="list-form-record-kind-' . $k . '"') ?><?php endforeach; ?>
        <span class="ms-2"></span>
        <?php foreach (LIST_KINDS as $k => $label): ?><?= hx_link('/lists/new?' . http_build_query(['record_kind' => $v['record_kind'], 'kind' => $k, 'name' => $v['name']]), e($label), 'btn btn-touch btn-sm ' . ($v['kind'] === $k ? 'btn-primary' : 'btn-light'), 'id="list-form-kind-' . $k . '"') ?><?php endforeach; ?>
    </div>
    <?php endif; ?>
    <form method="post" action="/lists/save.php" hx-post="/lists/save.php" hx-target="#flash" id="list-form">
        <?= csrf_field() ?>
        <input type="hidden" name="kind" value="<?= e($v['kind']) ?>"><input type="hidden" name="record_kind" value="<?= e($v['record_kind']) ?>">
        <?php if ($editing): ?><input type="hidden" name="list" value="<?= $id ?>"><input type="hidden" name="return_to" value="/lists/<?= $id ?>"><?php endif; ?>
        <div class="card mb-3"><div class="card-body"><div class="row g-3">
            <div class="col-12"><label class="form-label" for="list-form-field-name">Name</label>
                <input type="text" class="form-control btn-touch" id="list-form-field-name" name="name" value="<?= e((string) $v['name']) ?>" maxlength="120" required autofocus></div>
            <div class="col-12 fs-12 text-muted" id="list-form-what"><?= $smart ? 'A smart list of ' : 'A static list of ' ?><?= e(strtolower(LIST_RECORD_KINDS[$v['record_kind']])) ?> — <?= $smart ? 'its members are whatever the filters match when it is read.' : 'you choose who is in it, from a screen or a record.' ?></div>
            <div class="col-12"><label class="form-label" for="list-form-field-description">About</label><textarea class="form-control" id="list-form-field-description" name="description" rows="2" maxlength="1000"><?= e((string) $v['description']) ?></textarea></div>
            <?php if ($may['share']): ?><div class="col-12"><div class="form-check form-switch"><input class="form-check-input" type="checkbox" role="switch" id="list-form-field-is_shared" name="is_shared" value="yes"<?= $v['is_shared'] ? ' checked' : '' ?>>
                <label class="form-check-label" for="list-form-field-is_shared">Shared with everyone</label></div><div class="form-text">Everyone sees a shared list; only you and those who keep shared lists change it.</div></div><?php endif; ?>
        </div></div></div>
        <?php if ($smart): ?><?= view('lists/partials/filter-builder.php', ['kind' => $v['record_kind'], 'filters' => $v['filters']]) ?><?php endif; ?>
        <div class="d-flex gap-2 mb-3">
            <button type="submit" class="btn btn-primary btn-touch flex-grow-1" id="list-form-save-btn"><?= $editing ? 'Save' : 'Make the list' ?></button>
            <?= hx_link($editing ? '/lists/' . $id : '/lists', 'Cancel', 'btn btn-light btn-touch', 'id="list-form-cancel-btn"') ?>
        </div>
    </form>
    <?php if ($editing): ?><form method="post" action="/lists/delete.php" hx-post="/lists/delete.php" hx-target="#flash" hx-confirm="Delete <?= e($l['name']) ?>? The records in it stay."><?= csrf_field() ?><input type="hidden" name="list" value="<?= $id ?>">
        <button type="submit" class="btn btn-light text-danger btn-touch" id="list-form-delete-btn"><i class="feather-trash-2 me-1"></i>Delete the list</button></form><?php endif; ?>
</div>
