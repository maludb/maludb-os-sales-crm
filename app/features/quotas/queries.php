<?php
declare(strict_types=1);

/**
 * Quotas (screen admin-quotas; db/008 `quotas`): the amount a seller is expected to close in a month, which the forecast and the leaderboard read. A seller is a person who can own a deal — a Sales Rep, a Sales
 * Manager or the CRM admin; an agent has none. The grid is sellers down and the twelve months of a year across; a cell left empty is no quota.
 */

/** The grid of a year: ['year' => …, 'months' => ['2026-01', …], 'sellers' => [{member_id, display_name, cells: {'2026-01' => amount|null}}], 'currency' => …]. */
function quota_grid(PDO $pdo, int $year): array
{
    $months = [];
    for ($m = 1; $m <= 12; $m++) { $months[] = sprintf('%04d-%02d', $year, $m); }
    $sellers = $pdo->query("SELECT member_id, display_name FROM mcp_members WHERE member_kind = 'human' AND roles_resolved && ARRAY['user', 'manager', 'admin']::text[] ORDER BY display_name")->fetchAll();
    $st = $pdo->prepare('SELECT member_id, to_char(month, \'YYYY-MM\') AS ym, amount FROM quotas WHERE month >= CAST(:a AS date) AND month < CAST(:b AS date)');
    $st->execute(['a' => sprintf('%04d-01-01', $year), 'b' => sprintf('%04d-01-01', $year + 1)]);
    $have = [];
    foreach ($st->fetchAll() as $r) { $have[(int) $r['member_id']][$r['ym']] = $r['amount']; }
    return ['year' => $year, 'months' => $months, 'currency' => base_currency($pdo), 'sellers' => array_map(static function (array $s) use ($months, $have): array {
        $cells = [];
        foreach ($months as $m) { $cells[$m] = $have[(int) $s['member_id']][$m] ?? null; }
        return ['member_id' => (int) $s['member_id'], 'display_name' => $s['display_name'], 'cells' => $cells];
    }, $sellers)];
}

/** One quota: the amount or null. */
function quota_get(PDO $pdo, int $member, string $month): ?string
{
    $v = one_value($pdo, 'SELECT amount FROM quotas WHERE member_id = :m AND month = CAST(:d AS date)', ['m' => $member, 'd' => $month]);
    return $v === null ? null : (string) $v;
}
