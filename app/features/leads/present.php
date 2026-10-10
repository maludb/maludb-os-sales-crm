<?php
declare(strict_types=1);

/** The JSON shapes of leads — whitelists; a lead's message is shown on its own page only (never in a log). */
function present_lead_card(array $l): array
{
    $int = static fn ($v): ?int => $v === null ? null : (int) $v;
    return ['lead_id' => (int) $l['lead_id'], 'label' => $l['label'], 'company' => $l['company'], 'title' => $l['title'], 'email' => $l['email'], 'status' => $l['status'],
            'score' => $int($l['score']), 'source' => $l['source'], 'source_id' => $int($l['source_id']), 'owner_member_id' => $int($l['owner_member_id']), 'owner_name' => $l['owner_name'] ?? null,
            'last_touched_at' => json_ts($l['last_touched_at']), 'next_colour' => $l['next_colour'], 'restricted' => (bool) $l['restricted'], 'tags' => pg_text_array((string) $l['tags']),
            'created_at' => json_ts($l['created_at']), 'url' => '/leads/' . (int) $l['lead_id']];
}

function present_lead(array $l, array $tags, array $custom, array $duplicates): array
{
    $int = static fn ($v): ?int => $v === null ? null : (int) $v;
    return present_lead_card($l) + [
        'first_name' => $l['first_name'], 'last_name' => $l['last_name'], 'phone' => $l['phone'], 'website' => $l['website'], 'city' => $l['city'], 'region' => $l['region'], 'country' => $l['country'],
        'message' => $l['message'], 'campaign_id' => $int($l['campaign_id']), 'campaign' => $l['campaign_name'], 'utm' => json_decode((string) $l['utm'], true) ?: (object) [],
        'is_proposed' => (bool) $l['is_proposed'], 'unqualified_reason' => $l['unqualified_reason'], 'unqualified_note' => $l['unqualified_note'],
        'converted' => $l['converted_at'] === null ? null : ['at' => json_ts($l['converted_at']), 'account_id' => $int($l['converted_account_id']), 'account' => $l['converted_account_name'],
            'contact_id' => $int($l['converted_contact_id']), 'contact' => $l['converted_contact_name'], 'deal_id' => $int($l['converted_deal_id']), 'deal' => $l['converted_deal_title']],
        'restricted_to' => ['member_id' => $int($l['restricted_to_member_id']), 'member' => $l['restricted_member_name'], 'department_id' => $int($l['restricted_to_department_id']), 'department' => $l['restricted_department_name']],
        'custom_values' => json_decode((string) $l['custom_values'], true) ?: (object) [], 'custom' => $custom, 'tags' => array_column($tags, 'label'),
        'duplicates' => array_map(static fn (array $d): array => ['kind' => $d['kind'], 'id' => $d['id'], 'name' => $d['name'], 'detail' => $d['detail']], $duplicates),
    ];
}
