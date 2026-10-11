<?php /** The logging mailbox (screen `admin-mailbox`; ids mailbox-form, mailbox-health, mailbox-recipe). Data: m (mailbox() or null), health (mailbox_summary), webhook_url, tz, notice */
$v = $m ?? ['id' => null, 'address' => '', 'display_name' => 'Sales CRM', 'provider' => 'malumail', 'webhook_secret_ref' => 'MAILBOX_CRM_WEBHOOK_SECRET', 'signature' => '', 'poll_enabled' => true, 'active' => true];
$tone = ['ok' => 'success', 'quiet' => 'warning', 'error' => 'danger', 'off' => 'secondary', 'none' => 'secondary'][$health['state']] ?? 'secondary';
$when = static fn (?string $t): string => $t === null ? 'never' : format_ts($t, $tz, 'M j, g:i A'); ?>
<?= view('shared/header.php', ['id' => 'admin-mailbox', 'title' => 'Logging mailbox', 'crumbs' => [['Today', '/'], ['Admin', '/admin'], ['Logging mailbox', null]]]) ?>
<div class="main-content" id="admin-mailbox-content">
    <?= view('shared/notice.php', ['notice' => $notice]) ?>
    <div class="row g-3">
        <div class="col-xl-7">
            <form method="post" action="/admin/mailbox/save.php" hx-post="/admin/mailbox/save.php" hx-target="#flash" id="mailbox-form"><?= csrf_field() ?>
                <?php if ($m !== null): ?><input type="hidden" name="mailbox" value="<?= (int) $m['id'] ?>"><?php endif; ?>
                <div class="card mb-3"><div class="card-header"><h5 class="card-title mb-0"><?= $m === null ? 'Set up the mailbox' : 'The mailbox' ?></h5></div><div class="card-body"><div class="row g-3">
                    <div class="col-md-7"><label class="form-label" for="mailbox-form-field-address">Address</label>
                        <input type="email" class="form-control btn-touch" id="mailbox-form-field-address" name="address" value="<?= e($v['address']) ?>" maxlength="254" required placeholder="crm-1a2b3c4d@mail.example.com">
                        <div class="form-text">Sellers BCC it; every email the application sends has it as Reply-To.</div></div>
                    <div class="col-md-5"><label class="form-label" for="mailbox-form-field-name">Name</label>
                        <input type="text" class="form-control btn-touch" id="mailbox-form-field-name" name="display_name" value="<?= e($v['display_name']) ?>" maxlength="80" required></div>
                    <div class="col-md-5"><label class="form-label" for="mailbox-form-field-provider">Provider</label>
                        <select class="form-select btn-touch" id="mailbox-form-field-provider" name="provider"><option value="malumail" <?= $v['provider'] === 'malumail' ? 'selected' : '' ?>>MaluMail</option><option value="imap" <?= $v['provider'] === 'imap' ? 'selected' : '' ?>>IMAP</option></select></div>
                    <div class="col-md-7"><label class="form-label" for="mailbox-form-field-secret">The webhook secret's key name</label>
                        <input type="text" class="form-control btn-touch font-monospace" id="mailbox-form-field-secret" name="webhook_secret_ref" value="<?= e((string) $v['webhook_secret_ref']) ?>" maxlength="64" pattern="[A-Z][A-Z0-9_]*" placeholder="MAILBOX_CRM_WEBHOOK_SECRET" autocomplete="off" spellcheck="false">
                        <div class="form-text" id="mailbox-secret-help">The <strong>name</strong> of the line in <code>config/.env</code> that holds the secret — never the secret itself.
                            <?php if ($m !== null): ?><span id="mailbox-secret-state" class="<?= $m['secret_set'] ? 'text-success' : 'text-danger' ?>"><?= $m['secret_set'] ? 'That key is set.' : 'That key is not set — the webhook is refused until it is.' ?></span><?php endif; ?></div></div>
                    <div class="col-12"><label class="form-label" for="mailbox-form-field-signature">Signature</label>
                        <textarea class="form-control" id="mailbox-form-field-signature" name="signature" rows="3" maxlength="2000"><?= e((string) $v['signature']) ?></textarea>
                        <div class="form-text">Under the emails sellers send, after their own.</div></div>
                    <div class="col-6"><input type="hidden" name="poll_enabled" value="no"><div class="form-check form-switch"><input class="form-check-input" type="checkbox" role="switch" name="poll_enabled" value="yes" id="mailbox-form-field-poll" <?= $v['poll_enabled'] ? 'checked' : '' ?>>
                        <label class="form-check-label" for="mailbox-form-field-poll">Also check for mail once a minute</label></div></div>
                    <div class="col-6"><input type="hidden" name="active" value="no"><div class="form-check form-switch"><input class="form-check-input" type="checkbox" role="switch" name="active" value="yes" id="mailbox-form-field-active" <?= $v['active'] ? 'checked' : '' ?>>
                        <label class="form-check-label" for="mailbox-form-field-active">Switched on</label></div></div>
                </div></div></div>
                <div class="admin-save-bar d-flex gap-2 mb-3"><button type="submit" class="btn btn-primary btn-touch flex-grow-1" id="mailbox-form-save-btn"><?= $m === null ? 'Set up the mailbox' : 'Save' ?></button>
                    <?= hx_link('/admin', 'Cancel', 'btn btn-light btn-touch', 'id="mailbox-form-cancel-btn"') ?></div>
            </form>
        </div>
        <div class="col-xl-5">
            <div class="card mb-3" id="mailbox-health"><div class="card-header d-flex align-items-center gap-2"><h5 class="card-title mb-0 flex-grow-1">Health</h5>
                <span class="badge bg-soft-<?= e($tone) ?> text-<?= e($tone) ?>" id="mailbox-health-state"><?= e($health['words']) ?></span></div>
                <div class="card-body fs-12">
                <?php if ($m === null): ?><p class="text-muted mb-0" id="mailbox-health-none">No mailbox yet. Fill in the form and save it; then connect it at MaluMail (below).</p>
                <?php else: ?>
                <dl class="row mb-0">
                    <dt class="col-6">Last webhook</dt><dd class="col-6" id="mailbox-last-webhook"><?= e($when($m['last_webhook_at'])) ?></dd>
                    <dt class="col-6">Last check for mail</dt><dd class="col-6" id="mailbox-last-poll"><?= e($when($m['last_polled_at'])) ?></dd>
                    <dt class="col-6">Arrived this week</dt><dd class="col-6" id="mailbox-received"><?= (int) $m['received_7d'] ?></dd>
                    <dt class="col-6">Waiting to be matched</dt><dd class="col-6" id="mailbox-pending"><?= (int) $m['pending'] ?></dd>
                    <dt class="col-6">Last failure</dt><dd class="col-6" id="mailbox-last-error"><?= $m['last_error'] === null ? 'none' : e((string) $m['last_error']) . '<div class="text-muted">' . e($when($m['last_error_at'])) . '</div>' ?></dd>
                </dl>
                <?php endif; ?>
                </div></div>
            <div class="card mb-3" id="mailbox-recipe"><div class="card-header"><h5 class="card-title mb-0">Connecting it (once, at MaluMail)</h5></div><div class="card-body fs-12">
                <ol class="ps-3 mb-2">
                    <li>At MaluMail, on the business's sending domain, create the mailbox above with a name nobody guesses, such as <code>crm-&lt;16 random characters&gt;@&lt;mail domain&gt;</code>.</li>
                    <li>Give it an inbound webhook to <code class="user-select-all text-break" id="mailbox-webhook-url"><?= e($webhook_url) ?></code> and a webhook secret.</li>
                    <li>A server administrator puts the secret in <code>config/.env</code> as the key named in the form (<code>ROOT_STEPS</code> step 3). It is never typed into this page, and never printed.</li>
                    <li>Save this form with the address and the key's name. The health above turns green when the first webhook or check arrives.</li>
                </ol>
                <p class="text-muted mb-0">Sellers then BCC the address from their own mail; a customer's reply comes to it.</p>
            </div></div>
        </div>
    </div>
</div>
