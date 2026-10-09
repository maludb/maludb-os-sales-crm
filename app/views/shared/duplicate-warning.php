<?php /** The duplicate warning as you type (HTMX on blur): what already looks like this. Data: rows (record_duplicates), kind, policy */ ?>
<div id="duplicate-warning" aria-live="polite">
<?php if ($rows !== []): ?>
    <div class="alert alert-warning fs-12 mb-3" role="status">
        <div class="fw-semibold mb-1"><?= $kind === 'contact' && $policy === 'block_exact_email' && array_filter($rows, static fn ($r) => $r['matched_on'] === 'email') ? 'This address already belongs to a contact — the business does not allow a second one.' : 'This may already be here:' ?></div>
        <ul class="mb-0 ps-3">
        <?php foreach ($rows as $r): ?>
            <li id="duplicate-warning-<?= (int) $r['id'] ?>"><a href="<?= e($r['url']) ?>" target="_blank" rel="noopener" class="alert-link"><?= e($r['name'] !== '' ? $r['name'] : '(no name)') ?></a>
                <span class="text-muted">— the same <?= e($r['matched_on'] === 'name' ? 'name, nearly' : $r['matched_on']) ?><?= $r['detail'] ? ' · ' . e($r['detail']) : '' ?></span></li>
        <?php endforeach; ?>
        </ul>
    </div>
<?php endif; ?>
</div>
