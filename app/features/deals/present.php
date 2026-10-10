<?php
declare(strict_types=1);

/** Whitelist presenters (the JSON shapes of get_deal, a board card, a list row). Never a raw row. */
function present_deal_card(array $d): array
{
    return ['deal_id' => (int) $d['deal_id'], 'number' => $d['number'], 'title' => $d['title'], 'account_id' => $d['account_id'] === null ? null : (int) $d['account_id'], 'account_name' => $d['account_name'],
        'stage_id' => (int) $d['stage_id'], 'stage_name' => $d['stage_name'], 'position' => (int) $d['position'], 'value' => (float) $d['value'], 'currency' => $d['currency'],
        'weighted_value' => (float) $d['weighted_value'], 'probability' => (int) $d['probability'], 'expected_close_date' => $d['expected_close_date'], 'status' => $d['status'],
        'owner_member_id' => $d['owner_member_id'] === null ? null : (int) $d['owner_member_id'], 'owner_name' => $d['owner_name'] ?? null,
        'next_activity_at' => json_ts($d['next_activity_at']), 'next_colour' => $d['next_colour'], 'days_in_stage' => isset($d['days_in_stage']) ? (int) $d['days_in_stage'] : null,
        'is_rotting' => (bool) ($d['is_rotting'] ?? false), 'missing_next' => $d['missing_next'] ?? [], 'tags' => $d['tags'] ?? [], 'restricted' => (bool) ($d['restricted'] ?? false)];
}

function present_deal(array $d, array $summary, array $custom): array
{
    return present_deal_card($d + ['missing_next' => $summary['missing_next']]) + [
        'pipeline_id' => (int) $d['pipeline_id'], 'pipeline_name' => $d['pipeline_name'], 'primary_contact_id' => $d['primary_contact_id'] === null ? null : (int) $d['primary_contact_id'],
        'primary_contact_name' => $d['primary_contact_name'], 'value_from_products' => (bool) $d['value_from_products'], 'probability_override' => $d['probability_override'] === null ? null : (int) $d['probability_override'],
        'won_at' => json_ts($d['won_at']), 'lost_at' => json_ts($d['lost_at']), 'lost_reason' => $d['lost_reason'], 'lost_note' => $d['lost_note'], 'source' => $d['source_name'], 'campaign' => $d['campaign_name'],
        'lead_id' => $d['lead_id'] === null ? null : (int) $d['lead_id'], 'description' => $d['description'], 'stage_entered_at' => json_ts($d['stage_entered_at']), 'last_touched_at' => json_ts($d['last_touched_at']),
        'custom' => $custom, 'created_at' => json_ts($d['created_at']),
        'contacts' => array_map(static fn (array $c): array => ['contact_id' => (int) $c['contact_id'], 'name' => $c['contact_name'], 'role' => $c['role'], 'is_primary' => (bool) $c['is_primary'], 'title' => $c['title'], 'email' => $c['email']], $summary['contacts']),
        'products' => array_map('present_deal_product', $summary['products']), 'products_total' => $summary['products_total'],
        'next_step' => $summary['next_step'] === null ? null : ['activity_id' => (int) $summary['next_step']['activity_id'], 'kind' => $summary['next_step']['kind'], 'subject' => $summary['next_step']['subject'], 'at' => json_ts($summary['next_step']['at'])],
        'next_stage' => $summary['next_stage'] === null ? null : ['stage_id' => $summary['next_stage']['stage_id'], 'name' => $summary['next_stage']['name']],
        'stages' => array_map(static fn (array $s): array => ['stage_id' => $s['stage_id'], 'name' => $s['name'], 'kind' => $s['kind'], 'probability' => $s['probability'], 'missing' => $summary['missing'][$s['stage_id']] ?? []], $summary['stages']),
        'history' => array_map(static fn (array $h): array => ['stage_id' => $h['stage_id'], 'stage' => $h['stage'], 'entered_at' => json_ts($h['entered_at']), 'seconds' => $h['seconds'], 'current' => $h['current']], $summary['strip']),
    ];
}

function present_deal_product(array $p): array
{
    return ['deal_product_id' => (int) $p['deal_product_id'], 'position' => (int) $p['position'], 'name' => $p['name'], 'provider_app' => $p['provider_app'], 'provider_id' => $p['provider_id'], 'sku' => $p['sku'],
        'quantity' => (float) $p['quantity'], 'unit_price' => (float) $p['unit_price'], 'discount_percent' => (float) $p['discount_percent'], 'line_total' => (float) $p['line_total'], 'recurring' => $p['recurring']];
}
