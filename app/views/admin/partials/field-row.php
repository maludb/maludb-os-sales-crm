<?php /** One custom field (admin-fields; id field-{id}). Data: f (a row of fields()), kind, pipelines (names by id, for a deal's) */
$id = $f['id'];
$archived = $f['archived_at'] !== null; ?>
<div class="d-flex align-items-start gap-2 px-3 py-2 border-bottom<?= $archived ? ' text-muted' : '' ?>" id="field-<?= $id ?>">
    <div class="min-w-0 flex-grow-1 fs-12">
        <div class="fw-semibold"><?= e($f['label']) ?> <code class="fw-normal"><?= e($f['key']) ?></code>
            <span class="badge bg-soft-secondary text-secondary ms-1" id="field-<?= $id ?>-type"><?= e(FIELD_TYPES[$f['field_type']] ?? $f['field_type']) ?></span>
            <?php if ($f['required_to_win']): ?><span class="badge bg-soft-warning text-warning ms-1" id="field-<?= $id ?>-win">needed to win</span><?php endif; ?>
            <?php if ($archived): ?><span class="badge bg-soft-dark text-dark ms-1" id="field-<?= $id ?>-archived">archived</span><?php endif; ?></div>
        <?php if ($f['options_list'] !== []): ?><div class="text-muted" id="field-<?= $id ?>-options"><?= e(implode(' · ', $f['options_list'])) ?></div><?php endif; ?>
        <div class="text-muted"><?php if ($kind === 'deal'): ?><?= $f['pipeline_ids'] === [] ? 'every pipeline' : e(implode(', ', array_map(static fn (int $p): string => $pipelines[$p] ?? ('#' . $p), $f['pipeline_ids']))) ?> · <?php endif; ?>
            <span id="field-<?= $id ?>-use"><?= $f['in_use'] > 0 ? (int) $f['in_use'] . ' record' . ($f['in_use'] === 1 ? ' has' : 's have') . ' a value' : 'no value stored yet' ?></span></div>
    </div>
    <?= hx_link('/admin/fields?kind=' . rawurlencode($kind) . '&field=' . $id . '#field-form', '<i class="feather-edit-2"></i>', 'avatar-text avatar-sm flex-shrink-0', 'id="field-' . $id . '-edit-btn" title="Edit" aria-label="Edit ' . e($f['label']) . '"') ?>
</div>
