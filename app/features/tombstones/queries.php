<?php
declare(strict_types=1);

/**
 * What waits in the tombstones (screen admin-purge; Phase 4's `tombstones`): a record deleted is hidden at once and kept for `tombstone_days`, then the worker purges it (crm_tombstones_purge_kinds, db/024).
 * Each row carries its `purges_at` and whether it is due. The leads, accounts, contacts and deals come through mcp_tombstones (a manager's view); an activity — which that view leaves out — is read
 * from its table, name only, on this screen alone (records.purge).
 */
const TOMBSTONE_KINDS = ['lead' => 'Leads', 'account' => 'Accounts', 'contact' => 'Contacts', 'deal' => 'Deals', 'activity' => 'Activities'];

/** The counts by kind: [kind => [waiting, due]] and the total due — due = past the window, so the next purge takes it. */
function tombstone_counts(PDO $pdo): array
{
    $days = (int) one_value($pdo, 'SELECT tombstone_days FROM crm_settings WHERE id = 1');
    $out = ['days' => $days, 'kinds' => [], 'due' => 0, 'waiting' => 0, 'next_purge_at' => null];
    foreach (['lead' => 'leads', 'account' => 'accounts', 'contact' => 'contacts', 'deal' => 'deals', 'activity' => 'activities'] as $kind => $table) {
        $r = one_row($pdo, "SELECT count(*) FILTER (WHERE deleted_at < now() - make_interval(days => :d)) AS due, count(*) FILTER (WHERE deleted_at >= now() - make_interval(days => :d)) AS waiting,
                                   min(deleted_at) FILTER (WHERE deleted_at >= now() - make_interval(days => :d)) AS next_at FROM $table WHERE deleted_at IS NOT NULL", ['d' => $days]);
        $out['kinds'][$kind] = ['due' => (int) $r['due'], 'waiting' => (int) $r['waiting']];
        $out['due'] += (int) $r['due'];
        $out['waiting'] += (int) $r['waiting'];
        if ($r['next_at'] !== null && ($out['next_purge_at'] === null || $r['next_at'] < $out['next_purge_at'])) {
            $out['next_purge_at'] = $r['next_at'];
        }
    }
    if ($out['next_purge_at'] !== null) {
        $out['next_purge_at'] = (string) one_value($pdo, 'SELECT CAST(CAST(:t AS timestamptz) + make_interval(days => :d) AS text)', ['t' => $out['next_purge_at'], 'd' => $days]);
    }
    return $out;
}

/** The tombstones, newest first, up to $limit: [{record_kind, record_id, name, deleted_at, deleted_by, deleted_by_name, merged_into_id, purges_at, due}]. */
function tombstones(PDO $pdo, ?string $kind = null, int $limit = 200): array
{
    $kind = $kind !== null && isset(TOMBSTONE_KINDS[$kind]) ? $kind : null;
    $st = $pdo->prepare("SELECT t.record_kind, t.record_id, t.name, t.deleted_at, t.deleted_by, m.display_name AS deleted_by_name, t.merged_into_id,
                                t.deleted_at + make_interval(days => (SELECT tombstone_days FROM crm_settings WHERE id = 1)) AS purges_at,
                                t.deleted_at < now() - make_interval(days => (SELECT tombstone_days FROM crm_settings WHERE id = 1)) AS due
                           FROM (SELECT record_kind, record_id, name, deleted_at, deleted_by, merged_into_id FROM mcp_tombstones
                                 UNION ALL SELECT 'activity', a.id, a.subject, a.deleted_at, a.deleted_by, NULL FROM activities a WHERE a.deleted_at IS NOT NULL) t
                           LEFT JOIN members m ON m.id = t.deleted_by
                          WHERE CAST(:k AS text) IS NULL OR t.record_kind = CAST(:k AS text) ORDER BY t.deleted_at DESC, t.record_kind, t.record_id LIMIT :l");
    $st->bindValue('k', $kind);
    $st->bindValue('l', max(1, min(1000, $limit)), PDO::PARAM_INT);
    $st->execute();
    return array_map(static fn (array $r): array => ['record_id' => (int) $r['record_id'], 'due' => (bool) $r['due']] + $r, $st->fetchAll());
}

/** The whitelist of a tombstone for JSON. */
function present_tombstone(array $t): array
{
    return ['record_kind' => $t['record_kind'], 'record_id' => $t['record_id'], 'name' => $t['name'], 'deleted_at' => json_ts($t['deleted_at']), 'deleted_by' => $t['deleted_by'] === null ? null : (int) $t['deleted_by'],
        'deleted_by_name' => $t['deleted_by_name'], 'merged_into_id' => $t['merged_into_id'] === null ? null : (int) $t['merged_into_id'], 'purges_at' => json_ts($t['purges_at']), 'due' => $t['due']];
}
