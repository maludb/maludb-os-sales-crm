<?php /** An account's form (account-add / account-edit): page-header-form, the fields of account_create, the parent and owner pickers, the duplicate warning as
 * you type (on blur), the custom fields, and — editing, for a manager — the Restriction section (its own form). Data: a (null on add), values, sources, custom_html,
 * restriction (null or the restriction partial's data), dupes */
$editing = $a !== null;
$id = $editing ? (int) $a['account_id'] : null;
$v = $values;
$in = static fn (string $name, string $label, string $type = 'text', array $o = []) => '<div class="' . ($o['col'] ?? 'col-md-6') . '"><label class="form-label" for="account-form-field-' . $name . '">' . e($label) . '</label>'
    . '<input type="' . $type . '" class="form-control btn-touch" id="account-form-field-' . $name . '" name="' . $name . '" value="' . e((string) ($v[$name] ?? '')) . '"' . ($o['attrs'] ?? '') . '></div>';
$dupes = ' hx-get="/accounts/new?dupes=1' . ($editing ? '&amp;except=' . $id : '') . '" hx-trigger="blur changed" hx-target="#duplicate-warning" hx-swap="outerHTML" hx-include="#account-form-field-name, #account-form-field-website, #account-form-field-domain"'; ?>
<?= view('shared/header.php', ['id' => 'account-form', 'title' => $editing ? 'Edit ' . $a['name'] : 'New account', 'crumbs' => [['Today', '/'], ['Accounts', '/accounts'], [$editing ? 'Edit' : 'New', null]],
    'back' => [$editing ? '/accounts/' . $id : '/accounts', $editing ? $a['name'] : 'Accounts']]) ?>
<div class="main-content" id="account-form-content">
    <form method="post" action="/accounts/save.php" hx-post="/accounts/save.php" hx-target="#flash" id="account-form">
        <?= csrf_field() ?>
        <?php if ($editing): ?><input type="hidden" name="account" value="<?= $id ?>"><input type="hidden" name="return_to" value="/accounts/<?= $id ?>"><?php endif; ?>
        <div class="card mb-3"><div class="card-body"><div class="row g-3">
            <div class="col-12"><label class="form-label" for="account-form-field-name">Name</label>
                <input type="text" class="form-control btn-touch" id="account-form-field-name" name="name" value="<?= e((string) $v['name']) ?>" maxlength="200" required autofocus<?= $dupes ?>></div>
            <div class="col-12"><?= view('shared/duplicate-warning.php', ['rows' => $dupes_rows, 'kind' => 'account', 'policy' => 'warn']) ?></div>
            <div class="col-md-6"><label class="form-label" for="account-form-field-website">Website</label>
                <input type="url" class="form-control btn-touch" id="account-form-field-website" name="website" value="<?= e((string) $v['website']) ?>" placeholder="https://acme.com" maxlength="300"<?= $dupes ?>></div>
            <div class="col-md-6"><label class="form-label" for="account-form-field-domain">Domain</label>
                <input type="text" class="form-control btn-touch" id="account-form-field-domain" name="domain" value="<?= e((string) $v['domain']) ?>" placeholder="from the website" maxlength="253"<?= $dupes ?>>
                <div class="form-text">One account per domain; it is how email finds the company.</div></div>
            <?= $in('legal_name', 'Legal name', 'text', ['attrs' => ' maxlength="200"']) ?>
            <?= $in('industry', 'Industry', 'text', ['attrs' => ' maxlength="120"']) ?>
            <div class="col-md-6"><label class="form-label" for="account-form-field-kind">Kind</label>
                <select class="form-select btn-touch" id="account-form-field-kind" name="kind">
                    <?php foreach ($kinds as $k): ?><option value="<?= e($k) ?>" <?= $v['kind'] === $k ? 'selected' : '' ?>><?= e(ACCOUNT_KINDS[$k] ?? $k) ?></option><?php endforeach; ?></select>
                <div class="form-text">A customer is made by winning a deal.</div></div>
            <div class="col-md-6"><label class="form-label" for="account-form-field-employees">Size</label>
                <select class="form-select btn-touch" id="account-form-field-employees" name="employees"><option value="">—</option>
                    <?php foreach (EMPLOYEE_BANDS as $b): ?><option value="<?= e($b) ?>" <?= $v['employees'] === $b ? 'selected' : '' ?>><?= e($b) ?> people</option><?php endforeach; ?></select></div>
            <?= $in('annual_revenue', 'Annual revenue', 'number', ['attrs' => ' min="0" step="any"']) ?>
            <?= $in('currency', 'Currency', 'text', ['attrs' => ' maxlength="3" placeholder="' . e(base_currency(db())) . '"']) ?>
            <?= $in('phone', 'Phone', 'tel', ['attrs' => ' maxlength="40"']) ?>
            <?= $in('email', 'Email', 'email', ['attrs' => ' maxlength="254"']) ?>
            <?= $in('city', 'City', 'text', ['attrs' => ' maxlength="120"']) ?>
            <?= $in('region', 'Region', 'text', ['attrs' => ' maxlength="120"']) ?>
            <?= $in('country', 'Country', 'text', ['attrs' => ' maxlength="2" placeholder="US"']) ?>
            <div class="col-md-6"><label class="form-label" for="account-form-field-source">Source</label>
                <select class="form-select btn-touch" id="account-form-field-source" name="source"><option value="">—</option>
                    <?php foreach ($sources as $s): ?><option value="<?= (int) $s['source_id'] ?>" <?= (int) ($v['source_id'] ?? 0) === (int) $s['source_id'] ? 'selected' : '' ?>><?= e($s['name']) ?></option><?php endforeach; ?></select></div>
            <div class="col-md-6"><label class="form-label" for="account-form-field-billing_address">Billing address</label><textarea class="form-control" id="account-form-field-billing_address" name="billing_address" rows="2" maxlength="500"><?= e((string) $v['billing_address']) ?></textarea></div>
            <div class="col-md-6"><label class="form-label" for="account-form-field-shipping_address">Shipping address</label><textarea class="form-control" id="account-form-field-shipping_address" name="shipping_address" rows="2" maxlength="500"><?= e((string) $v['shipping_address']) ?></textarea></div>
            <div class="col-md-6"><label class="form-label" id="account-form-parent-label" for="account-form-field-parent">Parent account</label>
                <?= picker_field(['id' => 'account-form-field-parent', 'name' => 'parent_account', 'source' => 'account', 'value' => $v['parent_account_id'], 'params' => $editing ? ['except' => $id] : [], 'labelledby' => 'account-form-parent-label', 'title' => 'The parent account', 'placeholder' => 'None — a company of its own']) ?></div>
            <div class="col-md-6"><label class="form-label" id="account-form-owner-label" for="account-form-field-owner">Owner</label>
                <?= picker_field(['id' => 'account-form-field-owner', 'name' => 'owner', 'source' => 'owner', 'value' => $v['owner_member_id'], 'labelledby' => 'account-form-owner-label', 'title' => 'The owner', 'placeholder' => 'Nobody yet']) ?></div>
            <div class="col-12"><label class="form-label" for="account-form-field-description">About</label><textarea class="form-control" id="account-form-field-description" name="description" rows="3" maxlength="5000"><?= e((string) $v['description']) ?></textarea></div>
            <?php if (!$editing && $may_tag): ?><div class="col-12"><label class="form-label" for="account-form-field-tags">Tags</label><input type="text" class="form-control btn-touch" id="account-form-field-tags" name="tags" value="" placeholder="enterprise, emea" maxlength="300"><div class="form-text">Separate with commas; a new one is made.</div></div><?php endif; ?>
        </div></div></div>
        <?= $custom_html ?>
        <div class="d-flex gap-2 mb-3">
            <button type="submit" class="btn btn-primary btn-touch flex-grow-1" id="account-form-save-btn"><?= $editing ? 'Save' : 'Add the account' ?></button>
            <?= hx_link($editing ? '/accounts/' . $id : '/accounts', 'Cancel', 'btn btn-light btn-touch', 'id="account-form-cancel-btn"') ?>
        </div>
    </form>
    <?php if ($restriction !== null): ?><?= view('shared/restriction.php', $restriction) ?><?php endif; ?>
</div>
