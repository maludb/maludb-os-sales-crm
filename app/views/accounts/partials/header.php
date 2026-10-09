<?php /** The account's header: name, kind, owner, the lock when restricted; Log call · Schedule · New deal · Send email (each when its slice has shipped) and ⋯ Edit · Restrict · Delete. Data: a, may, back */
$aid = (int) $a['account_id'];
$quick = [
    ['/log/call?account=' . $aid, '/log/call.php', 'feather-phone', 'Log call', 'account-log-call-btn', $may['log']],
    ['/activities/new?account=' . $aid, '/activities/form.php', 'feather-clock', 'Schedule', 'account-schedule-btn', $may['log']],
    ['/deals/new?account=' . $aid, '/deals/form.php', 'feather-plus', 'New deal', 'account-new-deal-btn', $may['deal']],
    ['/email/compose?account=' . $aid, '/email/compose.php', 'feather-mail', 'Send email', 'account-email-btn', $may['email']],
];
$restricted = $a['restricted_to_member_id'] !== null || $a['restricted_to_department_id'] !== null; ?>
<div class="page-header" id="account-view-header">
    <div class="page-header-left d-flex align-items-center min-w-0">
        <div class="page-header-title min-w-0"><h5 class="m-b-10 text-truncate" id="account-view-name"><?php if ($restricted): ?><i class="feather-lock text-warning me-1" title="Restricted" id="account-view-lock"></i><?php endif; ?><?= e($a['name']) ?></h5></div>
        <ul class="breadcrumb d-none d-md-flex"><li class="breadcrumb-item"><?= hx_link('/', 'Today') ?></li><li class="breadcrumb-item"><?= hx_link('/accounts', 'Accounts') ?></li><li class="breadcrumb-item"><?= e($a['name']) ?></li></ul>
    </div>
    <div class="page-header-right ms-auto d-flex flex-wrap gap-1 align-items-center">
        <?php foreach ($quick as [$url, $file, $icon, $label, $bid, $allowed]): if (!$allowed || !nav_built($file)) { continue; } ?>
            <?= hx_link($url, '<i class="' . $icon . ' me-1"></i><span class="d-none d-sm-inline">' . e($label) . '</span>', 'btn btn-light btn-touch', 'id="' . $bid . '" aria-label="' . e($label) . '"') ?>
        <?php endforeach; ?>
        <?php if ($may['edit'] || $may['restrict'] || $may['delete']): ?>
        <div class="dropdown">
            <button type="button" class="btn btn-light btn-touch" data-bs-toggle="dropdown" aria-expanded="false" id="account-more-btn" aria-label="More"><i class="feather-more-horizontal"></i></button>
            <div class="dropdown-menu dropdown-menu-end" id="account-more-menu">
                <?php if ($may['edit']): ?><?= hx_link('/accounts/' . $aid . '/edit', '<i class="feather-edit-2 me-2"></i>Edit', 'dropdown-item', 'id="account-edit-btn"') ?><?php endif; ?>
                <?php if ($may['restrict']): ?><a href="/accounts/<?= $aid ?>/edit#restriction-section" class="dropdown-item" id="account-restrict-btn"><i class="feather-lock me-2"></i><?= $restricted ? 'Change who may see it' : 'Restrict' ?></a><?php endif; ?>
                <?php if ($may['delete']): ?>
                <form method="post" action="/records/delete.php" hx-post="/records/delete.php" hx-target="#flash" hx-confirm="Delete the account <?= e($a['name']) ?>? It can be restored for a while."><?= csrf_field() ?>
                    <input type="hidden" name="kind" value="account"><input type="hidden" name="record" value="<?= $aid ?>">
                    <button type="submit" class="dropdown-item text-danger" id="account-delete-btn"><i class="feather-trash-2 me-2"></i>Delete</button></form>
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>
    </div>
</div>
<?php if (($b = back_link()) !== null): ?><div class="px-3 pb-2"><?= hx_link($b[0], '<i class="feather-arrow-left me-1"></i>Back to ' . e($b[1]), 'fs-12 fw-semibold', 'id="account-view-back"') ?></div><?php endif; ?>
