<?php /** A contact's form (contact-add / contact-edit): names, title, email (the duplicate warning on blur; block or warn per the setting), phones, role, the account
 * picker, primary, opt-outs are the header's, birthday, custom fields, the owner picker; editing, for a manager, the Restriction section. Data: c (null on add), values, sources, custom_html, restriction, policy, may_tag */
$editing = $c !== null;
$id = $editing ? (int) $c['contact_id'] : null;
$v = $values;
$in = static fn (string $name, string $label, string $type = 'text', string $attrs = '', string $col = 'col-md-6') => '<div class="' . $col . '"><label class="form-label" for="contact-form-field-' . $name . '">' . e($label) . '</label>'
    . '<input type="' . $type . '" class="form-control btn-touch" id="contact-form-field-' . $name . '" name="' . $name . '" value="' . e((string) ($v[$name] ?? '')) . '"' . $attrs . '></div>';
$dupes = ' hx-get="/contacts/new?dupes=1' . ($editing ? '&amp;except=' . $id : '') . '" hx-trigger="blur changed" hx-target="#duplicate-warning" hx-swap="outerHTML" hx-include="#contact-form-field-email, #contact-form-field-first_name, #contact-form-field-last_name"';
$title = $editing ? 'Edit ' . (trim(($c['first_name'] ?? '') . ' ' . ($c['last_name'] ?? '')) ?: (string) $c['email']) : 'New contact'; ?>
<?= view('shared/header.php', ['id' => 'contact-form', 'title' => $title, 'crumbs' => [['Today', '/'], ['Contacts', '/contacts'], [$editing ? 'Edit' : 'New', null]],
    'back' => [$editing ? '/contacts/' . $id : ($v['account_id'] ? '/accounts/' . (int) $v['account_id'] : '/contacts'), $editing ? 'the contact' : ($v['account_id'] ? 'the account' : 'Contacts')]]) ?>
<div class="main-content" id="contact-form-content">
    <form method="post" action="/contacts/save.php" hx-post="/contacts/save.php" hx-target="#flash" id="contact-form">
        <?= csrf_field() ?>
        <?php if ($editing): ?><input type="hidden" name="contact" value="<?= $id ?>"><input type="hidden" name="return_to" value="/contacts/<?= $id ?>"><?php endif; ?>
        <div class="card mb-3"><div class="card-body"><div class="row g-3">
            <div class="col-md-6"><label class="form-label" for="contact-form-field-first_name">First name</label><input type="text" class="form-control btn-touch" id="contact-form-field-first_name" name="first_name" value="<?= e((string) $v['first_name']) ?>" maxlength="100" autofocus<?= $dupes ?>></div>
            <div class="col-md-6"><label class="form-label" for="contact-form-field-last_name">Last name</label><input type="text" class="form-control btn-touch" id="contact-form-field-last_name" name="last_name" value="<?= e((string) $v['last_name']) ?>" maxlength="100"<?= $dupes ?>></div>
            <div class="col-md-6"><label class="form-label" for="contact-form-field-email">Email</label><input type="email" class="form-control btn-touch" id="contact-form-field-email" name="email" value="<?= e((string) $v['email']) ?>" maxlength="254"<?= $dupes ?>>
                <div class="form-text">A name or an email is enough to start.</div></div>
            <?= $in('title', 'Title', 'text', ' maxlength="150"') ?>
            <div class="col-12"><?= view('shared/duplicate-warning.php', ['rows' => [], 'kind' => 'contact', 'policy' => $policy]) ?></div>
            <div class="col-md-6"><label class="form-label" id="contact-form-account-label" for="contact-form-field-account">Account</label>
                <?= picker_field(['id' => 'contact-form-field-account', 'name' => 'account', 'source' => 'account', 'value' => $v['account_id'], 'labelledby' => 'contact-form-account-label', 'title' => 'Their company', 'placeholder' => $editing ? 'No company' : 'Found by their email\'s domain', 'create' => has_right('accounts.write') ? ['label' => 'New account', 'url' => '/accounts/new'] : []]) ?></div>
            <div class="col-md-6"><label class="form-label" for="contact-form-field-role">Role</label>
                <select class="form-select btn-touch" id="contact-form-field-role" name="role"><option value="">—</option><?php foreach (CONTACT_ROLES as $k => $l): ?><option value="<?= e($k) ?>" <?= $v['role'] === $k ? 'selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select></div>
            <div class="col-md-6"><input type="hidden" name="is_primary" value="no"><label class="d-flex align-items-center gap-2 border rounded px-3 btn-touch mt-md-4" for="contact-form-field-is_primary"><input type="checkbox" class="form-check-input mt-0" id="contact-form-field-is_primary" name="is_primary" value="yes" <?= $v['is_primary'] ? 'checked' : '' ?>>The account's primary contact</label></div>
            <div class="col-md-6"><input type="hidden" name="do_not_call" value="no"><label class="d-flex align-items-center gap-2 border rounded px-3 btn-touch mt-md-4" for="contact-form-field-do_not_call"><input type="checkbox" class="form-check-input mt-0" id="contact-form-field-do_not_call" name="do_not_call" value="yes" <?= $v['do_not_call'] ? 'checked' : '' ?>>Do not call</label></div>
            <?= $in('phone', 'Phone', 'tel', ' maxlength="40"') ?>
            <?= $in('mobile', 'Mobile', 'tel', ' maxlength="40"') ?>
            <?= $in('email_alt', 'Another email', 'email', ' maxlength="254"') ?>
            <?= $in('department_text', 'Department', 'text', ' maxlength="120"') ?>
            <div class="col-md-6"><label class="form-label" for="contact-form-field-preferred_channel">Prefers</label>
                <select class="form-select btn-touch" id="contact-form-field-preferred_channel" name="preferred_channel"><option value="">—</option><?php foreach (CONTACT_CHANNELS as $k => $l): ?><option value="<?= e($k) ?>" <?= $v['preferred_channel'] === $k ? 'selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select></div>
            <?= $in('birthday', 'Birthday', 'date', ' max="' . date('Y-m-d') . '"') ?>
            <?= $in('linkedin_url', 'LinkedIn', 'url', ' maxlength="300" placeholder="https://www.linkedin.com/in/…"') ?>
            <?= $in('timezone', 'Time zone', 'text', ' maxlength="64" placeholder="America/Chicago"') ?>
            <?= $in('city', 'City', 'text', ' maxlength="120"') ?>
            <?= $in('region', 'Region', 'text', ' maxlength="120"') ?>
            <?= $in('country', 'Country', 'text', ' maxlength="2" placeholder="US"') ?>
            <div class="col-md-6"><label class="form-label" for="contact-form-field-source">Source</label>
                <select class="form-select btn-touch" id="contact-form-field-source" name="source"><option value="">—</option><?php foreach ($sources as $s): ?><option value="<?= (int) $s['source_id'] ?>" <?= (int) ($v['source_id'] ?? 0) === (int) $s['source_id'] ? 'selected' : '' ?>><?= e($s['name']) ?></option><?php endforeach; ?></select></div>
            <div class="col-12"><label class="form-label" for="contact-form-field-address">Address</label><textarea class="form-control" id="contact-form-field-address" name="address" rows="2" maxlength="500"><?= e((string) $v['address']) ?></textarea></div>
            <div class="col-md-6"><label class="form-label" id="contact-form-owner-label" for="contact-form-field-owner">Owner</label>
                <?= picker_field(['id' => 'contact-form-field-owner', 'name' => 'owner', 'source' => 'owner', 'value' => $v['owner_member_id'], 'labelledby' => 'contact-form-owner-label', 'title' => 'The owner', 'placeholder' => 'Nobody yet']) ?></div>
            <?php if (!$editing && $may_tag): ?><div class="col-md-6"><label class="form-label" for="contact-form-field-tags">Tags</label><input type="text" class="form-control btn-touch" id="contact-form-field-tags" name="tags" maxlength="300" placeholder="champion, webinar"></div><?php endif; ?>
        </div></div></div>
        <?= $custom_html ?>
        <div class="d-flex gap-2 mb-3">
            <button type="submit" class="btn btn-primary btn-touch flex-grow-1" id="contact-form-save-btn"><?= $editing ? 'Save' : 'Add the contact' ?></button>
            <?= hx_link($editing ? '/contacts/' . $id : '/contacts', 'Cancel', 'btn btn-light btn-touch', 'id="contact-form-cancel-btn"') ?>
        </div>
    </form>
    <?php if ($restriction !== null): ?><?= view('shared/restriction.php', $restriction) ?><?php endif; ?>
</div>
