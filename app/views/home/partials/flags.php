<?php /** My open deals with no next step, and my rotting ones — each a link to the board filtered (design §6.2). Data: s */ $f = $s['flags']; ?>
<div class="card mb-3" id="home-flags"><div class="list-group list-group-flush">
    <a class="list-group-item list-group-item-action d-flex align-items-center justify-content-between today-row" id="home-flag-no-next-step"
       href="<?= e($s['board'] . '?owner=me&flag=no_next_step') ?>" hx-get="<?= e($s['board'] . '?owner=me&flag=no_next_step') ?>" hx-target="#page-content" hx-swap="innerHTML" hx-push-url="<?= e($s['board'] . '?owner=me&flag=no_next_step') ?>">
        <span><i class="feather-alert-circle me-2 text-<?= $f['no_next_step'] > 0 ? 'danger' : 'muted' ?>"></i>Deals with no next step</span>
        <span class="badge <?= $f['no_next_step'] > 0 ? 'text-bg-danger' : 'bg-soft-secondary text-secondary' ?>"><?= (int) $f['no_next_step'] ?></span>
    </a>
    <a class="list-group-item list-group-item-action d-flex align-items-center justify-content-between today-row" id="home-flag-rotting"
       href="<?= e($s['board'] . '?owner=me&flag=rotting') ?>" hx-get="<?= e($s['board'] . '?owner=me&flag=rotting') ?>" hx-target="#page-content" hx-swap="innerHTML" hx-push-url="<?= e($s['board'] . '?owner=me&flag=rotting') ?>">
        <span><i class="feather-thermometer me-2 text-<?= $f['rotting'] > 0 ? 'warning' : 'muted' ?>"></i>Deals sitting too long in a stage</span>
        <span class="badge <?= $f['rotting'] > 0 ? 'text-bg-warning' : 'bg-soft-secondary text-secondary' ?>"><?= (int) $f['rotting'] ?></span>
    </a>
</div></div>
