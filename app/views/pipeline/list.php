<?php /** The deal list (screen `deal-list`): the board's deals as a table grouped by stage or owner, its columns the saved view's; a header sorts.
 * Data: pipeline, groups, total, filters, columns, group, sort, pipelines, views, owners, teams, sources, may, notice, back, currency */
$tz = member_timezone();
$action = $may['create'] ? hx_link('/deals/new?pipeline=' . (int) $pipeline['pipeline_id'], '<i class="feather-plus me-1"></i>New deal', 'btn btn-primary btn-touch', 'id="deal-list-new-btn"') : '';
$sortable = ['title' => 'title', 'value' => 'value', 'weighted' => 'weighted', 'close' => 'close', 'next' => 'next', 'entered' => 'entered'];
$cell = static function (string $c, array $d) use ($tz): string {
    return match ($c) {
        'number' => e($d['number']),
        'title' => ($d['restricted'] ? '<i class="feather-lock me-1 text-warning"></i>' : '') . hx_link('/deals/' . (int) $d['deal_id'], e($d['title']), 'fw-semibold text-dark'),
        'account' => $d['account_id'] === null ? '<span class="text-muted">—</span>' : hx_link('/accounts/' . (int) $d['account_id'], e((string) $d['account_name'])),
        'contact' => $d['primary_contact_id'] === null ? '<span class="text-muted">—</span>' : hx_link('/contacts/' . (int) $d['primary_contact_id'], e((string) $d['primary_contact_name'])),
        'stage' => e($d['stage_name']) . ($d['status'] !== 'open' ? ' <span class="badge text-bg-' . DEAL_STATUS_BADGE[$d['status']] . '">' . DEAL_STATUSES[$d['status']] . '</span>' : ''),
        'value' => '<span class="text-nowrap">' . e(money($d['value'], $d['currency'])) . '</span>',
        'weighted' => '<span class="text-nowrap">' . e(money($d['weighted_value'], $d['currency'])) . '</span>',
        'probability' => (int) $d['probability'] . '%',
        'close' => $d['expected_close_date'] === null ? '<span class="text-muted">—</span>' : '<span class="text-nowrap' . ($d['status'] === 'open' && $d['expected_close_date'] < date('Y-m-d') ? ' text-danger' : '') . '">' . e(format_date($d['expected_close_date'])) . '</span>',
        'owner' => e((string) ($d['owner_name'] ?? 'Unowned')),
        'next' => '<span class="next-chip next-' . e((string) $d['next_colour']) . '">' . ($d['next_activity_at'] === null ? 'No next step' : e(format_ts($d['next_activity_at'], $tz, 'M j'))) . '</span>',
        'entered' => (int) $d['days_in_stage'] . ' d' . ($d['is_rotting'] ? ' <i class="feather-clock text-danger" title="Rotting"></i>' : ''),
        'tags' => e(implode(', ', $d['tags'])),
        default => '',
    };
}; ?>
<?= view('shared/header.php', ['id' => 'deal-list', 'title' => $pipeline['name'], 'crumbs' => [['Today', '/'], ['Pipeline', pipeline_url($pipeline, 'board')], ['List', null]], 'action' => $action]) ?>
<div class="main-content" id="deal-list">
    <?= view('shared/notice.php', ['notice' => $notice]) ?>
    <?= view('pipeline/partials/view-switch.php', ['pipeline' => $pipeline, 'pipelines' => $pipelines, 'page' => 'list', 'filters' => $filters]) ?>
    <?= view('pipeline/partials/filters.php', ['pipeline' => $pipeline, 'page' => 'list', 'filters' => $filters, 'owners' => $owners, 'teams' => $teams, 'sources' => $sources, 'views' => $views, 'may' => $may, 'columns' => $columns]) ?>
    <div class="d-flex flex-wrap gap-2 align-items-center mb-2 fs-12" id="deal-list-group">
        <span class="text-muted" id="deal-list-total"><?= (int) $total ?> deal<?= $total === 1 ? '' : 's' ?></span><span class="text-muted ms-2">Group by</span>
        <?php foreach (['stage' => 'Stage', 'owner' => 'Owner'] as $k => $label): ?><?= hx_link(pipeline_url($pipeline, 'list', $filters, ['group' => $k]), $label, 'btn btn-sm btn-touch ' . ($group === $k ? 'btn-primary' : 'btn-light'), 'id="deal-list-group-' . $k . '"') ?><?php endforeach; ?>
    </div>
    <?php if ($groups === []): ?>
        <div class="card"><div class="card-body text-center py-4 text-muted" id="deal-list-empty">No deal matches.</div></div>
    <?php endif; ?>
    <?php $bulkOn = !empty($bulk); ?>
    <?= $bulkOn ? bulk_bar(db(), 'deal', $filters + ['pipeline' => (int) $pipeline['pipeline_id']], (int) $total, null, $back) : '' ?>
    <?php foreach ($groups as $gi => $g): ?>
    <div class="card mb-3" id="deal-list-group-<?= $gi ?>"><div class="card-header d-flex flex-wrap gap-2 align-items-baseline"><span class="fw-semibold"><?= e($g['name']) ?></span><span class="badge bg-soft-secondary text-secondary"><?= count($g['rows']) ?></span>
        <span class="ms-auto fs-12 text-muted"><?= e(money($g['value'], $currency)) ?> · <?= e(money($g['weighted'], $currency)) ?> weighted</span></div>
        <div class="table-responsive"><table class="table table-hover mb-0 deal-list-table">
            <thead><tr><?php if ($bulkOn): ?><th style="width:2rem"></th><?php endif; ?><?php foreach ($columns as $c): ?><th class="fs-11 text-nowrap"><?= isset($sortable[$c]) ? hx_link(pipeline_url($pipeline, 'list', $filters, ['sort' => $sortable[$c]]), e(DEAL_LIST_COLUMNS[$c]) . ($sort === $sortable[$c] ? ' <i class="feather-chevron-down"></i>' : ''), 'text-muted', 'id="deal-list-sort-' . $c . '-' . $gi . '"') : e(DEAL_LIST_COLUMNS[$c]) ?></th><?php endforeach; ?></tr></thead>
            <tbody><?php foreach ($g['rows'] as $d): ?><tr id="deal-list-row-<?= (int) $d['deal_id'] ?>"><?php if ($bulkOn): ?><td><?= bulk_check('deal', (int) $d['deal_id'], true, (string) $d['title']) ?></td><?php endif; ?><?php foreach ($columns as $c): ?><td class="fs-12"><?= $cell($c, $d) ?></td><?php endforeach; ?></tr><?php endforeach; ?></tbody>
        </table></div></div>
    <?php endforeach; ?>
</div>
