<?php /** The import's rows, filtered by result, paged (100). Data: i, rows {rows, total, page_size}, result, page */
$iid = (int) $i['import_id'];
$kindUrl = ['leads_csv' => '/leads/', 'contacts_csv' => '/contacts/', 'accounts_csv' => '/accounts/', 'deals_csv' => '/deals/'][$i['kind']];
$built = nav_built($kindUrl . 'view.php');
$pages = (int) ceil($rows['total'] / $rows['page_size']);
$url = static fn (?string $r, int $p = 1) => '/import/' . $iid . '?' . http_build_query(array_filter(['result' => $r, 'page' => $p > 1 ? $p : null])); ?>
<div class="card" id="import-rows"><div class="card-header d-flex flex-wrap gap-1 align-items-center">
    <h5 class="card-title mb-0 me-2">Rows</h5>
    <?= hx_link($url(null), 'All', 'btn btn-sm btn-touch ' . ($result === null ? 'btn-primary' : 'btn-light'), 'id="import-rows-all"') ?>
    <?php foreach (IMPORT_RESULTS as $k => $label): ?><?= hx_link($url($k), e($label), 'btn btn-sm btn-touch ' . ($result === $k ? 'btn-primary' : 'btn-light'), 'id="import-rows-' . $k . '"') ?><?php endforeach; ?>
</div>
<?php if ($rows['rows'] === []): ?><div class="card-body fs-12 text-muted" id="import-rows-empty"><?= in_array($i['status'], ['queued', 'running'], true) ? 'Not yet — the worker is on it.' : 'No rows here.' ?></div>
<?php else: ?>
<div class="table-responsive"><table class="table mb-0 fs-12" id="import-rows-table"><thead class="thead-light"><tr><th class="text-end">Row</th><th>Result</th><th>What happened</th></tr></thead><tbody>
<?php foreach ($rows['rows'] as $r): $res = (string) $r['result']; $c = ['created' => 'success', 'updated' => 'info', 'skipped' => 'secondary', 'refused' => 'danger'][$res] ?? 'secondary'; ?>
    <tr id="import-row-<?= (int) $r['row_number'] ?>"><td class="text-end text-muted"><?= (int) $r['row_number'] ?></td><td><span class="badge bg-soft-<?= $c ?> text-<?= $c ?>"><?= e($res) ?></span></td>
        <td class="text-break"><?= e((string) ($r['message'] ?? '')) ?><?= $r['record_id'] !== null && !(bool) $i['dry_run'] && $built ? ' ' . hx_link($kindUrl . (int) $r['record_id'], 'open', 'fw-semibold') : '' ?></td></tr>
<?php endforeach; ?>
</tbody></table></div>
<?php if ($pages > 1): ?><div class="card-footer"><ul class="pagination mb-0">
    <?php if ($page > 1): ?><li class="page-item"><?= hx_link($url($result, $page - 1), 'Previous', 'page-link') ?></li><?php endif; ?><li class="page-item disabled"><span class="page-link"><?= $page ?> of <?= $pages ?></span></li>
    <?php if ($page < $pages): ?><li class="page-item"><?= hx_link($url($result, $page + 1), 'Next', 'page-link', 'id="import-rows-next"') ?></li><?php endif; ?></ul></div><?php endif; ?>
<?php endif; ?>
</div>
