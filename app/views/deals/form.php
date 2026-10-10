<?php /** A deal's form (deal-add / deal-edit): title, account and primary contact (pickers), pipeline and stage, value or value from the lines, currency, close date,
 * owner, source, campaign, the probability override (edit), description, tags (add), custom fields for the pipeline; the restriction (managers).
 * Data: d, values, pipelines, stages, sources, custom_html, restriction, may_tag, may_owner, currency, focus */
$editing = $d !== null;
$id = $editing ? (int) $d['deal_id'] : null;
$v = $values;
$closed = ($v['status'] ?? 'open') !== 'open';
$back = $editing ? ['/deals/' . $id, 'the deal'] : [pipeline_board_url(), 'the board']; ?>
<?= view('shared/header.php', ['id' => 'deal-form', 'title' => $editing ? 'Edit ' . $d['title'] : 'New deal', 'crumbs' => [['Today', '/'], ['Pipeline', pipeline_board_url()], [$editing ? 'Edit' : 'New deal', null]], 'back' => $back]) ?>
<div class="main-content" id="deal-form-content">
    <form method="post" action="/deals/save.php" hx-post="/deals/save.php" hx-target="#flash" id="deal-form">
        <?= csrf_field() ?><?php if ($editing): ?><input type="hidden" name="deal" value="<?= $id ?>"><input type="hidden" name="return_to" value="/deals/<?= $id ?>"><?php endif; ?>
        <div class="card mb-3"><div class="card-body"><div class="row g-3">
            <div class="col-12"><label class="form-label" for="deal-form-field-title">Title</label><input type="text" class="form-control btn-touch" id="deal-form-field-title" name="title" maxlength="200" required value="<?= e((string) $v['title']) ?>"<?= $focus === '' ? ' autofocus' : '' ?>></div>
            <div class="col-md-6"><label class="form-label" id="deal-form-account-label" for="deal-form-field-account">Account</label>
                <?= picker_field(['id' => 'deal-form-field-account', 'name' => 'account', 'source' => 'account', 'value' => $v['account_id'], 'labelledby' => 'deal-form-account-label', 'title' => 'The account', 'placeholder' => 'None']) ?></div>
            <div class="col-md-6"><label class="form-label" id="deal-form-contact-label" for="deal-form-field-primary_contact">Primary contact</label>
                <?= picker_field(['id' => 'deal-form-field-primary_contact', 'name' => 'primary_contact', 'source' => 'contact', 'value' => $v['primary_contact_id'], 'labelledby' => 'deal-form-contact-label', 'title' => 'The primary contact', 'placeholder' => 'None',
                    'include' => '#deal-form-field-account']) ?></div>
            <div class="col-md-6"><label class="form-label" for="deal-form-field-pipeline">Pipeline</label><select class="form-select btn-touch" id="deal-form-field-pipeline" name="pipeline"<?= $closed ? ' disabled' : '' ?>>
                <?php foreach ($pipelines as $p): ?><option value="<?= (int) $p['pipeline_id'] ?>"<?= (int) $p['pipeline_id'] === (int) $v['pipeline_id'] ? ' selected' : '' ?>><?= e($p['name']) ?></option><?php endforeach; ?></select></div>
            <div class="col-md-6"><label class="form-label" for="deal-form-field-stage">Stage</label><select class="form-select btn-touch" id="deal-form-field-stage" name="stage"<?= $closed ? ' disabled' : '' ?>>
                <?php foreach ($stages as $s): ?><option value="<?= (int) $s['stage_id'] ?>"<?= (int) $s['stage_id'] === (int) $v['stage_id'] ? ' selected' : '' ?><?= $s['kind'] !== 'open' ? ' disabled' : '' ?>><?= e($s['name']) ?> · <?= (int) $s['probability'] ?>%<?= $s['required_fields'] !== [] ? ' — needs ' . e(implode(', ', array_map(static fn ($f) => stage_field_words(db(), $f), $s['required_fields']))) : '' ?></option><?php endforeach; ?></select>
                <div class="fs-11 text-muted">Changing the pipeline puts the deal in its first stage.</div></div>
            <div class="col-md-4"><label class="form-label" for="deal-form-field-value">Value</label><input type="number" step="any" min="0" class="form-control btn-touch" id="deal-form-field-value" name="value" value="<?= e((string) $v['value']) ?>"<?= $v['value_from_products'] ? ' readonly' : '' ?>></div>
            <div class="col-md-2"><label class="form-label" for="deal-form-field-currency">Currency</label><input type="text" maxlength="3" class="form-control btn-touch text-uppercase" id="deal-form-field-currency" name="currency" placeholder="<?= e($currency) ?>" value="<?= e((string) ($v['currency'] ?? '')) ?>"></div>
            <div class="col-md-6"><label class="form-label" for="deal-form-field-expected_close_date">Expected close</label><input type="date" class="form-control btn-touch" id="deal-form-field-expected_close_date" name="expected_close_date" value="<?= e((string) $v['expected_close_date']) ?>"></div>
            <?php if ($editing): ?>
            <div class="col-md-6"><input type="hidden" name="value_from_products" value="no"><label class="form-check mt-md-4"><input type="checkbox" class="form-check-input" name="value_from_products" value="yes" id="deal-form-field-value_from_products"<?= $v['value_from_products'] ? ' checked' : '' ?>> The value is the products' total</label></div>
            <div class="col-md-6"><label class="form-label" for="deal-form-field-probability_override">Probability, set by hand (%)</label><input type="number" min="0" max="100" class="form-control btn-touch" id="deal-form-field-probability_override" name="probability_override" placeholder="The stage's" value="<?= e((string) ($v['probability_override'] ?? '')) ?>"<?= $focus === 'probability' ? ' autofocus' : '' ?>>
                <div class="fs-11 text-muted">Blank follows the stage.</div></div>
            <?php endif; ?>
            <div class="col-md-6"><label class="form-label" id="deal-form-owner-label" for="deal-form-field-owner">Owner</label>
                <?php if ($may_owner): ?><?= picker_field(['id' => 'deal-form-field-owner', 'name' => 'owner', 'source' => 'owner', 'value' => $v['owner_member_id'], 'labelledby' => 'deal-form-owner-label', 'title' => 'The owner', 'placeholder' => 'Unowned']) ?>
                <?php else: ?><div class="form-control-plaintext"><?= e((string) (member_name(db(), $v['owner_member_id']) ?? 'Unowned')) ?></div><?php endif; ?></div>
            <div class="col-md-6"><label class="form-label" for="deal-form-field-source">Source</label><select class="form-select btn-touch" id="deal-form-field-source" name="source"><option value="">—</option>
                <?php foreach ($sources as $s): ?><option value="<?= (int) $s['source_id'] ?>"<?= (int) ($v['source_id'] ?? 0) === (int) $s['source_id'] ? ' selected' : '' ?>><?= e($s['name']) ?></option><?php endforeach; ?></select></div>
            <div class="col-md-6"><label class="form-label" id="deal-form-campaign-label" for="deal-form-field-campaign">Campaign</label>
                <?= picker_field(['id' => 'deal-form-field-campaign', 'name' => 'campaign', 'source' => 'campaign', 'value' => $v['campaign_id'], 'labelledby' => 'deal-form-campaign-label', 'title' => 'The campaign', 'placeholder' => 'None']) ?></div>
            <?php if (!$editing && $may_tag): ?><div class="col-md-6"><label class="form-label" for="deal-form-field-tags">Tags</label><input type="text" class="form-control btn-touch" id="deal-form-field-tags" name="tags" maxlength="300" placeholder="upsell, q4"></div><?php endif; ?>
            <div class="col-12"><label class="form-label" for="deal-form-field-description">Description</label><textarea class="form-control" id="deal-form-field-description" name="description" rows="3" maxlength="10000"><?= e((string) $v['description']) ?></textarea></div>
        </div></div></div>
        <?= $custom_html ?>
        <div class="d-flex gap-2 mb-3"><button type="submit" class="btn btn-primary btn-touch flex-grow-1" id="deal-form-save-btn"><?= $editing ? 'Save' : 'Add the deal' ?></button><?= hx_link($back[0], 'Cancel', 'btn btn-light btn-touch', 'id="deal-form-cancel-btn"') ?></div>
    </form>
    <?php if ($restriction !== null): ?><?= view('shared/restriction.php', $restriction) ?><?php endif; ?>
</div>
