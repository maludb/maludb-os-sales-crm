<?php /** A campaign's form (campaign-add / campaign-edit). Data: c (null on add), values, base_currency */
$editing = $c !== null;
$id = $editing ? (int) $c['campaign_id'] : null;
$v = $values;
$in = static fn (string $name, string $label, string $type = 'text', array $o = []) => '<div class="' . ($o['col'] ?? 'col-md-6') . '"><label class="form-label" for="campaign-form-field-' . $name . '">' . e($label) . '</label>'
    . '<input type="' . $type . '" class="form-control btn-touch" id="campaign-form-field-' . $name . '" name="' . $name . '" value="' . e((string) ($v[$name] ?? '')) . '"' . ($o['attrs'] ?? '') . '>' . (isset($o['help']) ? '<div class="form-text">' . e($o['help']) . '</div>' : '') . '</div>'; ?>
<?= view('shared/header.php', ['id' => 'campaign-form', 'title' => $editing ? 'Edit ' . $c['name'] : 'New campaign', 'crumbs' => [['Today', '/'], ['Campaigns', '/campaigns'], [$editing ? 'Edit' : 'New', null]],
    'back' => [$editing ? '/campaigns/' . $id : '/campaigns', $editing ? $c['name'] : 'Campaigns']]) ?>
<div class="main-content" id="campaign-form-content">
    <form method="post" action="/campaigns/save.php" hx-post="/campaigns/save.php" hx-target="#flash" id="campaign-form">
        <?= csrf_field() ?>
        <?php if ($editing): ?><input type="hidden" name="campaign" value="<?= $id ?>"><input type="hidden" name="return_to" value="/campaigns/<?= $id ?>"><?php endif; ?>
        <div class="card mb-3"><div class="card-body"><div class="row g-3">
            <div class="col-12"><label class="form-label" for="campaign-form-field-name">Name</label>
                <input type="text" class="form-control btn-touch" id="campaign-form-field-name" name="name" value="<?= e((string) $v['name']) ?>" maxlength="200" required autofocus></div>
            <div class="col-md-6"><label class="form-label" for="campaign-form-field-kind">Kind</label>
                <select class="form-select btn-touch" id="campaign-form-field-kind" name="kind"><?php foreach (CAMPAIGN_KINDS as $k => $l): ?><option value="<?= e($k) ?>" <?= $v['kind'] === $k ? 'selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select></div>
            <div class="col-md-6"><label class="form-label" for="campaign-form-field-status">Status</label>
                <select class="form-select btn-touch" id="campaign-form-field-status" name="status"><?php foreach (CAMPAIGN_STATUSES as $k => $l): ?><option value="<?= e($k) ?>" <?= $v['status'] === $k ? 'selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select></div>
            <?= $in('starts_on', 'Starts', 'date') ?><?= $in('ends_on', 'Ends', 'date') ?>
            <?= $in('budget', 'Budget', 'number', ['attrs' => ' min="0" step="any"', 'help' => 'What it costs; the cost of a lead and of a won deal come from it.']) ?>
            <?= $in('currency', 'Currency', 'text', ['attrs' => ' maxlength="3" placeholder="' . e($base_currency) . '"']) ?>
            <?= $in('expected_leads', 'Leads hoped for', 'number', ['attrs' => ' min="0" step="1"']) ?>
            <?= $in('utm_campaign', 'utm campaign', 'text', ['attrs' => ' maxlength="120"', 'help' => 'The campaign name your links carry, for leads that arrive from the website.']) ?>
            <div class="col-12"><label class="form-label" id="campaign-form-owner-label" for="campaign-form-field-owner">Owner</label>
                <?= picker_field(['id' => 'campaign-form-field-owner', 'name' => 'owner', 'source' => 'owner', 'value' => $v['owner_member_id'], 'labelledby' => 'campaign-form-owner-label', 'title' => 'The owner', 'placeholder' => 'Nobody yet']) ?></div>
            <div class="col-12"><label class="form-label" for="campaign-form-field-description">About</label><textarea class="form-control" id="campaign-form-field-description" name="description" rows="3" maxlength="5000"><?= e((string) $v['description']) ?></textarea></div>
        </div></div></div>
        <div class="d-flex gap-2 mb-3">
            <button type="submit" class="btn btn-primary btn-touch flex-grow-1" id="campaign-form-save-btn"><?= $editing ? 'Save' : 'Add the campaign' ?></button>
            <?= hx_link($editing ? '/campaigns/' . $id : '/campaigns', 'Cancel', 'btn btn-light btn-touch', 'id="campaign-form-cancel-btn"') ?>
        </div>
    </form>
</div>
