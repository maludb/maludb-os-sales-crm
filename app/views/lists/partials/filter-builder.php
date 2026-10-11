<?php /** The filter builder (#filter-builder): the find_* vocabulary as form fields (filters.php is the one place it is defined), with the live count refreshed on change. Data: kind, filters (compact) */ ?>
<div class="card mb-3" id="filter-builder">
    <div class="card-header d-flex flex-wrap gap-2 align-items-center"><span class="fw-semibold">Filters</span><span class="ms-auto text-muted" hx-get="/lists/new?preview=1&amp;record_kind=<?= e($kind) ?>" hx-trigger="change from:#filter-builder delay:400ms, load" hx-include="#filter-builder" hx-target="this" hx-swap="innerHTML" id="filter-preview-wrap"></span></div>
    <div class="card-body"><div class="row g-3"><?= filters_form($kind, $filters) ?></div></div>
</div>
