<?php /** The contact's header: name, title, account, the lock; Log call · Schedule · Send email (when shipped) and ⋯ Edit · No email · No texts · Restrict · Delete. Data: c, may */
$cid = (int) $c['contact_id'];
$name = $c['name'] !== '' ? $c['name'] : (string) $c['email'];
$quick = [
    ['/log/call?contact=' . $cid, '/log/call.php', 'feather-phone', 'Log call', 'contact-log-call-btn', $may['log']],
    ['/activities/new?contact=' . $cid, '/activities/form.php', 'feather-clock', 'Schedule', 'contact-schedule-btn', $may['log']],
    ['/email/compose?contact=' . $cid, '/email/compose.php', 'feather-mail', 'Send email', 'contact-email-btn', $may['email'] && !$c['email_opt_out']],
];
$restricted = $c['restricted_to_member_id'] !== null || $c['restricted_to_department_id'] !== null; ?>
<div class="page-header" id="contact-view-header">
    <div class="page-header-left d-flex align-items-center min-w-0">
        <div class="page-header-title min-w-0"><h5 class="m-b-10 text-truncate" id="contact-view-name"><?php if ($restricted): ?><i class="feather-lock text-warning me-1" title="Restricted" id="contact-view-lock"></i><?php endif; ?><?= e($name) ?></h5>
            <div class="fs-12 text-muted text-truncate" id="contact-view-subtitle"><?= e((string) ($c['title'] ?? '')) ?><?php if ($c['account_id'] !== null && $c['account_name'] !== null): ?><?= $c['title'] ? ' at ' : '' ?><?= hx_link(with_back('/accounts/' . (int) $c['account_id'], '/contacts/' . $cid), e($c['account_name']), 'fw-semibold', 'id="contact-view-account-link"') ?><?php endif; ?></div></div>
    </div>
    <div class="page-header-right ms-auto d-flex flex-wrap gap-1 align-items-center">
        <?php foreach ($quick as [$url, $file, $icon, $label, $bid, $allowed]): if (!$allowed || !nav_built($file)) { continue; } ?>
            <?= hx_link($url, '<i class="' . $icon . ' me-1"></i><span class="d-none d-sm-inline">' . e($label) . '</span>', 'btn btn-light btn-touch', 'id="' . $bid . '" aria-label="' . e($label) . '"') ?>
        <?php endforeach; ?>
        <?php if ($may['edit'] || $may['restrict']): ?>
        <div class="dropdown">
            <button type="button" class="btn btn-light btn-touch" data-bs-toggle="dropdown" aria-expanded="false" id="contact-more-btn" aria-label="More"><i class="feather-more-horizontal"></i></button>
            <div class="dropdown-menu dropdown-menu-end" id="contact-more-menu">
                <?php if ($may['edit']): ?><?= hx_link('/contacts/' . $cid . '/edit', '<i class="feather-edit-2 me-2"></i>Edit', 'dropdown-item', 'id="contact-edit-btn"') ?>
                    <?php foreach (['email' => ['No email', $c['email_opt_out']], 'sms' => ['No texts', $c['sms_opt_out']]] as $ch => [$label, $on]): if ($on) { continue; } ?>
                    <form method="post" action="/contacts/opt-out.php" hx-post="/contacts/opt-out.php" hx-target="#flash" hx-confirm="Record that <?= e($name) ?> asked for <?= e(strtolower($label)) ?>?"><?= csrf_field() ?>
                        <input type="hidden" name="contact" value="<?= $cid ?>"><input type="hidden" name="channel" value="<?= $ch ?>">
                        <button type="submit" class="dropdown-item" id="contact-opt-out-<?= $ch ?>-btn"><i class="feather-slash me-2"></i><?= e($label) ?></button></form>
                    <?php endforeach; ?>
                <?php endif; ?>
                <?php if ($may['restrict']): ?><a href="/contacts/<?= $cid ?>/edit#restriction-section" class="dropdown-item" id="contact-restrict-btn"><i class="feather-lock me-2"></i><?= $restricted ? 'Change who may see it' : 'Restrict' ?></a><?php endif; ?>
                <?php if ($may['edit']): ?>
                <form method="post" action="/records/delete.php" hx-post="/records/delete.php" hx-target="#flash" hx-confirm="Delete <?= e($name) ?>? It can be restored for a while."><?= csrf_field() ?>
                    <input type="hidden" name="kind" value="contact"><input type="hidden" name="record" value="<?= $cid ?>">
                    <button type="submit" class="dropdown-item text-danger" id="contact-delete-btn"><i class="feather-trash-2 me-2"></i>Delete</button></form>
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>
    </div>
</div>
<?php if (($b = back_link()) !== null): ?><div class="px-3 pb-2"><?= hx_link($b[0], '<i class="feather-arrow-left me-1"></i>Back to ' . e($b[1]), 'fs-12 fw-semibold', 'id="contact-view-back"') ?></div><?php endif; ?>
