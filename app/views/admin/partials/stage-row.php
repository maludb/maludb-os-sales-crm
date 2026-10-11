<?php /** One stage of a pipeline (admin-pipelines; id stage-row-{id}). Data: s (a stages row of pipelines_with_stages), p (its pipeline), order (the ids of the open live stages in order, for Move up/down), i (this stage's place in it, or null) */
$id = (int) $s['id'];
$archived = $s['archived_at'] !== null;
$open = $s['kind'] === 'open';
$sortable = $open && !$archived;
$colour = $s['colour'] ?: '#6c757d';
$move = static fn (int $to): string => implode(',', (static function (array $o, int $i, int $j): array { [$o[$i], $o[$j]] = [$o[$j], $o[$i]]; return $o; })($order, $i, $to));
$words = static fn (string $f): string => STAGE_REQUIRED_COLUMNS[$f] ?? str_replace('_', ' ', $f); ?>
<li class="list-group-item d-flex align-items-start gap-2 stage-row<?= $archived ? ' text-muted' : '' ?>" id="stage-row-<?= $id ?>" data-stage-id="<?= $id ?>">
    <?php if ($sortable): ?><span class="stage-handle btn-touch d-flex align-items-center flex-shrink-0 text-muted" id="stage-<?= $id ?>-handle" title="Drag to reorder" aria-hidden="true"><i class="feather-menu"></i></span>
    <?php else: ?><span class="stage-handle-none flex-shrink-0" aria-hidden="true"></span><?php endif; ?>
    <span class="wd-10 ht-10 mt-2 d-inline-block rounded-circle flex-shrink-0" style="background:<?= e($colour) ?>"></span>
    <div class="min-w-0 flex-grow-1 fs-12">
        <div class="fw-semibold"><?= hx_link('/admin/stages/' . $id . '/edit', e($s['name']), 'text-reset', 'id="stage-' . $id . '-edit-link"') ?>
            <?php if (!$open): ?><span class="badge bg-soft-<?= e(STAGE_KIND_TONE[$s['kind']]) ?> text-<?= e(STAGE_KIND_TONE[$s['kind']]) ?> ms-1" id="stage-<?= $id ?>-kind"><?= e(STAGE_KINDS[$s['kind']]) ?></span><?php endif; ?>
            <?php if ($archived): ?><span class="badge bg-soft-dark text-dark ms-1" id="stage-<?= $id ?>-archived">archived</span><?php endif; ?>
            <?php if ($s['ask_assistant']): ?><span class="badge bg-soft-info text-info ms-1" id="stage-<?= $id ?>-assistant" title="The Sales Assistant is asked for a next step when a deal enters"><i class="feather-cpu me-1"></i>Assistant</span><?php endif; ?></div>
        <div class="text-muted"><?= (int) $s['probability'] ?>% likely
            <?= $s['rotting_days'] !== null ? ' · rots after ' . (int) $s['rotting_days'] . ' days' : '' ?>
            <?= $s['wip_limit'] !== null ? ' · WIP ' . (int) $s['wip_limit'] : '' ?>
            · <span id="stage-<?= $id ?>-deals"><?= (int) $s['deals_open'] ?> open deal<?= (int) $s['deals_open'] === 1 ? '' : 's' ?></span></div>
        <?php if ($s['required_fields'] !== []): ?><div class="d-flex flex-wrap gap-1 mt-1" id="stage-<?= $id ?>-required"><span class="text-muted">needs</span>
            <?php foreach ($s['required_fields'] as $f): ?><span class="badge bg-soft-secondary text-secondary"><?= e($words($f)) ?></span><?php endforeach; ?></div><?php endif; ?>
    </div>
    <div class="d-flex gap-1 flex-shrink-0">
        <?php if ($sortable && $i !== null): ?>
            <?php foreach ([['up', -1, 'feather-arrow-up', 'Move up'], ['down', 1, 'feather-arrow-down', 'Move down']] as [$dir, $d, $icon, $title]): $to = $i + $d; if ($to < 0 || $to >= count($order)) { continue; } ?>
            <form method="post" action="/admin/stages/reorder.php" hx-post="/admin/stages/reorder.php" hx-target="#stage-list-<?= (int) $s['pipeline_id'] ?>" hx-swap="outerHTML" class="d-inline"><?= csrf_field() ?>
                <input type="hidden" name="pipeline" value="<?= (int) $s['pipeline_id'] ?>"><input type="hidden" name="stages" value="<?= e($move($to)) ?>"><input type="hidden" name="on" value="list">
                <button type="submit" class="avatar-text avatar-sm border-0" id="stage-<?= $id ?>-<?= $dir ?>-btn" title="<?= e($title) ?>" aria-label="<?= e($title . ' ' . $s['name']) ?>"><i class="<?= $icon ?>"></i></button></form>
            <?php endforeach; ?>
        <?php endif; ?>
        <?= hx_link('/admin/stages/' . $id . '/edit', '<i class="feather-edit-2"></i>', 'avatar-text avatar-sm', 'id="stage-' . $id . '-edit-btn" title="Edit" aria-label="Edit ' . e($s['name']) . '"') ?>
    </div>
</li>
