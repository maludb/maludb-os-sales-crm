<?php /** One campaign (campaign-view): the header, the results tiles, its email (queued · sent · failed and the form that queues it), Add members, the members with their status, what it produced, notes.
 * Data: c, results, members, counts, batches, produced, status, page, may, notes, me, tz, notice, back */
$cid = (int) $c['campaign_id'];
$badge = CAMPAIGN_STATUS_BADGE[$c['status']] ?? 'secondary';
$emailable = !in_array($c['status'], ['cancelled', 'done'], true); ?>
<div class="page-header" id="campaign-view-header">
    <div class="page-header-left d-flex align-items-center min-w-0">
        <div class="page-header-title min-w-0"><h5 class="m-b-10 text-truncate" id="campaign-view-name"><?= e($c['name']) ?></h5>
            <div class="fs-12" id="campaign-view-status"><span class="badge bg-soft-<?= e($badge) ?> text-<?= e($badge) ?>"><?= e(CAMPAIGN_STATUSES[$c['status']] ?? $c['status']) ?></span>
                <span class="text-muted"><?= e(CAMPAIGN_KINDS[$c['kind']] ?? $c['kind']) ?><?= campaign_dates_words($c) !== '' ? ' · ' . e(campaign_dates_words($c)) : '' ?> · <?= e((string) ($c['owner_name'] ?? 'No owner')) ?></span></div></div>
    </div>
    <?php if ($may['manage']): ?><div class="page-header-right ms-auto d-flex flex-wrap gap-1 align-items-center">
        <?= hx_link('/campaigns/' . $cid . '/edit', '<i class="feather-edit-2 me-1"></i>Edit', 'btn btn-light btn-touch', 'id="campaign-edit-btn"') ?>
        <div class="dropdown"><button type="button" class="btn btn-light btn-touch" data-bs-toggle="dropdown" aria-expanded="false" id="campaign-more-btn" aria-label="More"><i class="feather-more-horizontal"></i></button>
            <div class="dropdown-menu dropdown-menu-end">
                <form method="post" action="/records/delete.php" hx-post="/records/delete.php" hx-target="#flash" hx-confirm="Delete <?= e($c['name']) ?>?"><?= csrf_field() ?><input type="hidden" name="kind" value="campaign"><input type="hidden" name="record" value="<?= $cid ?>">
                    <button type="submit" class="dropdown-item text-danger" id="campaign-delete-btn"><i class="feather-trash-2 me-2"></i>Delete</button></form>
            </div></div></div><?php endif; ?>
</div>
<?php if (($b = back_link()) !== null): ?><div class="px-3 pb-2"><?= hx_link($b[0], '<i class="feather-arrow-left me-1"></i>Back to ' . e($b[1]), 'fs-12 fw-semibold', 'id="campaign-view-back"') ?></div><?php endif; ?>
<div class="main-content" id="campaign-view-content">
    <?= view('shared/notice.php', ['notice' => $notice]) ?>
    <?php if ($c['description']): ?><div class="card mb-3"><div class="card-body text-break" id="campaign-description" style="white-space: pre-wrap"><?= e($c['description']) ?></div></div><?php endif; ?>
    <?= view('campaigns/partials/results.php', ['c' => $c, 'results' => $results, 'counts' => $counts]) ?>

    <div class="card mb-3" id="campaign-email">
        <div class="card-header d-flex flex-wrap gap-2 align-items-center"><span class="fw-semibold">Email</span>
            <span class="ms-auto d-flex flex-wrap gap-1 fs-12">
                <span class="badge bg-soft-secondary text-secondary" id="campaign-email-queued"><?= (int) $batches['queued'] ?> waiting</span>
                <span class="badge bg-soft-success text-success" id="campaign-email-sent"><?= (int) $batches['sent'] ?> sent</span>
                <span class="badge bg-soft-<?= $batches['failed'] > 0 ? 'danger text-danger' : 'secondary text-secondary' ?>" id="campaign-email-failed"><?= (int) $batches['failed'] ?> not sent</span></span></div>
        <div class="card-body">
            <?php if ($batches['latest_failures'] !== []): ?><ul class="fs-12 text-danger mb-3" id="campaign-email-failures">
                <?php foreach ($batches['latest_failures'] as $f): ?><li><?= e((string) ($f['name'] ?? $f['address'])) ?> — <?= e((string) ($f['error'] ?? 'not sent')) ?></li><?php endforeach; ?></ul><?php endif; ?>
            <?php if ($may['email'] && $emailable): ?>
            <form method="post" action="/campaigns/email.php" hx-post="/campaigns/email.php" hx-target="#flash" id="campaign-email-form" class="row g-2 align-items-end"><?= csrf_field() ?>
                <input type="hidden" name="campaign" value="<?= $cid ?>"><input type="hidden" name="return_to" value="/campaigns/<?= $cid ?>">
                <div class="col-12 col-md-5"><label class="form-label" id="campaign-email-template-label" for="campaign-email-template">Template</label>
                    <?= picker_field(['id' => 'campaign-email-template', 'name' => 'template', 'source' => 'template', 'required' => true, 'labelledby' => 'campaign-email-template-label', 'title' => 'The email to send', 'placeholder' => 'Choose a template']) ?></div>
                <div class="col-12 col-md-3"><label class="form-label" for="campaign-email-members">To</label>
                    <select name="members" id="campaign-email-members" class="form-select btn-touch"><option value="planned">Members still planned (<?= (int) ($counts['planned'] ?? 0) ?>)</option><option value="all">Every member</option></select></div>
                <div class="col-12 col-md-4"><div class="form-check mb-2"><input class="form-check-input" type="checkbox" name="confirm" value="yes" id="campaign-email-confirm" required><label class="form-check-label fs-12" for="campaign-email-confirm">Queue it — each person gets their own, with an unsubscribe link</label></div>
                    <button type="submit" class="btn btn-primary btn-touch w-100" id="campaign-email-send-btn"><i class="feather-mail me-1"></i>Email members</button></div>
            </form>
            <div class="fs-11 text-muted mt-2">People who opted out or have no address are skipped. The messages go out within a minute or two.</div>
            <?php elseif (!$emailable): ?><div class="fs-12 text-muted">A <?= e(strtolower(CAMPAIGN_STATUSES[$c['status']])) ?> campaign is not emailed; make it active first.</div>
            <?php elseif (!$may['email']): ?><div class="fs-12 text-muted">Emailing a campaign needs the right to send email.</div><?php endif; ?>
        </div>
    </div>

    <?php if ($may['add']): ?>
    <div class="card mb-3" id="campaign-add-members"><div class="card-header fw-semibold">Add members</div><div class="card-body">
        <div class="row g-3">
            <form method="post" action="/campaigns/members/add.php" hx-post="/campaigns/members/add.php" hx-target="#flash" class="col-12 col-md-6" id="campaign-add-contact-form"><?= csrf_field() ?>
                <input type="hidden" name="campaign" value="<?= $cid ?>"><input type="hidden" name="return_to" value="/campaigns/<?= $cid ?>">
                <label class="form-label" id="campaign-add-contact-label" for="campaign-add-contact">A contact</label>
                <div class="d-flex gap-2"><div class="flex-grow-1"><?= picker_field(['id' => 'campaign-add-contact', 'name' => 'contact', 'source' => 'contact', 'required' => true, 'labelledby' => 'campaign-add-contact-label', 'title' => 'A contact', 'placeholder' => 'Choose a contact']) ?></div>
                    <button type="submit" class="btn btn-primary btn-touch" id="campaign-add-contact-btn">Add</button></div></form>
            <form method="post" action="/campaigns/members/add.php" hx-post="/campaigns/members/add.php" hx-target="#flash" class="col-12 col-md-6" id="campaign-add-lead-form"><?= csrf_field() ?>
                <input type="hidden" name="campaign" value="<?= $cid ?>"><input type="hidden" name="return_to" value="/campaigns/<?= $cid ?>">
                <label class="form-label" id="campaign-add-lead-label" for="campaign-add-lead">A lead</label>
                <div class="d-flex gap-2"><div class="flex-grow-1"><?= picker_field(['id' => 'campaign-add-lead', 'name' => 'lead', 'source' => 'lead', 'required' => true, 'labelledby' => 'campaign-add-lead-label', 'title' => 'A lead', 'placeholder' => 'Choose a lead']) ?></div>
                    <button type="submit" class="btn btn-primary btn-touch" id="campaign-add-lead-btn">Add</button></div></form>
            <?php if ($may['manage'] && has_right('lists.own')): ?>
            <form method="post" action="/bulk/campaign.php" id="campaign-add-list-form" class="col-12"><?= csrf_field() ?>
                <input type="hidden" name="campaign" value="<?= $cid ?>"><input type="hidden" name="return_to" value="/campaigns/<?= $cid ?>">
                <label class="form-label" id="campaign-add-list-label" for="campaign-add-list">Everyone on a list</label>
                <div class="row g-2"><div class="col-12 col-md-5"><?= picker_field(['id' => 'campaign-add-list', 'name' => 'list', 'source' => 'list', 'required' => true, 'params' => ['any' => 1], 'labelledby' => 'campaign-add-list-label', 'title' => 'A list of contacts or leads', 'placeholder' => 'Choose a list']) ?></div>
                    <div class="col-6 col-md-3"><label class="visually-hidden" for="campaign-add-list-kind">They are</label><select name="kind" id="campaign-add-list-kind" class="form-select btn-touch"><option value="contact">Contacts</option><option value="lead">Leads</option></select></div>
                    <div class="col-6 col-md-4"><button type="submit" class="btn btn-light btn-touch w-100" id="campaign-add-list-btn">Add them all</button></div></div>
                <div class="fs-11 text-muted mt-1">As planned. A smart list is read as it is now.</div></form>
            <?php endif; ?>
        </div>
    </div></div>
    <?php endif; ?>

    <?= view('campaigns/partials/members.php', ['c' => $c, 'members' => $members, 'counts' => $counts, 'status' => $status, 'page' => $page, 'may' => $may, 'tz' => $tz]) ?>

    <div class="row g-3 mb-3" id="campaign-produced">
        <div class="col-md-6"><div class="card h-100 mb-0"><div class="card-header fw-semibold">Leads it brought <span class="badge bg-soft-secondary text-secondary"><?= (int) $results['leads'] ?></span></div>
            <ul class="list-group list-group-flush" id="campaign-produced-leads">
                <?php if ($produced['leads'] === []): ?><li class="list-group-item text-muted fs-12">None yet. A lead carries the campaign it came from.</li><?php endif; ?>
                <?php foreach ($produced['leads'] as $l): ?><li class="list-group-item d-flex justify-content-between gap-2 fs-12"><?= hx_link(with_back('/leads/' . (int) $l['lead_id'], '/campaigns/' . $cid), e((string) $l['label']), 'fw-semibold text-dark') ?>
                    <span class="badge bg-soft-<?= e(LEAD_STATUS_BADGE[$l['status']] ?? 'secondary') ?> text-<?= e(LEAD_STATUS_BADGE[$l['status']] ?? 'secondary') ?>"><?= e(LEAD_STATUSES[$l['status']] ?? $l['status']) ?></span></li><?php endforeach; ?></ul></div></div>
        <div class="col-md-6"><div class="card h-100 mb-0"><div class="card-header fw-semibold">Deals it brought <span class="badge bg-soft-secondary text-secondary"><?= (int) $results['deals'] ?></span></div>
            <ul class="list-group list-group-flush" id="campaign-produced-deals">
                <?php if ($produced['deals'] === []): ?><li class="list-group-item text-muted fs-12">None yet.</li><?php endif; ?>
                <?php foreach ($produced['deals'] as $d): ?><li class="list-group-item d-flex justify-content-between gap-2 fs-12"><span class="min-w-0 text-truncate"><?= hx_link(with_back('/deals/' . (int) $d['deal_id'], '/campaigns/' . $cid), e($d['number'] . ' · ' . $d['title']), 'fw-semibold text-dark') ?></span>
                    <span class="text-nowrap"><?= e(money($d['value'], $d['currency'])) ?> <span class="badge bg-soft-<?= e(DEAL_STATUS_BADGE[$d['status']] ?? 'secondary') ?> text-<?= e(DEAL_STATUS_BADGE[$d['status']] ?? 'secondary') ?>"><?= e(DEAL_STATUSES[$d['status']] ?? $d['status']) ?></span></span></li><?php endforeach; ?></ul></div></div>
    </div>
    <?= view('shared/notes-panel.php', ['kind' => 'campaign', 'id' => $cid, 'notes' => $notes, 'may' => ['add' => true, 'delete_any' => $may['manager']], 'me' => $me, 'tz' => $tz, 'back' => '/campaigns/' . $cid]) ?>
</div>
