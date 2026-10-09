<?php /** Deals: open first (stage, value, close), then won and lost. Data: a, deals, may, tz */ $aid = (int) $a['account_id']; $built = nav_built('/deals/view.php'); ?>
<div class="d-flex justify-content-between align-items-center mb-2"><h6 class="mb-0">Deals</h6>
    <?php if ($may['deal'] && nav_built('/deals/form.php')): ?><?= hx_link('/deals/new?account=' . $aid, '<i class="feather-plus me-1"></i>New deal', 'btn btn-light btn-touch', 'id="account-add-deal-btn"') ?><?php endif; ?></div>
<?php if ($deals === []): ?>
    <div class="card"><div class="card-body fs-12 text-muted" id="account-deals-empty">No deals with this account yet.</div></div>
<?php else: ?>
<div class="card"><div class="table-responsive"><table class="table table-hover mb-0 fs-12" id="account-deals-table">
    <thead class="thead-light"><tr><th>Deal</th><th>Stage</th><th class="text-end">Value</th><th class="d-none d-md-table-cell">Close</th></tr></thead><tbody>
    <?php foreach ($deals as $d): $did = (int) $d['deal_id']; $title = '<span class="text-muted">' . e((string) $d['number']) . '</span> ' . e($d['title']); ?>
        <tr id="account-deal-<?= $did ?>"><td><?= $built ? hx_link(with_back('/deals/' . $did, '/accounts/' . $aid . '?tab=deals'), $title, 'text-dark') : $title ?></td>
            <td><?= $d['status'] === 'open' ? e((string) $d['stage_name']) : '<span class="badge bg-soft-' . ($d['status'] === 'won' ? 'success text-success' : 'danger text-danger') . '">' . e($d['status']) . '</span>' ?></td>
            <td class="text-end text-nowrap"><?= e(money($d['value'], $d['currency'])) ?></td><td class="d-none d-md-table-cell text-nowrap"><?= e((string) ($d['expected_close_date'] ?? '')) ?></td></tr>
    <?php endforeach; ?>
</tbody></table></div></div>
<?php endif; ?>
