<?php /** An activity's form (#activity-form; activity-add / activity-edit): the kind as buttons, the subject, the record (deal, contact, account, lead —
 * pickers), when (a due time for a task or a deadline; a start and an end for a call, a meeting or a lunch — calendar.js shows the kind's fields; without
 * JavaScript both stand, labelled), all day, the reminder, who does it, where, the priority, the repeat (tasks), notes; on a new one people taking part
 * and tags; custom fields. Data: a, values, tz, back, custom_html, may_tag, may_owner */
$editing = $a !== null;
$id = $editing ? (int) $a['activity_id'] : null;
$v = $values;
$cancel = $back ?? ($editing ? '/activities/' . $id : '/activities');
$kind = (string) $v['kind'];
$reminder = $editing && $v['remind_minutes_before'] === null ? 'none' : (string) ($v['remind_minutes_before'] ?? '');
$f = static fn (string $name): string => 'activity-form-field-' . $name; ?>
<?= view('shared/header.php', ['id' => 'activity-form', 'title' => $editing ? 'Edit ' . $a['subject'] : 'Schedule', 'crumbs' => [['Today', '/'], ['Activities', '/activities'], [$editing ? 'Edit' : 'Schedule', null]],
    'back' => $back !== null ? [$back, 'where you were'] : null]) ?>
<div class="main-content" id="activity-form-content">
    <form method="post" action="/activities/save.php" hx-post="/activities/save.php" hx-target="#flash" id="activity-form" data-kind="<?= e($kind) ?>">
        <?= csrf_field() ?><?php if ($editing): ?><input type="hidden" name="activity" value="<?= $id ?>"><?php endif; ?>
        <?php if ($back !== null): ?><input type="hidden" name="return_to" value="<?= e($back) ?>"><?php endif; ?>
        <div class="card mb-3"><div class="card-body"><div class="row g-3">
            <div class="col-12"><div class="form-label">What</div><div class="d-flex flex-wrap gap-1" role="radiogroup" aria-label="Kind" id="<?= $f('kind') ?>">
                <?php foreach (ACTIVITY_FORM_KINDS as $k): ?><input type="radio" class="btn-check" name="kind" value="<?= e($k) ?>" id="<?= $f('kind-' . $k) ?>"<?= $k === $kind ? ' checked' : '' ?>>
                    <label class="btn btn-outline-secondary btn-touch cal-kind-toggle cal-kind-<?= e($k) ?>" for="<?= $f('kind-' . $k) ?>"><i class="<?= e(activity_kind_icon($k)) ?> me-1"></i><?= e(ACTIVITY_KINDS[$k]) ?></label><?php endforeach; ?>
                <?php if ($kind === 'email'): ?><input type="hidden" name="kind" value="email"><?php endif; ?></div></div>
            <div class="col-12"><label class="form-label" for="<?= $f('subject') ?>">Subject</label><input type="text" class="form-control btn-touch" id="<?= $f('subject') ?>" name="subject" maxlength="300" required value="<?= e((string) $v['subject']) ?>" placeholder="Discovery call" autofocus></div>
            <div class="col-md-6" data-kind-when="due"><label class="form-label" for="<?= $f('due_at') ?>">Due <span class="fs-11 text-muted">(a task or a deadline)</span></label>
                <input type="datetime-local" class="form-control btn-touch" id="<?= $f('due_at') ?>" name="due_at" value="<?= e(local_input($v['due_at'], $tz)) ?>"></div>
            <div class="col-md-3" data-kind-when="timed"><label class="form-label" for="<?= $f('starts_at') ?>">Starts <span class="fs-11 text-muted">(a call, a meeting, a lunch)</span></label>
                <input type="datetime-local" class="form-control btn-touch" id="<?= $f('starts_at') ?>" name="starts_at" value="<?= e(local_input($v['starts_at'], $tz)) ?>"></div>
            <div class="col-md-3" data-kind-when="timed"><label class="form-label" for="<?= $f('ends_at') ?>">Ends</label>
                <input type="datetime-local" class="form-control btn-touch" id="<?= $f('ends_at') ?>" name="ends_at" value="<?= e(local_input($v['ends_at'], $tz)) ?>"></div>
            <div class="col-md-6"><input type="hidden" name="all_day" value="no"><label class="form-check mt-md-4"><input type="checkbox" class="form-check-input" name="all_day" value="yes" id="<?= $f('all_day') ?>"<?= $v['all_day'] ? ' checked' : '' ?>> All day</label>
                <div class="fs-11 text-muted">Times are in <?= e($tz) ?>.</div></div>
            <div class="col-md-6"><label class="form-label" id="activity-form-deal-label" for="<?= $f('deal') ?>">Deal</label>
                <?= picker_field(['id' => $f('deal'), 'name' => 'deal', 'source' => 'deal', 'value' => $v['deal_id'], 'labelledby' => 'activity-form-deal-label', 'title' => 'The deal', 'placeholder' => 'None', 'include' => '#' . $f('account')]) ?></div>
            <div class="col-md-6"><label class="form-label" id="activity-form-contact-label" for="<?= $f('contact') ?>">Contact</label>
                <?= picker_field(['id' => $f('contact'), 'name' => 'contact', 'source' => 'contact', 'value' => $v['contact_id'], 'labelledby' => 'activity-form-contact-label', 'title' => 'The contact', 'placeholder' => 'None', 'include' => '#' . $f('account')]) ?></div>
            <div class="col-md-6"><label class="form-label" id="activity-form-account-label" for="<?= $f('account') ?>">Account</label>
                <?= picker_field(['id' => $f('account'), 'name' => 'account', 'source' => 'account', 'value' => $v['account_id'], 'labelledby' => 'activity-form-account-label', 'title' => 'The account', 'placeholder' => 'None']) ?></div>
            <div class="col-md-6"><label class="form-label" id="activity-form-lead-label" for="<?= $f('lead') ?>">Lead</label>
                <?= picker_field(['id' => $f('lead'), 'name' => 'lead', 'source' => 'lead', 'value' => $v['lead_id'], 'labelledby' => 'activity-form-lead-label', 'title' => 'The lead', 'placeholder' => 'None']) ?></div>
            <div class="col-md-6"><label class="form-label" id="activity-form-owner-label" for="<?= $f('owner') ?>">Who does it</label>
                <?php if ($may_owner): ?><?= picker_field(['id' => $f('owner'), 'name' => 'owner', 'source' => 'owner', 'value' => $v['owner_member_id'], 'labelledby' => 'activity-form-owner-label', 'title' => 'Who does it', 'placeholder' => 'Me']) ?>
                <?php else: ?><div class="form-control-plaintext"><?= e((string) (member_name(db(), $v['owner_member_id']) ?? 'Nobody')) ?></div><?php endif; ?></div>
            <div class="col-md-6"><label class="form-label" for="<?= $f('remind_minutes_before') ?>">Reminder</label><select class="form-select btn-touch" id="<?= $f('remind_minutes_before') ?>" name="remind_minutes_before">
                <?php foreach (ACTIVITY_REMIND_CHOICES as $rv => $label): $rv = (string) $rv; if ($rv === '' && $editing) { continue; } ?><option value="<?= e($rv) ?>"<?= $rv === $reminder ? ' selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?>
                <?php if ($reminder !== '' && $reminder !== 'none' && !array_key_exists((int) $reminder, ACTIVITY_REMIND_CHOICES)): ?><option value="<?= e($reminder) ?>" selected><?= (int) $reminder ?> minutes before</option><?php endif; ?></select></div>
            <div class="col-md-6"><label class="form-label" for="<?= $f('location') ?>">Where</label><input type="text" class="form-control btn-touch" id="<?= $f('location') ?>" name="location" maxlength="300" value="<?= e((string) $v['location']) ?>" placeholder="Their office"></div>
            <div class="col-md-6"><label class="form-label" for="<?= $f('meeting_url') ?>">Meeting link</label><input type="url" class="form-control btn-touch" id="<?= $f('meeting_url') ?>" name="meeting_url" maxlength="500" value="<?= e((string) $v['meeting_url']) ?>" placeholder="https://"></div>
            <div class="col-md-3"><label class="form-label" for="<?= $f('priority') ?>">Priority</label><select class="form-select btn-touch" id="<?= $f('priority') ?>" name="priority">
                <?php foreach (ACTIVITY_PRIORITIES as $pv => $label): ?><option value="<?= $pv ?>"<?= $pv === $v['priority'] ? ' selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?></select></div>
            <div class="col-md-3" data-kind-when="task"><label class="form-label" for="<?= $f('recurrence') ?>">Repeats <span class="fs-11 text-muted">(a task)</span></label><select class="form-select btn-touch" id="<?= $f('recurrence') ?>" name="recurrence">
                <?php foreach (RECURRENCE_CHOICES as $rv => $label): ?><option value="<?= e($rv) ?>"<?= $rv === (string) ($v['recurrence'] ?? '') ? ' selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?></select></div>
            <?php if (!$editing): ?>
            <div class="col-md-6"><label class="form-label" id="activity-form-participants-label" for="<?= $f('participants') ?>">With (a contact)</label>
                <?= picker_field(['id' => $f('participants'), 'name' => 'participants', 'source' => 'contact', 'value' => null, 'labelledby' => 'activity-form-participants-label', 'title' => 'Who else takes part', 'placeholder' => 'Nobody else', 'include' => '#' . $f('account')]) ?></div>
            <div class="col-md-6"><label class="form-label" id="activity-form-members-label" for="<?= $f('members') ?>">With (a colleague)</label>
                <?= picker_field(['id' => $f('members'), 'name' => 'members', 'source' => 'member', 'value' => null, 'labelledby' => 'activity-form-members-label', 'title' => 'A colleague who comes along', 'placeholder' => 'Nobody else']) ?></div>
            <?php if ($may_tag): ?><div class="col-md-6"><label class="form-label" for="<?= $f('tags') ?>">Tags</label><input type="text" class="form-control btn-touch" id="<?= $f('tags') ?>" name="tags" maxlength="300" placeholder="demo, q4"></div><?php endif; ?>
            <?php endif; ?>
            <div class="col-12"><label class="form-label" for="<?= $f('body') ?>">Notes</label><textarea class="form-control" id="<?= $f('body') ?>" name="body" rows="3" maxlength="10000" placeholder="What to cover"><?= e((string) $v['body']) ?></textarea></div>
        </div></div></div>
        <?= $custom_html ?>
        <div class="d-flex gap-2 mb-3"><button type="submit" class="btn btn-primary btn-touch flex-grow-1" id="activity-form-save-btn"><?= $editing ? 'Save' : 'Schedule it' ?></button><?= hx_link($cancel, 'Cancel', 'btn btn-light btn-touch', 'id="activity-form-cancel-btn"') ?></div>
    </form>
</div>
