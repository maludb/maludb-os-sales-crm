<?php /** Contacts: cards, the primary first; Add (a contact at this account). Data: a, contacts, may, tz */ $aid = (int) $a['account_id']; ?>
<div class="d-flex justify-content-between align-items-center mb-2"><h6 class="mb-0">People at <?= e($a['name']) ?></h6>
    <?php if ($may['contact']): ?><?= hx_link('/contacts/new?account=' . $aid, '<i class="feather-plus me-1"></i>Add a contact', 'btn btn-light btn-touch', 'id="account-add-contact-btn"') ?><?php endif; ?></div>
<?php if ($contacts === []): ?>
    <div class="card"><div class="card-body fs-12 text-muted" id="account-contacts-empty">Nobody here yet.</div></div>
<?php else: ?>
<div class="row g-3" id="account-contacts-cards">
<?php foreach ($contacts as $c): $cid = (int) $c['contact_id']; ?>
    <div class="col-md-6"><div class="card h-100 mb-0" id="account-contact-<?= $cid ?>"><div class="card-body">
        <div class="d-flex align-items-start gap-2">
            <div class="min-w-0 flex-grow-1">
                <div class="fw-semibold text-truncate"><?= hx_link(with_back('/contacts/' . $cid, '/accounts/' . $aid . '?tab=contacts'), e($c['name'] !== '' ? $c['name'] : (string) $c['email']), 'text-dark') ?></div>
                <div class="fs-12 text-muted text-truncate"><?= e(trim(($c['title'] ?? '') . ($c['role'] ? ' · ' . (CONTACT_ROLES[$c['role']] ?? $c['role']) : ''), ' ·')) ?></div>
            </div>
            <?php if ($c['is_primary']): ?><span class="badge bg-soft-primary text-primary" id="account-contact-<?= $cid ?>-primary">Primary</span><?php endif; ?>
        </div>
        <div class="fs-12 mt-1 text-truncate"><?= e((string) ($c['email'] ?? '')) ?><?= $c['email_opt_out'] ? ' <span class="badge text-bg-danger">No email</span>' : '' ?></div>
        <?php if (!$c['is_primary'] && $may['edit']): ?>
        <form method="post" action="/contacts/primary.php" hx-post="/contacts/primary.php" hx-target="#flash" class="mt-2"><?= csrf_field() ?><input type="hidden" name="contact" value="<?= $cid ?>"><input type="hidden" name="return_to" value="/accounts/<?= $aid ?>?tab=contacts">
            <button type="submit" class="btn btn-light btn-sm btn-touch" id="account-contact-<?= $cid ?>-make-primary">Make primary</button></form>
        <?php endif; ?>
    </div></div></div>
<?php endforeach; ?>
</div>
<?php endif; ?>
