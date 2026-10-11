<?php /** Exports (export-list). Data: rows (find_exports), tz, busy, notice, title_of. Ready to download (once), being made, expiring. A page waiting on the worker refreshes itself every 5 seconds. */
$me = (int) current_member_id(); ?>
<?= view('shared/header.php', ['id' => 'export-list', 'title' => 'Exports', 'crumbs' => [['Today', '/'], ['Lists', '/lists'], ['Exports', null]]]) ?>
<div class="main-content" id="export-list-content"<?= $busy ? ' hx-get="/exports" hx-trigger="every 5s" hx-target="#page-content" hx-swap="innerHTML"' : '' ?>>
    <?= view('shared/notice.php', ['notice' => $notice]) ?>
    <?php if ($rows === []): ?>
        <div class="card"><div class="card-body"><div class="empty-state text-center py-4" id="export-list-empty"><span class="avatar-text avatar-lg rounded mb-2"><i class="feather-download"></i></span><div class="fw-semibold">No exports yet</div>
            <div class="fs-12 text-muted mb-2">Export what a screen shows or a list holds from the bar above its cards. The file is made in a minute or two, kept for seven days, and can be downloaded once.</div>
            <?= hx_link('/lists', 'Open the lists', 'btn btn-light btn-touch') ?></div></div></div>
    <?php else: ?>
    <div class="fs-12 text-muted mb-2">A file is kept for seven days and can be downloaded once; make another if you need it again.</div>
    <div class="card"><div class="table-responsive"><table class="table table-hover mb-0 fs-12" id="export-list-table">
        <thead class="thead-light"><tr><th>What</th><th>State</th><th class="text-end">Rows</th><th class="d-none d-md-table-cell">Made</th><th class="d-none d-md-table-cell">Until</th><th class="text-end"></th></tr></thead><tbody>
        <?php foreach ($rows as $e): $eid = (int) $e['export_id']; ?>
            <tr id="export-<?= $eid ?>">
                <td class="min-w-0"><span class="fw-semibold"><?= e($title_of($e)) ?></span> <span class="badge bg-soft-secondary text-secondary"><?= e(EXPORT_FORMATS[$e['format']] ?? $e['format']) ?></span>
                    <?php if ((int) $e['created_by'] !== $me): ?><div class="text-muted">by <?= e((string) $e['maker']) ?></div><?php endif; ?>
                    <?php if ($e['status'] === 'failed' && $e['log']): ?><div class="text-danger" id="export-<?= $eid ?>-error"><?= e((string) $e['log']) ?></div><?php endif; ?></td>
                <td><span class="badge bg-soft-<?= e($e['state_badge']) ?> text-<?= e($e['state_badge']) ?>" id="export-<?= $eid ?>-state"><?= e($e['state_words']) ?></span></td>
                <td class="text-end"><?= $e['status'] === 'done' ? (int) $e['item_count'] : '—' ?></td>
                <td class="d-none d-md-table-cell text-nowrap"><?= e(format_ts($e['created_at'], $tz, 'M j, g:i A')) ?></td>
                <td class="d-none d-md-table-cell text-nowrap"><?= $e['state'] === 'ready' ? e(format_ts($e['expires_at'], $tz, 'M j')) : '—' ?></td>
                <td class="text-end"><?php if ($e['can_download']): ?>
                    <form method="post" action="/exports/download.php" class="d-inline"><?= csrf_field() ?><input type="hidden" name="export" value="<?= $eid ?>">
                        <button type="submit" class="btn btn-primary btn-sm btn-touch" id="export-<?= $eid ?>-download-btn"><i class="feather-download me-1"></i>Download</button></form><?php endif; ?></td>
            </tr>
        <?php endforeach; ?>
    </tbody></table></div></div>
    <?php endif; ?>
</div>
