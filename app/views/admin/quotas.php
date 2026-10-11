<?php /** Quotas (screen `admin-quotas`; ids quota-grid, quota-{member}-{month}, quotas-form, quotas-save-btn). Data: grid (quota_grid), this_year, notice */
$y = $grid['year']; ?>
<?= view('shared/header.php', ['id' => 'admin-quotas', 'title' => 'Quotas', 'crumbs' => [['Today', '/'], ['Admin', '/admin'], ['Quotas', null]]]) ?>
<div class="main-content" id="admin-quotas-content">
    <?= view('shared/notice.php', ['notice' => $notice]) ?>
    <div class="d-flex align-items-center gap-2 mb-3" id="quotas-years">
        <?= hx_link('/admin/quotas?year=' . ($y - 1), '<i class="feather-chevron-left"></i>', 'btn btn-light btn-touch', 'id="quotas-prev" aria-label="' . ($y - 1) . '"') ?>
        <div class="fw-semibold px-2" id="quotas-year"><?= $y ?></div>
        <?= hx_link('/admin/quotas?year=' . ($y + 1), '<i class="feather-chevron-right"></i>', 'btn btn-light btn-touch', 'id="quotas-next" aria-label="' . ($y + 1) . '"') ?>
        <?php if ($y !== $this_year): ?><?= hx_link('/admin/quotas', 'This year', 'btn btn-light btn-touch ms-2', 'id="quotas-today"') ?><?php endif; ?>
        <div class="fs-12 text-muted ms-auto" id="quotas-currency">in <?= e($grid['currency']) ?></div>
    </div>
    <form method="post" action="/admin/quotas/save.php" hx-post="/admin/quotas/save.php" hx-target="#flash" id="quotas-form"><?= csrf_field() ?>
        <div class="card mb-3"><div class="card-body p-0"><?= view('admin/partials/quota-grid.php', ['grid' => $grid]) ?></div></div>
        <div class="fs-12 text-muted mb-2">An empty cell is no quota. The forecast and the leaderboard measure against these.</div>
        <div class="admin-save-bar d-flex gap-2 mb-3"><button type="submit" class="btn btn-primary btn-touch flex-grow-1" id="quotas-save-btn">Save the quotas</button>
            <?= hx_link('/admin/quotas?year=' . $y, 'Cancel', 'btn btn-light btn-touch', 'id="quotas-cancel-btn"') ?></div>
    </form>
</div>
