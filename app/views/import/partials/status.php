<?php /** The import's state, polled every 3 s while it is queued or running (/import/{id}/status). Data: i */
$busy = in_array($i['status'], ['queued', 'running'], true); $iid = (int) $i['import_id']; ?>
<div id="import-status" class="d-flex flex-wrap align-items-center gap-2"<?= $busy ? ' hx-get="/import/' . $iid . '/status?was=busy" hx-trigger="every 3s" hx-swap="outerHTML"' : '' ?>>
    <span class="badge bg-soft-<?= e(IMPORT_STATUS_BADGE[$i['status']] ?? 'secondary') ?> text-<?= e(IMPORT_STATUS_BADGE[$i['status']] ?? 'secondary') ?> fs-12" id="import-status-badge"><?= $busy ? '<i class="feather-loader me-1"></i>' : '' ?><?= e(import_state_words($i)) ?></span>
    <?php foreach (['created' => ['rows_created', 'success'], 'updated' => ['rows_updated', 'info'], 'skipped' => ['rows_skipped', 'secondary'], 'refused' => ['rows_refused', 'danger']] as $k => [$col, $c]): ?>
        <span class="badge bg-soft-<?= $c ?> text-<?= $c ?> fs-12" id="import-count-<?= $k ?>"><?= (int) $i[$col] ?> <?= (bool) $i['dry_run'] ? 'would be ' : '' ?><?= $k ?></span>
    <?php endforeach; ?>
    <?php if ($i['status'] === 'failed' && $i['log']): ?><div class="alert alert-danger fs-12 w-100 mb-0" id="import-status-log"><?= e($i['log']) ?></div><?php endif; ?>
</div>
