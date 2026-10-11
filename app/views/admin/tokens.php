<?php /** Every key and token (screen `admin-tokens`; ids tokens-table, key-{id}, capture-key-form, keys-minted). Data: rows (tokens_all), minted (a capture key just made: [id, raw, label, what] or null), capture_default, leads_url, tz, notice
 * A key's HASH is never read here. Revoke posts to slice 8's key_revoke (an MCP token is its owner's to revoke on My settings → Tokens). */ ?>
<?= view('shared/header.php', ['id' => 'admin-tokens', 'title' => 'Keys and tokens', 'crumbs' => [['Today', '/'], ['Admin', '/admin'], ['Keys and tokens', null]]]) ?>
<div class="main-content" id="admin-tokens-content">
    <?= view('shared/notice.php', ['notice' => $notice]) ?>
    <?php if ($minted): ?>
    <div class="alert alert-success" id="keys-minted" role="status">
        <div class="fw-bold mb-1">The new <?= e($minted['what']) ?> "<?= e($minted['label']) ?>" — copy it now; it is shown once and cannot be shown again.</div>
        <code class="user-select-all text-break" id="keys-minted-value"><?= e($minted['raw']) ?></code>
        <div class="fs-12 mt-1">The website's form posts leads to <code><?= e($leads_url) ?></code> with <code>Authorization: Bearer …</code>. The key does nothing else.</div>
    </div>
    <?php endif; ?>
    <div class="row g-3">
        <div class="col-xxl-4 order-xxl-2">
            <div class="card mb-3" id="capture-key-card"><div class="card-header"><h5 class="card-title mb-0">Mint a lead-capture key</h5></div><div class="card-body">
                <p class="fs-12 text-muted">For a form on the website. The leads it posts carry the source you choose and go through the assignment rules. <?= (int) $capture_default ?> a day by default.</p>
                <form method="post" action="/admin/tokens/capture-mint.php" hx-post="/admin/tokens/capture-mint.php" hx-target="#page-content" hx-swap="innerHTML" id="capture-key-form" class="row g-2"><?= csrf_field() ?>
                    <div class="col-12"><label class="form-label fs-12 text-muted" for="capture-key-label">The form it serves</label><input type="text" class="form-control btn-touch" id="capture-key-label" name="label" maxlength="80" required placeholder="Website contact form"></div>
                    <div class="col-12"><div class="form-label fs-12 text-muted" id="capture-key-source-label">Its leads' source</div>
                        <?= picker_field(['id' => 'capture-key-source', 'name' => 'source', 'source' => 'source', 'value' => null, 'required' => true, 'labelledby' => 'capture-key-source-label', 'title' => 'Lead source', 'placeholder' => 'Choose a source']) ?></div>
                    <div class="col-12"><label class="form-label fs-12 text-muted" for="capture-key-rate">Leads a day (at most)</label><input type="number" class="form-control btn-touch" id="capture-key-rate" name="rate_per_day" min="1" max="10000000" placeholder="<?= (int) $capture_default ?>"></div>
                    <div class="col-12"><button type="submit" class="btn btn-primary btn-touch w-100" id="capture-key-btn"><i class="feather-key me-1"></i>Mint the key</button></div>
                </form>
            </div></div>
        </div>
        <div class="col-xxl-8 order-xxl-1">
            <div class="card mb-0" id="tokens-card"><div class="card-header"><h5 class="card-title mb-0">Every key and token</h5></div>
                <div class="card-body p-0"><div class="table-responsive"><table class="table table-hover align-middle mb-0 fs-12" id="tokens-table">
                    <thead class="thead-light"><tr><th>Label</th><th class="d-none d-md-table-cell">Owner</th><th class="d-none d-lg-table-cell">Limits</th><th class="text-end">Today</th><th class="d-none d-md-table-cell">Last used</th><th>State</th><th></th></tr></thead>
                    <tbody>
                    <?php if ($rows === []): ?><tr><td colspan="7" class="text-center text-muted py-4" id="tokens-empty">No key or token has been made.</td></tr><?php endif; ?>
                    <?php foreach ($rows as $t): $p = present_token_row($t); $id = $p['key_id']; ?>
                        <tr id="key-<?= $id ?>" class="<?= $p['live'] ? '' : 'text-muted' ?>">
                            <td><div class="fw-semibold"><?= e($p['label']) ?></div>
                                <span class="badge bg-soft-<?= $p['kind'] === 'lead_capture_key' ? 'info' : 'secondary' ?> text-<?= $p['kind'] === 'lead_capture_key' ? 'info' : 'secondary' ?>" id="key-<?= $id ?>-kind"><?= e(['api_key' => 'API key', 'lead_capture_key' => 'Lead capture', 'mcp_token' => 'MCP token'][$p['kind']]) ?></span>
                                <?php if ($p['source_name']): ?><span class="text-muted ms-1" id="key-<?= $id ?>-source"><?= e($p['source_name']) ?></span><?php endif; ?>
                                <div class="d-md-none text-muted"><?= e($p['member_name']) ?></div></td>
                            <td class="d-none d-md-table-cell"><?= e($p['member_name']) ?></td>
                            <td class="d-none d-lg-table-cell text-nowrap"><?= $p['rate_per_minute'] === null ? '—' : (int) $p['rate_per_minute'] . ' a minute<br>' . (int) $p['rate_per_day'] . ' a day' ?></td>
                            <td class="text-end"><?= $p['kind'] === 'mcp_token' ? '—' : (int) $p['calls_today'] ?></td>
                            <td class="d-none d-md-table-cell text-nowrap"><?= e(format_ts($t['last_used_at'], $tz, 'M j, g:i A') ?: 'never') ?></td>
                            <td><span class="badge bg-soft-<?= $p['live'] ? 'success' : 'secondary' ?> text-<?= $p['live'] ? 'success' : 'secondary' ?>" id="key-<?= $id ?>-state"><?= $p['revoked'] ? 'revoked' : ($p['live'] ? 'live' : 'expired') ?></span></td>
                            <td class="text-end"><?php if ($p['live'] && $p['kind'] !== 'mcp_token'): ?>
                                <form method="post" action="/settings/tokens/key-revoke.php" hx-post="/settings/tokens/key-revoke.php" hx-confirm="Revoke the key <?= e($p['label']) ?>? Anything using it stops working at once." hx-target="#page-content" hx-swap="innerHTML"><?= csrf_field() ?>
                                    <input type="hidden" name="api_key" value="<?= $id ?>"><input type="hidden" name="return_to" value="/admin/tokens">
                                    <button type="submit" class="avatar-text avatar-sm border-0" id="key-<?= $id ?>-revoke-btn" title="Revoke" aria-label="Revoke <?= e($p['label']) ?>"><i class="feather-x"></i></button></form>
                            <?php endif; ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody></table></div></div></div>
        </div>
    </div>
</div>
