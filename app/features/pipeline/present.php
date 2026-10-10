<?php
declare(strict_types=1);

/** The board's JSON: each stage's head and its cards; the closed columns' month. */
function present_board(array $pipeline, array $board): array
{
    return ['pipeline' => ['pipeline_id' => $pipeline['pipeline_id'], 'key' => $pipeline['key'], 'name' => $pipeline['name']], 'currency' => $board['currency'],
        'stages' => array_map(static fn (array $c): array => ['stage_id' => $c['stage']['stage_id'], 'name' => $c['stage']['name'], 'probability' => $c['stage']['probability'], 'colour' => $c['stage']['colour'],
            'count' => $c['count'], 'value' => $c['value'], 'weighted' => $c['weighted'], 'other_currency' => $c['other_currency'], 'rotting' => $c['rotting'], 'no_next_step' => $c['no_next_step'],
            'wip_limit' => $c['stage']['wip_limit'], 'over_wip' => $c['over_wip'], 'deals' => array_map('present_deal_card', $c['deals'])], $board['stages']),
        'closed' => array_map(static fn (?array $c): ?array => $c === null ? null : ['stage_id' => $c['stage_id'], 'name' => $c['name'], 'count_this_month' => $c['count'], 'value_this_month' => $c['value']], $board['closed'])];
}

function present_forecast(array $grid): array
{
    return ['by' => $grid['by'], 'months' => $grid['months'], 'rows' => array_map(static fn (array $r): array => ['id' => $r['id'], 'name' => $r['name'], 'cells' => $r['cells'], 'quota' => $r['quota'], 'sum' => $r['sum']], $grid['rows']),
        'totals' => $grid['totals']];
}
