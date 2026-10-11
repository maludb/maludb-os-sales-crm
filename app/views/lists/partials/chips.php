<?php /** A smart list's filters as chips (#list-filters). Data: chips [[label, value]…], l (the list), may */ ?>
<div class="d-flex flex-wrap gap-1 align-items-center mb-2" id="list-filters">
    <?php foreach ($chips as $i => [$label, $value]): ?><span class="badge bg-soft-primary text-primary fs-12 fw-normal" id="list-filter-chip-<?= $i ?>"><?= e($label) ?>: <?= e($value) ?></span><?php endforeach; ?>
    <?php if ($chips === []): ?><span class="text-muted fs-12">No filters.</span><?php endif; ?>
    <?php if ($may['edit']): ?><?= hx_link('/lists/' . (int) $l['list_id'] . '/edit', '<i class="feather-sliders me-1"></i>Edit filters', 'btn btn-light btn-sm btn-touch ms-1', 'id="list-edit-filters-btn"') ?><?php endif; ?>
</div>
