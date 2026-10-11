<?php
declare(strict_types=1);

/** The JSON shape of a list (find_lists / the list screens): whitelisted. */
function present_list(array $l, ?int $liveCount = null): array
{
    $int = static fn ($v): ?int => $v === null ? null : (int) $v;
    return ['list_id' => (int) $l['list_id'], 'name' => $l['name'], 'kind' => $l['kind'], 'record_kind' => $l['record_kind'], 'filters' => (object) filters_decode($l['filters']),
            'is_shared' => (bool) $l['is_shared'], 'owner_member_id' => $int($l['owner_member_id']), 'owner_name' => $l['owner_name'] ?? null, 'member_count' => (int) $l['member_count'],
            'counted_at' => json_ts($l['counted_at']), 'description' => $l['description'], 'created_at' => json_ts($l['created_at']), 'url' => '/lists/' . (int) $l['list_id']]
        + ($liveCount === null ? [] : ['live_count' => $liveCount]);
}

/** A member of a list as its kind's card data (the rows are find_*'s; the JSON gives the identifying columns). */
function present_list_member(string $kind, array $r): array
{
    return match ($kind) {
        'lead' => ['record_id' => (int) $r['lead_id'], 'name' => $r['label'], 'company' => $r['company'], 'email' => $r['email'], 'status' => $r['status'], 'owner_member_id' => $r['owner_member_id'] === null ? null : (int) $r['owner_member_id'], 'url' => '/leads/' . (int) $r['lead_id']],
        'contact' => ['record_id' => (int) $r['contact_id'], 'name' => $r['name'], 'email' => $r['email'], 'account_name' => $r['account_name'], 'lifecycle' => $r['lifecycle'], 'email_opt_out' => (bool) $r['email_opt_out'], 'owner_member_id' => $r['owner_member_id'] === null ? null : (int) $r['owner_member_id'], 'url' => '/contacts/' . (int) $r['contact_id']],
        'account' => ['record_id' => (int) $r['account_id'], 'name' => $r['name'], 'domain' => $r['domain'], 'kind' => $r['kind'], 'owner_member_id' => $r['owner_member_id'] === null ? null : (int) $r['owner_member_id'], 'url' => '/accounts/' . (int) $r['account_id']],
        'deal' => ['record_id' => (int) $r['deal_id'], 'name' => $r['title'], 'number' => $r['number'], 'account_name' => $r['account_name'], 'stage_name' => $r['stage_name'], 'value' => $r['value'] === null ? null : (float) $r['value'], 'currency' => $r['currency'], 'status' => $r['status'], 'owner_member_id' => $r['owner_member_id'] === null ? null : (int) $r['owner_member_id'], 'url' => '/deals/' . (int) $r['deal_id']],
    };
}
