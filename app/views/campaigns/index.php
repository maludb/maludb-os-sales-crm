<?php /** Campaigns (campaign-list). Data: result {rows, total}, filters, page, owners, may (create), notice, back */
$f = $filters;
$query = static fn (array $over) => '/campaigns?' . http_build_query(array_filter(array_merge(['status' => $f['status'], 'kind' => $f['kind'], 'owner' => $f['owner'], 'q' => $f['q']], $over), static fn ($v) => $v !== null && $v !== ''));
$chip = static fn (string $label, array $over, bool $on, string $id) => hx_link($query($over), e($label), 'btn btn-touch btn-sm ' . ($on ? 'btn-primary' : 'btn-light'), 'id="' . $id . '"');
$pages = (int) ceil($result['total'] / CAMPAIGN_PAGE); ?>
<?= view('shared/header.php', ['id' => 'campaign-list', 'title' => 'Campaigns', 'crumbs' => [['Today', '/'], ['Campaigns', null]],
    'action' => $may['create'] ? hx_link('/campaigns/new', '<i class="feather-plus me-1"></i>New campaign', 'btn btn-primary btn-touch', 'id="campaign-new-btn"') : '']) ?>
<div class="main-content" id="campaign-list-content">
    <?= view('shared/notice.php', ['notice' => $notice]) ?>
    <form method="get" action="/campaigns" class="row g-2 mb-2 align-items-end" id="campaign-list-filters" hx-get="/campaigns" hx-target="#page-content" hx-push-url="true">
        <div class="col-12 col-md-4"><label class="visually-hidden" for="campaign-list-filter-q">Search</label>
            <input type="search" name="q" id="campaign-list-filter-q" class="form-control btn-touch" placeholder="Name or utm campaign…" value="<?= e($f['q']) ?>"></div>
        <div class="col-6 col-md-3"><label class="visually-hidden" for="campaign-list-filter-kind">Kind</label>
            <select name="kind" id="campaign-list-filter-kind" class="form-select btn-touch"><option value="">Any kind</option>
                <?php foreach (CAMPAIGN_KINDS as $k => $l): ?><option value="<?= e($k) ?>" <?= $f['kind'] === $k ? 'selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select></div>
        <div class="col-6 col-md-3"><label class="visually-hidden" for="campaign-list-filter-owner">Owner</label>
            <select name="owner" id="campaign-list-filter-owner" class="form-select btn-touch"><option value="">Any owner</option><option value="me" <?= $f['owner'] === 'me' ? 'selected' : '' ?>>Mine</option>
                <?php foreach ($owners as $o): ?><option value="<?= (int) $o['member_id'] ?>" <?= $f['owner'] === (string) $o['member_id'] ? 'selected' : '' ?>><?= e($o['display_name']) ?></option><?php endforeach; ?></select></div>
        <div class="col-12 col-md-2"><input type="hidden" name="status" value="<?= e((string) $f['status']) ?>"><button type="submit" class="btn btn-light btn-touch w-100" id="campaign-list-filter-btn"><i class="feather-filter me-1"></i>Filter</button></div>
    </form>
    <div class="d-flex flex-wrap gap-1 mb-3" id="campaign-list-statuses">
        <?= $chip('All', ['status' => null], $f['status'] === null, 'campaign-list-status-all') ?>
        <?php foreach (CAMPAIGN_STATUSES as $k => $label): ?><?= $chip($label, ['status' => $k], $f['status'] === $k, 'campaign-list-status-' . $k) ?><?php endforeach; ?>
    </div>
    <div class="fs-12 text-muted mb-2" id="campaign-list-total"><?= (int) $result['total'] ?> campaign<?= $result['total'] === 1 ? '' : 's' ?></div>
    <?php if ($result['rows'] === []): ?>
        <div class="card"><div class="card-body"><div class="empty-state text-center py-4" id="campaign-list-empty"><span class="avatar-text avatar-lg rounded mb-2"><i class="feather-flag"></i></span>
            <div class="fw-semibold"><?= $f['q'] !== '' || $f['status'] !== null || $f['kind'] !== null || $f['owner'] !== null ? 'No campaign matches.' : 'No campaigns yet.' ?></div>
            <div class="fs-12 text-muted mb-2">A campaign gathers the people you reached out to, and shows what came of it.</div>
            <?php if ($may['create']): ?><div class="mt-2"><?= hx_link('/campaigns/new', 'Start a campaign', 'btn btn-primary btn-touch', 'id="campaign-list-empty-new-btn"') ?></div><?php endif; ?></div></div></div>
    <?php else: ?>
        <div class="row g-3" id="campaign-list-cards"><?php foreach ($result['rows'] as $c) { echo view('campaigns/partials/card.php', ['c' => $c, 'back' => $back]); } ?></div>
        <?php if ($pages > 1): ?><nav class="mt-3" id="campaign-list-pagination"><ul class="pagination mb-0">
            <?php if ($page > 1): ?><li class="page-item"><?= hx_link($query(['page' => $page - 1]), 'Previous', 'page-link') ?></li><?php endif; ?>
            <li class="page-item disabled"><span class="page-link"><?= $page ?> of <?= $pages ?></span></li>
            <?php if ($page < $pages): ?><li class="page-item"><?= hx_link($query(['page' => $page + 1]), 'Next', 'page-link', 'id="campaign-list-next"') ?></li><?php endif; ?>
        </ul></nav><?php endif; ?>
    <?php endif; ?>
</div>
