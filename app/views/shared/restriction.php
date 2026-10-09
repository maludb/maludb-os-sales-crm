<?php /** A manager's Restriction section (record_restrict): who may see this record besides the managers. Its own form. Data: kind, id, row (restricted_to_* and names), owner_name, departments, back */
$restricted = $row['restricted_to_member_id'] !== null || $row['restricted_to_department_id'] !== null; ?>
<div class="card mb-3" id="restriction-section"><div class="card-header d-flex align-items-center gap-2"><i class="feather-lock"></i><h5 class="card-title mb-0">Who may see it</h5></div><div class="card-body">
    <div class="fs-12 mb-3" id="restriction-state"><?= $restricted
        ? 'Restricted to ' . e(implode(' and ', array_filter([$row['restricted_member_name'] ?? null, isset($row['restricted_department_name']) ? 'the ' . $row['restricted_department_name'] . ' team' : null]))) . ' — and the managers.'
        : 'Everyone here sees it (the business\'s rule). Restrict it to its owner and a team when it is sensitive.' ?></div>
    <form method="post" action="/records/restrict.php" hx-post="/records/restrict.php" hx-target="#flash" id="restriction-form" class="row g-2 align-items-end"><?= csrf_field() ?>
        <input type="hidden" name="kind" value="<?= e($kind) ?>"><input type="hidden" name="record" value="<?= (int) $id ?>"><input type="hidden" name="return_to" value="<?= e($back) ?>">
        <div class="col-md-6"><label class="form-label" id="restriction-form-member-label" for="restriction-form-field-member">To a person</label>
            <?= picker_field(['id' => 'restriction-form-field-member', 'name' => 'member', 'source' => 'owner', 'value' => $row['restricted_to_member_id'] ?? $row['owner_member_id'], 'labelledby' => 'restriction-form-member-label', 'title' => 'Restrict to']) ?></div>
        <div class="col-md-6"><label class="form-label" for="restriction-form-field-department">And a team</label>
            <select name="department" id="restriction-form-field-department" class="form-select btn-touch"><option value="">— no team —</option>
                <?php foreach ($departments as $d): ?><option value="<?= (int) $d['department_id'] ?>" <?= (int) ($row['restricted_to_department_id'] ?? 0) === (int) $d['department_id'] ? 'selected' : '' ?>><?= e($d['name']) ?></option><?php endforeach; ?></select></div>
        <div class="col-12 d-flex gap-2">
            <button type="submit" class="btn btn-warning btn-touch" id="restriction-form-save-btn"><i class="feather-lock me-1"></i><?= $restricted ? 'Change the restriction' : 'Restrict it' ?></button>
            <?php if ($restricted): ?><button type="submit" name="lift" value="yes" class="btn btn-light btn-touch" id="restriction-form-lift-btn" formnovalidate><i class="feather-unlock me-1"></i>Open it to everyone</button><?php endif; ?>
        </div>
    </form>
</div></div>
