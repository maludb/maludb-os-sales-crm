<?php /** One lead (lead-view): the header (Convert · Unqualify · Assign · ⋯), the duplicate warning, the three records once converted, the fields, the score, notes and files,
 * activities, the timeline. A converted lead is read-only (no edit, no qualify). Data: what html/leads/view.php gathers. */
$lid = (int) $l['lead_id'];
$field = static fn (string $label, ?string $value, string $id) => '<div class="col-sm-6 mb-2" id="lead-field-' . $id . '"><div class="fs-11 text-muted">' . e($label) . '</div><div class="text-break">' . ($value === null || $value === '' ? '<span class="text-muted">—</span>' : e($value)) . '</div></div>';
$converted = $l['converted_at'] !== null;
$restricted = $l['restricted_to_member_id'] !== null || $l['restricted_to_department_id'] !== null;
$utm = json_decode((string) $l['utm'], true) ?: []; ?>
<div class="page-header" id="lead-view-header">
    <div class="page-header-left d-flex align-items-center min-w-0">
        <div class="page-header-title min-w-0"><h5 class="m-b-10 text-truncate" id="lead-view-name"><?php if ($restricted): ?><i class="feather-lock text-warning me-1"></i><?php endif; ?><?= e($l['label']) ?></h5>
            <div class="fs-12" id="lead-view-status"><span class="badge bg-soft-<?= e(LEAD_STATUS_BADGE[$l['status']] ?? 'secondary') ?> text-<?= e(LEAD_STATUS_BADGE[$l['status']] ?? 'secondary') ?>"><?= e(LEAD_STATUSES[$l['status']] ?? $l['status']) ?></span>
                <?php if ($l['is_proposed']): ?><span class="badge bg-soft-info text-info">proposed</span><?php endif; ?> <span class="text-muted"><?= e((string) ($l['owner_name'] ?? 'Unowned')) ?></span></div></div>
    </div>
    <div class="page-header-right ms-auto d-flex flex-wrap gap-1 align-items-center">
        <?php if ($may['convert']): ?><?= hx_link('/leads/' . $lid . '/convert', '<i class="feather-shuffle me-1"></i>Convert', 'btn btn-primary btn-touch', 'id="lead-convert-btn"') ?><?php endif; ?>
        <?php if (!$converted && $may['log'] && nav_built('/log/call.php')): ?><?= hx_link('/log/call?lead=' . $lid, '<i class="feather-phone me-1"></i><span class="d-none d-sm-inline">Log call</span>', 'btn btn-light btn-touch', 'id="lead-log-call-btn"') ?><?php endif; ?>
        <?php if ($may['edit'] || $may['restrict']): ?>
        <div class="dropdown"><button type="button" class="btn btn-light btn-touch" data-bs-toggle="dropdown" aria-expanded="false" id="lead-more-btn" aria-label="More"><i class="feather-more-horizontal"></i></button>
            <div class="dropdown-menu dropdown-menu-end">
                <?php if ($may['edit']): ?><?= hx_link('/leads/' . $lid . '/edit', '<i class="feather-edit-2 me-2"></i>Edit', 'dropdown-item', 'id="lead-edit-btn"') ?><?php endif; ?>
                <?php if ($may['restrict']): ?><a href="/leads/<?= $lid ?>/edit#restriction-section" class="dropdown-item" id="lead-restrict-btn"><i class="feather-lock me-2"></i>Restrict</a><?php endif; ?>
                <?php if ($may['edit']): ?><form method="post" action="/records/delete.php" hx-post="/records/delete.php" hx-target="#flash" hx-confirm="Delete <?= e($l['label']) ?>?"><?= csrf_field() ?><input type="hidden" name="kind" value="lead"><input type="hidden" name="record" value="<?= $lid ?>">
                    <button type="submit" class="dropdown-item text-danger" id="lead-delete-btn"><i class="feather-trash-2 me-2"></i>Delete</button></form><?php endif; ?>
            </div></div>
        <?php endif; ?>
    </div>
</div>
<?php if (($b = back_link()) !== null): ?><div class="px-3 pb-2"><?= hx_link($b[0], '<i class="feather-arrow-left me-1"></i>Back to ' . e($b[1]), 'fs-12 fw-semibold', 'id="lead-view-back"') ?></div><?php endif; ?>
<div class="main-content" id="lead-view-content">
    <?= view('shared/notice.php', ['notice' => $notice]) ?>
    <?php if ($l['is_proposed'] && $may['proposed']): ?>
        <div class="alert alert-info d-flex flex-wrap align-items-center gap-2" id="lead-proposed-banner"><span class="flex-grow-1">Proposed — not in the book yet.</span>
            <form method="post" action="/leads/accept.php" hx-post="/leads/accept.php" hx-target="#flash"><?= csrf_field() ?><input type="hidden" name="lead" value="<?= $lid ?>"><input type="hidden" name="return_to" value="/leads/<?= $lid ?>"><button type="submit" class="btn btn-primary btn-sm btn-touch" id="lead-accept-btn">Accept</button></form>
            <form method="post" action="/leads/dismiss.php" hx-post="/leads/dismiss.php" hx-target="#flash" hx-confirm="Dismiss it?"><?= csrf_field() ?><input type="hidden" name="lead" value="<?= $lid ?>"><button type="submit" class="btn btn-light btn-sm btn-touch" id="lead-dismiss-btn">Dismiss</button></form></div>
    <?php endif; ?>
    <?php if ($dupes !== [] && !$converted): ?><div class="alert alert-warning fs-12" id="lead-duplicates"><span class="fw-semibold">Possible duplicate:</span>
        <?php foreach ($dupes as $i => $d): ?><?= $i ? ', ' : ' ' ?><a href="<?= e($d['url']) ?>" class="alert-link" id="lead-duplicate-<?= e($d['kind']) ?>-<?= (int) $d['id'] ?>"><?= e($d['name']) ?></a> (<?= e($d['detail']) ?>)<?php endforeach; ?></div><?php endif; ?>
    <?php if ($converted): ?>
        <div class="row g-3 mb-3" id="lead-converted">
            <?php foreach ([['account', 'feather-briefcase', 'Account', $l['converted_account_id'], $l['converted_account_name']], ['contact', 'feather-user', 'Contact', $l['converted_contact_id'], $l['converted_contact_name']],
                            ['deal', 'feather-columns', 'Deal', $l['converted_deal_id'], $l['converted_deal_title']]] as [$k, $icon, $label, $rid, $name]): if ($rid === null) { continue; } ?>
                <div class="col-md-4"><div class="card h-100 mb-0" id="lead-converted-<?= $k ?>"><div class="card-body d-flex align-items-center gap-2"><span class="avatar-text avatar-md rounded"><i class="<?= $icon ?>"></i></span>
                    <div class="min-w-0"><div class="fs-11 text-muted"><?= $label ?></div><div class="fw-semibold text-truncate"><?= $name !== null && ($k !== 'deal' || nav_built('/deals/view.php')) ? hx_link('/' . $k . 's/' . (int) $rid, e($name), 'text-dark') : e((string) ($name ?? 'not yours to see')) ?></div></div></div></div></div>
            <?php endforeach; ?>
            <div class="col-12 fs-12 text-muted">Converted <?= e(format_ts($l['converted_at'], $tz, 'M j, Y')) ?> — the lead is kept, read-only, as the record of where it came from.</div>
        </div>
    <?php endif; ?>
    <div class="mb-3"><?= view('shared/tag-editor.php', ['kind' => 'lead', 'id' => $lid, 'tags' => $tags, 'may' => ['edit' => $may['tag']], 'back' => '/leads/' . $lid]) ?></div>
    <div class="row g-3">
        <div class="col-lg-8">
            <div class="card mb-3" id="lead-fields"><div class="card-body"><div class="row">
                <?= $field('Company', $l['company'], 'company') ?><?= $field('Title', $l['title'], 'title') ?><?= $field('Email', $l['email'], 'email') ?><?= $field('Phone', $l['phone'], 'phone') ?>
                <?= $field('Website', $l['website'], 'website') ?><?= $field('Where', trim(implode(', ', array_filter([$l['city'], $l['region'], $l['country']]))), 'where') ?>
                <?= $field('Source', $l['source'], 'source') ?><?= $field('Campaign', $l['campaign_name'], 'campaign') ?>
                <?php if ($utm !== []): ?><?= $field('Came from', implode(' · ', array_map(static fn ($k, $v) => str_replace('utm_', '', $k) . ' ' . $v, array_keys($utm), $utm)), 'utm') ?><?php endif; ?>
                <?php if ($l['status'] === 'unqualified'): ?><?= $field('Unqualified because', trim(($l['unqualified_reason'] ?? '') . ($l['unqualified_note'] ? ' — ' . $l['unqualified_note'] : '')), 'unqualified') ?><?php endif; ?>
                <?php if ($l['message']): ?><div class="col-12 mb-2" id="lead-field-message"><div class="fs-11 text-muted">Their message</div><div class="text-break" style="white-space: pre-wrap"><?= e($l['message']) ?></div></div><?php endif; ?>
                <?php foreach ($custom as $label => $value): ?><?= $field($label, $value, 'custom-' . md5($label)) ?><?php endforeach; ?>
            </div></div></div>
            <?= view('shared/notes-panel.php', ['kind' => 'lead', 'id' => $lid, 'notes' => $notes, 'may' => ['add' => true, 'delete_any' => $may['manager']], 'me' => $me, 'tz' => $tz, 'back' => '/leads/' . $lid]) ?>
            <?= view('shared/attachments-panel.php', ['kind' => 'lead', 'id' => $lid, 'files' => $files, 'may' => ['add' => $may['edit'], 'delete_any' => $may['manager']], 'me' => $me, 'tz' => $tz, 'back' => '/leads/' . $lid, 'max_mb' => $max_mb]) ?>
        </div>
        <div class="col-lg-4">
            <?php if ($may['edit'] && $l['status'] !== 'unqualified'): ?>
            <form method="post" action="/leads/qualify.php" hx-post="/leads/qualify.php" hx-target="#flash" class="card mb-3" id="lead-qualify-form"><div class="card-body"><?= csrf_field() ?><input type="hidden" name="lead" value="<?= $lid ?>">
                <div class="d-flex align-items-center gap-2 mb-2"><span class="lead-score<?= $l['score'] === null ? ' lead-score-none' : '' ?>" style="--score: <?= (int) ($l['score'] ?? 0) ?>"><span><?= $l['score'] === null ? '–' : (int) $l['score'] ?></span></span><span class="fw-semibold">How promising</span></div>
                <label class="form-label fs-12 text-muted" for="lead-qualify-field-score">Score (0–100)</label>
                <input type="number" min="0" max="100" class="form-control btn-touch mb-2" name="score" id="lead-qualify-field-score" value="<?= e((string) ($l['score'] ?? '')) ?>">
                <select name="status" class="form-select btn-touch mb-2" id="lead-qualify-field-status" aria-label="Status"><option value="working" <?= $l['status'] !== 'new' ? 'selected' : '' ?>>Working</option><option value="new" <?= $l['status'] === 'new' ? 'selected' : '' ?>>New</option></select>
                <button type="submit" class="btn btn-light btn-touch w-100" id="lead-qualify-btn">Save the score</button></div></form>
            <form method="post" action="/leads/unqualify.php" hx-post="/leads/unqualify.php" hx-target="#flash" class="card mb-3" id="lead-unqualify-form"><div class="card-body"><?= csrf_field() ?><input type="hidden" name="lead" value="<?= $lid ?>">
                <div class="fw-semibold mb-2">Not a fit?</div>
                <select name="reason" class="form-select btn-touch mb-2" id="lead-unqualify-field-reason" required aria-label="Why"><option value="">Why…</option><?php foreach ($reasons as $r): ?><option value="<?= (int) $r['lost_reason_id'] ?>"><?= e($r['label']) ?></option><?php endforeach; ?></select>
                <input type="text" name="note" maxlength="500" class="form-control btn-touch mb-2" id="lead-unqualify-field-note" placeholder="A word more (optional)">
                <button type="submit" class="btn btn-outline-dark btn-touch w-100" id="lead-unqualify-btn">Unqualify</button></div></form>
            <?php elseif ($may['edit'] && $l['status'] === 'unqualified'): ?>
            <form method="post" action="/leads/requalify.php" hx-post="/leads/requalify.php" hx-target="#flash" class="card mb-3" id="lead-requalify-form"><div class="card-body"><?= csrf_field() ?><input type="hidden" name="lead" value="<?= $lid ?>">
                <div class="fs-12 text-muted mb-2">Unqualified: <?= e((string) $l['unqualified_reason']) ?>.</div><button type="submit" class="btn btn-light btn-touch w-100" id="lead-requalify-btn">Requalify — back to work</button></div></form>
            <?php endif; ?>
            <?php if ($may['assign']): ?>
            <form method="post" action="/leads/assign.php" hx-post="/leads/assign.php" hx-target="#flash" class="card mb-3" id="lead-assign-form"><div class="card-body"><?= csrf_field() ?><input type="hidden" name="lead" value="<?= $lid ?>">
                <label class="fw-semibold mb-2 d-block" id="lead-assign-label" for="lead-assign-field-owner">Owner</label>
                <?php if ($may['assign_any']): ?><?= picker_field(['id' => 'lead-assign-field-owner', 'name' => 'owner', 'source' => 'owner', 'value' => $l['owner_member_id'], 'labelledby' => 'lead-assign-label', 'title' => 'Give it to', 'placeholder' => 'Unowned — the inbox']) ?>
                <?php else: ?><input type="hidden" name="owner" value="<?= (int) $me ?>"><div class="fs-12 text-muted"><?= e((string) ($l['owner_name'] ?? 'Unowned')) ?></div><?php endif; ?>
                <button type="submit" class="btn btn-light btn-touch w-100 mt-2" id="lead-assign-btn"><?= $may['assign_any'] ? 'Assign' : 'Take it' ?></button></div></form>
            <?php endif; ?>
            <?= view('shared/record-agenda.php', ['kind' => 'lead', 'id' => $lid, 'activities' => $activities, 'may' => $may, 'tz' => $tz]) ?>
        </div>
    </div>
    <div class="mt-3"><?= view('shared/timeline.php', ['rows' => $timeline, 'tz' => $tz, 'prefix' => 'lead']) ?></div>
</div>
