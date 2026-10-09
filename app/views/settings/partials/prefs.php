<?php /** How I am told and reminded. Data: prefs, defaults, refusal, osChannels */ ?>
<form method="post" action="/settings/prefs.php" hx-post="/settings/prefs.php" hx-target="#flash" id="prefs">
    <?= csrf_field() ?><input type="hidden" name="return_to" value="/settings/">
    <div class="card mb-3"><div class="card-header"><h5 class="card-title mb-0">How you are told</h5></div><div class="card-body">
        <input type="hidden" name="email_enabled" value="no"><input type="hidden" name="text_enabled" value="no">
        <label class="d-flex align-items-center gap-2 border rounded px-3 mb-2 btn-touch" for="prefs-field-email"><input type="checkbox" class="form-check-input mt-0" name="email_enabled" value="yes" id="prefs-field-email" <?= $prefs['email_enabled'] ? 'checked' : '' ?>><i class="feather-mail"></i> Email</label>
        <label class="d-flex align-items-center gap-2 border rounded px-3 mb-2 btn-touch" for="prefs-field-text"><input type="checkbox" class="form-check-input mt-0" name="text_enabled" value="yes" id="prefs-field-text" <?= $prefs['text_enabled'] ? 'checked' : '' ?>><i class="feather-message-square"></i> Text message</label>
        <?php if ($refusal === 'no_verified_phone'): ?>
            <div class="alert alert-warning fs-12 mb-2" id="prefs-text-note">Texts need a phone number verified in the operating system. <a href="<?= e($osChannels) ?>" class="alert-link" id="prefs-text-link">Add or verify your phone</a>. Until then you are emailed.</div>
        <?php elseif ($refusal === 'opted_out'): ?>
            <div class="alert alert-warning fs-12 mb-2" id="prefs-text-note">You turned texts off (or replied STOP). You can turn them back on in the operating system: <a href="<?= e($osChannels) ?>" class="alert-link" id="prefs-text-link">your channels</a>. Until then you are emailed.</div>
        <?php elseif ($refusal === 'no_sender'): ?>
            <div class="alert alert-secondary fs-12 mb-2" id="prefs-text-note">This business has not set up texting yet, so you are emailed.</div>
        <?php else: ?>
            <div class="fs-12 text-muted mb-2" id="prefs-text-hint">Texts go to the phone you verified in the operating system. <a href="<?= e($osChannels) ?>" id="prefs-text-link">Your channels</a></div>
        <?php endif; ?>
    </div></div>
    <div class="card mb-3"><div class="card-header"><h5 class="card-title mb-0">Tell me when</h5></div><div class="card-body" id="prefs-kinds">
        <input type="hidden" name="kinds[]" value="">
        <?php foreach (NOTICE_KINDS as $k => $label): ?>
            <label class="d-flex align-items-center gap-2 border rounded px-3 mb-2 btn-touch" for="prefs-field-kind-<?= e($k) ?>"><input type="checkbox" class="form-check-input mt-0" name="kinds[]" value="<?= e($k) ?>" id="prefs-field-kind-<?= e($k) ?>" <?= in_array($k, $prefs['kinds'], true) ? 'checked' : '' ?>><?= e($label) ?></label>
        <?php endforeach; ?>
    </div></div>
    <div class="card mb-3"><div class="card-header"><h5 class="card-title mb-0">Text me when</h5></div><div class="card-body" id="prefs-text-kinds">
        <div class="fs-12 text-muted mb-2">Only when texts are on. Fewer is better: a text should mean "look now".</div>
        <input type="hidden" name="text_kinds[]" value="">
        <?php foreach (NOTICE_KINDS as $k => $label): ?>
            <label class="d-flex align-items-center gap-2 border rounded px-3 mb-2 btn-touch" for="prefs-field-text-kind-<?= e($k) ?>"><input type="checkbox" class="form-check-input mt-0" name="text_kinds[]" value="<?= e($k) ?>" id="prefs-field-text-kind-<?= e($k) ?>" <?= in_array($k, $prefs['text_kinds'], true) ? 'checked' : '' ?>><?= e($label) ?></label>
        <?php endforeach; ?>
    </div></div>
    <div class="card mb-3"><div class="card-header"><h5 class="card-title mb-0">Reminders and the morning note</h5></div><div class="card-body" id="prefs-reminders">
        <label class="form-label fs-12 text-muted" for="prefs-field-remind">A new activity reminds me</label>
        <select name="default_remind_minutes" id="prefs-field-remind" class="form-select btn-touch mb-3">
            <option value=""<?= $prefs['default_remind_minutes'] === null ? ' selected' : '' ?>>The business's default (<?= e(REMIND_CHOICES[$defaults['default_remind_minutes']] ?? $defaults['default_remind_minutes'] . ' minutes before') ?>)</option>
            <?php foreach (REMIND_CHOICES as $m => $label): ?><option value="<?= (int) $m ?>"<?= $prefs['default_remind_minutes'] === $m ? ' selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?>
        </select>
        <input type="hidden" name="morning_note" value="no">
        <label class="d-flex align-items-center gap-2 border rounded px-3 mb-2 btn-touch" for="prefs-field-morning"><input type="checkbox" class="form-check-input mt-0" name="morning_note" value="yes" id="prefs-field-morning" <?= $prefs['morning_note'] ? 'checked' : '' ?>><i class="feather-sunrise"></i> The Sales Assistant's morning note on weekdays</label>
        <label class="form-label fs-12 text-muted" for="prefs-field-morning-hour">At</label>
        <select name="morning_hour" id="prefs-field-morning-hour" class="form-select btn-touch mb-3">
            <option value=""<?= $prefs['morning_hour'] === null ? ' selected' : '' ?>>The business's hour (<?= sprintf('%02d:00', $defaults['morning_hour']) ?>)</option>
            <?php for ($h = 0; $h < 24; $h++): ?><option value="<?= $h ?>"<?= $prefs['morning_hour'] === $h ? ' selected' : '' ?>><?= sprintf('%02d:00', $h) ?></option><?php endfor; ?>
        </select>
        <input type="hidden" name="digest" value="no">
        <label class="d-flex align-items-center gap-2 border rounded px-3 mb-0 btn-touch" for="prefs-field-digest"><input type="checkbox" class="form-check-input mt-0" name="digest" value="yes" id="prefs-field-digest" <?= $prefs['digest'] ? 'checked' : '' ?>><i class="feather-inbox"></i> One email a day instead of one per notice</label>
    </div></div>
    <button type="submit" class="btn btn-primary btn-touch w-100" id="prefs-save-btn">Save</button>
</form>
