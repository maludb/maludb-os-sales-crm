<?php /** Contacts (contact-list). Data: result {rows, total}, filters, page, owners, views, account_name, may (create), notice, back */
$f = $filters;
$query = static fn (array $over) => '/contacts?' . http_build_query(array_filter(array_merge(['q' => $f['q'], 'account' => $f['account'], 'lifecycle' => $f['lifecycle'], 'owner' => $f['owner'], 'role' => $f['role'], 'opt_out' => $f['opt_out'], 'tag' => $f['tag'], 'untouched_days' => $f['untouched_days'], 'view' => $f['view']], $over), static fn ($v) => $v !== null && $v !== ''));
$chip = static fn (string $label, array $over, bool $on, string $id) => hx_link($query($over), e($label), 'btn btn-touch btn-sm ' . ($on ? 'btn-primary' : 'btn-light'), 'id="' . $id . '"');
$pages = (int) ceil($result['total'] / CONTACT_PAGE); ?>
<?= view('shared/header.php', ['id' => 'contact-list', 'title' => 'Contacts', 'crumbs' => [['Today', '/'], ['Contacts', null]],
    'action' => $may['create'] ? hx_link('/contacts/new' . ($f['account'] !== null ? '?account=' . (int) $f['account'] : ''), '<i class="feather-plus me-1"></i>New contact', 'btn btn-primary btn-touch', 'id="contact-new-btn"') : '']) ?>
<div class="main-content" id="contact-list-content">
    <?= view('shared/notice.php', ['notice' => $notice]) ?>
    <form method="get" action="/contacts" class="row g-2 mb-2 align-items-end" id="contact-list-filters" hx-get="/contacts" hx-target="#page-content" hx-push-url="true">
        <?php if ($f['account'] !== null): ?><input type="hidden" name="account" value="<?= (int) $f['account'] ?>"><?php endif; ?>
        <div class="col-12 col-md-4"><label class="visually-hidden" for="contact-list-filter-q">Search</label>
            <input type="search" name="q" id="contact-list-filter-q" class="form-control btn-touch" placeholder="Name, email or company…" value="<?= e($f['q']) ?>"></div>
        <div class="col-6 col-md-2"><label class="visually-hidden" for="contact-list-filter-owner">Owner</label>
            <select name="owner" id="contact-list-filter-owner" class="form-select btn-touch"><option value="">Any owner</option><option value="me" <?= $f['owner'] === 'me' ? 'selected' : '' ?>>Mine</option>
                <?php foreach ($owners as $o): ?><option value="<?= (int) $o['member_id'] ?>" <?= $f['owner'] === (string) $o['member_id'] ? 'selected' : '' ?>><?= e($o['display_name']) ?></option><?php endforeach; ?></select></div>
        <div class="col-6 col-md-2"><label class="visually-hidden" for="contact-list-filter-role">Role</label>
            <select name="role" id="contact-list-filter-role" class="form-select btn-touch"><option value="">Any role</option>
                <?php foreach (CONTACT_ROLES as $k => $l): ?><option value="<?= e($k) ?>" <?= $f['role'] === $k ? 'selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select></div>
        <div class="col-6 col-md-2"><label class="visually-hidden" for="contact-list-filter-untouched">Untouched</label>
            <select name="untouched_days" id="contact-list-filter-untouched" class="form-select btn-touch"><option value="">Any last touch</option>
                <?php foreach ([7, 30, 90, 180] as $d): ?><option value="<?= $d ?>" <?= $f['untouched_days'] === $d ? 'selected' : '' ?>>Untouched <?= $d ?>+ days</option><?php endforeach; ?></select></div>
        <div class="col-6 col-md-2"><input type="hidden" name="lifecycle" value="<?= e((string) $f['lifecycle']) ?>"><button type="submit" class="btn btn-light btn-touch w-100" id="contact-list-filter-btn"><i class="feather-filter me-1"></i>Filter</button></div>
    </form>
    <div class="d-flex flex-wrap gap-1 mb-3" id="contact-list-lifecycles">
        <?= $chip('All', ['lifecycle' => null, 'opt_out' => null], $f['lifecycle'] === null && $f['opt_out'] === null, 'contact-list-lifecycle-all') ?>
        <?php foreach (CONTACT_LIFECYCLES as $k => $label): ?><?= $chip($label, ['lifecycle' => $k], $f['lifecycle'] === $k, 'contact-list-lifecycle-' . $k) ?><?php endforeach; ?>
        <?= $chip('No email', ['opt_out' => 'email'], $f['opt_out'] === 'email', 'contact-list-opt-out-email') ?>
        <?php foreach ($views as $v): ?><?= hx_link('/contacts?view=' . (int) $v['saved_view_id'], e($v['name']), 'btn btn-touch btn-sm ' . ($f['view'] === (int) $v['saved_view_id'] ? 'btn-info' : 'btn-outline-info'), 'id="contact-list-view-' . (int) $v['saved_view_id'] . '"') ?><?php endforeach; ?>
        <?php if ($f['account'] !== null && $account_name !== null): ?><span class="badge bg-soft-primary text-primary align-self-center" id="contact-list-account">at <?= e($account_name) ?> <?= hx_link($query(['account' => null]), '<i class="feather-x"></i>', 'text-primary', 'aria-label="All accounts"') ?></span><?php endif; ?>
        <?php if ($f['tag'] !== null): ?><span class="badge bg-soft-primary text-primary align-self-center" id="contact-list-tag">tag: <?= e($f['tag']) ?> <?= hx_link($query(['tag' => null]), '<i class="feather-x"></i>', 'text-primary', 'aria-label="Clear the tag"') ?></span><?php endif; ?>
    </div>
    <div class="fs-12 text-muted mb-2" id="contact-list-total"><?= (int) $result['total'] ?> contact<?= $result['total'] === 1 ? '' : 's' ?></div>
    <?php if ($result['rows'] === []): ?>
        <div class="card" id="contact-list-empty-card"><div class="card-body"><div class="empty-state text-center py-4" id="contact-list-empty"><span class="avatar-text avatar-lg rounded mb-2"><i class="feather-users"></i></span>
            <div class="fw-semibold"><?= $f['q'] !== '' || $f['lifecycle'] !== null || $f['owner'] !== null ? 'Nobody matches.' : 'No contacts yet.' ?></div>
            <?php if ($may['create']): ?><div class="mt-2"><?= hx_link('/contacts/new', 'Add a contact', 'btn btn-primary btn-touch', 'id="contact-list-empty-new-btn"') ?></div><?php endif; ?></div></div></div>
    <?php else: ?>
        <div class="row g-3" id="contact-list-cards"><?php foreach ($result['rows'] as $c) { echo view('contacts/partials/card.php', ['c' => $c, 'back' => $back]); } ?></div>
        <?php if ($pages > 1): ?><nav class="mt-3" id="contact-list-pagination"><ul class="pagination mb-0">
            <?php if ($page > 1): ?><li class="page-item"><?= hx_link($query(['page' => $page - 1]), 'Previous', 'page-link') ?></li><?php endif; ?>
            <li class="page-item disabled"><span class="page-link"><?= $page ?> of <?= $pages ?></span></li>
            <?php if ($page < $pages): ?><li class="page-item"><?= hx_link($query(['page' => $page + 1]), 'Next', 'page-link', 'id="contact-list-next"') ?></li><?php endif; ?>
        </ul></nav><?php endif; ?>
    <?php endif; ?>
</div>
