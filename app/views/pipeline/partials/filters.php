<?php /** The filter bar of the board and the list (owner, team, close, source, value range, no next step, rotting, q, tag), the saved views as chips, Save this view.
 * Data: pipeline, page, filters, owners, teams, sources, views, may */
$f = $filters;
$action = '/pipeline/' . rawurlencode((string) $pipeline['key']) . '/' . $page;
$sel = static fn ($a, $b) => (string) $a === (string) $b ? ' selected' : ''; ?>
<form method="get" action="<?= e($action) ?>" class="card mb-2" id="board-filters" hx-get="<?= e($action) ?>" hx-target="#page-content" hx-push-url="true"><div class="card-body py-2">
    <div class="row g-2 align-items-end">
        <div class="col-12 col-md-3"><label class="visually-hidden" for="board-filter-q">Search</label><input type="search" name="q" id="board-filter-q" class="form-control btn-touch" placeholder="Deal, number, account…" value="<?= e($f['q']) ?>"></div>
        <div class="col-6 col-md-2"><label class="visually-hidden" for="board-filter-owner">Owner</label><select name="owner" id="board-filter-owner" class="form-select btn-touch">
            <option value="">Any owner</option><option value="me"<?= $sel($f['owner'], 'me') ?>>Mine</option><option value="none"<?= $sel($f['owner'], 'none') ?>>Unowned</option>
            <?php foreach ($owners as $o): ?><option value="<?= (int) $o['member_id'] ?>"<?= $sel($f['owner'], $o['member_id']) ?>><?= e($o['display_name']) ?></option><?php endforeach; ?></select></div>
        <div class="col-6 col-md-2"><label class="visually-hidden" for="board-filter-team">Team</label><select name="team" id="board-filter-team" class="form-select btn-touch">
            <option value="">Any team</option><?php foreach ($teams as $t): ?><option value="<?= (int) $t['department_id'] ?>"<?= $sel($f['team'], $t['department_id']) ?>><?= e($t['name']) ?></option><?php endforeach; ?></select></div>
        <div class="col-6 col-md-2"><label class="visually-hidden" for="board-filter-close">Close</label><select name="close" id="board-filter-close" class="form-select btn-touch">
            <option value="">Any close date</option><?php foreach (BOARD_CLOSE as $k => $label): ?><option value="<?= $k ?>"<?= $sel($f['close'], $k) ?>><?= e($label) ?></option><?php endforeach; ?></select></div>
        <div class="col-6 col-md-3 d-flex gap-2"><button type="submit" class="btn btn-light btn-touch flex-grow-1" id="board-filter-btn"><i class="feather-filter me-1"></i>Filter</button>
            <?php if (board_filtered($f) || $f['view'] !== null): ?><?= hx_link($action, 'Clear', 'btn btn-outline-secondary btn-touch', 'id="board-filter-clear"') ?><?php endif; ?></div>
    </div>
    <details class="mt-2" id="board-filters-more"<?= $f['source'] || $f['min_value'] || $f['max_value'] || $f['tag'] || $f['no_next_step'] || $f['rotting'] || ($page === 'list' && ($f['status'] ?? null)) ? ' open' : '' ?>>
        <summary class="fs-12 fw-semibold text-primary">More filters</summary>
        <div class="row g-2 mt-1">
            <div class="col-6 col-md-2"><label class="form-label fs-11 mb-0" for="board-filter-source">Source</label><select name="source" id="board-filter-source" class="form-select btn-touch">
                <option value="">Any</option><?php foreach ($sources as $s): ?><option value="<?= (int) $s['source_id'] ?>"<?= $sel($f['source'], $s['source_id']) ?>><?= e($s['name']) ?></option><?php endforeach; ?></select></div>
            <div class="col-6 col-md-2"><label class="form-label fs-11 mb-0" for="board-filter-tag">Tag</label><input type="text" name="tag" id="board-filter-tag" class="form-control btn-touch" maxlength="60" value="<?= e((string) $f['tag']) ?>"></div>
            <div class="col-6 col-md-2"><label class="form-label fs-11 mb-0" for="board-filter-min">Value from</label><input type="number" min="0" step="any" name="min_value" id="board-filter-min" class="form-control btn-touch" value="<?= e((string) $f['min_value']) ?>"></div>
            <div class="col-6 col-md-2"><label class="form-label fs-11 mb-0" for="board-filter-max">Value up to</label><input type="number" min="0" step="any" name="max_value" id="board-filter-max" class="form-control btn-touch" value="<?= e((string) $f['max_value']) ?>"></div>
            <?php if ($page === 'list'): ?><div class="col-6 col-md-2"><label class="form-label fs-11 mb-0" for="board-filter-status">Status</label><select name="status" id="board-filter-status" class="form-select btn-touch">
                <?php foreach (['' => 'Open', 'won' => 'Won', 'lost' => 'Lost', 'all' => 'All'] as $k => $label): ?><option value="<?= $k ?>"<?= $sel($f['status'] ?? '', $k) ?>><?= $label ?></option><?php endforeach; ?></select></div><?php endif; ?>
            <div class="col-12 col-md-2 d-flex flex-column justify-content-end gap-1">
                <label class="form-check mb-0 fs-12"><input type="checkbox" class="form-check-input" name="no_next_step" value="1" id="board-filter-no-next"<?= $f['no_next_step'] ? ' checked' : '' ?>> No next step</label>
                <label class="form-check mb-0 fs-12"><input type="checkbox" class="form-check-input" name="rotting" value="1" id="board-filter-rotting"<?= $f['rotting'] ? ' checked' : '' ?>> Rotting</label></div>
        </div>
    </details>
    <?php if ($f['view'] !== null): ?><input type="hidden" name="view" value="<?= (int) $f['view'] ?>"><?php endif; ?>
    <?php if ($page === 'list'): ?><input type="hidden" name="group" value="<?= e((string) ($f['group'] ?? '')) ?>"><input type="hidden" name="sort" value="<?= e((string) ($f['sort'] ?? '')) ?>"><?php endif; ?>
</div></form>
<div class="d-flex flex-wrap gap-1 align-items-center mb-3" id="board-saved-views">
    <?php foreach ($views as $v): ?><?= hx_link(pipeline_url($pipeline, $page, ['view' => $v['saved_view_id']]), ($v['scope'] === 'personal' ? '<i class="feather-user me-1"></i>' : '') . e($v['name']), 'btn btn-touch btn-sm ' . ($f['view'] === $v['saved_view_id'] ? 'btn-info' : 'btn-outline-info'), 'id="board-view-chip-' . $v['saved_view_id'] . '"') ?><?php endforeach; ?>
    <?= hx_link('/views', 'Manage views', 'btn btn-touch btn-sm btn-link', 'id="board-views-manage"') ?>
    <?php if ($may['save_view']): ?><details class="w-100" id="board-save-view"><summary class="fs-12 fw-semibold text-primary">Save this view</summary>
        <?= view('views/form.php', ['v' => null, 'kind' => 'deal', 'filters' => array_merge(array_filter(array_intersect_key($f, array_flip(['q', 'owner', 'team', 'close', 'tag', 'source', 'campaign', 'min_value', 'max_value', 'no_next_step', 'rotting', 'status'])), static fn ($x) => $x !== null && $x !== '' && $x !== false), ['pipeline' => $pipeline['pipeline_id']]),
            'layout' => $page, 'sort' => $f['sort'] ?? null, 'columns' => $columns ?? [], 'may_share' => $may['share_view'], 'return_to' => $action, 'prefix' => 'board-save-view']) ?></details><?php endif; ?>
</div>
