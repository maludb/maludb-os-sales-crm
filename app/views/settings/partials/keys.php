<?php /** The API keys card pair (screen `tokens`; ids keys-list, key-{id}). Data: keys, others (other people's, tokens.manage), limits [per minute, per day], may_raise, tz */ ?>
    <div class="row g-3 mt-1" id="keys-section">
        <div class="col-xl-5">
            <div class="card mb-0 h-100" id="keys-mint-card"><div class="card-header"><h5 class="card-title mb-0">API keys</h5></div><div class="card-body fs-12">
                <p>A key lets a script or another system read and write here <strong>as you</strong>: it sees exactly what you see, up to <?= (int) $limits[0] ?> calls a minute and <?= (int) $limits[1] ?> a day (you may lower these<?= $may_raise ? ', or raise them' : '' ?>). It is shown once.</p>
                <form method="post" action="/settings/tokens/key-mint.php" id="key-form" class="row g-2 align-items-end" hx-post="/settings/tokens/key-mint.php" hx-target="#page-content" hx-swap="innerHTML">
                    <?= csrf_field() ?>
                    <div class="col-12"><label class="form-label fs-12 text-muted" for="key-form-field-label">Label</label><input type="text" name="label" id="key-form-field-label" class="form-control btn-touch" required maxlength="80" placeholder="Nightly export"></div>
                    <div class="col-4"><label class="form-label fs-12 text-muted" for="key-form-field-minute">A minute</label><input type="number" name="rate_per_minute" id="key-form-field-minute" class="form-control btn-touch" min="1" max="100000" placeholder="<?= (int) $limits[0] ?>"></div>
                    <div class="col-4"><label class="form-label fs-12 text-muted" for="key-form-field-day">A day</label><input type="number" name="rate_per_day" id="key-form-field-day" class="form-control btn-touch" min="1" max="10000000" placeholder="<?= (int) $limits[1] ?>"></div>
                    <div class="col-4"><label class="form-label fs-12 text-muted" for="key-form-field-expires">Days left</label><input type="number" name="expires_in_days" id="key-form-field-expires" class="form-control btn-touch" min="1" max="3650" placeholder="never"></div>
                    <div class="col-12"><button type="submit" class="btn btn-primary btn-touch w-100" id="key-form-save-btn"><i class="feather-key me-1"></i>Mint a key</button></div>
                </form>
            </div></div>
        </div>
        <div class="col-xl-7">
            <div class="card mb-0 h-100" id="keys-card"><div class="card-header"><h5 class="card-title mb-0">Your keys</h5></div>
                <div class="card-body p-0" id="keys-list">
                <?php if ($keys === []): ?><div class="text-center text-muted py-4 fs-12" id="keys-empty">No keys yet.</div><?php endif; ?>
                <?php foreach ($keys as $k) { $mine = true; $id = (int) $k['key_id']; $live = key_is_live($k); $capture = $k['lead_source_id'] !== null; ?>
<div class="d-flex align-items-start gap-2 px-3 py-2 border-bottom <?= $live ? '' : 'text-muted' ?>" id="key-<?= $id ?>">
    <span class="wd-10 ht-10 mt-2 bg-<?= $live ? 'success' : 'secondary' ?> d-inline-block rounded-circle flex-shrink-0"></span>
    <div class="min-w-0 flex-grow-1 fs-12">
        <div class="fw-semibold text-truncate"><?= e($k['label']) ?>
            <?php if ($capture): ?><span class="badge bg-soft-info text-info ms-1">lead capture · <?= e((string) ($k['source_name'] ?? 'a source')) ?></span><?php endif; ?>
            <?php if (!$live): ?><span class="badge bg-soft-secondary text-secondary ms-1"><?= $k['revoked_at'] !== null ? 'revoked' : 'expired' ?></span><?php endif; ?></div>
        <div class="text-muted"><?= $mine ? '' : e((string) ($k['member_name'] ?? '')) . ' · ' ?><?= (int) $k['rate_per_minute'] ?> a minute · <?= (int) $k['rate_per_day'] ?> a day · <?= (int) $k['calls_today'] ?> today
            · last used <?= e(format_ts($k['last_used_at'], $tz, 'M j, g:i A') ?: 'never') ?> · <?= $k['expires_at'] ? ($live ? 'expires ' : 'expired ') . e(format_ts($k['expires_at'], $tz, 'M j, Y')) : 'never expires' ?></div>
    </div>
    <?php if ($live): ?>
    <div class="d-flex gap-1 flex-shrink-0">
        <?php if ($mine): ?><form method="post" action="/settings/tokens/key-rotate.php" hx-post="/settings/tokens/key-rotate.php" hx-confirm="Rotate the key <?= e($k['label']) ?>? You get a new one now; the old one works for a day more." hx-target="#page-content" hx-swap="innerHTML"><?= csrf_field() ?><input type="hidden" name="api_key" value="<?= $id ?>"><button type="submit" class="avatar-text avatar-sm border-0" title="Rotate" id="key-<?= $id ?>-rotate-btn" aria-label="Rotate <?= e($k['label']) ?>"><i class="feather-refresh-cw"></i></button></form><?php endif; ?>
        <form method="post" action="/settings/tokens/key-revoke.php" hx-post="/settings/tokens/key-revoke.php" hx-confirm="Revoke the key <?= e($k['label']) ?>? Anything using it stops working at once." hx-target="#page-content" hx-swap="innerHTML"><?= csrf_field() ?><input type="hidden" name="api_key" value="<?= $id ?>"><button type="submit" class="avatar-text avatar-sm border-0" title="Revoke" id="key-<?= $id ?>-revoke-btn" aria-label="Revoke <?= e($k['label']) ?>"><i class="feather-x"></i></button></form>
    </div>
    <?php endif; ?>
</div>
<?php } ?>
                </div></div>
        </div>
        <?php if ($others !== []): ?>
        <div class="col-12"><div class="card mb-0" id="keys-others-card"><div class="card-header"><h5 class="card-title mb-0">Other people's keys and lead-capture keys</h5></div>
            <div class="card-body p-0" id="keys-others-list"><?php foreach ($others as $k) { $mine = false; $id = (int) $k['key_id']; $live = key_is_live($k); $capture = $k['lead_source_id'] !== null; ?>
<div class="d-flex align-items-start gap-2 px-3 py-2 border-bottom <?= $live ? '' : 'text-muted' ?>" id="key-<?= $id ?>">
    <span class="wd-10 ht-10 mt-2 bg-<?= $live ? 'success' : 'secondary' ?> d-inline-block rounded-circle flex-shrink-0"></span>
    <div class="min-w-0 flex-grow-1 fs-12">
        <div class="fw-semibold text-truncate"><?= e($k['label']) ?>
            <?php if ($capture): ?><span class="badge bg-soft-info text-info ms-1">lead capture · <?= e((string) ($k['source_name'] ?? 'a source')) ?></span><?php endif; ?>
            <?php if (!$live): ?><span class="badge bg-soft-secondary text-secondary ms-1"><?= $k['revoked_at'] !== null ? 'revoked' : 'expired' ?></span><?php endif; ?></div>
        <div class="text-muted"><?= $mine ? '' : e((string) ($k['member_name'] ?? '')) . ' · ' ?><?= (int) $k['rate_per_minute'] ?> a minute · <?= (int) $k['rate_per_day'] ?> a day · <?= (int) $k['calls_today'] ?> today
            · last used <?= e(format_ts($k['last_used_at'], $tz, 'M j, g:i A') ?: 'never') ?> · <?= $k['expires_at'] ? ($live ? 'expires ' : 'expired ') . e(format_ts($k['expires_at'], $tz, 'M j, Y')) : 'never expires' ?></div>
    </div>
    <?php if ($live): ?>
    <div class="d-flex gap-1 flex-shrink-0">
        <?php if ($mine): ?><form method="post" action="/settings/tokens/key-rotate.php" hx-post="/settings/tokens/key-rotate.php" hx-confirm="Rotate the key <?= e($k['label']) ?>? You get a new one now; the old one works for a day more." hx-target="#page-content" hx-swap="innerHTML"><?= csrf_field() ?><input type="hidden" name="api_key" value="<?= $id ?>"><button type="submit" class="avatar-text avatar-sm border-0" title="Rotate" id="key-<?= $id ?>-rotate-btn" aria-label="Rotate <?= e($k['label']) ?>"><i class="feather-refresh-cw"></i></button></form><?php endif; ?>
        <form method="post" action="/settings/tokens/key-revoke.php" hx-post="/settings/tokens/key-revoke.php" hx-confirm="Revoke the key <?= e($k['label']) ?>? Anything using it stops working at once." hx-target="#page-content" hx-swap="innerHTML"><?= csrf_field() ?><input type="hidden" name="api_key" value="<?= $id ?>"><button type="submit" class="avatar-text avatar-sm border-0" title="Revoke" id="key-<?= $id ?>-revoke-btn" aria-label="Revoke <?= e($k['label']) ?>"><i class="feather-x"></i></button></form>
    </div>
    <?php endif; ?>
</div>
<?php } ?></div></div></div>
        <?php endif; ?>
    </div>
