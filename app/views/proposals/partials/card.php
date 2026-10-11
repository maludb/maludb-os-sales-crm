<?php /** A proposal card (screen `proposals`; ids proposal-{id}, proposal-{id}-accept). Data: r (proposal_render), back, manager */
$id = $r['id']; $open = $r['status'] === 'proposed'; ?>
<div class="col-md-6 col-xl-4"><div class="card h-100 mb-0" id="proposal-<?= $id ?>"><div class="card-body d-flex flex-column">
    <div class="d-flex align-items-start gap-2">
        <span class="avatar-text avatar-md rounded flex-shrink-0"><i class="<?= e($r['icon']) ?>"></i></span>
        <div class="min-w-0 flex-grow-1">
            <div class="fw-semibold fs-14"><?= e($r['title']) ?></div>
            <div class="fs-12 text-muted"><?= e($r['kind_label']) ?><?php if ($r['record'] !== null): ?> · <?= hx_link(with_back($r['record']['url'], $back), e($r['record']['label']), '', 'id="proposal-' . $id . '-record"') ?><?php endif; ?></div>
        </div>
        <span class="badge bg-soft-<?= e($r['tone']) ?> text-<?= e($r['tone']) ?> flex-shrink-0" id="proposal-<?= $id ?>-status"><?= e($r['status_label']) ?></span>
    </div>
    <div class="mt-2 fs-12" id="proposal-<?= $id ?>-payload">
        <?php
        $partial = in_array($r['kind'], ['next_step', 'lead_qualify', 'duplicate'], true) ? 'payload-' . $r['kind'] : 'payload-other';
        echo view('proposals/partials/' . $partial . '.php', ['r' => $r, 'back' => $back]); ?>
    </div>
    <div class="fs-11 text-muted mt-2"><?= $r['for_name'] ? 'For ' . e($r['for_name']) : '' ?><?= $r['proposed_by_name'] ? ' · from ' . e($r['proposed_by_name']) : '' ?><?= $r['decided'] !== null ? ' · ' . e($r['decided']) : '' ?></div>
    <?php if ($open): ?>
    <div class="d-flex gap-2 mt-auto pt-3">
        <form method="post" action="/proposals/accept.php" hx-post="/proposals/accept.php" hx-target="#page-content" hx-swap="innerHTML" class="flex-grow-1"><?= csrf_field() ?><input type="hidden" name="proposal" value="<?= $id ?>">
            <button type="submit" class="btn btn-primary btn-touch w-100" id="proposal-<?= $id ?>-accept"><i class="feather-check me-1"></i><?= $r['kind'] === 'duplicate' ? 'Review the pair' : 'Accept' ?></button></form>
        <form method="post" action="/proposals/dismiss.php" hx-post="/proposals/dismiss.php" hx-target="#page-content" hx-swap="innerHTML"><?= csrf_field() ?><input type="hidden" name="proposal" value="<?= $id ?>">
            <button type="submit" class="btn btn-light btn-touch" id="proposal-<?= $id ?>-dismiss"><i class="feather-x me-1"></i>Dismiss</button></form>
    </div>
    <?php elseif ($r['result_activity_id'] !== null): ?>
    <div class="mt-auto pt-3 fs-12"><?= hx_link(with_back('/activities/' . $r['result_activity_id'], $back), 'Open the activity it made', 'fw-semibold', 'id="proposal-' . $id . '-activity"') ?></div>
    <?php endif; ?>
</div></div></div>
