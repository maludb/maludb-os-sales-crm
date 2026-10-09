<?php /** The list — the swapped region. Data: rows, page, more, query, tz */
$pageUrl = static fn (int $p): string => '/activity?' . http_build_query($query + ['page_no' => $p]);
?>
<div id="activity-results" class="card-body custom-card-action p-0">
    <div class="table-responsive">
        <table class="table table-hover mb-0" id="activity-table">
            <thead class="thead-light"><tr>
                <th id="activity-col-when">When</th><th id="activity-col-what">What</th><th id="activity-col-source" class="d-none d-md-table-cell">Source</th>
            </tr></thead>
            <tbody id="activity-tbody">
            <?php if ($rows === []): ?>
                <tr><td colspan="3" class="text-center text-muted py-4" id="activity-empty">Nothing here that you may see.</td></tr>
            <?php endif; ?>
            <?php foreach ($rows as $r): ?>
                <tr id="activity-row-<?= (int) $r['activity_id'] ?>">
                    <td class="text-nowrap fs-12" title="<?= e($r['occurred_at']) ?>"><?= e(format_ts($r['occurred_at'], $tz, 'M j, g:i A')) ?></td>
                    <td class="text-wrap"><?= e($r['sentence']) ?><?= ($u = activity_record_link($r)) !== null ? ' ' . hx_link(with_back($u[0], here_url()), e($u[1]), 'fs-12') : '' ?></td>
                    <td class="d-none d-md-table-cell"><span class="badge bg-soft-<?= ($r['source'] ?? '') === 'agent' ? 'info text-info' : (($r['source'] ?? '') === 'web' ? 'secondary text-dark' : 'warning text-warning') ?>"><?= e($r['source']) ?></span><?= $r['agent_run_id'] ? ' <small class="text-muted">run #' . (int) $r['agent_run_id'] . '</small>' : '' ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php if ($page > 1 || $more): ?>
    <nav class="p-3" id="activity-pagination"><ul class="pagination mb-0">
        <?php if ($page > 1): ?><li class="page-item"><a class="page-link" href="<?= e($pageUrl($page - 1)) ?>" hx-get="<?= e($pageUrl($page - 1)) ?>" hx-target="#activity-results" hx-swap="outerHTML" hx-push-url="<?= e($pageUrl($page - 1)) ?>">Newer</a></li><?php endif; ?>
        <?php if ($more): ?><li class="page-item"><a class="page-link" href="<?= e($pageUrl($page + 1)) ?>" hx-get="<?= e($pageUrl($page + 1)) ?>" hx-target="#activity-results" hx-swap="outerHTML" hx-push-url="<?= e($pageUrl($page + 1)) ?>">Older</a></li><?php endif; ?>
    </ul></nav>
    <?php endif; ?>
</div>
