<?php /** Accounts (account-list). Data: result {rows, total}, filters, page, owners, views, may (create), notice, back */
$f = $filters;
$query = static fn (array $over) => '/accounts?' . http_build_query(array_filter(array_merge(['q' => $f['q'], 'kind' => $f['kind'], 'owner' => $f['owner'], 'industry' => $f['industry'], 'country' => $f['country'], 'tag' => $f['tag'], 'untouched_days' => $f['untouched_days'], 'view' => $f['view']], $over), static fn ($v) => $v !== null && $v !== ''));
$chip = static fn (string $label, array $over, bool $on, string $id) => hx_link($query($over), e($label), 'btn btn-touch btn-sm ' . ($on ? 'btn-primary' : 'btn-light'), 'id="' . $id . '"');
$pages = (int) ceil($result['total'] / ACCOUNT_PAGE); ?>
<?= view('shared/header.php', ['id' => 'account-list', 'title' => 'Accounts', 'crumbs' => [['Today', '/'], ['Accounts', null]],
    'action' => $may['create'] ? hx_link('/accounts/new', '<i class="feather-plus me-1"></i>New account', 'btn btn-primary btn-touch', 'id="account-new-btn"') : '']) ?>
<div class="main-content" id="account-list-content">
    <?= view('shared/notice.php', ['notice' => $notice]) ?>
    <form method="get" action="/accounts" class="row g-2 mb-2 align-items-end" id="account-list-filters" hx-get="/accounts" hx-target="#page-content" hx-push-url="true">
        <div class="col-12 col-md-4"><label class="visually-hidden" for="account-list-filter-q">Search</label>
            <input type="search" name="q" id="account-list-filter-q" class="form-control btn-touch" placeholder="Name or domain…" value="<?= e($f['q']) ?>"></div>
        <div class="col-6 col-md-2"><label class="visually-hidden" for="account-list-filter-owner">Owner</label>
            <select name="owner" id="account-list-filter-owner" class="form-select btn-touch"><option value="">Any owner</option><option value="me" <?= $f['owner'] === 'me' ? 'selected' : '' ?>>Mine</option>
                <?php foreach ($owners as $o): ?><option value="<?= (int) $o['member_id'] ?>" <?= $f['owner'] === (string) $o['member_id'] ? 'selected' : '' ?>><?= e($o['display_name']) ?></option><?php endforeach; ?></select></div>
        <div class="col-6 col-md-2"><label class="visually-hidden" for="account-list-filter-industry">Industry</label>
            <input type="text" name="industry" id="account-list-filter-industry" class="form-control btn-touch" placeholder="Industry" value="<?= e((string) $f['industry']) ?>"></div>
        <div class="col-6 col-md-2"><label class="visually-hidden" for="account-list-filter-untouched">Untouched</label>
            <select name="untouched_days" id="account-list-filter-untouched" class="form-select btn-touch"><option value="">Any last touch</option>
                <?php foreach ([7, 30, 90, 180] as $d): ?><option value="<?= $d ?>" <?= $f['untouched_days'] === $d ? 'selected' : '' ?>>Untouched <?= $d ?>+ days</option><?php endforeach; ?></select></div>
        <div class="col-6 col-md-2"><input type="hidden" name="kind" value="<?= e((string) $f['kind']) ?>"><button type="submit" class="btn btn-light btn-touch w-100" id="account-list-filter-btn"><i class="feather-filter me-1"></i>Filter</button></div>
    </form>
    <div class="d-flex flex-wrap gap-1 mb-3" id="account-list-kinds">
        <?= $chip('All', ['kind' => null], $f['kind'] === null, 'account-list-kind-all') ?>
        <?php foreach (ACCOUNT_KINDS as $k => $label): ?><?= $chip($label, ['kind' => $k], $f['kind'] === $k, 'account-list-kind-' . $k) ?><?php endforeach; ?>
        <?php foreach ($views as $v): ?><?= hx_link('/accounts?view=' . (int) $v['saved_view_id'], e($v['name']), 'btn btn-touch btn-sm ' . ($f['view'] === (int) $v['saved_view_id'] ? 'btn-info' : 'btn-outline-info'), 'id="account-list-view-' . (int) $v['saved_view_id'] . '"') ?><?php endforeach; ?>
        <?php if ($f['tag'] !== null): ?><span class="badge bg-soft-primary text-primary align-self-center" id="account-list-tag">tag: <?= e($f['tag']) ?> <?= hx_link($query(['tag' => null]), '<i class="feather-x"></i>', 'text-primary', 'aria-label="Clear the tag"') ?></span><?php endif; ?>
    </div>
    <?php $bulkOn = !empty($bulk) && $result['rows'] !== []; ?>
    <div class="fs-12 text-muted mb-2" id="account-list-total"><?= (int) $result['total'] ?> account<?= $result['total'] === 1 ? '' : 's' ?></div>
    <?php if ($result['rows'] === []): ?>
        <div class="card" id="account-list-empty-card"><div class="card-body"><div class="empty-state text-center py-4" id="account-list-empty"><span class="avatar-text avatar-lg rounded mb-2"><i class="feather-briefcase"></i></span>
            <div class="fw-semibold"><?= $f['q'] !== '' || $f['kind'] !== null || $f['owner'] !== null ? 'No account matches.' : 'No accounts yet.' ?></div>
            <?php if ($may['create']): ?><div class="mt-2"><?= hx_link('/accounts/new', 'Add an account', 'btn btn-primary btn-touch', 'id="account-list-empty-new-btn"') ?></div><?php endif; ?></div></div></div>
    <?php else: ?>
        <?= $bulkOn ? bulk_bar(db(), 'account', $filters, (int) $result['total'], null, $back) : '' ?>
        <div class="row g-3" id="account-list-cards"><?php foreach ($result['rows'] as $a) { echo view('accounts/partials/card.php', ['a' => $a, 'back' => $back, 'bulk' => $bulkOn]); } ?></div>
        <?php if ($pages > 1): ?><nav class="mt-3" id="account-list-pagination"><ul class="pagination mb-0">
            <?php if ($page > 1): ?><li class="page-item"><?= hx_link($query(['page' => $page - 1]), 'Previous', 'page-link') ?></li><?php endif; ?>
            <li class="page-item disabled"><span class="page-link"><?= $page ?> of <?= $pages ?></span></li>
            <?php if ($page < $pages): ?><li class="page-item"><?= hx_link($query(['page' => $page + 1]), 'Next', 'page-link', 'id="account-list-next"') ?></li><?php endif; ?>
        </ul></nav><?php endif; ?>
    <?php endif; ?>
</div>
