<?php /** Lists (list-list). Data: rows (find_lists), filters, me, notice. Cards: static or smart, of what, how many, whose. */
$f = $filters;
$query = static fn (array $over) => '/lists?' . http_build_query(array_filter(array_merge(['record_kind' => $f['record_kind'], 'kind' => $f['kind'], 'q' => $f['q']], $over), static fn ($v) => $v !== null && $v !== ''));
$chip = static fn (string $label, array $over, bool $on, string $id) => hx_link($query($over), e($label), 'btn btn-touch btn-sm ' . ($on ? 'btn-primary' : 'btn-light'), 'id="' . $id . '"'); ?>
<?= view('shared/header.php', ['id' => 'list-list', 'title' => 'Lists', 'crumbs' => [['Today', '/'], ['Lists', null]],
    'action' => hx_link('/lists/new', '<i class="feather-plus me-1"></i>New list', 'btn btn-primary btn-touch', 'id="list-new-btn"')]) ?>
<div class="main-content" id="list-list-content">
    <?= view('shared/notice.php', ['notice' => $notice]) ?>
    <div class="d-flex flex-wrap gap-1 mb-2" id="list-list-kinds">
        <?= $chip('All', ['record_kind' => null], $f['record_kind'] === null, 'list-list-kind-all') ?>
        <?php foreach (LIST_RECORD_KINDS as $k => $label): ?><?= $chip($label, ['record_kind' => $k], $f['record_kind'] === $k, 'list-list-kind-' . $k) ?><?php endforeach; ?>
        <span class="ms-2"></span>
        <?= $chip('Static', ['kind' => $f['kind'] === 'static' ? null : 'static'], $f['kind'] === 'static', 'list-list-static') ?>
        <?= $chip('Smart', ['kind' => $f['kind'] === 'smart' ? null : 'smart'], $f['kind'] === 'smart', 'list-list-smart') ?>
        <?= hx_link('/exports', '<i class="feather-download me-1"></i>Exports', 'btn btn-touch btn-sm btn-outline-secondary ms-auto', 'id="list-list-exports-link"') ?>
    </div>
    <div class="fs-12 text-muted mb-2" id="list-list-total"><?= count($rows) ?> list<?= count($rows) === 1 ? '' : 's' ?></div>
    <?php if ($rows === []): ?>
        <div class="card"><div class="card-body"><div class="empty-state text-center py-4" id="list-list-empty"><span class="avatar-text avatar-lg rounded mb-2"><i class="feather-list"></i></span>
            <div class="fw-semibold">No lists yet.</div>
            <div class="fs-12 text-muted mb-2">A static list is people you pick; a smart list is a filter that keeps itself up to date.</div>
            <?= hx_link('/lists/new', 'Make a list', 'btn btn-primary btn-touch', 'id="list-list-empty-new-btn"') ?></div></div></div>
    <?php else: ?>
        <div class="row g-3" id="list-list-cards">
        <?php foreach ($rows as $l): $lid = (int) $l['list_id']; $smart = $l['kind'] === 'smart'; ?>
            <div class="col-md-6 col-xl-4"><div class="card h-100 mb-0" id="list-card-<?= $lid ?>"><div class="card-body d-flex flex-column">
                <div class="d-flex align-items-start gap-2">
                    <span class="avatar-text avatar-md rounded flex-shrink-0"><i class="feather-<?= $smart ? 'filter' : 'list' ?>"></i></span>
                    <div class="min-w-0 flex-grow-1">
                        <div class="fw-semibold fs-14 text-truncate"><?= hx_link(with_back('/lists/' . $lid, '/lists'), e($l['name']), 'text-dark', 'id="list-card-' . $lid . '-link"') ?></div>
                        <div class="fs-12 text-muted text-truncate"><?= e(LIST_RECORD_KINDS[$l['record_kind']] ?? $l['record_kind']) ?> · <?= $smart ? 'a filter' : 'chosen by hand' ?></div>
                    </div>
                    <span class="badge bg-soft-<?= $smart ? 'info text-info' : 'secondary text-secondary' ?> flex-shrink-0" id="list-card-<?= $lid ?>-kind"><?= $smart ? 'Smart' : 'Static' ?></span>
                </div>
                <div class="d-flex flex-wrap gap-1 mt-2 fs-12 align-items-center">
                    <span class="fw-semibold" id="list-card-<?= $lid ?>-count"><?= (int) $l['member_count'] ?></span><span class="text-muted"><?= $smart ? 'match' . ($l['counted_at'] ? '' : ' (not counted yet)') : 'in it' ?></span>
                    <?php if ($l['is_shared']): ?><span class="badge bg-soft-primary text-primary" id="list-card-<?= $lid ?>-shared">Shared</span><?php endif; ?>
                </div>
                <?php if ($l['description']): ?><div class="fs-12 text-muted mt-1 text-truncate"><?= e($l['description']) ?></div><?php endif; ?>
                <div class="fs-11 text-muted mt-auto pt-2"><?= (int) $l['owner_member_id'] === $me ? 'Yours' : e((string) ($l['owner_name'] ?? 'Shared with everyone')) ?></div>
            </div></div></div>
        <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>
