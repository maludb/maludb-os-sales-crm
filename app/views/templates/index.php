<?php /** The email templates (template-list): cards — name, kind, the subject, shared or mine, how often used; Use · Edit · Delete by right; New; filters. Data: rows, total, f, me, may, notice */ ?>
<?= view('shared/header.php', ['id' => 'template-list', 'title' => 'Email templates', 'crumbs' => [['Today', '/'], ['Email', '/email/unmatched'], ['Templates', null]], 'back' => ['/email/unmatched', 'Email'],
    'action' => hx_link('/email/templates/new', '<i class="feather-plus me-1"></i>New template', 'btn btn-primary btn-touch', 'id="template-new-btn"')]) ?>
<div class="main-content" id="template-list-content">
    <?= view('shared/notice.php', ['notice' => $notice]) ?>
    <form method="get" action="/email/templates" class="d-flex flex-wrap gap-2 align-items-end mb-3" id="template-filters" hx-get="/email/templates" hx-target="#page-content" hx-push-url="true">
        <div class="flex-grow-1" style="min-width: 10rem"><label class="form-label fs-11 mb-0" for="template-filter-q">Search</label><input type="search" class="form-control btn-touch" name="q" id="template-filter-q" value="<?= e($f['q']) ?>" placeholder="Name or subject"></div>
        <div><label class="form-label fs-11 mb-0" for="template-filter-kind">Kind</label><select class="form-select btn-touch" name="kind" id="template-filter-kind"><option value="">Any</option>
            <?php foreach (TEMPLATE_KINDS as $k => $label): ?><option value="<?= e($k) ?>"<?= $f['kind'] === $k ? ' selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?></select></div>
        <label class="form-check mb-2"><input type="checkbox" class="form-check-input" name="mine" value="1" id="template-filter-mine"<?= $f['mine'] ? ' checked' : '' ?>> Mine only</label>
        <button type="submit" class="btn btn-light btn-touch" id="template-filter-btn">Filter</button>
    </form>
    <?php if ($rows === []): ?><div class="card"><div class="card-body text-center text-muted py-4" id="template-list-empty">No templates match. Make one with New template — a message with merge fields like {{contact.first_name}} filled for each person.</div></div><?php endif; ?>
    <div class="row g-3" id="template-cards">
    <?php foreach ($rows as $t): $tid = (int) $t['template_id']; $own = (int) $t['owner_member_id'] === $me; $can = $own || $may['manage']; ?>
        <div class="col-md-6 col-xl-4"><div class="card h-100 mb-0" id="template-card-<?= $tid ?>"><div class="card-body d-flex flex-column">
            <div class="d-flex align-items-start gap-2"><span class="avatar-text avatar-md rounded"><i class="feather-file-text"></i></span>
                <div class="min-w-0 flex-grow-1"><div class="fw-semibold text-truncate" id="template-card-<?= $tid ?>-name"><?= e($t['name']) ?></div>
                    <div class="fs-11 text-muted"><?= $t['is_shared'] ? 'Shared' : 'Mine' ?><?= !$own && $t['owner_name'] ? ' · ' . e($t['owner_name']) : '' ?> · used <?= (int) $t['usage_count'] ?> time<?= (int) $t['usage_count'] === 1 ? '' : 's' ?></div></div>
                <span class="badge bg-soft-<?= e(TEMPLATE_KIND_BADGE[$t['kind']] ?? 'secondary') ?> text-<?= e(TEMPLATE_KIND_BADGE[$t['kind']] ?? 'secondary') ?>"><?= e(TEMPLATE_KINDS[$t['kind']] ?? $t['kind']) ?></span></div>
            <div class="fs-12 mt-2 text-truncate" id="template-card-<?= $tid ?>-subject"><span class="text-muted">Subject</span> <?= e($t['subject']) ?></div>
            <div class="fs-12 text-muted mt-1" style="display: -webkit-box; -webkit-line-clamp: 3; -webkit-box-orient: vertical; overflow: hidden"><?= e(mb_substr((string) $t['body'], 0, 220)) ?></div>
            <div class="d-flex gap-1 mt-auto pt-2">
                <?php if ($may['use']): ?><?= hx_link('/email/compose?template=' . $tid, '<i class="feather-edit me-1"></i>Use', 'btn btn-sm btn-primary btn-touch', 'id="template-card-' . $tid . '-use"') ?><?php endif; ?>
                <?php if ($can): ?><?= hx_link('/email/templates/' . $tid . '/edit', 'Edit', 'btn btn-sm btn-light btn-touch', 'id="template-card-' . $tid . '-edit"') ?>
                <form method="post" action="/email/templates/delete.php" hx-post="/email/templates/delete.php" hx-target="#flash" hx-confirm="Delete the template <?= e($t['name']) ?>?" class="ms-auto"><?= csrf_field() ?><input type="hidden" name="template" value="<?= $tid ?>">
                    <button type="submit" class="btn btn-sm btn-light text-danger btn-touch" id="template-card-<?= $tid ?>-delete" aria-label="Delete"><i class="feather-trash-2"></i></button></form><?php endif; ?>
            </div>
        </div></div></div>
    <?php endforeach; ?>
    </div>
</div>
