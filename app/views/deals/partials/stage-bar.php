<?php /** The stage bar (#deal-stage-bar): the pipeline's open stages in order — the ones passed filled, the current one marked, each a POST of deal_move; a stage the deal
 * may not enter yet shows what it lacks (a flag and the fields under it). Won and Lost end it, as links to their forms. A closed deal's bar is read-only.
 * Data: d, summary, may */
$id = (int) $d['deal_id'];
$open = array_values(array_filter($summary['stages'], static fn ($s) => $s['kind'] === 'open'));
$curPos = null;
foreach ($open as $i => $s) { if ($s['stage_id'] === (int) $d['stage_id']) { $curPos = $i; } }
$closed = $d['status'] !== 'open';
$editable = $may['edit'] && !$closed; ?>
<nav class="deal-stage-bar mb-3" id="deal-stage-bar" aria-label="Stages">
    <?php foreach ($open as $i => $s): $sid = $s['stage_id']; $missing = $summary['missing'][$sid] ?? []; $state = $closed ? 'past' : ($i < (int) $curPos ? 'past' : ($i === $curPos ? 'current' : 'ahead'));
        $title = $s['name'] . ' · ' . $s['probability'] . '%' . ($missing !== [] && $state === 'ahead' ? ' — needs ' . implode(', ', array_map(static fn ($f) => stage_field_words(db(), $f), $missing)) : ''); ?>
        <form method="post" action="/deals/<?= $id ?>/move" hx-post="/deals/<?= $id ?>/move" hx-target="#flash" class="deal-stage deal-stage-<?= $state ?>" id="deal-stage-bar-<?= $sid ?>" style="--stage: <?= e((string) ($s['colour'] ?? '#6c757d')) ?>"><?= csrf_field() ?>
            <input type="hidden" name="deal" value="<?= $id ?>"><input type="hidden" name="to_stage" value="<?= $sid ?>"><input type="hidden" name="return_to" value="/deals/<?= $id ?>">
            <button type="submit" class="deal-stage-btn" title="<?= e($title) ?>"<?= $editable && $state !== 'current' ? '' : ' disabled' ?> aria-current="<?= $state === 'current' ? 'step' : 'false' ?>">
                <span class="text-truncate"><?= e($s['name']) ?></span><?php if ($missing !== [] && $state === 'ahead'): ?><i class="feather-flag ms-1" aria-label="needs fields"></i><?php endif; ?></button>
        </form>
    <?php endforeach; ?>
    <?php if ($closed): ?><span class="deal-stage deal-stage-closed deal-stage-<?= e($d['status']) ?>" id="deal-stage-bar-closed"><span class="deal-stage-btn"><?= $d['status'] === 'won' ? '<i class="feather-award me-1"></i>Won' : '<i class="feather-x-circle me-1"></i>Lost' ?></span></span>
    <?php elseif ($may['edit']): ?>
        <?= hx_link('/deals/' . $id . '/won', '<i class="feather-award me-1"></i>Won', 'deal-stage deal-stage-won-link', 'id="deal-stage-bar-won"') ?>
        <?= hx_link('/deals/' . $id . '/lost', '<i class="feather-x-circle me-1"></i>Lost', 'deal-stage deal-stage-lost-link', 'id="deal-stage-bar-lost"') ?>
    <?php endif; ?>
</nav>
<?php if (!$closed && $summary['next_stage'] !== null && $summary['missing_next'] !== []): ?>
    <div class="alert alert-warning py-2 fs-12" id="deal-missing-next"><i class="feather-flag me-1"></i><?= e($summary['next_stage']['name']) ?> needs: <?= e(implode(', ', array_map(static fn ($f) => stage_field_words(db(), $f), $summary['missing_next']))) ?>.
        <?php if ($may['edit']): ?><?= hx_link('/deals/' . $id . '/edit', 'Fill them in', 'alert-link ms-1', 'id="deal-missing-next-edit"') ?><?php endif; ?></div>
<?php endif; ?>
