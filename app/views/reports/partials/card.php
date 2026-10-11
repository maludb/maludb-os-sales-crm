<?php /** One report's card: the number large, the period small, a link to the report. Data: c {name, title, url, headline{text, label, detail, tone}, period, note} */
$h = $c['headline']; $tone = $h['tone'] ?? null; ?>
<div class="col-12 col-sm-6 col-xl-4">
    <div class="card h-100 report-card" id="report-card-<?= e($c['name']) ?>"><div class="card-body d-flex flex-column">
        <div class="d-flex justify-content-between align-items-start mb-1">
            <div class="fw-semibold"><?= hx_link($c['url'], e($c['title']), 'text-dark', 'id="report-card-' . e($c['name']) . '-link"') ?></div>
            <div class="fs-11 text-muted text-end"><?= e($c['period']) ?></div>
        </div>
        <div class="report-figure<?= $tone !== null ? ' text-' . e($tone) : '' ?>" id="report-card-<?= e($c['name']) ?>-figure"><?= e($h['text']) ?></div>
        <div class="fs-12 text-muted"><?= e($h['label']) ?><?= ($h['detail'] ?? '') !== '' ? ' · ' . e($h['detail']) : '' ?></div>
        <?php if (($c['note'] ?? '') !== '' && $h['text'] === '—'): ?><div class="fs-11 text-muted mt-1"><?= e($c['note']) ?></div><?php endif; ?>
        <div class="mt-auto pt-2"><?= hx_link($c['url'], 'Open the report <i class="feather-arrow-right ms-1"></i>', 'fs-12 fw-semibold') ?></div>
    </div></div>
</div>
