<?php /** A lead's form (lead-add / lead-edit): fields per lead_create, the source (a select — a short list), the campaign and owner pickers, custom fields; the restriction. Data: l, values, sources, may_tag, custom_html, restriction */
$editing = $l !== null;
$id = $editing ? (int) $l['lead_id'] : null;
$v = $values;
$in = static fn (string $name, string $label, string $type = 'text', string $attrs = '', string $col = 'col-md-6') => '<div class="' . $col . '"><label class="form-label" for="lead-form-field-' . $name . '">' . e($label) . '</label>'
    . '<input type="' . $type . '" class="form-control btn-touch" id="lead-form-field-' . $name . '" name="' . $name . '" value="' . e((string) ($v[$name] ?? '')) . '"' . $attrs . '></div>'; ?>
<?= view('shared/header.php', ['id' => 'lead-form', 'title' => $editing ? 'Edit ' . $l['label'] : 'New lead', 'crumbs' => [['Today', '/'], ['Leads', '/leads'], [$editing ? 'Edit' : 'New', null]], 'back' => [$editing ? '/leads/' . $id : '/leads', $editing ? 'the lead' : 'Leads']]) ?>
<div class="main-content" id="lead-form-content">
    <form method="post" action="/leads/save.php" hx-post="/leads/save.php" hx-target="#flash" id="lead-form">
        <?= csrf_field() ?><?php if ($editing): ?><input type="hidden" name="lead" value="<?= $id ?>"><input type="hidden" name="return_to" value="/leads/<?= $id ?>"><?php endif; ?>
        <div class="card mb-3"><div class="card-body"><div class="row g-3">
            <?= $in('first_name', 'First name', 'text', ' maxlength="100" autofocus') ?><?= $in('last_name', 'Last name', 'text', ' maxlength="100"') ?>
            <?= $in('company', 'Company', 'text', ' maxlength="200"') ?><?= $in('title', 'Title', 'text', ' maxlength="150"') ?>
            <?= $in('email', 'Email', 'email', ' maxlength="254"') ?><?= $in('phone', 'Phone', 'tel', ' maxlength="40"') ?>
            <div class="col-12 fs-11 text-muted mt-0">A company, a name or an email is enough.</div>
            <?= $in('website', 'Website', 'url', ' maxlength="300"') ?><?= $in('city', 'City', 'text', ' maxlength="120"') ?><?= $in('region', 'Region', 'text', ' maxlength="120"') ?><?= $in('country', 'Country', 'text', ' maxlength="2" placeholder="US"') ?>
            <div class="col-md-6"><label class="form-label" for="lead-form-field-source">Source</label><select class="form-select btn-touch" id="lead-form-field-source" name="source"><option value="">—</option>
                <?php foreach ($sources as $s): ?><option value="<?= (int) $s['source_id'] ?>" <?= (int) ($v['source_id'] ?? 0) === (int) $s['source_id'] ? 'selected' : '' ?>><?= e($s['name']) ?></option><?php endforeach; ?></select></div>
            <div class="col-md-6"><label class="form-label" id="lead-form-campaign-label" for="lead-form-field-campaign">Campaign</label>
                <?= picker_field(['id' => 'lead-form-field-campaign', 'name' => 'campaign', 'source' => 'campaign', 'value' => $v['campaign_id'], 'labelledby' => 'lead-form-campaign-label', 'title' => 'The campaign', 'placeholder' => 'None']) ?></div>
            <div class="col-md-6"><label class="form-label" id="lead-form-owner-label" for="lead-form-field-owner">Owner</label>
                <?= picker_field(['id' => 'lead-form-field-owner', 'name' => 'owner', 'source' => 'owner', 'value' => $editing ? $v['owner_member_id'] : null, 'labelledby' => 'lead-form-owner-label', 'title' => 'The owner', 'placeholder' => $editing ? 'Unowned' : 'The assignment rules decide']) ?></div>
            <?php if (!$editing && $may_tag): ?><div class="col-md-6"><label class="form-label" for="lead-form-field-tags">Tags</label><input type="text" class="form-control btn-touch" id="lead-form-field-tags" name="tags" maxlength="300" placeholder="webinar, emea"></div><?php endif; ?>
            <div class="col-12"><label class="form-label" for="lead-form-field-message">Their message</label><textarea class="form-control" id="lead-form-field-message" name="message" rows="3" maxlength="2000"><?= e((string) $v['message']) ?></textarea></div>
        </div></div></div>
        <?= $custom_html ?>
        <div class="d-flex gap-2 mb-3"><button type="submit" class="btn btn-primary btn-touch flex-grow-1" id="lead-form-save-btn"><?= $editing ? 'Save' : 'Add the lead' ?></button><?= hx_link($editing ? '/leads/' . $id : '/leads', 'Cancel', 'btn btn-light btn-touch', 'id="lead-form-cancel-btn"') ?></div>
    </form>
    <?php if ($restriction !== null): ?><?= view('shared/restriction.php', $restriction) ?><?php endif; ?>
</div>
