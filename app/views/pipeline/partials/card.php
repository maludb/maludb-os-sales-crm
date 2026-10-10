<?php /** A deal card (pipeline-board.md §1): title, account, value, the owner's initials, the next step in the four colours, the rotting mark, up to three tags (not at
 * 375), the missing-fields flag; the menu (a <details>, so it works without JavaScript): Move to… (the no-JS move), Won, Lost, Log call, Schedule, Open.
 * Data: d, stages (the pipeline's open stages), pipeline, query (the board's filters), back */
$id = (int) $d['deal_id'];
$nc = (string) ($d['next_colour'] ?? 'none');
$nextWords = ['overdue' => 'Overdue', 'today' => 'Today', 'scheduled' => '', 'none' => 'No next step'];
$nextText = $d['next_activity_at'] === null ? 'No next step' : trim(($nextWords[$nc] ?? '') . ' ' . format_ts($d['next_activity_at'], member_timezone(), $nc === 'today' ? 'g:i A' : 'M j'));
$missing = $d['missing_next'] ?? [];
$tz = member_timezone(); ?>
<article class="board-card card mb-2" id="deal-card-<?= $id ?>" data-deal-id="<?= $id ?>" data-position="<?= (int) $d['position'] ?>"><div class="card-body p-2">
    <div class="d-flex align-items-start gap-1">
        <div class="min-w-0 flex-grow-1">
            <div class="fw-semibold fs-13 text-truncate"><?php if ($d['restricted']): ?><i class="feather-lock me-1 text-warning" title="Restricted"></i><?php endif; ?><?= hx_link(with_back('/deals/' . $id, $back), e($d['title']), 'text-dark', 'id="deal-card-' . $id . '-link"') ?></div>
            <div class="fs-11 text-muted text-truncate" id="deal-card-<?= $id ?>-account"><?= e((string) ($d['account_name'] ?? 'No account')) ?></div>
        </div>
        <details class="board-card-menu" id="deal-card-<?= $id ?>-menu"><summary class="btn btn-sm btn-light px-1 py-0" aria-label="Deal menu"><i class="feather-more-vertical"></i></summary>
            <div class="board-card-menu-body card shadow-sm p-2">
                <form method="post" action="/deals/<?= $id ?>/move" hx-post="/deals/<?= $id ?>/move" hx-target="#board-columns" hx-swap="outerHTML" class="mb-2" id="deal-card-<?= $id ?>-move-form"><?= csrf_field() ?>
                    <input type="hidden" name="deal" value="<?= $id ?>"><input type="hidden" name="on" value="board"><input type="hidden" name="board_query" value="<?= e($query) ?>"><input type="hidden" name="return_to" value="<?= e($back) ?>">
                    <label class="fs-11 text-muted" for="deal-card-<?= $id ?>-move">Move to</label>
                    <div class="d-flex gap-1"><select name="to_stage" class="form-select form-select-sm" id="deal-card-<?= $id ?>-move">
                        <?php foreach ($stages as $s): ?><option value="<?= (int) $s['stage_id'] ?>"<?= (int) $s['stage_id'] === (int) $d['stage_id'] ? ' selected' : '' ?>><?= e($s['name']) ?></option><?php endforeach; ?></select>
                        <button type="submit" class="btn btn-sm btn-primary" id="deal-card-<?= $id ?>-move-btn">Move</button></div></form>
                <div class="d-flex flex-column fs-12">
                    <?= hx_link('/deals/' . $id . '/won', '<i class="feather-award me-1 text-success"></i>Won', 'py-1', 'id="deal-card-' . $id . '-won"') ?>
                    <?= hx_link('/deals/' . $id . '/lost', '<i class="feather-x-circle me-1 text-danger"></i>Lost', 'py-1', 'id="deal-card-' . $id . '-lost"') ?>
                    <?php if (nav_built('/log/call.php')): ?><?= hx_link('/log/call?deal=' . $id, '<i class="feather-phone me-1"></i>Log call', 'py-1', 'id="deal-card-' . $id . '-log"') ?><?php endif; ?>
                    <?php if (nav_built('/activities/form.php')): ?><?= hx_link('/activities/new?deal=' . $id, '<i class="feather-clock me-1"></i>Schedule', 'py-1', 'id="deal-card-' . $id . '-schedule"') ?><?php endif; ?>
                    <?= hx_link(with_back('/deals/' . $id, $back), '<i class="feather-external-link me-1"></i>Open', 'py-1', 'id="deal-card-' . $id . '-open"') ?>
                </div>
            </div></details>
    </div>
    <div class="d-flex align-items-center gap-1 mt-1">
        <span class="fw-semibold fs-12" id="deal-card-<?= $id ?>-value"><?= e(money_short($d['value'], $d['currency'])) ?></span>
        <?php if ($d['is_rotting']): ?><i class="feather-clock text-danger fs-11" id="deal-card-<?= $id ?>-rotting" title="<?= (int) $d['days_in_stage'] ?> days in stage"></i><?php endif; ?>
        <?php if ($missing !== []): ?><i class="feather-flag text-warning fs-11" id="deal-card-<?= $id ?>-missing" title="The next stage needs: <?= e(implode(', ', array_map(static fn ($f) => stage_field_words(db(), $f), $missing))) ?>"></i><?php endif; ?>
        <span class="ms-auto avatar-text avatar-sm rounded-circle board-avatar" title="<?= e((string) ($d['owner_name'] ?? 'Unowned')) ?>"><?= e($d['owner_name'] === null ? '–' : initials($d['owner_name'])) ?></span>
    </div>
    <div class="d-flex flex-wrap gap-1 mt-1 align-items-center">
        <span class="next-chip next-<?= e($nc) ?>" id="deal-card-<?= $id ?>-next"><?php if ($d['next_kind']): ?><i class="<?= e(activity_kind_icon((string) $d['next_kind'])) ?>"></i><?php endif; ?><?= e($nextText) ?></span>
        <?php foreach (array_slice($d['tags'], 0, 3) as $t): ?><span class="badge bg-soft-secondary text-secondary board-card-tag"><?= e($t) ?></span><?php endforeach; ?>
    </div>
</div></article>
