<?php /** The admin home (screen `admin`; ids admin-cards, admin-card-{area}, admin-mailbox-health, admin-tombstones-due). Data: areas ([id, url, icon, title, right, words, tone] — those the caller holds), sum (admin_summary) */ ?>
<?= view('shared/header.php', ['id' => 'admin', 'title' => 'Admin', 'crumbs' => [['Today', '/'], ['Admin', null]]]) ?>
<div class="main-content" id="admin-content">
    <?php if (has_right('mailbox.manage') && in_array($sum['mailbox']['state'], ['error', 'quiet', 'none'], true)): $mb = $sum['mailbox']; ?>
    <div class="alert alert-<?= $mb['state'] === 'error' ? 'danger' : 'warning' ?> d-flex align-items-center gap-2" id="admin-mailbox-health" role="status"><i class="feather-mail"></i>
        <div class="flex-grow-1"><strong>The logging mailbox:</strong> <?= e($mb['words']) ?>.</div><?= hx_link('/admin/mailbox', 'Look', 'btn btn-light btn-sm btn-touch', 'id="admin-mailbox-health-btn"') ?></div>
    <?php endif; ?>
    <?php if (has_right('records.purge') && $sum['tombstones']['due'] > 0): ?>
    <div class="alert alert-warning d-flex align-items-center gap-2" id="admin-tombstones-due" role="status"><i class="feather-trash-2"></i>
        <div class="flex-grow-1"><strong><?= (int) $sum['tombstones']['due'] ?> deleted record<?= $sum['tombstones']['due'] === 1 ? ' is' : 's are' ?> past <?= (int) $sum['tombstones']['days'] ?> days</strong> and will be purged by the next pass.</div><?= hx_link('/admin/purge', 'Look', 'btn btn-light btn-sm btn-touch', 'id="admin-tombstones-due-btn"') ?></div>
    <?php endif; ?>
    <div class="row g-3" id="admin-cards">
        <?php foreach ($areas as [$id, $url, $icon, $title, $right, $words, $tone]): ?>
        <div class="col-md-6 col-xl-4" id="admin-card-<?= e($id) ?>"><div class="card mb-0 h-100"><div class="card-body d-flex gap-3 align-items-start">
            <span class="avatar-text avatar-lg rounded flex-shrink-0"><i class="<?= e($icon) ?>"></i></span>
            <div class="min-w-0 flex-grow-1"><div class="fw-semibold"><?= hx_link($url, e($title), 'text-reset stretched-link', 'id="admin-card-' . e($id) . '-link"') ?></div>
                <div class="fs-12 <?= $tone ? 'text-' . e($tone) : 'text-muted' ?>" id="admin-card-<?= e($id) ?>-words"><?= e($words) ?></div></div>
        </div></div></div>
        <?php endforeach; ?>
    </div>
</div>
