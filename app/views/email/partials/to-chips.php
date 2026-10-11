<?php /** The To chips (#compose-to): the people an email from this record can go to, as checkboxes — the ones who opted out or have no address marked and not offered. Data: contacts [{contact_id, name, address, account_name, opted_out, allowed, selected}] */ ?>
<div class="d-flex flex-wrap gap-2" role="group" aria-labelledby="compose-to-label" id="compose-to-chips">
<?php foreach ($contacts as $p): $cid = (int) $p['contact_id']; $off = !$p['allowed']; ?>
    <label class="compose-chip border rounded d-flex align-items-start gap-2 p-2 mb-0<?= $off ? ' bg-soft-secondary text-muted' : '' ?>" for="compose-to-<?= $cid ?>" id="compose-to-chip-<?= $cid ?>" style="min-width: 0; max-width: 100%; text-transform: none; cursor: <?= $off ? 'not-allowed' : 'pointer' ?>">
        <input type="checkbox" class="form-check-input mt-1 flex-shrink-0" name="contacts[]" value="<?= $cid ?>" id="compose-to-<?= $cid ?>"<?= $p['selected'] && !$off ? ' checked' : '' ?><?= $off ? ' disabled' : '' ?>>
        <span class="min-w-0"><span class="d-block fw-semibold text-truncate"><?= e($p['name']) ?>
            <?php if ($p['opted_out']): ?><span class="badge bg-soft-danger text-danger ms-1" id="compose-to-optout-<?= $cid ?>">opted out</span><?php elseif ($off): ?><span class="badge bg-soft-secondary text-secondary ms-1">no address</span><?php endif; ?></span>
            <span class="d-block fs-11 text-muted text-break"><?= e((string) ($p['address'] ?? 'no address')) ?></span></span>
    </label>
<?php endforeach; ?>
</div>
