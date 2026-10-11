<?php /** The quota grid (admin-quotas; id quota-grid; inputs quota-{member}-{YYYY-MM}). Data: grid (quota_grid()) */
$monthName = static fn (string $ym): string => date('M', mktime(0, 0, 0, (int) substr($ym, 5, 2), 1, 2000)); ?>
<div class="table-responsive quota-grid-wrap"><table class="table table-sm align-middle mb-0 quota-grid" id="quota-grid">
    <thead class="thead-light"><tr><th class="quota-grid-seller">Seller</th><?php foreach ($grid['months'] as $ym): ?><th class="text-center" id="quota-head-<?= e($ym) ?>"><?= e($monthName($ym)) ?></th><?php endforeach; ?><th class="text-end">Year</th></tr></thead>
    <tbody>
    <?php if ($grid['sellers'] === []): ?><tr><td colspan="14" class="text-center text-muted py-4" id="quota-empty">No seller works here yet — a quota is for a Sales Rep, a Sales Manager or the CRM admin.</td></tr><?php endif; ?>
    <?php foreach ($grid['sellers'] as $s): $mid = (int) $s['member_id']; $sum = 0.0; foreach ($s['cells'] as $c) { $sum += $c === null ? 0.0 : (float) $c; } ?>
        <tr id="quota-row-<?= $mid ?>">
            <th class="quota-grid-seller fw-semibold text-nowrap" scope="row"><?= e($s['display_name']) ?></th>
            <?php foreach ($grid['months'] as $ym): $v = $s['cells'][$ym]; ?>
                <td class="p-1"><label class="visually-hidden" for="quota-<?= $mid ?>-<?= e($ym) ?>"><?= e($s['display_name'] . ' ' . $monthName($ym)) ?></label>
                    <input type="number" class="form-control form-control-sm text-end quota-cell" id="quota-<?= $mid ?>-<?= e($ym) ?>" name="cells[<?= $mid ?>][<?= e($ym) ?>]" value="<?= $v === null ? '' : e(rtrim(rtrim(number_format((float) $v, 2, '.', ''), '0'), '.')) ?>" min="0" step="0.01" inputmode="decimal" placeholder="—"></td>
            <?php endforeach; ?>
            <td class="text-end text-nowrap fs-12" id="quota-total-<?= $mid ?>"><?= e(money($sum, $grid['currency'])) ?></td>
        </tr>
    <?php endforeach; ?>
    </tbody></table></div>
