<?php /** One activity (activity-view): the header (kind, subject, state, the record; Done or Reopen · Snooze · ⋯ Edit · Delete), when and where, the reminder,
 * Reschedule, the outcome, what was said, the record, the owner with Reassign, participants, custom fields, notes. Data: what html/activities/view.php gathers. */
$id = (int) $a['activity_id'];
$rec = activity_record($a);
$open = $a['done_at'] === null;
$timed = $a['starts_at'] !== null;
$fmt = $a['all_day'] ? 'l, M j, Y' : 'l, M j, Y · g:i A';
$when = format_ts($a['at'], $tz, $fmt) . ($a['all_day'] ? ' · all day' : '') . ($timed && !$a['all_day'] && $a['ends_at'] !== null ? ' – ' . format_ts($a['ends_at'], $tz, 'g:i A') : '');
$stateTone = ['overdue' => 'danger', 'today' => 'warning', 'scheduled' => 'success', 'done' => 'secondary'][$a['state']] ?? 'secondary';
$back = '/activities/' . $id; ?>
<div class="page-header" id="activity-view-header">
    <div class="page-header-left d-flex align-items-center min-w-0 gap-2">
        <span class="avatar-text avatar-md rounded cal-kind-<?= e($a['kind']) ?>"><i class="<?= e(activity_kind_icon($a['kind'])) ?>"></i></span>
        <div class="page-header-title min-w-0"><h5 class="m-b-10 text-truncate" id="activity-view-title"><?= e($a['subject']) ?></h5>
            <div class="fs-12 d-flex flex-wrap gap-1 align-items-center" id="activity-view-sub"><span class="text-muted"><?= e(ACTIVITY_KINDS[$a['kind']]) ?></span>
                <span class="badge text-bg-<?= $stateTone ?>" id="activity-view-state"><?= e(ACTIVITY_STATE_WORDS[$a['state']] ?? $a['state']) ?></span>
                <?php if ($rec !== null): ?><?= hx_link($rec[3], e($rec[2]), 'text-dark', 'id="activity-view-record"') ?><?php endif; ?></div></div>
    </div>
    <div class="page-header-right ms-auto d-flex flex-wrap gap-1 align-items-center">
        <?php if ($may['complete']): ?><?= hx_link('/activities/' . $id . '/complete', '<i class="feather-check me-1"></i>Done', 'btn btn-success btn-touch', 'id="activity-done-btn"') ?><?php endif; ?>
        <?php if ($may['reopen']): ?><form method="post" action="/activities/reopen.php" hx-post="/activities/reopen.php" hx-target="#flash"><?= csrf_field() ?><input type="hidden" name="activity" value="<?= $id ?>">
            <button type="submit" class="btn btn-light btn-touch" id="activity-reopen-btn"><i class="feather-rotate-ccw me-1"></i>Reopen</button></form><?php endif; ?>
        <?php if ($may['snooze']): ?>
        <div class="dropdown"><button type="button" class="btn btn-light btn-touch" data-bs-toggle="dropdown" aria-expanded="false" id="activity-snooze-btn"><i class="feather-bell-off me-1"></i>Snooze</button>
            <div class="dropdown-menu dropdown-menu-end p-1" id="activity-snooze-menu">
                <?php foreach (['10m' => 'For 10 minutes', '1h' => 'For an hour', 'tomorrow' => 'Until tomorrow, ' . $snooze_tomorrow] as $v => $label): ?>
                <form method="post" action="/activities/snooze.php" hx-post="/activities/snooze.php" hx-target="#flash"><?= csrf_field() ?><input type="hidden" name="activity" value="<?= $id ?>"><input type="hidden" name="until" value="<?= $v ?>">
                    <button type="submit" class="dropdown-item" id="activity-snooze-<?= $v ?>"><?= e($label) ?></button></form>
                <?php endforeach; ?>
            </div></div>
        <?php endif; ?>
        <?php if ($may['edit'] || $may['delete']): ?>
        <div class="dropdown"><button type="button" class="btn btn-light btn-touch" data-bs-toggle="dropdown" aria-expanded="false" id="activity-more-btn" aria-label="More"><i class="feather-more-horizontal"></i></button>
            <div class="dropdown-menu dropdown-menu-end" id="activity-more-menu">
                <?php if ($may['edit']): ?><?= hx_link('/activities/' . $id . '/edit', '<i class="feather-edit-2 me-2"></i>Edit', 'dropdown-item', 'id="activity-edit-btn"') ?><?php endif; ?>
                <?php if ($may['delete']): ?><form method="post" action="/records/delete.php" hx-post="/records/delete.php" hx-target="#flash" hx-confirm="Delete <?= e($a['subject']) ?>? It can be restored for a while."><?= csrf_field() ?>
                    <input type="hidden" name="kind" value="activity"><input type="hidden" name="record" value="<?= $id ?>"><button type="submit" class="dropdown-item text-danger" id="activity-delete-btn"><i class="feather-trash-2 me-2"></i>Delete</button></form><?php endif; ?>
            </div></div>
        <?php endif; ?>
    </div>
</div>
<?php if (($b = back_link()) !== null): ?><div class="px-3 pb-2"><?= hx_link($b[0], '<i class="feather-arrow-left me-1"></i>Back to ' . e($b[1]), 'fs-12 fw-semibold', 'id="activity-view-back"') ?></div><?php endif; ?>
<div class="main-content" id="activity-view-content">
    <?= view('shared/notice.php', ['notice' => $notice, 'link' => $next !== null ? ['/activities/' . (int) $next['activity_id'], ACTIVITY_KINDS[$next['kind']] . ': ' . $next['subject'] . ', ' . format_ts($next['at'], $tz, 'D M j, g:i A')] : null]) ?>
    <div class="row g-3">
    <div class="col-lg-7">
        <div class="card mb-3" id="activity-when"><div class="card-body">
            <div class="fs-11 text-muted">When</div>
            <div class="fw-semibold<?= $a['state'] === 'overdue' ? ' text-danger' : '' ?>" id="activity-view-when"><?= e($when) ?></div>
            <?php if ($a['location'] || $a['meeting_url']): ?><div class="fs-12 mt-1" id="activity-view-where"><?php if ($a['location']): ?><i class="feather-map-pin me-1"></i><?= e($a['location']) ?><?php endif; ?>
                <?php if ($a['meeting_url']): ?> <a href="<?= e($a['meeting_url']) ?>" target="_blank" rel="noopener noreferrer" id="activity-view-link"><i class="feather-video me-1"></i>Join</a><?php endif; ?></div><?php endif; ?>
            <div class="fs-12 text-muted mt-1" id="activity-view-reminder"><i class="feather-bell me-1"></i><?php
                if (!$open) { echo 'Done ' . e(format_ts($a['done_at'], $tz, 'D M j, g:i A')) . ($a['done_by_name'] ? ' by ' . e($a['done_by_name']) : '') . '.'; }
                elseif ($a['snoozed_until'] !== null) { echo 'Snoozed until ' . e(format_ts($a['snoozed_until'], $tz, 'D M j, g:i A')) . '.'; }
                elseif ($a['remind_minutes_before'] === null) { echo 'No reminder.'; }
                else { echo 'Reminds ' . ((int) $a['remind_minutes_before'] === 0 ? 'at the time' : e(ACTIVITY_REMIND_CHOICES[(int) $a['remind_minutes_before']] ?? $a['remind_minutes_before'] . ' minutes before')) . ($a['reminded_at'] !== null ? ' — reminded ' . e(format_ts($a['reminded_at'], $tz, 'D M j, g:i A')) : ' — at ' . e(format_ts($a['remind_at'], $tz, 'D M j, g:i A'))) . '.'; } ?></div>
            <?php if ($a['recurrence']): ?><div class="fs-12 text-muted" id="activity-view-repeat"><i class="feather-repeat me-1"></i><?= e(RECURRENCE_CHOICES[$a['recurrence']] ?? $a['recurrence']) ?></div><?php endif; ?>
            <?php if ($a['priority'] !== 'normal'): ?><div class="fs-12" id="activity-view-priority"><span class="badge bg-soft-<?= $a['priority'] === 'high' ? 'danger text-danger' : 'secondary text-secondary' ?>"><?= e(ACTIVITY_PRIORITIES[$a['priority']]) ?> priority</span></div><?php endif; ?>
            <?php if ($may['reschedule']): ?>
            <form method="post" action="/activities/reschedule.php" hx-post="/activities/reschedule.php" hx-target="#flash" class="d-flex flex-wrap gap-2 align-items-end mt-2" id="activity-reschedule-form"><?= csrf_field() ?><input type="hidden" name="activity" value="<?= $id ?>">
                <div><label class="form-label fs-11 mb-0" for="activity-reschedule-at">Move to</label>
                    <input type="<?= $a['all_day'] ? 'date' : 'datetime-local' ?>" class="form-control form-control-sm btn-touch" name="at" id="activity-reschedule-at" value="<?= e($a['all_day'] ? format_ts($a['at'], $tz, 'Y-m-d') : local_input($a['at'], $tz)) ?>" required></div>
                <button type="submit" class="btn btn-sm btn-light btn-touch" id="activity-reschedule-btn">Reschedule</button></form>
            <?php endif; ?>
        </div></div>
        <?php if (!$open): ?><div class="card mb-3" id="activity-outcome"><div class="card-body"><div class="fs-11 text-muted">Outcome</div>
            <div class="fw-semibold" id="activity-view-outcome"><?= e(OUTCOME_WORDS[$a['outcome']] ?? (string) $a['outcome']) ?></div>
            <?php if ($a['outcome_note']): ?><div class="fs-12 mt-1" id="activity-view-outcome-note"><?= nl2br(e($a['outcome_note'])) ?></div><?php endif; ?></div></div><?php endif; ?>
        <div class="card mb-3" id="activity-body"><div class="card-body"><div class="fs-11 text-muted"><?= $open ? 'Notes for it' : 'What was said' ?></div>
            <?php if ($a['body'] !== null && $a['body'] !== ''): ?><div class="text-break" id="activity-view-body"><?= nl2br(e($a['body'])) ?></div>
            <?php elseif ((bool) $a['is_private'] && $a['body'] === null): ?><div class="fs-12 text-muted" id="activity-view-body-private">Private to its seller.</div>
            <?php else: ?><div class="fs-12 text-muted" id="activity-view-body-empty">Nothing written.</div><?php endif; ?></div></div>
        <?php if ($custom !== []): ?><div class="card mb-3" id="activity-custom"><div class="card-body"><div class="row"><?php foreach ($custom as $label => $shown): ?>
            <div class="col-sm-6 mb-2"><div class="fs-11 text-muted"><?= e((string) $label) ?></div><div><?= e((string) $shown) ?></div></div><?php endforeach; ?></div></div></div><?php endif; ?>
        <?= view('shared/notes-panel.php', ['kind' => 'activity', 'id' => $id, 'notes' => $notes, 'may' => ['add' => $may['note'], 'delete_any' => $may['manager']], 'me' => $me, 'tz' => $tz, 'back' => $back]) ?>
    </div>
    <div class="col-lg-5">
        <div class="card mb-3" id="activity-record"><div class="card-body">
            <div class="fs-11 text-muted mb-1">About</div>
            <?php foreach ([['deal', 'deal_id', 'deal_title', 'feather-dollar-sign'], ['contact', 'contact_id', 'contact_name', 'feather-user'], ['account', 'account_id', 'account_name', 'feather-briefcase'], ['lead', 'lead_id', 'lead_name', 'feather-user-plus']] as [$k, $col, $name, $icon]): if ($a[$col] === null) { continue; } ?>
                <div class="mb-1" id="activity-record-<?= $k ?>"><i class="<?= $icon ?> me-1 text-muted"></i><?= hx_link('/' . $k . 's/' . (int) $a[$col], e((string) ($a[$name] ?? ('#' . $a[$col]))), 'text-dark fw-semibold') ?> <span class="fs-11 text-muted"><?= e($k) ?></span></div>
            <?php endforeach; ?>
            <?php if ($rec === null): ?><div class="fs-12 text-muted">A task of its own.</div><?php endif; ?>
        </div></div>
        <div class="card mb-3" id="activity-owner"><div class="card-body">
            <div class="fs-11 text-muted">Who does it</div>
            <div class="fw-semibold" id="activity-view-owner"><?= e((string) ($a['owner_name'] ?? 'Nobody')) ?><?= $a['owner_kind'] === 'agent' ? ' <span class="badge bg-soft-info text-info">agent</span>' : '' ?></div>
            <div class="fs-11 text-muted">Made by <?= e((string) ($a['created_by_name'] ?? '—')) ?> · <?= e(format_ts($a['created_at'], $tz, 'M j, Y')) ?><?= $a['source'] !== 'web' ? ' · ' . e($a['source']) : '' ?></div>
            <?php if ($may['assign']): ?>
            <form method="post" action="/activities/assign.php" hx-post="/activities/assign.php" hx-target="#flash" class="mt-2" id="activity-assign-form"><?= csrf_field() ?><input type="hidden" name="activity" value="<?= $id ?>">
                <label class="form-label fs-11 mb-0" id="activity-assign-label" for="activity-assign-owner">Give it to</label>
                <div class="d-flex gap-2"><div class="flex-grow-1"><?= picker_field(['id' => 'activity-assign-owner', 'name' => 'owner', 'source' => 'owner', 'value' => $a['owner_member_id'], 'labelledby' => 'activity-assign-label', 'title' => 'Who does it', 'placeholder' => 'Choose']) ?></div>
                    <button type="submit" class="btn btn-sm btn-light btn-touch" id="activity-assign-btn">Reassign</button></div></form>
            <?php endif; ?>
        </div></div>
        <?= view('activities/partials/participants.php', ['a' => $a, 'participants' => $participants, 'may' => $may]) ?>
    </div>
    </div>
</div>
