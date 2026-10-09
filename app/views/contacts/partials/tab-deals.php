<?php /** Deals: the contact's deals with their role on each. Data: c, deals */ $cid = (int) $c['contact_id']; $built = nav_built('/deals/view.php'); $roles = CONTACT_ROLES + ['primary' => 'Primary contact']; ?>
<?php if ($deals === []): ?><div class="card"><div class="card-body fs-12 text-muted" id="contact-deals-empty">Not on any deal yet.</div></div>
<?php else: ?>
<div class="card"><div class="table-responsive"><table class="table table-hover mb-0 fs-12" id="contact-deals-table">
    <thead class="thead-light"><tr><th>Deal</th><th>Role</th><th>Stage</th><th class="text-end">Value</th></tr></thead><tbody>
    <?php foreach ($deals as $d): $did = (int) $d['deal_id']; $title = '<span class="text-muted">' . e((string) $d['number']) . '</span> ' . e($d['title']); ?>
        <tr id="contact-deal-<?= $did ?>"><td><?= $built ? hx_link(with_back('/deals/' . $did, '/contacts/' . $cid . '?tab=deals'), $title, 'text-dark') : $title ?></td><td><?= e($roles[$d['role']] ?? (string) $d['role']) ?></td>
            <td><?= $d['status'] === 'open' ? e((string) $d['stage_name']) : e($d['status']) ?></td><td class="text-end text-nowrap"><?= e(money($d['value'], $d['currency'])) ?></td></tr>
    <?php endforeach; ?>
</tbody></table></div></div>
<?php endif; ?>
