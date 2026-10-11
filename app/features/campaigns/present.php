<?php
declare(strict_types=1);

function present_campaign(array $c, ?array $results = null): array
{
    $int = static fn ($v): ?int => $v === null ? null : (int) $v;
    $num = static fn ($v): ?float => $v === null ? null : (float) $v;
    $out = ['campaign_id' => (int) $c['campaign_id'], 'name' => $c['name'], 'kind' => $c['kind'], 'status' => $c['status'], 'starts_on' => $c['starts_on'], 'ends_on' => $c['ends_on'], 'budget' => $num($c['budget']),
            'currency' => $c['currency'], 'expected_leads' => $int($c['expected_leads']), 'utm_campaign' => $c['utm_campaign'], 'owner_member_id' => $int($c['owner_member_id']), 'owner_name' => $c['owner_name'] ?? null,
            'description' => $c['description'], 'created_at' => json_ts($c['created_at']), 'url' => '/campaigns/' . (int) $c['campaign_id']];
    $res = $results ?? (array_key_exists('members', $c) ? $c : null);
    if ($res !== null) {
        $out['results'] = ['members' => (int) $res['members'], 'responded' => (int) $res['responded'], 'leads' => (int) $res['leads'], 'converted' => (int) $res['converted'], 'deals' => (int) $res['deals'], 'won' => (int) $res['won'],
            'won_value' => $num($res['won_value']), 'open_value' => $num($res['open_value']), 'cost_per_lead' => $num($res['cost_per_lead']), 'cost_per_won' => $num($res['cost_per_won'])];
    }
    return $out;
}

function present_campaign_member(array $m): array
{
    return ['campaign_member_id' => (int) $m['campaign_member_id'], 'lead_id' => $m['lead_id'] === null ? null : (int) $m['lead_id'], 'contact_id' => $m['contact_id'] === null ? null : (int) $m['contact_id'],
            'name' => (bool) $m['hidden'] ? null : $m['name'], 'restricted' => (bool) $m['hidden'], 'status' => $m['status'], 'added_at' => json_ts($m['added_at']), 'responded_at' => json_ts($m['responded_at']),
            'email_allowed' => $m['email_allowed'] === null ? null : (bool) $m['email_allowed']];
}
