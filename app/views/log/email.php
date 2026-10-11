<?php /** The quick log of an email sent or received elsewhere (#log-email-form): who, which way, the subject, the pasted text, the deal, when. Posts email_log. Data: contact, deal, deals, back, now, tz */
$f = static fn (string $name): string => 'log-email-field-' . $name; ?>
<?= view('shared/header.php', ['id' => 'log-email', 'title' => 'Log an email', 'crumbs' => [['Today', '/'], ['Email', '/email/unmatched'], ['Log an email', null]], 'back' => $back !== null ? [$back, 'where you were'] : null]) ?>
<div class="main-content" id="log-email-content">
    <form method="post" action="/log/email.php" hx-post="/log/email.php" hx-target="#flash" id="log-email-form"><?= csrf_field() ?>
        <?php if ($back !== null): ?><input type="hidden" name="return_to" value="<?= e($back) ?>"><?php endif; ?>
        <div class="card mb-3"><div class="card-body"><div class="row g-3">
            <div class="col-12"><label class="form-label" id="<?= $f('contact') ?>-label" for="<?= $f('contact') ?>">Who it was with</label>
                <?= picker_field(['id' => $f('contact'), 'name' => 'contact', 'source' => 'contact', 'value' => $contact['contact_id'] ?? null, 'labelledby' => $f('contact') . '-label', 'title' => 'Who it was with', 'placeholder' => 'Choose a contact', 'required' => true]) ?>
                <?php if ($contact !== null && $contact['email_opt_out']): ?><div class="fs-12 text-danger mt-1" id="<?= $f('contact') ?>-optout"><i class="feather-slash me-1"></i>They have opted out of email; you can still log what passed.</div><?php endif; ?></div>
            <div class="col-12"><div class="form-label" id="<?= $f('direction') ?>-label">Which way</div>
                <div class="d-flex flex-wrap gap-2" role="radiogroup" aria-labelledby="<?= $f('direction') ?>-label" id="<?= $f('direction') ?>">
                    <input type="radio" class="btn-check" name="direction" value="out" id="<?= $f('direction-out') ?>" checked><label class="btn btn-outline-secondary btn-touch" for="<?= $f('direction-out') ?>"><i class="feather-arrow-up-right me-1"></i>I sent it</label>
                    <input type="radio" class="btn-check" name="direction" value="in" id="<?= $f('direction-in') ?>"><label class="btn btn-outline-secondary btn-touch" for="<?= $f('direction-in') ?>"><i class="feather-arrow-down-left me-1"></i>They sent it</label></div></div>
            <div class="col-12"><div class="form-label" id="<?= $f('deal') ?>-label">The deal</div>
                <?php if ($deal !== null): ?><input type="hidden" name="deal" value="<?= (int) $deal['deal_id'] ?>"><div class="fw-semibold" id="<?= $f('deal') ?>"><?= e($deal['title']) ?></div>
                <?php elseif ($contact !== null && $deals !== [] && count($deals) <= 3): ?>
                    <div class="d-flex flex-wrap gap-2" role="radiogroup" aria-labelledby="<?= $f('deal') ?>-label" id="<?= $f('deal') ?>">
                        <?php foreach ($deals as $i => $d): ?><input type="radio" class="btn-check" name="deal" value="<?= (int) $d['deal_id'] ?>" id="<?= $f('deal-' . (int) $d['deal_id']) ?>"<?= count($deals) === 1 ? ' checked' : '' ?>>
                            <label class="btn btn-outline-secondary btn-touch" for="<?= $f('deal-' . (int) $d['deal_id']) ?>"><?= e($d['title']) ?></label><?php endforeach; ?>
                        <input type="radio" class="btn-check" name="deal" value="" id="<?= $f('deal-none') ?>"<?= count($deals) > 1 ? ' checked' : '' ?>><label class="btn btn-outline-secondary btn-touch" for="<?= $f('deal-none') ?>">No deal</label></div>
                <?php else: ?><?= picker_field(['id' => $f('deal'), 'name' => 'deal', 'source' => 'deal', 'value' => null, 'labelledby' => $f('deal') . '-label', 'title' => 'The deal', 'placeholder' => 'None']) ?><?php endif; ?></div>
            <div class="col-12"><label class="form-label" for="<?= $f('subject') ?>">Subject</label><input type="text" class="form-control btn-touch" name="subject" id="<?= $f('subject') ?>" maxlength="300" required placeholder="What the email was about"></div>
            <div class="col-12"><label class="form-label" for="<?= $f('body') ?>">The text <span class="fs-11 text-muted">(paste it, or leave it out)</span></label><textarea class="form-control" name="body" id="<?= $f('body') ?>" rows="6" maxlength="100000"></textarea></div>
            <div class="col-md-6"><label class="form-label" for="<?= $f('at') ?>">When</label><input type="datetime-local" class="form-control btn-touch" name="at" id="<?= $f('at') ?>" value="<?= e($now) ?>"><div class="fs-11 text-muted">In <?= e($tz) ?>.</div></div>
        </div></div></div>
        <div class="d-flex gap-2 mb-3"><button type="submit" class="btn btn-primary btn-touch flex-grow-1" id="log-email-form-save-btn">Log it</button>
            <?= $back !== null ? hx_link($back, 'Cancel', 'btn btn-light btn-touch', 'id="log-email-form-cancel-btn"') : '' ?></div>
    </form>
</div>
