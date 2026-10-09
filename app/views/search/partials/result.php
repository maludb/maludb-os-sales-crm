<?php /** One result: the title as a link carrying ?back=, the status, the highlighted excerpt. Data: r, q */
$status = (string) ($r['status'] ?? '');
$here = '/search?' . http_build_query(['q' => $q]);
?>
<div class="list-group-item search-result" id="search-result-<?= e($r['entity_kind']) ?>-<?= (int) $r['entity_id'] ?>">
    <div class="d-flex justify-content-between align-items-start gap-2">
        <div class="min-w-0">
            <div class="fw-semibold text-truncate"><?= hx_link(with_back(search_result_url($r), $here), e((string) $r['title']), 'text-dark') ?></div>
            <div class="fs-12 text-muted text-break"><?= search_headline_html($r['headline']) ?></div>
        </div>
        <?php if ($status !== ''): ?><span class="badge bg-soft-secondary text-secondary flex-shrink-0"><?= e(str_replace('_', ' ', $status)) ?></span><?php endif; ?>
    </div>
</div>
