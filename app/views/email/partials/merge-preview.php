<?php /** What the merge did (#compose-preview): the fields filled from the record, what is still to fill — an unknown field, one with nothing to fill it, one filled for each person when it goes — and the body as it will read.
 * Data: s, notes, filled, perPerson, tpl_label */ ?>
<div id="compose-preview" class="border rounded p-2 bg-soft-secondary">
    <div class="fs-11 text-muted mb-1">Preview<?= $tpl_label !== null ? ' — from the template ' . e($tpl_label) : '' ?></div>
    <?php if ($filled !== []): ?><div class="fs-12 mb-1" id="compose-preview-filled">Filled in: <?php foreach ($filled as $field => $value): ?><span class="badge bg-soft-success text-success me-1"><?= e($field) ?> → <?= e(mb_substr((string) $value, 0, 40)) ?></span><?php endforeach; ?></div><?php endif; ?>
    <?php if ($notes['unknown'] !== []): ?><div class="fs-12 text-danger mb-1" id="compose-preview-unknown"><i class="feather-alert-circle me-1"></i>Not a merge field: <?= e(implode(', ', $notes['unknown'])) ?> — it will be sent as typed unless you take it out.</div><?php endif; ?>
    <?php if ($notes['missing'] !== []): ?><div class="fs-12 text-warning mb-1" id="compose-preview-missing"><i class="feather-alert-triangle me-1"></i>Nothing to fill <?= e(implode(', ', $notes['missing'])) ?> here (no deal, meeting or signature) — fill it in or take it out before sending.</div><?php endif; ?>
    <?php if ($notes['when_sent'] !== []): ?><div class="fs-12 text-info mb-1" id="compose-preview-when-sent"><i class="feather-users me-1"></i>With several people, <?= e(implode(', ', $notes['when_sent'])) ?> is filled for each one when it goes.</div><?php endif; ?>
    <div class="fs-12 text-break" id="compose-preview-body"><?= trim($s['body']) === '' ? '<span class="text-muted">The message will show here.</span>' : md_render($s['body']) ?></div>
</div>
