<?php /** An email template's form (#template-form; template-add / template-edit): name, kind, subject, body (Markdown) with the merge fields listed (#template-merge-fields), shared (templates.manage) or mine. Data: t, values, may */
$editing = $t !== null;
$id = $editing ? (int) $t['template_id'] : null;
$v = $values;
$f = static fn (string $name): string => 'template-form-field-' . $name; ?>
<?= view('shared/header.php', ['id' => 'template-form', 'title' => $editing ? 'Edit ' . $t['name'] : 'New template', 'crumbs' => [['Today', '/'], ['Email', '/email/unmatched'], ['Templates', '/email/templates'], [$editing ? 'Edit' : 'New', null]],
    'back' => ['/email/templates', 'the templates']]) ?>
<div class="main-content" id="template-form-content">
    <form method="post" action="/email/templates/save.php" hx-post="/email/templates/save.php" hx-target="#flash" id="template-form"><?= csrf_field() ?>
        <?php if ($editing): ?><input type="hidden" name="template" value="<?= $id ?>"><?php endif; ?>
        <div class="card mb-3"><div class="card-body"><div class="row g-3">
            <div class="col-md-8"><label class="form-label" for="<?= $f('name') ?>">Name</label><input type="text" class="form-control btn-touch" id="<?= $f('name') ?>" name="name" maxlength="120" required value="<?= e((string) $v['name']) ?>" placeholder="Introduction" autofocus></div>
            <div class="col-md-4"><label class="form-label" for="<?= $f('kind') ?>">Kind</label><select class="form-select btn-touch" id="<?= $f('kind') ?>" name="kind">
                <?php foreach (TEMPLATE_KINDS as $k => $label): ?><option value="<?= e($k) ?>"<?= $v['kind'] === $k ? ' selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?></select></div>
            <div class="col-12"><label class="form-label" for="<?= $f('subject') ?>">Subject</label><input type="text" class="form-control btn-touch" id="<?= $f('subject') ?>" name="subject" maxlength="300" required value="<?= e((string) $v['subject']) ?>" placeholder="Hello from {{sender.company}}"></div>
            <div class="col-12"><label class="form-label" for="<?= $f('body') ?>">Message <span class="fs-11 text-muted">(Markdown: **bold**, *italic*, - lists, [links](https://…))</span></label>
                <textarea class="form-control" id="<?= $f('body') ?>" name="body" rows="12" maxlength="20000" required><?= e((string) $v['body']) ?></textarea></div>
            <div class="col-12" id="template-merge-fields"><div class="form-label">Merge fields <span class="fs-11 text-muted">— type one where it belongs; it is filled for each person when the email is made</span></div>
                <div class="d-flex flex-wrap gap-1"><?php foreach (MERGE_FIELDS as $field => $what): ?><span class="badge bg-soft-info text-info" id="merge-field-<?= e(str_replace('.', '-', $field)) ?>" title="<?= e($what) ?>">{{<?= e($field) ?>}}</span><?php endforeach; ?></div>
                <ul class="fs-11 text-muted mt-2 mb-0 ps-3"><?php foreach (MERGE_FIELDS as $field => $what): ?><li><code>{{<?= e($field) ?>}}</code> — <?= e($what) ?></li><?php endforeach; ?></ul>
                <div class="fs-11 text-muted mt-1">A field that is none of these is sent as typed; the compose screen marks it.</div></div>
            <div class="col-12">
                <?php if ($may['share']): ?><input type="hidden" name="is_shared" value="no"><label class="form-check"><input type="checkbox" class="form-check-input" name="is_shared" value="yes" id="<?= $f('is_shared') ?>"<?= $v['is_shared'] ? ' checked' : '' ?>>
                    Shared with everyone here <span class="fs-11 text-muted">(otherwise only you use it)</span></label>
                <?php else: ?><div class="fs-12 text-muted" id="<?= $f('is_shared') ?>-note"><?= $v['is_shared'] ? 'A shared template.' : 'Only you use this template. Marketing and the Sales Manager keep the shared ones.' ?></div><?php endif; ?>
            </div>
        </div></div></div>
        <div class="d-flex gap-2 mb-3"><button type="submit" class="btn btn-primary btn-touch flex-grow-1" id="template-form-save-btn"><?= $editing ? 'Save' : 'Save the template' ?></button><?= hx_link('/email/templates', 'Cancel', 'btn btn-light btn-touch', 'id="template-form-cancel-btn"') ?></div>
    </form>
</div>
