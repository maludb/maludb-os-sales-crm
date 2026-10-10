<?php /** Save (or change) a view: the name, shared or mine (shared for managers), and — carried as hidden fields — what the screen shows now: the filters, the layout,
 * the sort, the columns. Data: v (null = new), kind, filters, layout, sort, columns, may_share, return_to, prefix */
$editing = $v !== null;
$p = $prefix; ?>
<form method="post" action="/views/save.php" hx-post="/views/save.php" hx-target="#flash" class="row g-2 align-items-end mt-1" id="<?= e($p) ?>-form"><?= csrf_field() ?>
    <?php if ($editing): ?><input type="hidden" name="saved_view" value="<?= (int) $v['saved_view_id'] ?>"><?php endif; ?>
    <input type="hidden" name="record_kind" value="<?= e($kind) ?>"><input type="hidden" name="layout" value="<?= e($layout) ?>">
    <input type="hidden" name="filters" value="<?= e(json_encode($filters === [] ? (object) [] : $filters, JSON_UNESCAPED_SLASHES)) ?>">
    <?php if ($sort): ?><input type="hidden" name="sort_by" value="<?= e((string) $sort) ?>"><?php endif; ?>
    <?php foreach ($columns as $c): ?><input type="hidden" name="columns[]" value="<?= e($c) ?>"><?php endforeach; ?>
    <input type="hidden" name="return_to" value="<?= e($return_to) ?>">
    <div class="col-12 col-md-5"><label class="form-label fs-11 mb-0" for="<?= e($p) ?>-field-name">Name</label>
        <input type="text" name="name" maxlength="80" required class="form-control btn-touch" id="<?= e($p) ?>-field-name" value="<?= e((string) ($v['name'] ?? '')) ?>" placeholder="Closing this month, mine"></div>
    <div class="col-6 col-md-3"><label class="form-label fs-11 mb-0" for="<?= e($p) ?>-field-scope">Who sees it</label>
        <select name="scope" class="form-select btn-touch" id="<?= e($p) ?>-field-scope"><option value="personal">Only me</option>
            <?php if ($may_share): ?><option value="global"<?= ($v['scope'] ?? '') === 'global' ? ' selected' : '' ?>>Everyone here</option><?php endif; ?></select></div>
    <div class="col-6 col-md-4"><button type="submit" class="btn btn-light btn-touch w-100" id="<?= e($p) ?>-save-btn"><i class="feather-bookmark me-1"></i><?= $editing ? 'Save' : 'Save this view' ?></button></div>
    <?php if ($filters !== []): ?><div class="col-12 d-flex flex-wrap gap-1"><?php foreach (saved_view_chips(db(), $filters) as $chip): ?><span class="badge bg-soft-info text-info"><?= e($chip) ?></span><?php endforeach; ?></div><?php endif; ?>
</form>
