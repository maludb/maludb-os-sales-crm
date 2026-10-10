<?php /** One import (import-view). Data: i, rows, result, page, may (run, rollback), tz, notice */ $iid = (int) $i['import_id']; ?>
<?= view('shared/header.php', ['id' => 'import-view', 'title' => $i['file_name'], 'crumbs' => [['Today', '/'], ['Imports', '/import'], [$i['file_name'], null]], 'back' => ['/import', 'Imports']]) ?>
<div class="main-content" id="import-view-content">
    <?= view('shared/notice.php', ['notice' => $notice]) ?>
    <div class="card mb-3"><div class="card-body">
        <div class="fs-12 text-muted mb-2"><?= e(IMPORT_KINDS[$i['kind']] ?? $i['kind']) ?> · <?= (int) $i['row_count'] ?> rows · a match is <?= e(['skip' => 'skipped', 'update' => 'filled where empty', 'create' => 'ignored — always a new record'][$i['dedupe_policy']]) ?>
            <?= $i['owner_name'] ? ' · owner ' . e($i['owner_name']) : '' ?><?= $i['list_name'] ? ' · into the list ' . e($i['list_name']) : '' ?> · by <?= e((string) $i['created_by_name']) ?>, <?= e(format_ts($i['created_at'], $tz, 'M j, g:i A')) ?></div>
        <?= view('import/partials/status.php', ['i' => $i]) ?>
        <div class="d-flex flex-wrap gap-2 mt-3">
            <?php if ($may['run']): ?>
            <form method="post" action="/import/run.php" hx-post="/import/run.php" hx-target="#flash" hx-confirm="Run it for real? <?= (int) $i['rows_created'] ?> records will be made."><?= csrf_field() ?><input type="hidden" name="import" value="<?= $iid ?>">
                <button type="submit" class="btn btn-primary btn-touch" id="import-run-btn"><i class="feather-play me-1"></i>Run it for real</button></form>
            <?= hx_link('/import/new?kind=' . e($i['kind']), 'Start over', 'btn btn-light btn-touch', 'id="import-start-over-btn"') ?>
            <?php endif; ?>
            <?php if ($may['rollback']): ?>
            <form method="post" action="/import/rollback.php" hx-post="/import/rollback.php" hx-target="#flash" hx-confirm="Roll it back? The <?= (int) $i['rows_created'] ?> records it made go to the tombstone."><?= csrf_field() ?><input type="hidden" name="import" value="<?= $iid ?>">
                <button type="submit" class="btn btn-outline-danger btn-touch" id="import-rollback-btn"><i class="feather-rotate-ccw me-1"></i>Roll back</button></form>
            <span class="fs-12 text-muted align-self-center">until <?= e(format_ts($i['rollback_until'], $tz, 'M j, g:i A')) ?></span>
            <?php endif; ?>
        </div>
    </div></div>
    <div class="row g-3"><div class="col-lg-8"><?= view('import/partials/rows.php', ['i' => $i, 'rows' => $rows, 'result' => $result, 'page' => $page]) ?></div>
        <div class="col-lg-4"><?= view('import/partials/mapping.php', ['i' => $i]) ?></div></div>
</div>
