<?php /** Lead sources and lost reasons (screen `admin-sources`; ids sources-list, source-row-{id}, source-add-form, reasons-list, reason-row-{id}, reason-add-form-{kind}). Data: v (vocab()), notice */
$sourceUses = static fn (array $s): string => vocab_uses_words($s, SOURCE_USES);
$reasonUses = static fn (array $r): string => vocab_uses_words($r, REASON_USES);
$delete = static function (string $action, string $field, int $id, string $name, string $uses, string $btn): string {
    if ($uses !== '') {
        return '<button type="button" class="avatar-text avatar-sm border-0" id="' . $btn . '" disabled title="' . e($name . ' is named by ' . $uses . ' — deactivate it instead') . '" aria-label="Cannot delete ' . e($name) . ': named by ' . e($uses) . '"><i class="feather-trash-2 text-muted"></i></button>';
    }
    return '<form method="post" action="' . $action . '" hx-post="' . $action . '" hx-confirm="Delete ' . e($name) . '?" hx-target="#page-content" hx-swap="innerHTML" class="d-inline">' . csrf_field()
        . '<input type="hidden" name="' . $field . '" value="' . $id . '"><button type="submit" class="avatar-text avatar-sm border-0" id="' . $btn . '" title="Delete" aria-label="Delete ' . e($name) . '"><i class="feather-trash-2"></i></button></form>';
}; ?>
<?= view('shared/header.php', ['id' => 'admin-sources', 'title' => 'Sources and reasons', 'crumbs' => [['Today', '/'], ['Admin', '/admin'], ['Sources and reasons', null]]]) ?>
<div class="main-content" id="admin-sources-content">
    <?= view('shared/notice.php', ['notice' => $notice]) ?>
    <div class="row g-3">
        <div class="col-xl-6">
            <div class="card mb-0" id="sources-card"><div class="card-header"><h5 class="card-title mb-0">Lead sources</h5></div>
                <div class="card-body p-0" id="sources-list">
                <?php foreach ($v['sources'] as $s): $id = $s['id']; $uses = $sourceUses($s); ?>
                    <div class="px-3 py-2 border-bottom<?= $s['active'] ? '' : ' text-muted' ?>" id="source-row-<?= $id ?>">
                        <form method="post" action="/admin/sources/save.php" hx-post="/admin/sources/save.php" hx-target="#flash" id="source-form-<?= $id ?>" class="row g-2 align-items-center"><?= csrf_field() ?>
                            <input type="hidden" name="source" value="<?= $id ?>"><input type="hidden" name="active" value="no">
                            <div class="col-12 col-md-5"><label class="visually-hidden" for="source-<?= $id ?>-name">Name</label><input type="text" class="form-control btn-touch" id="source-<?= $id ?>-name" name="name" value="<?= e($s['name']) ?>" maxlength="60" required></div>
                            <div class="col-5 col-md-3"><label class="visually-hidden" for="source-<?= $id ?>-kind">Kind</label><select class="form-select btn-touch" id="source-<?= $id ?>-kind" name="kind">
                                <option value="inbound" <?= $s['kind'] === 'inbound' ? 'selected' : '' ?>>Inbound</option><option value="outbound" <?= $s['kind'] === 'outbound' ? 'selected' : '' ?>>Outbound</option></select></div>
                            <div class="col-3 col-md-2"><label class="visually-hidden" for="source-<?= $id ?>-order">Order</label><input type="number" class="form-control btn-touch" id="source-<?= $id ?>-order" name="sort_order" value="<?= $s['sort_order'] ?>" min="0" aria-label="Order"></div>
                            <div class="col-4 col-md-2"><div class="form-check form-switch"><input class="form-check-input" type="checkbox" role="switch" name="active" value="yes" id="source-<?= $id ?>-active" <?= $s['active'] ? 'checked' : '' ?>><label class="form-check-label fs-12" for="source-<?= $id ?>-active">In use</label></div></div>
                            <div class="col-12"><button type="submit" class="btn btn-light btn-touch" id="source-<?= $id ?>-save-btn">Save</button></div>
                        </form>
                        <div class="d-flex align-items-center gap-2 mt-1"><?= $delete('/admin/sources/delete.php', 'source', $id, $s['name'], $uses, 'source-' . $id . '-delete-btn') ?>
                            <span class="fs-12 text-muted" id="source-<?= $id ?>-uses"><?= $uses === '' ? 'Not named anywhere' : 'Named by ' . e($uses) ?></span></div>
                    </div>
                <?php endforeach; ?>
                    <div class="px-3 py-3 bg-light-subtle">
                        <form method="post" action="/admin/sources/save.php" hx-post="/admin/sources/save.php" hx-target="#flash" id="source-add-form" class="row g-2 align-items-end"><?= csrf_field() ?>
                            <div class="col-12 col-md-6"><label class="form-label fs-12 text-muted" for="source-add-name">Add a source</label><input type="text" class="form-control btn-touch" id="source-add-name" name="name" maxlength="60" placeholder="Trade show" required></div>
                            <div class="col-6 col-md-3"><label class="visually-hidden" for="source-add-kind">Kind</label><select class="form-select btn-touch" id="source-add-kind" name="kind"><option value="inbound">Inbound</option><option value="outbound">Outbound</option></select></div>
                            <div class="col-6 col-md-3"><button type="submit" class="btn btn-primary btn-touch w-100" id="source-add-btn"><i class="feather-plus me-1"></i>Add</button></div>
                        </form>
                    </div>
                </div></div>
        </div>
        <div class="col-xl-6">
            <div class="card mb-0" id="reasons-card"><div class="card-header"><h5 class="card-title mb-0">Lost reasons</h5></div>
                <div class="card-body p-0" id="reasons-list">
                <?php foreach (['lead' => "Why a lead is not pursued", 'deal' => 'Why a deal is lost'] as $kind => $title): ?>
                    <div class="px-3 pt-3 fw-semibold fs-12 text-muted" id="reasons-<?= $kind ?>-title"><?= e($title) ?></div>
                    <?php foreach (array_filter($v['reasons'], static fn (array $r): bool => $r['kind'] === $kind) as $r): $id = $r['id']; $uses = $reasonUses($r); ?>
                    <div class="px-3 py-2 border-bottom<?= $r['active'] ? '' : ' text-muted' ?>" id="reason-row-<?= $id ?>">
                        <form method="post" action="/admin/reasons/save.php" hx-post="/admin/reasons/save.php" hx-target="#flash" id="reason-form-<?= $id ?>" class="row g-2 align-items-center"><?= csrf_field() ?>
                            <input type="hidden" name="lost_reason" value="<?= $id ?>"><input type="hidden" name="kind" value="<?= e($kind) ?>"><input type="hidden" name="active" value="no">
                            <div class="col-12 col-md-6"><label class="visually-hidden" for="reason-<?= $id ?>-label">Reason</label><input type="text" class="form-control btn-touch" id="reason-<?= $id ?>-label" name="label" value="<?= e($r['label']) ?>" maxlength="60" required></div>
                            <div class="col-4 col-md-3"><label class="visually-hidden" for="reason-<?= $id ?>-order">Order</label><input type="number" class="form-control btn-touch" id="reason-<?= $id ?>-order" name="sort_order" value="<?= $r['sort_order'] ?>" min="0" aria-label="Order"></div>
                            <div class="col-8 col-md-3"><div class="form-check form-switch"><input class="form-check-input" type="checkbox" role="switch" name="active" value="yes" id="reason-<?= $id ?>-active" <?= $r['active'] ? 'checked' : '' ?>><label class="form-check-label fs-12" for="reason-<?= $id ?>-active">In use</label></div></div>
                            <div class="col-12"><button type="submit" class="btn btn-light btn-touch" id="reason-<?= $id ?>-save-btn">Save</button></div>
                        </form>
                        <div class="d-flex align-items-center gap-2 mt-1"><?= $delete('/admin/reasons/delete.php', 'lost_reason', $id, $r['label'], $uses, 'reason-' . $id . '-delete-btn') ?>
                            <span class="fs-12 text-muted" id="reason-<?= $id ?>-uses"><?= $uses === '' ? 'Not named anywhere' : 'Named by ' . e($uses) ?></span></div>
                    </div>
                    <?php endforeach; ?>
                    <div class="px-3 py-3 bg-light-subtle">
                        <form method="post" action="/admin/reasons/save.php" hx-post="/admin/reasons/save.php" hx-target="#flash" id="reason-add-form-<?= $kind ?>" class="row g-2 align-items-end"><?= csrf_field() ?>
                            <input type="hidden" name="kind" value="<?= e($kind) ?>">
                            <div class="col-8 col-md-9"><label class="form-label fs-12 text-muted" for="reason-add-<?= $kind ?>-label">Add a <?= $kind ?> reason</label><input type="text" class="form-control btn-touch" id="reason-add-<?= $kind ?>-label" name="label" maxlength="60" required></div>
                            <div class="col-4 col-md-3"><button type="submit" class="btn btn-primary btn-touch w-100" id="reason-add-<?= $kind ?>-btn"><i class="feather-plus me-1"></i>Add</button></div>
                        </form>
                    </div>
                <?php endforeach; ?>
                </div></div>
        </div>
    </div>
</div>
