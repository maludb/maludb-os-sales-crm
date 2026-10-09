<?php /** The opt-out banner: shown when the contact said no to email or texts (Sales CRM never sends them either). Data: c, may, tz */
$cid = (int) $c['contact_id'];
if (!$c['email_opt_out'] && !$c['sms_opt_out']) { return; } ?>
<div class="alert alert-danger d-flex flex-wrap align-items-center gap-2" id="contact-opt-out-banner" role="status">
    <i class="feather-slash"></i>
    <div class="flex-grow-1 min-w-0"><span class="fw-semibold"><?= $c['email_opt_out'] && $c['sms_opt_out'] ? 'No email and no texts.' : ($c['email_opt_out'] ? 'No email.' : 'No texts.') ?></span>
        <?= e((string) ($c['name'] ?: 'This contact')) ?> asked not to be contacted that way<?= $c['email_opt_out_at'] ? ' on ' . e(format_ts($c['email_opt_out_at'], $tz, 'M j, Y')) : '' ?><?= $c['opt_out_reason'] ? ' — “' . e($c['opt_out_reason']) . '”' : '' ?>. Logging what they send is still fine.</div>
    <?php if ($may['edit']): ?>
        <?php foreach (['email' => $c['email_opt_out'], 'sms' => $c['sms_opt_out']] as $ch => $on): if (!$on) { continue; } ?>
        <form method="post" action="/contacts/opt-out.php" hx-post="/contacts/opt-out.php" hx-target="#flash" hx-confirm="Did <?= e((string) $c['name']) ?> agree to <?= $ch === 'sms' ? 'texts' : 'email' ?> again?"><?= csrf_field() ?>
            <input type="hidden" name="contact" value="<?= $cid ?>"><input type="hidden" name="channel" value="<?= $ch ?>"><input type="hidden" name="undo" value="yes">
            <button type="submit" class="btn btn-light btn-sm btn-touch" id="contact-opt-in-<?= $ch ?>-btn">They agreed to <?= $ch === 'sms' ? 'texts' : 'email' ?> again</button></form>
        <?php endforeach; ?>
    <?php endif; ?>
</div>
