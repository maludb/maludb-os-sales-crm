<?php
declare(strict_types=1);

/** The JSON shapes of activities (whitelisted — never a raw row): get_activity and the list's rows. */
require_once __DIR__ . '/queries.php';

/** One activity with its record and participants (get_activity). The body is the view's to withhold (a private one). */
function present_activity(array $a, array $participants = []): array
{
    $rec = activity_record($a);
    $int = static fn ($v): ?int => $v === null ? null : (int) $v;
    return [
        'activity_id' => (int) $a['activity_id'], 'kind' => $a['kind'], 'subject' => $a['subject'], 'body' => $a['body'], 'state' => $a['state'],
        'at' => json_ts($a['at']), 'due_at' => json_ts($a['due_at']), 'starts_at' => json_ts($a['starts_at']), 'ends_at' => json_ts($a['ends_at']), 'all_day' => (bool) $a['all_day'],
        'timezone' => $a['timezone'], 'done_at' => json_ts($a['done_at']), 'done_by' => $int($a['done_by']), 'outcome' => $a['outcome'], 'outcome_note' => $a['outcome_note'],
        'remind_minutes_before' => $int($a['remind_minutes_before']), 'remind_at' => json_ts($a['remind_at']), 'reminded_at' => json_ts($a['reminded_at']), 'snoozed_until' => json_ts($a['snoozed_until']),
        'location' => $a['location'], 'meeting_url' => $a['meeting_url'], 'priority' => $a['priority'], 'recurrence' => $a['recurrence'], 'source' => $a['source'], 'is_private' => (bool) $a['is_private'],
        'owner_member_id' => $int($a['owner_member_id']), 'owner_name' => $a['owner_name'] ?? null, 'created_by' => $int($a['created_by']),
        'deal_id' => $int($a['deal_id']), 'contact_id' => $int($a['contact_id']), 'account_id' => $int($a['account_id']), 'lead_id' => $int($a['lead_id']),
        'record' => $rec === null ? null : ['kind' => $rec[0], 'id' => $rec[1], 'name' => $rec[2]],
        'custom_values' => is_string($a['custom_values']) ? (json_decode($a['custom_values'], true) ?: (object) []) : $a['custom_values'],
        'participants' => array_map(static fn (array $p): array => ['contact_id' => $int($p['contact_id']), 'member_id' => $int($p['member_id']), 'name' => $p['name'], 'response' => $p['response']], $participants),
        'created_at' => json_ts($a['created_at']), 'updated_at' => json_ts($a['updated_at']),
    ];
}

/** A list row (find_activities). */
function present_activity_row(array $r): array
{
    $rec = activity_record($r);
    return ['activity_id' => (int) $r['activity_id'], 'kind' => $r['kind'], 'subject' => $r['subject'], 'at' => json_ts($r['at']), 'all_day' => (bool) $r['all_day'], 'state' => $r['state'],
        'done_at' => json_ts($r['done_at']), 'outcome' => $r['outcome'], 'priority' => $r['priority'], 'owner_member_id' => $r['owner_member_id'] === null ? null : (int) $r['owner_member_id'],
        'owner_name' => $r['owner_name'], 'record' => $rec === null ? null : ['kind' => $rec[0], 'id' => $rec[1], 'name' => $rec[2]],
        'deal_id' => $r['deal_id'] === null ? null : (int) $r['deal_id'], 'contact_id' => $r['contact_id'] === null ? null : (int) $r['contact_id'],
        'account_id' => $r['account_id'] === null ? null : (int) $r['account_id'], 'lead_id' => $r['lead_id'] === null ? null : (int) $r['lead_id']];
}
