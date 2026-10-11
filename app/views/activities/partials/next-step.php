<?php /** The next step (#next-step-section) — the Done form's prompt and the quick-log forms' last section: its kind (none by default unless asked), when
 * (tomorrow 10:00, in 3 days, next week — or a date and a time), and a subject prefilled "Follow up with <name>". Open when `prompt`; folded otherwise
 * (folded, its kind stays "No next step", so nothing is made unless the person chooses). Data: prompt, subject, tz, kind? (the default kind when asked) */
$kind = $kind ?? 'call';
$body = '<div class="row g-2">'
    . '<div class="col-md-4"><label class="form-label fs-11 mb-0" for="next-step-kind">What</label><select class="form-select btn-touch" name="next_kind" id="next-step-kind">'
    . '<option value="none"' . ($prompt ? '' : ' selected') . '>No next step</option>'
    . implode('', array_map(static fn (string $k): string => '<option value="' . e($k) . '"' . ($prompt && $k === $kind ? ' selected' : '') . '>' . e(ACTIVITY_KINDS[$k]) . '</option>', ACTIVITY_FORM_KINDS)) . '</select></div>'
    . '<div class="col-md-8"><label class="form-label fs-11 mb-0" for="next-step-subject">Subject</label><input type="text" class="form-control btn-touch" name="next_subject" id="next-step-subject" maxlength="300" value="' . e($subject) . '"></div>'
    . '<div class="col-12"><div class="fs-11 text-muted mb-1">When</div><div class="d-flex flex-wrap gap-1" role="radiogroup" aria-label="When">';
$first = true;
foreach (NEXT_WHEN_CHOICES as $v => $label) {
    $body .= '<input type="radio" class="btn-check" name="next_at" id="next-step-at-' . $v . '" value="' . $v . '"' . ($first ? ' checked' : '') . '><label class="btn btn-outline-secondary btn-touch" for="next-step-at-' . $v . '">' . e($label) . '</label>';
    $first = false;
}
$body .= '<input type="radio" class="btn-check" name="next_at" id="next-step-at-pick" value="pick"><label class="btn btn-outline-secondary btn-touch" for="next-step-at-pick">On…</label>'
    . '<input type="datetime-local" class="form-control btn-touch w-auto" name="next_at_time" id="next-step-at-time" aria-label="On a date and time" onfocus="document.getElementById(\'next-step-at-pick\').checked=true"></div></div></div>'; ?>
<?php if ($prompt): ?>
<section class="card mb-3 border-primary" id="next-step-section"><div class="card-header"><h6 class="card-title mb-0">Schedule the next step?</h6></div><div class="card-body">
    <div class="fs-12 text-muted mb-2" id="next-step-why">The deal has no other step planned after this one.</div><?= $body ?></div></section>
<?php else: ?>
<details class="card mb-3" id="next-step-section"><summary class="card-header fw-semibold">Schedule a next step too</summary><div class="card-body"><?= $body ?></div></details>
<?php endif; ?>
