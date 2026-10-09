<?php
declare(strict_types=1);

/** The JSON shapes of contacts — whitelists, never a raw row. */
function present_contact_card(array $c): array
{
    $int = static fn ($v): ?int => $v === null ? null : (int) $v;
    return ['contact_id' => (int) $c['contact_id'], 'name' => $c['name'], 'title' => $c['title'], 'email' => $c['email'], 'phone' => $c['phone'], 'mobile' => $c['mobile'],
            'account_id' => $int($c['account_id']), 'account_name' => $c['account_name'], 'lifecycle' => $c['lifecycle'], 'role' => $c['role'], 'is_primary' => (bool) $c['is_primary'],
            'owner_member_id' => $int($c['owner_member_id']), 'owner_name' => $c['owner_name'] ?? null, 'last_touched_at' => json_ts($c['last_touched_at']), 'next_activity_at' => json_ts($c['next_activity_at']),
            'next_colour' => $c['next_colour'], 'email_opt_out' => (bool) $c['email_opt_out'], 'sms_opt_out' => (bool) $c['sms_opt_out'], 'do_not_call' => (bool) $c['do_not_call'],
            'open_deals_count' => (int) $c['open_deals_count'], 'restricted' => (bool) $c['restricted'], 'tags' => pg_text_array((string) $c['tags']), 'url' => '/contacts/' . (int) $c['contact_id']];
}

function present_contact(array $c, array $addresses, array $deals, ?array $next, array $tags, array $custom): array
{
    $int = static fn ($v): ?int => $v === null ? null : (int) $v;
    return present_contact_card($c) + [
        'first_name' => $c['first_name'], 'last_name' => $c['last_name'], 'department_text' => $c['department_text'], 'email_alt' => $c['email_alt'], 'linkedin_url' => $c['linkedin_url'],
        'address' => $c['address'], 'city' => $c['city'], 'region' => $c['region'], 'country' => $c['country'], 'timezone' => $c['timezone'], 'preferred_channel' => $c['preferred_channel'],
        'birthday' => $c['birthday'], 'source_id' => $int($c['source_id']), 'source' => $c['source_name'], 'email_opt_out_at' => json_ts($c['email_opt_out_at']), 'opt_out_reason' => $c['opt_out_reason'],
        'restricted_to' => ['member_id' => $int($c['restricted_to_member_id']), 'member' => $c['restricted_member_name'], 'department_id' => $int($c['restricted_to_department_id']), 'department' => $c['restricted_department_name']],
        'emails' => array_map(static fn (array $e): array => ['address' => $e['address'], 'is_primary' => (bool) $e['is_primary']], $addresses),
        'deals' => array_map(static fn (array $d): array => ['deal_id' => (int) $d['deal_id'], 'number' => $d['number'], 'title' => $d['title'], 'stage_name' => $d['stage_name'], 'status' => $d['status'], 'role' => $d['role']], $deals),
        'next_step' => $next === null ? null : ['activity_id' => (int) $next['activity_id'], 'kind' => $next['kind'], 'subject' => $next['subject'], 'at' => json_ts($next['at']), 'state' => $next['state']],
        'custom_values' => json_decode((string) $c['custom_values'], true) ?: (object) [], 'custom' => $custom, 'tags' => array_column($tags, 'label'),
        'created_at' => json_ts($c['created_at']), 'updated_at' => json_ts($c['updated_at']),
    ];
}
