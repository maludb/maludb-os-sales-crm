<?php /** My settings (screen `settings`). Data: prefs, defaults, refusal, tz, may (settings, feed, signature), signature, feed, osChannels, osProfile, recent, notice */ ?>
<?= view('shared/header.php', ['id' => 'settings', 'title' => 'My settings', 'crumbs' => [['Today', '/'], ['My settings', null]]]) ?>
<div class="main-content" id="settings-content">
    <?= view('shared/notice.php', ['notice' => $notice]) ?>
    <div class="d-flex flex-wrap gap-1 mb-3" id="settings-tabs">
        <?= hx_link('/settings/', 'How I am told', 'btn btn-touch btn-primary', 'id="settings-tab-notify"') ?>
        <?= hx_link('/settings/tokens/', 'Tokens', 'btn btn-touch btn-light', 'id="settings-tab-tokens"') ?>
        <?php if ($may['settings']): ?><?= hx_link('/admin', 'The application\'s settings', 'btn btn-touch btn-light', 'id="settings-tab-admin"') ?><?php endif; ?>
    </div>
    <div class="row g-3">
        <div class="col-lg-7"><?= view('settings/partials/prefs.php', ['prefs' => $prefs, 'defaults' => $defaults, 'refusal' => $refusal, 'osChannels' => $osChannels]) ?></div>
        <div class="col-lg-5">
            <?php if ($may['signature']): ?><?= view('settings/partials/signature.php', ['signature' => $signature]) ?><?php endif; ?>
            <?php if ($may['feed']): ?><?= view('settings/partials/feed.php', ['feed' => $feed, 'tz' => $tz, 'feed_url' => $feed_url ?? null]) ?><?php endif; ?>
            <div class="card mb-3" id="settings-timezone"><div class="card-header"><h5 class="card-title mb-0">Your time zone</h5></div><div class="card-body">
                <div class="fw-semibold" id="settings-timezone-value"><?= e($tz) ?></div>
                <div class="fs-12 text-muted">Times, reminders and the calendar are shown in it. It comes from your profile in the operating system: <a href="<?= e($osProfile) ?>" id="settings-timezone-link">change it there</a> and it follows within a minute.</div>
            </div></div>
        </div>
    </div>
    <?php if (($recent ?? []) !== []): ?>
    <div class="card mt-3" id="prefs-recent"><div class="card-header"><h5 class="card-title mb-0">Sent to you lately</h5></div><div class="card-body p-0"><div class="table-responsive"><table class="table mb-0 fs-12"><thead class="thead-light"><tr><th>When</th><th>What</th><th>By</th><th>Outcome</th></tr></thead><tbody>
        <?php foreach ($recent as $r): ?><tr id="prefs-recent-<?= (int) $r['id'] ?>"><td class="text-nowrap text-muted"><?= e(format_ts($r['created_at'], $tz, 'M j, g:i A')) ?></td><td><?= e($r['subject'] ?? $r['kind']) ?></td><td><?= e($r['channel']) ?></td><td><span class="badge bg-soft-<?= $r['status'] === 'sent' ? 'success text-success' : ($r['status'] === 'queued' ? 'secondary text-secondary' : 'warning text-warning') ?>"><?= e($r['status']) ?><?= $r['status'] === 'skipped' && $r['detail'] ? ' · ' . e($r['detail']) : '' ?></span></td></tr><?php endforeach; ?>
    </tbody></table></div></div></div>
    <?php endif; ?>
</div>
