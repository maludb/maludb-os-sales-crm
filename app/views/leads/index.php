<?php /** Leads (lead-list). Data: result, filters, page, owners, sources, views, inbox, may (create, import), notice, back */
$f = $filters;
$query = static fn (array $over) => '/leads?' . http_build_query(array_filter(array_merge(['q' => $f['q'], 'status' => $f['status'], 'owner' => $f['owner'], 'source' => $f['source'], 'campaign' => $f['campaign'], 'tag' => $f['tag'], 'untouched_days' => $f['untouched_days'], 'view' => $f['view']], $over), static fn ($v) => $v !== null && $v !== ''));
$chip = static fn (string $label, array $over, bool $on, string $id) => hx_link($query($over), e($label), 'btn btn-touch btn-sm ' . ($on ? 'btn-primary' : 'btn-light'), 'id="' . $id . '"');
$pages = (int) ceil($result['total'] / LEAD_PAGE);
$actions = ($may['import'] && nav_built('/import/form.php') ? hx_link('/import/new?kind=leads_csv', '<i class="feather-upload me-1"></i><span class="d-none d-sm-inline">Import</span>', 'btn btn-light btn-touch me-1', 'id="lead-import-btn" aria-label="Import"') : '')
    . ($may['create'] ? hx_link('/leads/new', '<i class="feather-plus me-1"></i>New lead', 'btn btn-primary btn-touch', 'id="lead-new-btn"') : ''); ?>
<?= view('shared/header.php', ['id' => 'lead-list', 'title' => 'Leads', 'crumbs' => [['Today', '/'], ['Leads', null]], 'action' => $actions]) ?>
<div class="main-content" id="lead-list-content">
    <?= view('shared/notice.php', ['notice' => $notice]) ?>
    <?php if ($inbox > 0): ?><div class="alert alert-info d-flex align-items-center justify-content-between gap-2" id="lead-list-inbox"><span><?= (int) $inbox ?> proposed lead<?= $inbox === 1 ? '' : 's' ?> waiting for a person.</span><?= hx_link('/leads/inbox', 'Open the inbox', 'btn btn-info btn-sm btn-touch', 'id="lead-list-inbox-btn"') ?></div><?php endif; ?>
    <form method="get" action="/leads" class="row g-2 mb-2 align-items-end" id="lead-list-filters" hx-get="/leads" hx-target="#page-content" hx-push-url="true">
        <div class="col-12 col-md-4"><label class="visually-hidden" for="lead-list-filter-q">Search</label><input type="search" name="q" id="lead-list-filter-q" class="form-control btn-touch" placeholder="Name, company or email…" value="<?= e($f['q']) ?>"></div>
        <div class="col-6 col-md-2"><label class="visually-hidden" for="lead-list-filter-owner">Owner</label>
            <select name="owner" id="lead-list-filter-owner" class="form-select btn-touch"><option value="">Any owner</option><option value="me" <?= $f['owner'] === 'me' ? 'selected' : '' ?>>Mine</option><option value="none" <?= $f['owner'] === 'none' ? 'selected' : '' ?>>Unowned</option>
                <?php foreach ($owners as $o): ?><option value="<?= (int) $o['member_id'] ?>" <?= $f['owner'] === (string) $o['member_id'] ? 'selected' : '' ?>><?= e($o['display_name']) ?></option><?php endforeach; ?></select></div>
        <div class="col-6 col-md-2"><label class="visually-hidden" for="lead-list-filter-source">Source</label>
            <select name="source" id="lead-list-filter-source" class="form-select btn-touch"><option value="">Any source</option>
                <?php foreach ($sources as $s): ?><option value="<?= (int) $s['source_id'] ?>" <?= $f['source'] === (int) $s['source_id'] ? 'selected' : '' ?>><?= e($s['name']) ?></option><?php endforeach; ?></select></div>
        <div class="col-6 col-md-2"><label class="visually-hidden" for="lead-list-filter-untouched">Untouched</label>
            <select name="untouched_days" id="lead-list-filter-untouched" class="form-select btn-touch"><option value="">Any last touch</option>
                <?php foreach ([2, 7, 30] as $d): ?><option value="<?= $d ?>" <?= $f['untouched_days'] === $d ? 'selected' : '' ?>>Untouched <?= $d ?>+ days</option><?php endforeach; ?></select></div>
        <div class="col-6 col-md-2"><input type="hidden" name="status" value="<?= e((string) $f['status']) ?>"><button type="submit" class="btn btn-light btn-touch w-100" id="lead-list-filter-btn"><i class="feather-filter me-1"></i>Filter</button></div>
    </form>
    <div class="d-flex flex-wrap gap-1 mb-3" id="lead-list-statuses">
        <?= $chip('In work', ['status' => null], $f['status'] === null, 'lead-list-status-open') ?>
        <?php foreach (LEAD_STATUSES as $k => $label): ?><?= $chip($label, ['status' => $k], $f['status'] === $k, 'lead-list-status-' . $k) ?><?php endforeach; ?>
        <?php foreach ($views as $v): ?><?= hx_link('/leads?view=' . (int) $v['saved_view_id'], e($v['name']), 'btn btn-touch btn-sm ' . ($f['view'] === (int) $v['saved_view_id'] ? 'btn-info' : 'btn-outline-info'), 'id="lead-list-view-' . (int) $v['saved_view_id'] . '"') ?><?php endforeach; ?>
    </div>
    <div class="d-flex flex-wrap gap-3 fs-12 mb-2" id="lead-list-more"><?= hx_link('/leads/inbox', '<i class="feather-inbox me-1"></i>The inbox' . ($inbox > 0 ? ' (' . (int) $inbox . ')' : ''), 'fw-semibold', 'id="lead-list-inbox-link"') ?>
        <?php if ($may['import']): ?><?= hx_link('/import', '<i class="feather-upload me-1"></i>Imports', 'fw-semibold', 'id="lead-list-imports-link"') ?><?php endif; ?>
        <?= hx_link('/duplicates?kind=lead', '<i class="feather-copy me-1"></i>Duplicates', 'fw-semibold', 'id="lead-list-duplicates-link"') ?></div>
    <div class="fs-12 text-muted mb-2" id="lead-list-total"><?= (int) $result['total'] ?> lead<?= $result['total'] === 1 ? '' : 's' ?></div>
    <?php if ($result['rows'] === []): ?>
        <div class="card"><div class="card-body"><div class="empty-state text-center py-4" id="lead-list-empty"><span class="avatar-text avatar-lg rounded mb-2"><i class="feather-user-plus"></i></span>
            <div class="fw-semibold"><?= $f['q'] !== '' || $f['status'] !== null || $f['owner'] !== null ? 'No lead matches.' : 'No leads in work.' ?></div>
            <?php if ($may['create']): ?><div class="mt-2"><?= hx_link('/leads/new', 'Add a lead', 'btn btn-primary btn-touch', 'id="lead-list-empty-new-btn"') ?></div><?php endif; ?></div></div></div>
    <?php else: ?>
        <div class="row g-3" id="lead-list-cards"><?php foreach ($result['rows'] as $l) { echo view('leads/partials/card.php', ['l' => $l, 'back' => $back]); } ?></div>
        <?php if ($pages > 1): ?><nav class="mt-3" id="lead-list-pagination"><ul class="pagination mb-0">
            <?php if ($page > 1): ?><li class="page-item"><?= hx_link($query(['page' => $page - 1]), 'Previous', 'page-link') ?></li><?php endif; ?>
            <li class="page-item disabled"><span class="page-link"><?= $page ?> of <?= $pages ?></span></li>
            <?php if ($page < $pages): ?><li class="page-item"><?= hx_link($query(['page' => $page + 1]), 'Next', 'page-link', 'id="lead-list-next"') ?></li><?php endif; ?>
        </ul></nav><?php endif; ?>
    <?php endif; ?>
</div>
