<?php /** Imports (import-list). Data: rows, filters, tz */ ?>
<?= view('shared/header.php', ['id' => 'import-list', 'title' => 'Imports', 'crumbs' => [['Today', '/'], ['Leads', '/leads'], ['Imports', null]],
    'action' => hx_link('/import/new', '<i class="feather-upload me-1"></i>New import', 'btn btn-primary btn-touch', 'id="import-new-btn"')]) ?>
<div class="main-content" id="import-list-content">
    <?php if ($rows === []): ?>
        <div class="card"><div class="card-body"><div class="empty-state text-center py-4" id="import-list-empty"><span class="avatar-text avatar-lg rounded mb-2"><i class="feather-upload"></i></span><div class="fw-semibold">No imports yet</div>
            <div class="fs-12 text-muted mb-2">Bring leads, contacts, accounts or deals in from a spreadsheet saved as CSV. A dry run shows what would happen first.</div><?= hx_link('/import/new', 'Import a CSV', 'btn btn-primary btn-touch') ?></div></div></div>
    <?php else: ?>
    <div class="card"><div class="table-responsive"><table class="table table-hover mb-0 fs-12" id="import-list-table">
        <thead class="thead-light"><tr><th>File</th><th>Of</th><th>State</th><th class="text-end">Created</th><th class="text-end d-none d-md-table-cell">Updated</th><th class="text-end d-none d-md-table-cell">Skipped</th><th class="text-end d-none d-md-table-cell">Refused</th><th class="d-none d-md-table-cell">When</th></tr></thead><tbody>
        <?php foreach ($rows as $i): $iid = (int) $i['import_id']; ?>
            <tr id="import-row-<?= $iid ?>"><td><?= hx_link('/import/' . $iid, e($i['file_name']), 'fw-semibold text-dark') ?></td><td><?= e(IMPORT_KINDS[$i['kind']] ?? $i['kind']) ?></td>
                <td><span class="badge bg-soft-<?= e(IMPORT_STATUS_BADGE[$i['status']] ?? 'secondary') ?> text-<?= e(IMPORT_STATUS_BADGE[$i['status']] ?? 'secondary') ?>"><?= e(import_state_words($i)) ?></span></td>
                <td class="text-end"><?= (int) $i['rows_created'] ?></td><td class="text-end d-none d-md-table-cell"><?= (int) $i['rows_updated'] ?></td><td class="text-end d-none d-md-table-cell"><?= (int) $i['rows_skipped'] ?></td>
                <td class="text-end d-none d-md-table-cell"><?= (int) $i['rows_refused'] ?></td><td class="d-none d-md-table-cell text-nowrap"><?= e(format_ts($i['created_at'], $tz, 'M j, g:i A')) ?></td></tr>
        <?php endforeach; ?>
    </tbody></table></div></div>
    <?php endif; ?>
</div>
