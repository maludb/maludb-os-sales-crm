<?php /** Done (#activity-complete-form): when it was done (now), the outcome as large buttons, a note, and the next step — the prompt open when the deal will
 * have no step after this one. Data: a, prompt, tz, now, next_subject */
$id = (int) $a['activity_id'];
$rec = activity_record($a);
$back = safe_local_path($_GET['back'] ?? null) ?? '/activities/' . $id; ?>
<?= view('shared/header.php', ['id' => 'activity-complete', 'title' => 'Done: ' . $a['subject'], 'crumbs' => [['Today', '/'], ['Activities', '/activities'], [$a['subject'], '/activities/' . $id], ['Done', null]],
    'back' => [$back, 'the activity']]) ?>
<div class="main-content" id="activity-complete-content">
    <form method="post" action="/activities/complete.php" hx-post="/activities/complete.php" hx-target="#flash" id="activity-complete-form"><?= csrf_field() ?>
        <input type="hidden" name="activity" value="<?= $id ?>"><?php if ($back !== '/activities/' . $id): ?><input type="hidden" name="return_to" value="<?= e($back) ?>"><?php endif; ?>
        <div class="card mb-3"><div class="card-body">
            <div class="fs-12 text-muted mb-2"><i class="<?= e(activity_kind_icon($a['kind'])) ?> me-1"></i><?= e(ACTIVITY_KINDS[$a['kind']]) ?><?= $rec !== null ? ' · ' . e($rec[2]) : '' ?> · <?= e(format_ts($a['at'], $tz, 'D M j, g:i A')) ?></div>
            <div class="mb-3"><div class="form-label">How did it go?</div><?= view('activities/partials/outcome-buttons.php', ['kind' => $a['kind']]) ?></div>
            <div class="row g-2">
                <div class="col-md-5"><label class="form-label" for="activity-complete-form-field-done_at">Done</label><input type="datetime-local" class="form-control btn-touch" name="done_at" id="activity-complete-form-field-done_at" value="<?= e($now) ?>"></div>
                <div class="col-12"><label class="form-label" for="activity-complete-form-field-outcome_note">A note</label><textarea class="form-control" name="outcome_note" id="activity-complete-form-field-outcome_note" rows="3" maxlength="2000" placeholder="What was agreed"></textarea></div>
            </div>
        </div></div>
        <?= view('activities/partials/next-step.php', ['prompt' => $prompt, 'subject' => $next_subject, 'tz' => $tz]) ?>
        <div class="d-flex gap-2 mb-3"><button type="submit" class="btn btn-success btn-touch flex-grow-1" id="activity-complete-btn"><i class="feather-check me-1"></i>Mark done</button>
            <?= hx_link($back, 'Cancel', 'btn btn-light btn-touch', 'id="activity-complete-cancel-btn"') ?></div>
    </form>
</div>
