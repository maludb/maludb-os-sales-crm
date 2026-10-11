<?php /** Deleted records (screen `admin-purge`; ids purge-counts, purge-list, purge-now-form, purge-now-btn). Data: counts (tombstone_counts), rows (tombstones), kind (the filter or null), tz, notice */
$days = (int) $counts['days']; ?>
<?= view('shared/header.php', ['id' => 'admin-purge', 'title' => 'Deleted records', 'crumbs' => [['Today', '/'], ['Admin', '/admin'], ['Deleted records', null]]]) ?>
<div class="main-content" id="admin-purge-content">
    <?= view('shared/notice.php', ['notice' => $notice]) ?>
    <div class="alert alert-light border fs-12" id="purge-help">A deleted record is hidden at once and kept <strong><?= $days ?> day<?= $days === 1 ? '' : 's' ?></strong> (Settings → Records) so it can be restored. Then the worker purges it for good, once a minute. Purging here does the same
        work now for the records whose time is up; a record still inside its window is never touched.</div>
    <div class="row g-3 mb-3" id="purge-counts">
        <?php foreach (TOMBSTONE_KINDS as $k => $label): $c = $counts['kinds'][$k]; ?>
            <div class="col-6 col-md-4 col-xl"><div class="card mb-0 h-100"><div class="card-body py-3">
                <div class="fs-12 text-muted"><?= e($label) ?></div>
                <div class="fs-4 fw-bold" id="purge-<?= $k ?>-due"><?= (int) $c['due'] ?> <span class="fs-12 fw-normal text-muted">due</span></div>
                <div class="fs-12 text-muted" id="purge-<?= $k ?>-waiting"><?= (int) $c['waiting'] ?> in their window</div>
            </div></div></div>
        <?php endforeach; ?>
    </div>
    <div class="card mb-3" id="purge-now-card"><div class="card-body d-flex flex-wrap align-items-center gap-3">
        <div class="flex-grow-1 fs-12" id="purge-summary"><?php if ($counts['due'] > 0): ?><strong><?= (int) $counts['due'] ?> record<?= $counts['due'] === 1 ? ' is' : 's are' ?> due.</strong> Purging destroys them and what hangs on them (notes, files, tags). It cannot be undone.
            <?php else: ?>Nothing is due.<?= $counts['next_purge_at'] !== null ? ' The next record is purged ' . e(format_ts($counts['next_purge_at'], $tz, 'M j, Y')) . '.' : '' ?><?php endif; ?></div>
        <form method="post" action="/admin/purge.php" hx-post="/admin/purge.php" hx-target="#page-content" hx-swap="innerHTML" hx-confirm="Purge <?= (int) $counts['due'] ?> record<?= $counts['due'] === 1 ? '' : 's' ?> for good? This cannot be undone." id="purge-now-form"><?= csrf_field() ?>
            <button type="submit" class="btn btn-danger btn-touch" id="purge-now-btn" <?= $counts['due'] === 0 ? 'disabled' : '' ?>><i class="feather-trash-2 me-1"></i>Purge now</button></form>
    </div></div>
    <div class="d-flex flex-wrap gap-1 mb-3" id="purge-kinds">
        <?= hx_link('/admin/purge', 'All', 'btn btn-touch btn-sm ' . ($kind === null ? 'btn-primary' : 'btn-light'), 'id="purge-kind-all"') ?>
        <?php foreach (TOMBSTONE_KINDS as $k => $label): ?><?= hx_link('/admin/purge?kind=' . $k, e($label), 'btn btn-touch btn-sm ' . ($kind === $k ? 'btn-primary' : 'btn-light'), 'id="purge-kind-' . $k . '"') ?><?php endforeach; ?>
    </div>
    <div class="card mb-0" id="purge-card"><div class="card-body p-0"><div class="table-responsive"><table class="table table-hover align-middle mb-0 fs-12" id="purge-list">
        <thead class="thead-light"><tr><th>Record</th><th class="d-none d-md-table-cell">Deleted</th><th>Purged</th></tr></thead>
        <tbody>
        <?php if ($rows === []): ?><tr><td colspan="3" class="text-center text-muted py-4" id="purge-empty">No deleted record waits.</td></tr><?php endif; ?>
        <?php foreach ($rows as $r): ?>
            <tr id="tombstone-<?= e($r['record_kind']) ?>-<?= (int) $r['record_id'] ?>">
                <td><div class="fw-semibold"><?= e((string) $r['name']) ?></div><div class="text-muted"><?= e(ucfirst($r['record_kind'])) ?> #<?= (int) $r['record_id'] ?><?= $r['merged_into_id'] !== null ? ' · merged into #' . (int) $r['merged_into_id'] : '' ?></div></td>
                <td class="d-none d-md-table-cell text-nowrap"><?= e(format_ts($r['deleted_at'], $tz, 'M j, Y')) ?><?= $r['deleted_by_name'] ? '<div class="text-muted">by ' . e($r['deleted_by_name']) . '</div>' : '' ?></td>
                <td class="text-nowrap"><?= $r['due'] ? '<span class="badge bg-soft-warning text-warning">due</span>' : e(format_ts($r['purges_at'], $tz, 'M j, Y')) ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody></table></div></div></div>
</div>
