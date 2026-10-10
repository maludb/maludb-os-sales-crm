<?php /** The won form (deal-won): confirm the value (the lines' total when the value follows them) and the close date (today, never later). Data: deal, today, required */
$id = (int) $deal['deal_id']; ?>
<?= view('shared/header.php', ['id' => 'deal-won', 'title' => 'Won: ' . $deal['title'], 'crumbs' => [['Today', '/'], ['Pipeline', pipeline_board_url()], [$deal['title'], '/deals/' . $id], ['Won', null]], 'back' => ['/deals/' . $id, 'the deal']]) ?>
<div class="main-content" id="deal-won-content">
    <form method="post" action="/deals/<?= $id ?>/won" hx-post="/deals/<?= $id ?>/won" hx-target="#flash" id="deal-won-form" class="card"><div class="card-body"><?= csrf_field() ?><input type="hidden" name="deal" value="<?= $id ?>">
        <div class="d-flex align-items-center gap-2 mb-3"><span class="avatar-text avatar-lg rounded bg-soft-success text-success"><i class="feather-award"></i></span><div><div class="fw-semibold"><?= e($deal['title']) ?></div><div class="fs-12 text-muted"><?= e((string) ($deal['account_name'] ?? '')) ?></div></div></div>
        <div class="row g-3">
            <div class="col-md-6"><label class="form-label" for="deal-won-field-value">Value (<?= e($deal['currency']) ?>)</label><input type="number" step="any" min="0" name="value" class="form-control btn-touch" id="deal-won-field-value" value="<?= e((string) $deal['value']) ?>"<?= $deal['value_from_products'] ? ' readonly' : '' ?>>
                <?php if ($deal['value_from_products']): ?><div class="fs-11 text-muted">The products' total — change the lines to change it.</div><?php endif; ?></div>
            <div class="col-md-6"><label class="form-label" for="deal-won-field-close_date">Closed on</label><input type="date" name="close_date" max="<?= e($today) ?>" class="form-control btn-touch" id="deal-won-field-close_date" value="<?= e($today) ?>"></div>
            <?php foreach ($required as $r): $set = ($deal['custom_values'][$r['key']] ?? null) !== null && ($deal['custom_values'][$r['key']] ?? '') !== ''; ?>
                <div class="col-12 fs-12 <?= $set ? 'text-muted' : 'text-danger' ?>" id="deal-won-required-<?= e($r['key']) ?>"><?= e($r['label']) ?> <?= $set ? 'is set.' : 'is required to win — ' . hx_link('/deals/' . $id . '/edit', 'set it first') . '.' ?></div>
            <?php endforeach; ?>
        </div>
        <div class="d-flex gap-2 mt-3"><button type="submit" class="btn btn-success btn-touch flex-grow-1" id="deal-won-save-btn"><i class="feather-award me-1"></i>Mark it won</button><?= hx_link('/deals/' . $id, 'Cancel', 'btn btn-light btn-touch', 'id="deal-won-cancel-btn"') ?></div>
    </div></form>
</div>
