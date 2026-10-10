<?php /** The deal's people with their roles (#deal-contacts): the primary first; change a role or make primary; take off; Add a person (the contact picker,
 * narrowed to the deal's account when it has one). Data: d, contacts, may */
$id = (int) $d['deal_id']; ?>
<div class="card mb-3" id="deal-contacts"><div class="card-header d-flex align-items-center"><h6 class="card-title mb-0">People</h6><span class="badge bg-soft-secondary text-secondary ms-2"><?= count($contacts) ?></span></div>
    <div class="card-body">
    <?php if ($contacts === []): ?><div class="text-muted fs-12 mb-2" id="deal-contacts-empty">Nobody on the deal yet.</div><?php endif; ?>
    <?php foreach ($contacts as $c): $cid = (int) $c['contact_id']; ?>
        <div class="d-flex flex-wrap align-items-center gap-2 py-2 border-bottom" id="deal-contact-<?= $cid ?>">
            <span class="avatar-text avatar-md rounded-circle"><?= e(initials($c['contact_name'])) ?></span>
            <div class="min-w-0 flex-grow-1"><div class="fw-semibold text-truncate"><?= hx_link('/contacts/' . $cid, e((string) $c['contact_name']), 'text-dark') ?><?php if ($c['is_primary']): ?> <span class="badge bg-soft-primary text-primary" id="deal-contact-<?= $cid ?>-primary">primary</span><?php endif; ?></div>
                <div class="fs-11 text-muted text-truncate"><?= e(trim(($c['title'] ?? '') . ($c['email'] ? ' · ' . $c['email'] : ''), ' ·')) ?: '&nbsp;' ?></div></div>
            <?php if ($may['edit']): ?>
            <form method="post" action="/deals/contacts/save.php" hx-post="/deals/contacts/save.php" hx-target="#flash" class="d-flex gap-1 align-items-center" id="deal-contact-<?= $cid ?>-form"><?= csrf_field() ?>
                <input type="hidden" name="deal" value="<?= $id ?>"><input type="hidden" name="contact" value="<?= $cid ?>"><input type="hidden" name="return_to" value="/deals/<?= $id ?>#deal-contacts">
                <label class="visually-hidden" for="deal-contact-<?= $cid ?>-role">Role</label>
                <select name="role" class="form-select form-select-sm" id="deal-contact-<?= $cid ?>-role"><?php foreach (CONTACT_ROLES as $k => $label): ?><option value="<?= $k ?>"<?= $c['role'] === $k ? ' selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?></select>
                <?php if (!$c['is_primary']): ?><label class="form-check fs-11 mb-0 text-nowrap"><input type="checkbox" class="form-check-input" name="is_primary" value="yes" id="deal-contact-<?= $cid ?>-make-primary"> primary</label><?php else: ?><input type="hidden" name="is_primary" value="yes"><?php endif; ?>
                <button type="submit" class="btn btn-sm btn-light" id="deal-contact-<?= $cid ?>-save">Save</button></form>
            <?php if (!$c['is_primary']): ?>
            <form method="post" action="/deals/contacts/remove.php" hx-post="/deals/contacts/remove.php" hx-target="#flash" hx-confirm="Take <?= e((string) $c['contact_name']) ?> off the deal?"><?= csrf_field() ?>
                <input type="hidden" name="deal" value="<?= $id ?>"><input type="hidden" name="contact" value="<?= $cid ?>"><input type="hidden" name="return_to" value="/deals/<?= $id ?>#deal-contacts">
                <button type="submit" class="btn btn-sm btn-light text-danger" id="deal-contact-<?= $cid ?>-remove" aria-label="Take off the deal"><i class="feather-x"></i></button></form>
            <?php endif; ?><?php else: ?><span class="badge bg-soft-secondary text-secondary"><?= e(CONTACT_ROLES[$c['role']] ?? $c['role']) ?></span><?php endif; ?>
        </div>
    <?php endforeach; ?>
    <?php if ($may['edit']): ?>
    <form method="post" action="/deals/contacts/add.php" hx-post="/deals/contacts/add.php" hx-target="#flash" class="row g-2 align-items-end mt-2" id="deal-contact-add-form"><?= csrf_field() ?>
        <input type="hidden" name="deal" value="<?= $id ?>"><input type="hidden" name="return_to" value="/deals/<?= $id ?>#deal-contacts">
        <div class="col-12 col-md-6"><label class="form-label fs-11 mb-0" id="deal-contact-add-label" for="deal-contact-add-field-contact">Add a person</label>
            <?= picker_field(['id' => 'deal-contact-add-field-contact', 'name' => 'contact', 'source' => 'contact', 'value' => null, 'labelledby' => 'deal-contact-add-label', 'title' => 'Who is on the deal', 'placeholder' => 'Choose a contact',
                'params' => $d['account_id'] === null ? [] : ['account' => (int) $d['account_id']], 'required' => true]) ?></div>
        <div class="col-6 col-md-3"><label class="form-label fs-11 mb-0" for="deal-contact-add-field-role">Role</label><select name="role" class="form-select btn-touch" id="deal-contact-add-field-role"><option value="">Their usual role</option>
            <?php foreach (CONTACT_ROLES as $k => $label): ?><option value="<?= $k ?>"><?= e($label) ?></option><?php endforeach; ?></select></div>
        <div class="col-6 col-md-3"><button type="submit" class="btn btn-light btn-touch w-100" id="deal-contact-add-btn"><i class="feather-user-plus me-1"></i>Add</button></div>
    </form>
    <?php endif; ?>
    </div></div>
