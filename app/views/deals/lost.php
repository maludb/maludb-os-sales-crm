<?php /** The lost form (deal-lost): the reason (a deal's) and a note. Data: deal, reasons */
$id = (int) $deal['deal_id']; ?>
<?= view('shared/header.php', ['id' => 'deal-lost', 'title' => 'Lost: ' . $deal['title'], 'crumbs' => [['Today', '/'], ['Pipeline', pipeline_board_url()], [$deal['title'], '/deals/' . $id], ['Lost', null]], 'back' => ['/deals/' . $id, 'the deal']]) ?>
<div class="main-content" id="deal-lost-content">
    <form method="post" action="/deals/<?= $id ?>/lost" hx-post="/deals/<?= $id ?>/lost" hx-target="#flash" id="deal-lost-form" class="card"><div class="card-body"><?= csrf_field() ?><input type="hidden" name="deal" value="<?= $id ?>">
        <div class="d-flex align-items-center gap-2 mb-3"><span class="avatar-text avatar-lg rounded bg-soft-danger text-danger"><i class="feather-x-circle"></i></span><div><div class="fw-semibold"><?= e($deal['title']) ?></div><div class="fs-12 text-muted"><?= e(money($deal['value'], $deal['currency'])) ?> · <?= e((string) ($deal['account_name'] ?? '')) ?></div></div></div>
        <fieldset class="mb-3"><legend class="form-label fs-14">Why was it lost?</legend>
            <?php foreach ($reasons as $r): ?><label class="form-check btn-touch mb-1"><input type="radio" class="form-check-input" name="reason" value="<?= (int) $r['lost_reason_id'] ?>" id="deal-lost-reason-<?= (int) $r['lost_reason_id'] ?>" required> <?= e($r['label']) ?></label><?php endforeach; ?></fieldset>
        <label class="form-label" for="deal-lost-field-note">A word more (optional)</label><textarea name="note" maxlength="500" rows="2" class="form-control" id="deal-lost-field-note"></textarea>
        <div class="d-flex gap-2 mt-3"><button type="submit" class="btn btn-danger btn-touch flex-grow-1" id="deal-lost-save-btn">Mark it lost</button><?= hx_link('/deals/' . $id, 'Cancel', 'btn btn-light btn-touch', 'id="deal-lost-cancel-btn"') ?></div>
    </div></form>
</div>
