<?php
declare(strict_types=1);

/** The JSON shapes of accounts — whitelists, never a raw row. */
function present_account_card(array $a): array
{
    return ['account_id' => (int) $a['account_id'], 'name' => $a['name'], 'domain' => $a['domain'], 'kind' => $a['kind'], 'industry' => $a['industry'], 'city' => $a['city'],
            'country' => $a['country'], 'owner_member_id' => $a['owner_member_id'] !== null ? (int) $a['owner_member_id'] : null, 'owner_name' => $a['owner_name'],
            'last_touched_at' => json_ts($a['last_touched_at']), 'next_activity_at' => json_ts($a['next_activity_at']), 'next_colour' => $a['next_colour'],
            'open_deals_count' => (int) $a['open_deals_count'], 'open_deals_value' => (float) $a['open_deals_value'], 'contacts_count' => (int) $a['contacts_count'],
            'restricted' => (bool) $a['restricted'], 'tags' => pg_text_array((string) $a['tags']), 'url' => '/accounts/' . (int) $a['account_id']];
}

function present_account(array $a, array $summary, array $tags, array $custom): array
{
    $int = static fn ($v): ?int => $v === null ? null : (int) $v;
    return present_account_card($a + ['owner_name' => $a['owner_name'] ?? null]) + [
        'legal_name' => $a['legal_name'], 'website' => $a['website'], 'employees' => $a['employees'], 'annual_revenue' => $a['annual_revenue'] !== null ? (float) $a['annual_revenue'] : null,
        'currency' => $a['currency'], 'phone' => $a['phone'], 'email' => $a['email'], 'billing_address' => $a['billing_address'], 'shipping_address' => $a['shipping_address'],
        'region' => $a['region'], 'parent_account_id' => $int($a['parent_account_id']), 'parent_name' => $a['parent_name'], 'source_id' => $int($a['source_id']), 'source' => $a['source_name'],
        'description' => $a['description'], 'won_deals_value' => (float) $a['won_deals_value'], 'gl_customer_id' => $int($a['gl_customer_id']),
        'restricted_to' => ['member_id' => $int($a['restricted_to_member_id']), 'member' => $a['restricted_member_name'], 'department_id' => $int($a['restricted_to_department_id']), 'department' => $a['restricted_department_name']],
        'custom_values' => json_decode((string) $a['custom_values'], true) ?: (object) [], 'custom' => $custom, 'tags' => array_column($tags, 'label'),
        'primary_contact' => $summary['primary'] === null ? null : ['contact_id' => (int) $summary['primary']['contact_id'], 'name' => $summary['primary']['name']],
        'counts' => ['contacts' => $summary['contacts']] + $summary['deals'],
        'next_step' => $summary['next'] === null ? null : ['activity_id' => (int) $summary['next']['activity_id'], 'kind' => $summary['next']['kind'], 'subject' => $summary['next']['subject'], 'at' => json_ts($summary['next']['at']), 'state' => $summary['next']['state']],
        'created_at' => json_ts($a['created_at']), 'updated_at' => json_ts($a['updated_at']),
    ];
}
