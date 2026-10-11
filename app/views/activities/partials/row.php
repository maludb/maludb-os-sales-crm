<?php /** One row of the activities list (#activity-row-{id}): the kind, the subject, when (or when it was done), the record, the owner (another's list), the state,
 * Done for an open one the caller may complete. Data: r, tz, back, me, show_owner */
$id = (int) $r['activity_id'];
$rec = activity_record($r);
$may = activity_may($r);
$when = $r['done_at'] !== null ? 'Done ' . format_ts($r['done_at'], $tz, 'D M j, g:i A') : ($r['all_day'] ? format_ts($r['at'], $tz, 'D M j') . ' · all day' : format_ts($r['at'], $tz, 'D M j, g:i A')); ?>
<div class="list-group-item d-flex align-items-center gap-2 activity-row cal-state-<?= e($r['state']) ?>" id="activity-row-<?= $id ?>">
    <span class="avatar-text avatar-md rounded cal-kind-<?= e($r['kind']) ?>" title="<?= e(ACTIVITY_KINDS[$r['kind']]) ?>"><i class="<?= e(activity_kind_icon($r['kind'])) ?>"></i></span>
    <div class="min-w-0 flex-grow-1">
        <div class="fw-semibold text-truncate"><?= hx_link(with_back('/activities/' . $id, $back), e($r['subject']), 'text-dark activity-subject', 'id="activity-row-' . $id . '-link"') ?><?= $r['priority'] === 'high' ? ' <span class="badge bg-soft-danger text-danger">High</span>' : '' ?><?= $r['recurrence'] ? ' <i class="feather-repeat text-muted fs-11" title="Repeats"></i>' : '' ?></div>
        <div class="fs-11 text-muted text-truncate"><?= e($when) ?><?= $rec !== null ? ' · ' . hx_link($rec[3], e($rec[2]), 'text-muted') : '' ?><?= $show_owner && $r['owner_name'] ? ' · ' . e($r['owner_name']) : '' ?><?= $r['outcome'] ? ' · ' . e(OUTCOME_WORDS[$r['outcome']] ?? $r['outcome']) : '' ?></div>
    </div>
    <span class="next-chip next-<?= e($r['state']) ?> d-none d-sm-inline-block"><?= e(ACTIVITY_STATE_WORDS[$r['state']] ?? $r['state']) ?></span>
    <?php if ($may['complete']): ?><?= hx_link(with_back('/activities/' . $id . '/complete', $back), '<i class="feather-check me-1"></i>Done', 'btn btn-sm btn-light btn-touch', 'id="activity-row-' . $id . '-done"') ?><?php endif; ?>
</div>
