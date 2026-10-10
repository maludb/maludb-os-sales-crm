<?php /** One of the conversion's three answers: Found (a radio to keep it, or the picker to choose another) or New. Data: kind (account|contact), found, made (the new one's words), picker_source */
$k = $kind; ?>
<div class="card mb-3" id="convert-<?= e($k) ?>"><div class="card-body">
    <div class="fs-12 text-muted mb-1"><?= $k === 'account' ? 'The company' : 'The person' ?></div>
    <?php if ($found !== null && empty($found['hidden'])): ?>
        <div class="fw-semibold" id="convert-<?= e($k) ?>-found">Found: <?= e((string) $found['name']) ?><?= $k === 'account' && ($found['domain'] ?? null) ? ' <span class="text-muted fw-normal">(' . e((string) $found['domain']) . ')</span>' : '' ?><?= $k === 'contact' && ($found['account_name'] ?? null) ? ' <span class="text-muted fw-normal">at ' . e((string) $found['account_name']) . '</span>' : '' ?></div>
        <div class="fs-12 text-muted">The conversion uses it<?= $k === 'account' ? ' (matched by ' . e((string) $found['by']) . ')' : '' ?>. Or choose another:</div>
    <?php elseif ($found !== null): ?>
        <div class="fw-semibold" id="convert-<?= e($k) ?>-hidden">Found: a <?= e($k) ?> restricted from you</div>
        <div class="fs-12 text-muted">The conversion will use it; you will not see it afterwards. Choose another to use instead:</div>
    <?php elseif ($made !== null): ?>
        <div class="fw-semibold" id="convert-<?= e($k) ?>-new">New: <?= e((string) $made['name']) ?><?= $k === 'contact' && ($made['email'] ?? null) ? ' <span class="text-muted fw-normal">&lt;' . e((string) $made['email']) . '&gt;</span>' : '' ?></div>
        <div class="fs-12 text-muted">Nothing like it is here — the conversion makes it. Or choose one that is:</div>
    <?php else: ?>
        <div class="fw-semibold" id="convert-<?= e($k) ?>-none">None — the lead names no <?= $k === 'account' ? 'company' : 'person' ?></div>
        <div class="fs-12 text-muted">Choose one to attach, or convert without:</div>
    <?php endif; ?>
    <div class="mt-2"><?= picker_field(['id' => 'convert-form-field-' . $k, 'name' => $k, 'source' => $k, 'value' => null, 'title' => $k === 'account' ? 'Use this account' : 'Use this contact', 'placeholder' => $found !== null || $made !== null ? 'As above' : 'None']) ?></div>
</div></div>
