<?php /** The quick log of a call (#log-call-form) — one screen on a phone: who, the deal, the outcome as large buttons, what was said, when, the next step.
 * Posts activity_create with done = yes. Data: v, deal, contact, deals, lead, tz, prompt, back, next_subject, now, hour_ago */
$kind = $kind ?? 'call';
$fid = 'log-' . $kind . '-form';
$f = static fn (string $name): string => 'log-' . $kind . '-field-' . $name; ?>
<?= view('shared/header.php', ['id' => 'log-' . $kind, 'title' => $kind === 'call' ? 'Log a call' : 'Log a meeting', 'crumbs' => [['Today', '/'], ['Activities', '/activities'], [$kind === 'call' ? 'Log a call' : 'Log a meeting', null]],
    'back' => $back !== null ? [$back, 'where you were'] : null]) ?>
<div class="main-content" id="log-<?= $kind ?>-content">
    <form method="post" action="/activities/save.php" hx-post="/activities/save.php" hx-target="#flash" id="<?= $fid ?>"><?= csrf_field() ?>
        <input type="hidden" name="kind" value="<?= e($kind) ?>"><input type="hidden" name="done" value="yes">
        <?php if ($v['lead'] !== null): ?><input type="hidden" name="lead" value="<?= (int) $v['lead'] ?>"><?php endif; ?>
        <?php if ($v['account'] !== null && $contact === null && $deal === null): ?><input type="hidden" name="account" value="<?= (int) $v['account'] ?>"><?php endif; ?>
        <div class="card mb-3"><div class="card-body"><div class="row g-3">
            <?php if ($lead !== null): ?>
                <div class="col-12" id="<?= $f('lead') ?>"><div class="fs-11 text-muted">The lead</div><div class="fw-semibold"><?= e($lead['label']) ?></div>
                    <?php if ($kind === 'call' && $lead['phone']): ?><a href="tel:<?= e($lead['phone']) ?>" class="fs-12"><i class="feather-phone me-1"></i><?= e($lead['phone']) ?></a><?php endif; ?></div>
            <?php else: ?>
                <div class="col-12"><label class="form-label" id="<?= $f('contact') ?>-label" for="<?= $f('contact') ?>">Who</label>
                    <?= picker_field(['id' => $f('contact'), 'name' => 'contact', 'source' => 'contact', 'value' => $v['contact'], 'labelledby' => $f('contact') . '-label', 'title' => $kind === 'call' ? 'Who you spoke to' : 'Who you met', 'placeholder' => 'Choose a contact']) ?>
                    <?php if ($contact !== null && $kind === 'call'): ?><div class="fs-12 mt-1" id="<?= $f('contact') ?>-phone"><?php foreach (array_filter([$contact['phone'], $contact['mobile']]) as $p): ?><a href="tel:<?= e($p) ?>" class="me-3"><i class="feather-phone me-1"></i><?= e($p) ?></a><?php endforeach; ?>
                        <?php if ($contact['do_not_call']): ?><span class="badge bg-soft-danger text-danger" id="<?= $f('contact') ?>-dnc">Do not call</span><?php endif; ?></div><?php endif; ?></div>
                <div class="col-12"><div class="form-label" id="<?= $f('deal') ?>-label">The deal</div>
                    <?php if ($deal !== null): ?><input type="hidden" name="deal" value="<?= (int) $deal['deal_id'] ?>"><div class="fw-semibold" id="<?= $f('deal') ?>"><?= e($deal['title']) ?></div>
                    <?php elseif ($contact !== null && $deals !== [] && count($deals) <= 3): ?>
                        <div class="d-flex flex-wrap gap-2" role="radiogroup" aria-labelledby="<?= $f('deal') ?>-label" id="<?= $f('deal') ?>">
                            <?php foreach ($deals as $i => $d): ?><input type="radio" class="btn-check" name="deal" value="<?= (int) $d['deal_id'] ?>" id="<?= $f('deal-' . (int) $d['deal_id']) ?>"<?= $i === 0 && count($deals) === 1 ? ' checked' : '' ?>>
                                <label class="btn btn-outline-secondary btn-touch" for="<?= $f('deal-' . (int) $d['deal_id']) ?>"><?= e($d['title']) ?></label><?php endforeach; ?>
                            <input type="radio" class="btn-check" name="deal" value="" id="<?= $f('deal-none') ?>"<?= count($deals) > 1 ? ' checked' : '' ?>><label class="btn btn-outline-secondary btn-touch" for="<?= $f('deal-none') ?>">No deal</label></div>
                    <?php else: ?>
                        <?= picker_field(['id' => $f('deal'), 'name' => 'deal', 'source' => 'deal', 'value' => null, 'labelledby' => $f('deal') . '-label', 'title' => 'The deal', 'placeholder' => 'None']) ?>
                    <?php endif; ?></div>
            <?php endif; ?>
            <div class="col-12"><div class="form-label">How did it go?</div><?= view('activities/partials/outcome-buttons.php', ['kind' => $kind]) ?></div>
            <div class="col-12"><label class="form-label" for="<?= $f('body') ?>">What was said</label><textarea class="form-control" name="body" id="<?= $f('body') ?>" rows="4" maxlength="10000" placeholder="Tap the microphone on your keyboard to dictate"></textarea></div>
            <div class="col-md-6"><label class="form-label" for="<?= $f('subject') ?>">Subject <span class="fs-11 text-muted">(optional)</span></label><input type="text" class="form-control btn-touch" name="subject" id="<?= $f('subject') ?>" maxlength="300" placeholder="<?= e(ACTIVITY_KINDS[$kind]) ?> with …"></div>
            <div class="col-md-6"><label class="form-label" for="<?= $f('when') ?>"><?= $kind === 'call' ? 'When' : 'It started' ?></label>
                <input type="datetime-local" class="form-control btn-touch" name="<?= $kind === 'call' ? 'done_at' : 'starts_at' ?>" id="<?= $f('when') ?>" value="<?= e($kind === 'call' ? $now : $hour_ago) ?>"></div>
        </div></div></div>
        <?= view('activities/partials/next-step.php', ['prompt' => $prompt, 'subject' => $next_subject, 'tz' => $tz]) ?>
        <div class="d-flex gap-2 mb-3"><button type="submit" class="btn btn-primary btn-touch flex-grow-1" id="<?= $fid ?>-save-btn">Log it</button>
            <?= $back !== null ? hx_link($back, 'Cancel', 'btn btn-light btn-touch', 'id="' . $fid . '-cancel-btn"') : '' ?></div>
    </form>
</div>
