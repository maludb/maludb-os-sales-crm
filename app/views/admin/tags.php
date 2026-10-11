<?php /** Tags (screen `admin-tags`; ids tags-chips, tag-{id}, tags-search). Data: tags (tags_with_counts), total, q, notice */ ?>
<?= view('shared/header.php', ['id' => 'admin-tags', 'title' => 'Tags', 'crumbs' => [['Today', '/'], ['Admin', '/admin'], ['Tags', null]]]) ?>
<div class="main-content" id="admin-tags-content">
    <?= view('shared/notice.php', ['notice' => $notice]) ?>
    <form method="get" action="/admin/tags" hx-get="/admin/tags" hx-target="#page-content" hx-swap="innerHTML" hx-push-url="true" class="d-flex gap-2 mb-3" id="tags-search" role="search">
        <label class="visually-hidden" for="tags-search-q">Find a tag</label><input type="search" class="form-control btn-touch" id="tags-search-q" name="q" value="<?= e($q) ?>" placeholder="Find a tag">
        <button type="submit" class="btn btn-light btn-touch" id="tags-search-btn" aria-label="Find"><i class="feather-search"></i></button>
    </form>
    <div class="fs-12 text-muted mb-2" id="tags-count"><?= $q === '' ? $total . ' tag' . ($total === 1 ? '' : 's') : count($tags) . ' of ' . $total . ' match' ?></div>
    <div class="row g-3" id="tags-chips">
        <?php if ($tags === []): ?><div class="col-12"><div class="card"><div class="card-body text-center text-muted" id="tags-empty">No tags<?= $q === '' ? ' yet — they are made as people tag records.' : ' match.' ?></div></div></div><?php endif; ?>
        <?php foreach ($tags as $t) { echo view('admin/partials/tag-chip.php', ['t' => $t]); } ?>
    </div>
</div>
