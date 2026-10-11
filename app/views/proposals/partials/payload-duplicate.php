<?php /** A duplicate's payload: the two sides, each a link when the viewer may open it, and what matched. Data: r, back */
$sides = $r['extra']['sides'] ?? []; ?>
<div class="d-flex flex-wrap align-items-center gap-1" id="proposal-<?= $r['id'] ?>-sides">
<?php foreach ($sides as $i => $s): ?>
    <?= $i === 1 ? '<span class="text-muted mx-1">and</span>' : '' ?>
    <?= $s['url'] === null ? '<span class="text-muted">' . e($s['label']) . '</span>' : hx_link(with_back($s['url'], $back), e($s['label']), 'fw-semibold') ?>
<?php endforeach; ?>
</div>
<?php foreach ($r['lines'] as $line): ?><div class="text-muted"><?= e($line) ?></div><?php endforeach; ?>
