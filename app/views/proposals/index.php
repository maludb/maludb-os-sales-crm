<?php /** Proposals (screen `proposals`). Data: cards (proposal_render rows), filters {kind, status, who}, total, page, manager, members (find_owners, a manager's `who`), waiting, notice, back */
$f = $filters;
$query = static fn (array $over) => '/proposals?' . http_build_query(array_filter(array_merge(['kind' => $f['kind'], 'status' => $f['status'] ?? 'all', 'who' => $f['who'] === 'me' ? null : $f['who']], $over), static fn ($v) => $v !== null && $v !== ''));
$chip = static fn (string $label, array $over, bool $on, string $id) => hx_link($query($over), e($label), 'btn btn-touch btn-sm ' . ($on ? 'btn-primary' : 'btn-light'), 'id="' . $id . '"');
$pages = max(1, (int) ceil($total / PROPOSAL_PAGE)); ?>
<?= view('shared/header.php', ['id' => 'proposals', 'title' => 'Proposals', 'crumbs' => [['Today', '/'], ['Proposals', null]]]) ?>
<div class="main-content" id="proposals-content">
    <?= view('shared/notice.php', ['notice' => $notice]) ?>
    <div class="d-flex flex-wrap gap-1 mb-2" id="proposals-statuses">
        <?= $chip('Waiting', ['status' => 'proposed'], $f['status'] === 'proposed', 'proposals-status-proposed') ?>
        <?= $chip('Accepted', ['status' => 'accepted'], $f['status'] === 'accepted', 'proposals-status-accepted') ?>
        <?= $chip('Dismissed', ['status' => 'dismissed'], $f['status'] === 'dismissed', 'proposals-status-dismissed') ?>
        <?= $chip('Everything', ['status' => 'all'], $f['status'] === null, 'proposals-status-all') ?>
    </div>
    <div class="d-flex flex-wrap gap-1 mb-2" id="proposals-kinds">
        <?= $chip('Any kind', ['kind' => null], $f['kind'] === null, 'proposals-kind-all') ?>
        <?php foreach (PROPOSAL_KINDS as $k => [$label]): if (in_array($k, ['lead_unqualify', 'reassign'], true)) { continue; } ?>
            <?= $chip($label, ['kind' => $k], $f['kind'] === $k, 'proposals-kind-' . $k) ?>
        <?php endforeach; ?>
    </div>
    <?php if ($manager): ?>
    <form method="get" action="/proposals" class="row g-2 mb-3 align-items-end" id="proposals-who-form" hx-get="/proposals" hx-target="#page-content" hx-push-url="true">
        <input type="hidden" name="kind" value="<?= e((string) $f['kind']) ?>"><input type="hidden" name="status" value="<?= e($f['status'] ?? 'all') ?>">
        <div class="col-8 col-md-4"><label class="visually-hidden" for="proposals-who">Whose</label>
            <select name="who" id="proposals-who" class="form-select btn-touch"><option value="me" <?= $f['who'] === 'me' ? 'selected' : '' ?>>For me</option><option value="all" <?= $f['who'] === 'all' ? 'selected' : '' ?>>Everyone's</option>
                <?php foreach ($members as $m): ?><option value="<?= (int) $m['member_id'] ?>" <?= $f['who'] === (string) $m['member_id'] ? 'selected' : '' ?>><?= e($m['display_name']) ?></option><?php endforeach; ?></select></div>
        <div class="col-4 col-md-2"><button type="submit" class="btn btn-light btn-touch w-100" id="proposals-who-btn"><i class="feather-filter me-1"></i>Show</button></div>
    </form>
    <?php endif; ?>
    <div class="fs-12 text-muted mb-2" id="proposals-total"><?= (int) $total ?> proposal<?= $total === 1 ? '' : 's' ?></div>
    <?php if ($cards === []): ?>
        <div class="card"><div class="card-body"><div class="empty-state text-center py-4" id="proposals-empty"><span class="avatar-text avatar-lg rounded mb-2"><i class="feather-zap"></i></span>
            <div class="fw-semibold"><?= $f['status'] === 'proposed' ? 'Nothing is waiting for you.' : 'No proposals match.' ?></div>
            <div class="fs-12 text-muted">The Sales Assistant proposes a next step on deals that have none, qualifies new leads and points out possible duplicates; each one waits here for your yes.</div></div></div></div>
    <?php else: ?>
        <div class="row g-3" id="proposals-list"><?php foreach ($cards as $r) { echo view('proposals/partials/card.php', ['r' => $r, 'back' => $back, 'manager' => $manager]); } ?></div>
        <?php if ($pages > 1): ?><nav class="mt-3" id="proposals-pagination"><ul class="pagination mb-0">
            <?php if ($page > 1): ?><li class="page-item"><?= hx_link($query(['page' => $page - 1]), 'Previous', 'page-link') ?></li><?php endif; ?>
            <li class="page-item disabled"><span class="page-link"><?= $page ?> of <?= $pages ?></span></li>
            <?php if ($page < $pages): ?><li class="page-item"><?= hx_link($query(['page' => $page + 1]), 'Next', 'page-link', 'id="proposals-next"') ?></li><?php endif; ?>
        </ul></nav><?php endif; ?>
    <?php endif; ?>
</div>
