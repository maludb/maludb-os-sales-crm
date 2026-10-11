<?php /** A campaign's members (#campaign-members). Data: c, members {rows, total}, counts, status (the filter), page, may, tz */
$cid = (int) $c['campaign_id'];
$pages = (int) ceil($members['total'] / CAMPAIGN_MEMBER_PAGE);
$url = static fn (array $over) => '/campaigns/' . $cid . '?' . http_build_query(array_filter(array_merge(['status' => $status], $over), static fn ($v) => $v !== null && $v !== '')) . '#campaign-members'; ?>
<div class="card mb-3" id="campaign-members">
    <div class="card-header d-flex flex-wrap gap-2 align-items-center"><span class="fw-semibold">Members</span><span class="badge bg-soft-secondary text-secondary" id="campaign-members-total"><?= (int) $members['total'] ?></span>
        <div class="d-flex flex-wrap gap-1 ms-auto" id="campaign-member-statuses">
            <?= hx_link($url(['status' => null, 'page' => null]), 'All', 'btn btn-sm btn-touch ' . ($status === null ? 'btn-primary' : 'btn-light'), 'id="campaign-members-status-all"') ?>
            <?php foreach (CAMPAIGN_MEMBER_STATUSES as $k => $label): if (empty($counts[$k]) && $status !== $k) { continue; } ?>
                <?= hx_link($url(['status' => $k, 'page' => null]), e($label) . ' ' . (int) ($counts[$k] ?? 0), 'btn btn-sm btn-touch ' . ($status === $k ? 'btn-primary' : 'btn-light'), 'id="campaign-members-status-' . $k . '"') ?>
            <?php endforeach; ?>
        </div></div>
    <?php if ($members['rows'] === []): ?>
        <div class="card-body text-center text-muted py-4" id="campaign-members-empty"><?= $status === null ? 'No one yet. Add people below, or from a list or a contact screen.' : 'No member is ' . e(strtolower(CAMPAIGN_MEMBER_STATUSES[$status])) . '.' ?></div>
    <?php else: ?>
    <div class="table-responsive"><table class="table table-hover mb-0 fs-12" id="campaign-members-table">
        <thead class="thead-light"><tr><th>Who</th><th>Status</th><th class="d-none d-md-table-cell">Added</th><?php if ($may['manage']): ?><th class="text-end">Change</th><?php endif; ?></tr></thead><tbody>
        <?php foreach ($members['rows'] as $m): $mid = (int) $m['campaign_member_id']; $isLead = $m['lead_id'] !== null; $rid = (int) ($isLead ? $m['lead_id'] : $m['contact_id']); ?>
            <tr id="campaign-member-<?= $mid ?>">
                <td class="min-w-0"><?php if ($m['hidden']): ?><span class="text-muted"><i class="feather-lock me-1"></i>A restricted <?= $isLead ? 'lead' : 'contact' ?></span>
                    <?php else: ?><?= hx_link(with_back('/' . ($isLead ? 'leads' : 'contacts') . '/' . $rid, '/campaigns/' . $cid), e((string) $m['name']), 'fw-semibold text-dark') ?>
                    <span class="badge bg-soft-<?= $isLead ? 'warning text-warning' : 'info text-info' ?>"><?= $isLead ? 'lead' : 'contact' ?></span>
                    <?php if (!$isLead && !$m['email_allowed']): ?><span class="badge text-bg-danger" id="campaign-member-<?= $mid ?>-no-email"><?= $m['email_opt_out'] ? 'No email' : 'No address' ?></span><?php endif; ?>
                    <div class="text-muted text-truncate"><?= e((string) ($isLead ? $m['company'] : $m['account_name'])) ?></div><?php endif; ?></td>
                <td><span class="badge bg-soft-<?= e(CAMPAIGN_MEMBER_BADGE[$m['status']] ?? 'secondary') ?> text-<?= e(CAMPAIGN_MEMBER_BADGE[$m['status']] ?? 'secondary') ?>" id="campaign-member-<?= $mid ?>-status"><?= e(CAMPAIGN_MEMBER_STATUSES[$m['status']] ?? $m['status']) ?></span></td>
                <td class="d-none d-md-table-cell text-nowrap"><?= e(format_ts($m['added_at'], $tz, 'M j')) ?></td>
                <?php if ($may['manage']): ?><td class="text-end text-nowrap">
                    <form method="post" action="/campaigns/members/status.php" hx-post="/campaigns/members/status.php" hx-target="#flash" class="d-inline-flex gap-1 align-items-center"><?= csrf_field() ?>
                        <input type="hidden" name="campaign" value="<?= $cid ?>"><input type="hidden" name="<?= $isLead ? 'lead' : 'contact' ?>" value="<?= $rid ?>"><input type="hidden" name="return_to" value="/campaigns/<?= $cid ?>">
                        <select name="status" class="form-select form-select-sm" aria-label="Status of <?= e((string) $m['name']) ?>" id="campaign-member-<?= $mid ?>-select">
                            <?php foreach (CAMPAIGN_MEMBER_SETTABLE as $k): ?><option value="<?= e($k) ?>" <?= $m['status'] === $k ? 'selected' : '' ?>><?= e(CAMPAIGN_MEMBER_STATUSES[$k]) ?></option><?php endforeach; ?></select>
                        <button type="submit" class="btn btn-light btn-sm" id="campaign-member-<?= $mid ?>-save-btn">Set</button></form>
                    <form method="post" action="/campaigns/members/remove.php" hx-post="/campaigns/members/remove.php" hx-target="#flash" hx-confirm="Take them out of the campaign?" class="d-inline"><?= csrf_field() ?>
                        <input type="hidden" name="campaign" value="<?= $cid ?>"><input type="hidden" name="<?= $isLead ? 'lead' : 'contact' ?>" value="<?= $rid ?>"><input type="hidden" name="return_to" value="/campaigns/<?= $cid ?>">
                        <button type="submit" class="btn btn-light btn-sm text-danger" id="campaign-member-<?= $mid ?>-remove-btn" aria-label="Take out"><i class="feather-x"></i></button></form></td><?php endif; ?>
            </tr>
        <?php endforeach; ?>
    </tbody></table></div>
    <?php if ($pages > 1): ?><div class="card-footer"><nav id="campaign-members-pagination"><ul class="pagination mb-0">
        <?php if ($page > 1): ?><li class="page-item"><?= hx_link($url(['page' => $page - 1]), 'Previous', 'page-link') ?></li><?php endif; ?>
        <li class="page-item disabled"><span class="page-link"><?= $page ?> of <?= $pages ?></span></li>
        <?php if ($page < $pages): ?><li class="page-item"><?= hx_link($url(['page' => $page + 1]), 'Next', 'page-link', 'id="campaign-members-next"') ?></li><?php endif; ?>
    </ul></nav></div><?php endif; ?>
    <?php endif; ?>
</div>
