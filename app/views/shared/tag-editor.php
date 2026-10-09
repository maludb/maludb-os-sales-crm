<?php /** A record's tags as chips with remove, and an add field (a new label makes a tag). Data: kind, id, tags, may (edit), back. assets/js/tag-editor.js suggests labels. */ ?>
<div class="d-flex flex-wrap align-items-center gap-1" id="tag-editor" data-kind="<?= e($kind) ?>" data-record="<?= (int) $id ?>">
    <?php foreach ($tags as $t): ?>
        <span class="badge bg-soft-primary text-primary d-inline-flex align-items-center gap-1" id="tag-chip-<?= (int) $t['tag_id'] ?>"><?= e($t['label']) ?>
        <?php if ($may['edit']): ?>
            <form method="post" action="/tags/remove.php" hx-post="/tags/remove.php" hx-target="#flash" class="d-inline"><?= csrf_field() ?>
                <input type="hidden" name="kind" value="<?= e($kind) ?>"><input type="hidden" name="record" value="<?= (int) $id ?>"><input type="hidden" name="tag" value="<?= e($t['label']) ?>"><input type="hidden" name="return_to" value="<?= e($back) ?>">
                <button type="submit" class="btn btn-link p-0 text-primary lh-1" aria-label="Remove the tag <?= e($t['label']) ?>" id="tag-chip-<?= (int) $t['tag_id'] ?>-remove"><i class="feather-x"></i></button></form>
        <?php endif; ?></span>
    <?php endforeach; ?>
    <?php if ($tags === [] && !$may['edit']): ?><span class="fs-12 text-muted">No tags.</span><?php endif; ?>
    <?php if ($may['edit']): ?>
        <form method="post" action="/tags/apply.php" hx-post="/tags/apply.php" hx-target="#flash" class="d-inline-flex gap-1" id="tag-editor-form"><?= csrf_field() ?>
            <input type="hidden" name="kind" value="<?= e($kind) ?>"><input type="hidden" name="record" value="<?= (int) $id ?>"><input type="hidden" name="return_to" value="<?= e($back) ?>">
            <label class="visually-hidden" for="tag-editor-field-tag">Add a tag</label>
            <input type="text" name="tag" id="tag-editor-field-tag" class="form-control form-control-sm btn-touch" style="width: 10rem" maxlength="60" placeholder="Add a tag" list="tag-editor-suggestions" autocomplete="off" required>
            <datalist id="tag-editor-suggestions"></datalist>
            <button type="submit" class="btn btn-light btn-touch btn-sm" id="tag-editor-add-btn" aria-label="Add the tag"><i class="feather-plus"></i></button>
        </form>
    <?php endif; ?>
</div>
