<?php /** Notifications (screen `notifications`): unread first. Data: rows, tz, unreadOnly */ ?>
<?= view('shared/header.php', ['id' => 'notifications', 'title' => 'Notifications', 'crumbs' => [['Today', '/'], ['Notifications', null]],
    'action' => $rows !== [] ? '<form method="post" action="/notifications/read-all.php" hx-post="/notifications/read-all.php" hx-target="#page-content" hx-swap="innerHTML" id="notifications-read-all">' . csrf_field()
        . '<input type="hidden" name="all" value="yes"><button type="submit" class="btn btn-light btn-touch" id="notifications-read-all-btn">Mark all read</button></form>' : '']) ?>
<div class="main-content" id="notifications-content">
    <div class="d-flex flex-wrap gap-1 mb-3" id="notifications-filters">
        <?= hx_link('/notifications', 'Everything', 'btn btn-touch ' . ($unreadOnly ? 'btn-light' : 'btn-primary'), 'id="notifications-filter-all"') ?>
        <?= hx_link('/notifications?unread=1', 'Unread', 'btn btn-touch ' . ($unreadOnly ? 'btn-primary' : 'btn-light'), 'id="notifications-filter-unread"') ?>
    </div>
    <?php if ($rows === []): ?>
        <div class="card" id="notifications-card"><div class="card-body">
            <div class="empty-state" id="notifications-empty">
                <span class="avatar-text avatar-lg rounded"><i class="feather-bell"></i></span>
                <div><div class="fw-semibold">Nothing yet</div>
                    <div class="fs-12 text-muted">When an activity of yours is due, a lead is routed to you, a contact replies, a deal of yours is won or the Sales Assistant proposes a next step, it is listed here. <?= hx_link('/settings/', 'Choose what you are told', 'fw-semibold') ?>.</div></div>
            </div>
        </div></div>
    <?php else: ?>
        <?php foreach ($rows as $n): $id = (int) $n['notification_id']; $unread = $n['read_at'] === null; ?>
            <div class="card mb-2<?= $unread ? ' border-primary' : '' ?>" id="notification-<?= $id ?>">
                <div class="card-body d-flex align-items-start gap-3 py-3">
                    <span class="avatar-text avatar-md rounded <?= $unread ? 'bg-soft-primary text-primary' : '' ?>"><i class="feather-bell"></i></span>
                    <div class="min-w-0 flex-grow-1">
                        <div class="fw-semibold"><?= ($u = notification_record_url($n)) !== null ? hx_link(with_back($u, '/notifications'), e($n['title']), 'text-dark') : e($n['title']) ?></div>
                        <?php if (($n['body'] ?? '') !== ''): ?><div class="fs-12 text-muted"><?= e($n['body']) ?></div><?php endif; ?>
                        <div class="fs-11 text-muted"><?= e(format_ts($n['created_at'], $tz, 'M j, g:i A')) ?></div>
                    </div>
                    <?php if ($unread): ?>
                        <form method="post" action="/notifications/read.php" hx-post="/notifications/read.php" hx-target="#page-content" hx-swap="innerHTML"><?= csrf_field() ?><input type="hidden" name="notification" value="<?= $id ?>">
                            <button type="submit" class="btn btn-light btn-sm" id="notification-<?= $id ?>-read-btn" aria-label="Mark read"><i class="feather-check"></i></button></form>
                    <?php endif; ?>
                </div>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>
</div>
