<?php /** One list (list-view): its members as the kind's cards (deals as rows) with the bulk bar; a smart list shows its filters as chips, Edit filters and the live count.
 * Data: l, result {rows, total}, page, may, chips, notice, back, bulk_fields, bulk_list_kind, currency */
$lid = (int) $l['list_id'];
$kind = $l['record_kind'];
$smart = $l['kind'] === 'smart';
$size = filters_page($kind);
$pages = (int) max(1, ceil($result['total'] / $size));
$query = static fn (array $over) => '/lists/' . $lid . '?' . http_build_query(array_filter($over, static fn ($v) => $v !== null && $v !== ''));
$bulkOn = $may['bulk'] && $result['rows'] !== [];
$action = '<div class="d-flex gap-1">' . ($may['edit'] ? hx_link('/lists/' . $lid . '/edit', '<i class="feather-edit-2 me-1"></i>Edit', 'btn btn-light btn-touch', 'id="list-edit-btn"') : '')
    . ($may['export'] ? '<form method="post" action="/exports/create.php" class="d-inline" id="list-export-form">' . csrf_field() . '<input type="hidden" name="kind" value="list"><input type="hidden" name="list" value="' . $lid . '"><input type="hidden" name="format" value="csv">'
        . '<button type="submit" class="btn btn-light btn-touch" id="list-export-btn"><i class="feather-download me-1"></i><span class="d-none d-sm-inline">Export</span></button></form>' : '') . '</div>'; ?>
<?= view('shared/header.php', ['id' => 'list-view', 'title' => $l['name'], 'crumbs' => [['Today', '/'], ['Lists', '/lists'], [$l['name'], null]], 'action' => $action,
    'back' => ($b = back_link()) !== null ? $b : null]) ?>
<div class="main-content" id="list-view-content">
    <?= view('shared/notice.php', ['notice' => $notice]) ?>
    <div class="d-flex flex-wrap gap-2 align-items-center mb-2 fs-12" id="list-view-meta">
        <span class="badge bg-soft-<?= $smart ? 'info text-info' : 'secondary text-secondary' ?>"><?= $smart ? 'Smart' : 'Static' ?></span>
        <span class="text-muted"><?= e(LIST_RECORD_KINDS[$kind]) ?> · <?= e((string) ($l['owner_name'] ?? 'Shared')) ?><?= $l['is_shared'] ? ' · shared with everyone' : '' ?></span>
        <span class="fw-semibold ms-auto" id="list-view-count"><?= (int) $result['total'] ?> <?= e(RECORD_KINDS[$kind]['noun']) ?><?= $result['total'] === 1 ? '' : 's' ?><?= $smart ? ' match now' : '' ?></span>
    </div>
    <?php if ($l['description']): ?><div class="fs-12 text-muted mb-2" id="list-view-description"><?= e($l['description']) ?></div><?php endif; ?>
    <?php if ($smart): ?><?= view('lists/partials/chips.php', ['chips' => $chips, 'l' => $l, 'may' => $may]) ?><?php endif; ?>
    <?= $bulkOn ? bulk_bar(db(), $kind, [], (int) $result['total'], $lid, $query(['page' => $page])) : '' ?>
    <?php if ($result['rows'] === []): ?>
        <div class="card"><div class="card-body"><div class="empty-state text-center py-4" id="list-view-empty"><span class="avatar-text avatar-lg rounded mb-2"><i class="feather-list"></i></span>
            <div class="fw-semibold"><?= $smart ? 'Nothing matches these filters now.' : 'No one is in this list yet.' ?></div>
            <?php if (!$smart): ?><div class="fs-12 text-muted">Tick records on the <?= e(strtolower(LIST_RECORD_KINDS[$kind])) ?> screen and add them here.</div><?php endif; ?></div></div></div>
    <?php elseif ($kind === 'deal'): ?>
        <div class="card" id="list-rows"><div class="table-responsive"><table class="table table-hover mb-0 fs-12" id="list-rows-table">
            <thead class="thead-light"><tr><?php if ($bulkOn): ?><th style="width:2rem"></th><?php endif; ?><th>Deal</th><th>Account</th><th>Stage</th><th class="text-end">Value</th><th class="d-none d-md-table-cell">Owner</th><?php if ($may['add']): ?><th></th><?php endif; ?></tr></thead><tbody>
            <?php foreach ($result['rows'] as $d): $did = (int) $d['deal_id']; ?>
                <tr id="list-row-<?= $did ?>"><?php if ($bulkOn): ?><td><?= bulk_check('deal', $did, true, (string) $d['title']) ?></td><?php endif; ?>
                    <td><?= hx_link(with_back('/deals/' . $did, $query(['page' => $page])), e($d['number'] . ' · ' . $d['title']), 'fw-semibold text-dark') ?></td>
                    <td><?= e((string) $d['account_name']) ?></td><td><?= e($d['stage_name']) ?><?= $d['status'] !== 'open' ? ' <span class="badge text-bg-' . e(DEAL_STATUS_BADGE[$d['status']] ?? 'secondary') . '">' . e(DEAL_STATUSES[$d['status']] ?? $d['status']) . '</span>' : '' ?></td>
                    <td class="text-end text-nowrap"><?= e(money($d['value'], $d['currency'])) ?></td><td class="d-none d-md-table-cell"><?= e((string) ($d['owner_name'] ?? 'Unowned')) ?></td>
                    <?php if ($may['add']): ?><td class="text-end"><form method="post" action="/lists/remove.php" hx-post="/lists/remove.php" hx-target="#flash" class="d-inline"><?= csrf_field() ?><input type="hidden" name="list" value="<?= $lid ?>"><input type="hidden" name="record" value="<?= $did ?>"><input type="hidden" name="return_to" value="<?= e($query(['page' => $page])) ?>">
                        <button type="submit" class="btn btn-light btn-sm text-danger" id="list-row-<?= $did ?>-remove-btn" aria-label="Take out of the list"><i class="feather-x"></i></button></form></td><?php endif; ?></tr>
            <?php endforeach; ?></tbody></table></div></div>
    <?php else: ?>
        <div class="row g-3" id="list-rows">
        <?php foreach ($result['rows'] as $r): ?>
            <?php $rid = (int) $r[['lead' => 'lead_id', 'contact' => 'contact_id', 'account' => 'account_id'][$kind]]; ?>
            <?= match ($kind) { 'lead' => view('leads/partials/card.php', ['l' => $r, 'back' => $back, 'bulk' => $bulkOn]), 'contact' => view('contacts/partials/card.php', ['c' => $r, 'back' => $back, 'bulk' => $bulkOn]),
                'account' => view('accounts/partials/card.php', ['a' => $r, 'back' => $back, 'bulk' => $bulkOn]) } ?>
        <?php endforeach; ?>
        </div>
    <?php endif; ?>
    <?php if ($pages > 1): ?><nav class="mt-3" id="list-view-pagination"><ul class="pagination mb-0">
        <?php if ($page > 1): ?><li class="page-item"><?= hx_link($query(['page' => $page - 1]), 'Previous', 'page-link') ?></li><?php endif; ?>
        <li class="page-item disabled"><span class="page-link"><?= $page ?> of <?= $pages ?></span></li>
        <?php if ($page < $pages): ?><li class="page-item"><?= hx_link($query(['page' => $page + 1]), 'Next', 'page-link', 'id="list-view-next"') ?></li><?php endif; ?>
    </ul></nav><?php endif; ?>
</div>
