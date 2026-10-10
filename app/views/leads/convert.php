<?php /** The conversion form (lead-convert, leads-and-conversion.md §3): what it will find and make, then the deal. Data: lead, preview, pipelines */
$lid = (int) $lead['lead_id'];
$p = $preview;
$dd = $p['deal_defaults']; ?>
<?= view('shared/header.php', ['id' => 'lead-convert', 'title' => 'Convert ' . $lead['label'], 'crumbs' => [['Today', '/'], ['Leads', '/leads'], ['Convert', null]], 'back' => ['/leads/' . $lid, 'the lead']]) ?>
<div class="main-content" id="lead-convert-content">
    <form method="post" action="/leads/<?= $lid ?>/convert" hx-post="/leads/<?= $lid ?>/convert" hx-target="#flash" id="convert-form">
        <?= csrf_field() ?><input type="hidden" name="lead" value="<?= $lid ?>">
        <p class="fs-12 text-muted">Converting makes the lead into the relationship's records — once. The lead stays, read-only, pointing at them.</p>
        <div class="row g-3"><div class="col-lg-6"><?= view('leads/partials/convert-found.php', ['kind' => 'account', 'found' => $p['account'], 'made' => $p['account_new']]) ?></div>
            <div class="col-lg-6"><?= view('leads/partials/convert-found.php', ['kind' => 'contact', 'found' => $p['contact'], 'made' => $p['contact_new']]) ?></div></div>
        <div class="card mb-3" id="convert-deal"><div class="card-body"><div class="row g-3">
            <div class="col-12"><input type="hidden" name="make_deal" value="no"><label class="d-flex align-items-center gap-2 border rounded px-3 btn-touch" for="convert-form-field-make_deal"><input type="checkbox" class="form-check-input mt-0" name="make_deal" value="yes" id="convert-form-field-make_deal" checked>Make a deal</label></div>
            <div class="col-md-6"><label class="form-label" for="convert-form-field-pipeline">Pipeline</label><select class="form-select btn-touch" name="pipeline" id="convert-form-field-pipeline">
                <?php foreach ($pipelines as $pl): ?><option value="<?= (int) $pl['pipeline_id'] ?>" <?= (int) $pl['pipeline_id'] === (int) $dd['pipeline_id'] ? 'selected' : '' ?>><?= e($pl['name']) ?></option><?php endforeach; ?></select></div>
            <div class="col-md-6"><label class="form-label" for="convert-form-field-deal_title">Deal</label><input type="text" class="form-control btn-touch" name="deal_title" id="convert-form-field-deal_title" value="<?= e($dd['title']) ?>" maxlength="200"></div>
            <div class="col-6"><label class="form-label" for="convert-form-field-value">Value (<?= e($dd['currency']) ?>)</label><input type="number" min="0" step="any" class="form-control btn-touch" name="value" id="convert-form-field-value"></div>
            <div class="col-6"><label class="form-label" for="convert-form-field-close_date">Expected to close</label><input type="date" class="form-control btn-touch" name="close_date" id="convert-form-field-close_date"></div>
        </div></div></div>
        <div class="d-flex gap-2 mb-3"><button type="submit" class="btn btn-primary btn-touch flex-grow-1" id="convert-form-save-btn"><i class="feather-shuffle me-1"></i>Convert</button><?= hx_link('/leads/' . $lid, 'Cancel', 'btn btn-light btn-touch', 'id="convert-form-cancel-btn"') ?></div>
    </form>
</div>
