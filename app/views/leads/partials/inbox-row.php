<?php /** One inbox row. Data: r, kind (proposed|unowned), tz, may, me */ $lid = (int) $r['lead_id']; ?>
<div class="list-group-item" id="inbox-<?= e($kind) ?>-<?= $lid ?>">
    <div class="d-flex align-items-start gap-2">
        <?php if ($kind === 'unowned' && $may['assign']): ?><input type="checkbox" class="form-check-input mt-1 inbox-select" name="leads[]" value="<?= $lid ?>" form="inbox-bulk-form" id="inbox-select-<?= $lid ?>" aria-label="Select <?= e($r['label']) ?>"><?php endif; ?>
        <div class="min-w-0 flex-grow-1">
            <div class="fw-semibold text-truncate"><?= hx_link(with_back('/leads/' . $lid, '/leads/inbox'), e($r['label']), 'text-dark') ?></div>
            <div class="fs-12 text-muted text-truncate"><?= e(trim(($r['email'] ?? '') . ($r['source'] ? ' · ' . $r['source'] : ''), ' ·')) ?> · <?= e(format_ts($r['created_at'], $tz, 'M j, g:i A')) ?></div>
            <?php if ($kind === 'proposed' && ($r['email_subject'] || $r['message'])): ?><div class="fs-12 text-break mt-1"><?= $r['email_subject'] ? '<span class="fw-semibold">' . e($r['email_subject']) . '</span> ' : '' ?><?= e(mb_substr((string) $r['message'], 0, 200)) ?></div><?php endif; ?>
        </div>
    </div>
    <div class="d-flex flex-wrap gap-1 mt-2">
    <?php if ($kind === 'proposed' && $may['write']): ?>
        <form method="post" action="/leads/accept.php" hx-post="/leads/accept.php" hx-target="#flash"><?= csrf_field() ?><input type="hidden" name="lead" value="<?= $lid ?>">
            <button type="submit" class="btn btn-primary btn-sm btn-touch" id="inbox-accept-<?= $lid ?>"><i class="feather-check me-1"></i>Accept</button></form>
        <form method="post" action="/leads/dismiss.php" hx-post="/leads/dismiss.php" hx-target="#flash" hx-confirm="Dismiss <?= e($r['label']) ?>?"><?= csrf_field() ?><input type="hidden" name="lead" value="<?= $lid ?>">
            <button type="submit" class="btn btn-light btn-sm btn-touch" id="inbox-dismiss-<?= $lid ?>"><i class="feather-x me-1"></i>Dismiss</button></form>
    <?php elseif ($kind === 'unowned' && $may['assign']): ?>
        <form method="post" action="/leads/assign.php" hx-post="/leads/assign.php" hx-target="#flash"><?= csrf_field() ?><input type="hidden" name="lead" value="<?= $lid ?>"><input type="hidden" name="owner" value="<?= (int) $me ?>"><input type="hidden" name="return_to" value="/leads/inbox">
            <button type="submit" class="btn btn-primary btn-sm btn-touch" id="inbox-take-<?= $lid ?>"><i class="feather-user-check me-1"></i>Take</button></form>
    <?php endif; ?>
    </div>
</div>
