<?php /** The agenda (the phone's default view): overdue first in red, then today (done ones struck), tomorrow, the rest of the week — each row the kind,
 * the time, the subject and the record; a row opens the activity. Data: cal, colours, may */
$sections = ['overdue' => ['Overdue', 'text-danger'], 'today' => ['Today', ''], 'tomorrow' => ['Tomorrow', ''], 'week' => ['Later this week', '']];
$total = array_sum(array_map('count', $cal['agenda'])); ?>
<div id="calendar-agenda">
    <?php if ($total === 0): ?>
        <div class="card"><div class="card-body empty-state" id="calendar-agenda-empty"><span class="avatar-text avatar-lg rounded"><i class="feather-calendar"></i></span>
            <div><div class="fw-semibold">Nothing planned this week</div><div class="fs-12 text-muted">Schedule a call, a meeting or a task<?= $may['create'] && nav_built('/activities/form.php') ? ': ' . hx_link('/activities/new', 'New') : '' ?>.</div></div></div></div>
    <?php endif; ?>
    <?php foreach ($sections as $key => [$label, $tone]): $rows = $cal['agenda'][$key]; if ($rows === []) { continue; } ?>
    <div class="card mb-3" id="calendar-agenda-<?= $key ?>"><div class="card-header py-2"><h6 class="card-title mb-0 <?= $tone ?>"><?= e($label) ?> <span class="badge bg-soft-secondary text-secondary"><?= count($rows) ?></span></h6></div>
        <div class="list-group list-group-flush">
        <?php foreach ($rows as $r): $id = (int) $r['activity_id']; $c = $colours[(int) ($r['owner_member_id'] ?? 0)] ?? null; ?>
            <a href="<?= e(with_back('/activities/' . $id, calendar_url($cal))) ?>" class="list-group-item list-group-item-action d-flex align-items-center gap-2 cal-agenda-row cal-state-<?= e($r['state']) ?>" id="cal-item-<?= $id ?>"
               hx-get="<?= e(with_back('/activities/' . $id, calendar_url($cal))) ?>" hx-target="#page-content" hx-swap="innerHTML" hx-push-url="<?= e(with_back('/activities/' . $id, calendar_url($cal))) ?>">
                <span class="avatar-text avatar-md rounded cal-kind-<?= e($r['kind']) ?>"><i class="<?= e(activity_kind_icon($r['kind'])) ?>"></i></span>
                <div class="min-w-0 flex-grow-1"><div class="fw-semibold text-truncate cal-subject"><?= e($r['subject']) ?></div>
                    <div class="fs-11 text-muted text-truncate"><?= $r['all_day'] ? 'All day' : e(format_ts($r['starts_at'], $cal['tz'], $key === 'overdue' || $key === 'week' ? 'D M j, g:i A' : 'g:i A')) ?><?= $r['record_name'] ? ' · ' . e($r['record_name']) : '' ?><?= $c !== null ? ' · ' . e($c['name']) : '' ?></div></div>
                <span class="next-chip next-<?= e($r['state']) ?>"><?= e(ACTIVITY_STATE_WORDS[$r['state']] ?? $r['state']) ?></span>
            </a>
        <?php endforeach; ?>
        </div></div>
    <?php endforeach; ?>
</div>
