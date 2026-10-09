<?php /** The custom fields section of a form. Data: defs (custom_field_defs), values, prefix (the form's id), errors. Each field sends custom[key]; custom_present[key] marks it so an emptied one clears. */ ?>
<div class="card mb-3" id="<?= e($prefix) ?>-custom"><div class="card-header"><h5 class="card-title mb-0">More fields</h5></div><div class="card-body"><div class="row g-3">
<?php foreach ($defs as $d): $k = (string) $d['key']; $fid = $prefix . '-field-custom-' . $k; $v = $values[$k] ?? null; $bad = isset($errors['custom_values']) || isset($errors['custom.' . $k]); ?>
    <div class="col-md-6"><input type="hidden" name="custom_present[<?= e($k) ?>]" value="1">
        <?php if ($d['field_type'] === 'checkbox'): ?>
            <label class="d-flex align-items-center gap-2 border rounded px-3 btn-touch" for="<?= e($fid) ?>"><input type="hidden" name="custom[<?= e($k) ?>]" value="no"><input type="checkbox" class="form-check-input mt-0" id="<?= e($fid) ?>" name="custom[<?= e($k) ?>]" value="yes" <?= $v ? 'checked' : '' ?>><?= e($d['label']) ?></label>
        <?php else: ?>
            <label class="form-label" for="<?= e($fid) ?>"><?= e($d['label']) ?></label>
            <?php if ($d['field_type'] === 'select'): ?>
                <select class="form-select btn-touch<?= $bad ? ' is-invalid' : '' ?>" id="<?= e($fid) ?>" name="custom[<?= e($k) ?>]"><option value="">—</option>
                    <?php foreach ($d['options_list'] as $o): ?><option value="<?= e($o) ?>" <?= $v === $o ? 'selected' : '' ?>><?= e($o) ?></option><?php endforeach; ?></select>
            <?php elseif ($d['field_type'] === 'multi_select'): ?>
                <select class="form-select<?= $bad ? ' is-invalid' : '' ?>" id="<?= e($fid) ?>" name="custom[<?= e($k) ?>][]" multiple size="<?= min(5, max(2, count($d['options_list']))) ?>">
                    <?php foreach ($d['options_list'] as $o): ?><option value="<?= e($o) ?>" <?= in_array($o, (array) $v, true) ? 'selected' : '' ?>><?= e($o) ?></option><?php endforeach; ?></select>
            <?php else: $type = ['number' => 'number', 'money' => 'number', 'date' => 'date', 'url' => 'url', 'email' => 'email', 'member' => 'number'][$d['field_type']] ?? 'text'; ?>
                <input type="<?= $type ?>" class="form-control btn-touch<?= $bad ? ' is-invalid' : '' ?>" id="<?= e($fid) ?>" name="custom[<?= e($k) ?>]" value="<?= e(is_scalar($v) ? (string) $v : '') ?>"<?= in_array($d['field_type'], ['number', 'money'], true) ? ' step="any"' : '' ?>>
            <?php endif; ?>
        <?php endif; ?>
    </div>
<?php endforeach; ?>
</div></div></div>
