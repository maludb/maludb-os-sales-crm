<?php /** One deal (deal-view): the header, the stage bar, the tabs by anchor (Overview · Activities · Emails · Notes & files · History).
 * Data: everything html/deals/view.php gathers. */
$id = (int) $d['deal_id'];
$closed = $d['status'] !== 'open';
$restricted = $d['restricted_to_member_id'] !== null || $d['restricted_to_department_id'] !== null;
$tabs = ['overview' => 'Overview', 'activities' => 'Activities', 'emails' => 'Emails', 'notes' => 'Notes & files', 'history' => 'History'];
$counts = ['activities' => count($activities['open']), 'emails' => count($emails), 'notes' => count($notes) + count($files)];
$quick = [
    ['/log/call?deal=' . $id, '/log/call.php', 'feather-phone', 'Log call', 'deal-log-call-btn', $may['log']],
    ['/activities/new?deal=' . $id, '/activities/form.php', 'feather-clock', 'Schedule', 'deal-schedule-btn', $may['log']],
    ['/email/compose?deal=' . $id, '/email/compose.php', 'feather-mail', 'Send email', 'deal-email-btn', $may['email']],
];
$field = static fn (string $label, string $html, string $fid) => '<div class="col-sm-6 col-lg-4 mb-2" id="deal-field-' . $fid . '"><div class="fs-11 text-muted">' . e($label) . '</div><div class="text-break">' . ($html === '' ? '<span class="text-muted">—</span>' : $html) . '</div></div>'; ?>
<div class="page-header" id="deal-view-header">
    <div class="page-header-left d-flex align-items-center min-w-0">
        <div class="page-header-title min-w-0"><h5 class="m-b-10 text-truncate" id="deal-view-title"><?php if ($restricted): ?><i class="feather-lock text-warning me-1" title="Restricted" id="deal-view-lock"></i><?php endif; ?><?= e($d['title']) ?></h5>
            <div class="fs-12 d-flex flex-wrap gap-1 align-items-center" id="deal-view-sub"><span class="text-muted"><?= e($d['number']) ?></span>
                <span class="badge text-bg-<?= DEAL_STATUS_BADGE[$d['status']] ?>" id="deal-view-status"><?= $d['status'] === 'open' ? e($d['stage_name']) : DEAL_STATUSES[$d['status']] ?></span>
                <?php if ($d['account_id'] !== null): ?><?= hx_link('/accounts/' . (int) $d['account_id'], '<i class="feather-briefcase me-1"></i>' . e((string) $d['account_name']), 'text-dark', 'id="deal-view-account"') ?><?php endif; ?></div></div>
    </div>
    <div class="page-header-right ms-auto d-flex flex-wrap gap-1 align-items-center">
        <?php foreach ($quick as [$url, $file, $icon, $label, $bid, $allowed]): if (!$allowed || !nav_built($file) || $closed) { continue; } ?>
            <?= hx_link($url, '<i class="' . $icon . ' me-1"></i><span class="d-none d-sm-inline">' . e($label) . '</span>', 'btn btn-light btn-touch', 'id="' . $bid . '" aria-label="' . e($label) . '"') ?>
        <?php endforeach; ?>
        <?php if (!$closed && $may['edit']): ?>
            <?= hx_link('/deals/' . $id . '/won', '<i class="feather-award me-1"></i>Won', 'btn btn-success btn-touch', 'id="deal-won-btn"') ?>
            <?= hx_link('/deals/' . $id . '/lost', '<i class="feather-x-circle me-1"></i>Lost', 'btn btn-outline-danger btn-touch', 'id="deal-lost-btn"') ?>
        <?php elseif ($closed && $may['reopen']): ?>
            <form method="post" action="/deals/reopen.php" hx-post="/deals/reopen.php" hx-target="#flash" hx-confirm="Reopen <?= e($d['title']) ?>?"><?= csrf_field() ?><input type="hidden" name="deal" value="<?= $id ?>">
                <button type="submit" class="btn btn-light btn-touch" id="deal-reopen-btn"><i class="feather-rotate-ccw me-1"></i>Reopen</button></form>
        <?php endif; ?>
        <?php if ($may['edit'] || $may['restrict'] || $may['assign']): ?>
        <div class="dropdown"><button type="button" class="btn btn-light btn-touch" data-bs-toggle="dropdown" aria-expanded="false" id="deal-more-btn" aria-label="More"><i class="feather-more-horizontal"></i></button>
            <div class="dropdown-menu dropdown-menu-end" id="deal-more-menu">
                <?php if ($may['edit']): ?><?= hx_link('/deals/' . $id . '/edit', '<i class="feather-edit-2 me-2"></i>Edit', 'dropdown-item', 'id="deal-edit-btn"') ?><?php endif; ?>
                <?php if ($may['assign']): ?><a href="/deals/<?= $id ?>?tab=overview#deal-assign-form" class="dropdown-item" id="deal-reassign-btn"><i class="feather-user-check me-2"></i>Reassign</a><?php endif; ?>
                <?php if ($may['restrict']): ?><a href="/deals/<?= $id ?>/edit#restriction-section" class="dropdown-item" id="deal-restrict-btn"><i class="feather-lock me-2"></i><?= $restricted ? 'Change who may see it' : 'Restrict' ?></a><?php endif; ?>
                <?php if ($may['delete']): ?><form method="post" action="/records/delete.php" hx-post="/records/delete.php" hx-target="#flash" hx-confirm="Delete the deal <?= e($d['title']) ?>? It can be restored for a while."><?= csrf_field() ?>
                    <input type="hidden" name="kind" value="deal"><input type="hidden" name="record" value="<?= $id ?>"><button type="submit" class="dropdown-item text-danger" id="deal-delete-btn"><i class="feather-trash-2 me-2"></i>Delete</button></form><?php endif; ?>
            </div></div>
        <?php endif; ?>
    </div>
</div>
<?php if (($b = back_link()) !== null): ?><div class="px-3 pb-2"><?= hx_link($b[0], '<i class="feather-arrow-left me-1"></i>Back to ' . e($b[1]), 'fs-12 fw-semibold', 'id="deal-view-back"') ?></div><?php endif; ?>
<div class="main-content" id="deal-view-content">
    <?= view('shared/notice.php', ['notice' => $notice]) ?>
    <div class="row g-3 mb-2" id="deal-view-numbers">
        <div class="col-6 col-md-3"><div class="fs-11 text-muted">Value</div><div class="fs-5 fw-semibold" id="deal-view-value"><?= e(money($d['value'], $d['currency'])) ?></div></div>
        <div class="col-6 col-md-3"><div class="fs-11 text-muted">Weighted · <?= (int) $d['probability'] ?>%<?= $d['probability_override'] !== null ? ' (set by hand)' : '' ?></div><div class="fs-5 fw-semibold" id="deal-view-weighted"><?= e(money($d['weighted_value'], $d['currency'])) ?></div></div>
        <div class="col-6 col-md-3"><div class="fs-11 text-muted"><?= $closed ? 'Closed' : 'Expected close' ?></div><div class="fw-semibold<?= !$closed && $d['expected_close_date'] !== null && $d['expected_close_date'] < date('Y-m-d') ? ' text-danger' : '' ?>" id="deal-view-close">
            <?= $closed ? e(format_ts($d['won_at'] ?? $d['lost_at'], $tz, 'M j, Y')) : ($d['expected_close_date'] === null ? '<span class="text-muted">Not set</span>' : e(format_date($d['expected_close_date']))) ?></div></div>
        <div class="col-6 col-md-3"><div class="fs-11 text-muted">Owner</div><div class="fw-semibold" id="deal-view-owner"><?= e((string) ($d['owner_name'] ?? 'Unowned')) ?></div></div>
    </div>
    <?php if ($d['status'] === 'lost'): ?><div class="alert alert-danger py-2 fs-12" id="deal-lost-reason">Lost: <?= e((string) $d['lost_reason']) ?><?= $d['lost_note'] ? ' — ' . e($d['lost_note']) : '' ?></div><?php endif; ?>
    <?= view('deals/partials/stage-bar.php', ['d' => $d, 'summary' => $summary, 'may' => $may]) ?>
    <div class="mb-3"><?= view('shared/tag-editor.php', ['kind' => 'deal', 'id' => $id, 'tags' => $tags, 'may' => ['edit' => $may['tag']], 'back' => '/deals/' . $id]) ?></div>
    <ul class="nav nav-tabs flex-nowrap overflow-auto mb-3" id="deal-tabs" role="tablist">
        <?php foreach ($tabs as $k => $label): ?>
            <li class="nav-item" role="presentation"><a class="nav-link text-nowrap<?= $tab === $k ? ' active' : '' ?>" id="deal-tab-<?= $k ?>-link" href="/deals/<?= $id ?>?tab=<?= $k ?>#deal-tab-<?= $k ?>"
                data-bs-toggle="tab" data-bs-target="#deal-tab-<?= $k ?>" role="tab" aria-controls="deal-tab-<?= $k ?>" aria-selected="<?= $tab === $k ? 'true' : 'false' ?>"><?= e($label) ?><?= isset($counts[$k]) && $counts[$k] > 0 ? ' <span class="badge bg-soft-secondary text-secondary">' . $counts[$k] . '</span>' : '' ?></a></li>
        <?php endforeach; ?>
    </ul>
    <div class="tab-content" id="deal-tab-content">
        <div class="tab-pane fade<?= $tab === 'overview' ? ' show active' : '' ?>" id="deal-tab-overview" role="tabpanel" aria-labelledby="deal-tab-overview-link">
            <div class="row g-3"><div class="col-lg-8">
                <div class="card mb-3" id="deal-fields"><div class="card-body"><div class="row">
                    <?= $field('Pipeline', hx_link(pipeline_url(['key' => $d['pipeline_key']], 'board'), e($d['pipeline_name'])), 'pipeline') ?>
                    <?= $field('Primary contact', $d['primary_contact_id'] === null ? '' : hx_link('/contacts/' . (int) $d['primary_contact_id'], e((string) $d['primary_contact_name'])), 'contact') ?>
                    <?= $field('In this stage', (int) $d['days_in_stage'] . ' day' . ((int) $d['days_in_stage'] === 1 ? '' : 's') . ($d['is_rotting'] ? ' <span class="badge bg-soft-danger text-danger">rotting</span>' : ''), 'in-stage') ?>
                    <?= $field('Source', e((string) $d['source_name']), 'source') ?><?= $field('Campaign', e((string) $d['campaign_name']), 'campaign') ?>
                    <?= $field('From the lead', $d['lead_id'] === null ? '' : hx_link('/leads/' . (int) $d['lead_id'], 'the lead'), 'lead') ?>
                    <?php foreach ($custom as $label => $value): ?><?= $field($label, e($value), 'custom-' . md5($label)) ?><?php endforeach; ?>
                    <?php if ($d['description']): ?><div class="col-12 mb-2" id="deal-field-description"><div class="fs-11 text-muted">Description</div><div class="text-break" style="white-space: pre-wrap"><?= e($d['description']) ?></div></div><?php endif; ?>
                </div></div></div>
                <?= view('deals/partials/products.php', ['d' => $d, 'products' => $summary['products'], 'total' => $summary['products_total'], 'may' => $may, 'connected' => $products_connected]) ?>
                <?= view('deals/partials/contacts.php', ['d' => $d, 'contacts' => $summary['contacts'], 'may' => $may]) ?>
            </div><div class="col-lg-4">
                <?= view('deals/partials/next-step.php', ['d' => $d, 'next' => $summary['next_step'], 'tz' => $tz, 'may' => $may]) ?>
                <?php if ($may['assign']): ?>
                <form method="post" action="/deals/assign.php" hx-post="/deals/assign.php" hx-target="#flash" class="card mb-3" id="deal-assign-form"><div class="card-body"><?= csrf_field() ?><input type="hidden" name="deal" value="<?= $id ?>"><input type="hidden" name="return_to" value="/deals/<?= $id ?>">
                    <label class="fw-semibold mb-2 d-block" id="deal-assign-label" for="deal-assign-field-owner">Owner</label>
                    <?= picker_field(['id' => 'deal-assign-field-owner', 'name' => 'owner', 'source' => 'owner', 'value' => $d['owner_member_id'], 'labelledby' => 'deal-assign-label', 'title' => 'Give it to', 'placeholder' => $may['assign_any'] ? 'Unowned' : 'Choose someone', 'required' => !$may['assign_any']]) ?>
                    <button type="submit" class="btn btn-light btn-touch w-100 mt-2" id="deal-assign-btn">Reassign</button></div></form>
                <?php endif; ?>
            </div></div>
        </div>
        <div class="tab-pane fade<?= $tab === 'activities' ? ' show active' : '' ?>" id="deal-tab-activities" role="tabpanel" aria-labelledby="deal-tab-activities-link">
            <?= view('shared/record-agenda.php', ['kind' => 'deal', 'id' => $id, 'activities' => $activities, 'may' => $may, 'tz' => $tz]) ?></div>
        <div class="tab-pane fade<?= $tab === 'emails' ? ' show active' : '' ?>" id="deal-tab-emails" role="tabpanel" aria-labelledby="deal-tab-emails-link">
            <?= view('shared/record-emails.php', ['prefix' => 'deal', 'emails' => $emails, 'tz' => $tz, 'back' => '/deals/' . $id . '?tab=emails']) ?></div>
        <div class="tab-pane fade<?= $tab === 'notes' ? ' show active' : '' ?>" id="deal-tab-notes" role="tabpanel" aria-labelledby="deal-tab-notes-link">
            <div class="row g-3"><div class="col-lg-7"><?= view('shared/notes-panel.php', ['kind' => 'deal', 'id' => $id, 'notes' => $notes, 'may' => ['add' => $may['note'], 'delete_any' => $may['manager']], 'me' => $me, 'tz' => $tz, 'back' => '/deals/' . $id . '?tab=notes']) ?></div>
            <div class="col-lg-5"><?= view('shared/attachments-panel.php', ['kind' => 'deal', 'id' => $id, 'files' => $files, 'may' => ['add' => $may['edit'], 'delete_any' => $may['manager']], 'me' => $me, 'tz' => $tz, 'back' => '/deals/' . $id . '?tab=notes', 'max_mb' => $max_mb]) ?></div></div></div>
        <div class="tab-pane fade<?= $tab === 'history' ? ' show active' : '' ?>" id="deal-tab-history" role="tabpanel" aria-labelledby="deal-tab-history-link">
            <?= view('deals/partials/history-strip.php', ['strip' => $summary['strip'], 'tz' => $tz]) ?>
            <?= view('shared/timeline.php', ['rows' => $timeline, 'tz' => $tz, 'prefix' => 'deal']) ?></div>
    </div>
</div>
