<?php /** One tag in the vocabulary (admin-tags; id tag-{id}): its name and use, and Rename · Merge into · Delete. Data: t (a row of tags_with_counts) */
$id = (int) $t['tag_id'];
$n = (int) $t['usage_count']; ?>
<div class="col-md-6 col-xl-4" id="tag-<?= $id ?>"><div class="card mb-0 h-100"><div class="card-body">
    <div class="d-flex align-items-start gap-2">
        <div class="min-w-0 flex-grow-1"><span class="badge bg-soft-primary text-primary fs-12" id="tag-<?= $id ?>-label"><?= e($t['label']) ?></span>
            <div class="fs-12 text-muted mt-1"><code><?= e($t['slug']) ?></code> · <span id="tag-<?= $id ?>-count"><?= $n ?> record<?= $n === 1 ? '' : 's' ?></span></div></div>
        <form method="post" action="/admin/tags/delete.php" hx-post="/admin/tags/delete.php" hx-confirm="Delete the tag <?= e($t['label']) ?>? It is taken off <?= $n ?> record<?= $n === 1 ? '' : 's' ?>." hx-target="#page-content" hx-swap="innerHTML"><?= csrf_field() ?>
            <input type="hidden" name="tag" value="<?= $id ?>"><button type="submit" class="avatar-text avatar-sm border-0" id="tag-<?= $id ?>-delete-btn" title="Delete" aria-label="Delete <?= e($t['label']) ?>"><i class="feather-trash-2"></i></button></form>
    </div>
    <details class="mt-2" id="tag-<?= $id ?>-rename"><summary class="fs-12 fw-semibold text-primary">Rename</summary>
        <form method="post" action="/admin/tags/save.php" hx-post="/admin/tags/save.php" hx-target="#flash" class="d-flex gap-2 mt-2"><?= csrf_field() ?><input type="hidden" name="tag" value="<?= $id ?>">
            <label class="visually-hidden" for="tag-<?= $id ?>-name">New name</label><input type="text" class="form-control btn-touch" id="tag-<?= $id ?>-name" name="label" value="<?= e($t['label']) ?>" maxlength="60" required>
            <button type="submit" class="btn btn-light btn-touch" id="tag-<?= $id ?>-rename-btn">Rename</button></form></details>
    <details class="mt-2" id="tag-<?= $id ?>-merge"><summary class="fs-12 fw-semibold text-primary">Merge into…</summary>
        <form method="post" action="/admin/tags/merge.php" hx-post="/admin/tags/merge.php" hx-confirm="Merge <?= e($t['label']) ?> into the tag you chose? <?= e($t['label']) ?> goes; its records keep the other." hx-target="#page-content" hx-swap="innerHTML" class="mt-2"><?= csrf_field() ?><input type="hidden" name="tag" value="<?= $id ?>">
            <div class="mb-2"><?= picker_field(['id' => 'tag-' . $id . '-into', 'name' => 'into', 'source' => 'tag', 'value' => null, 'title' => 'The tag that stays', 'placeholder' => 'The tag that stays', 'required' => true]) ?></div>
            <button type="submit" class="btn btn-light btn-touch w-100" id="tag-<?= $id ?>-merge-btn"><i class="feather-git-merge me-1"></i>Merge</button></form></details>
</div></div></div>
