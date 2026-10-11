<?php
declare(strict_types=1);

/**
 * quota_set (log quota.set: the seller, the month, the amount before and after): one quota for a seller and a month — an amount of 0 or more, or blank to remove it. The grid posts every cell and the handler applies the
 * ones that differ, one quota_set each.
 */
require_once __DIR__ . '/queries.php';

/** The first of the month a string names ('2026-11' or '2026-11-01' or any day of it), as a date string; null when it is no month. */
function quota_month(string $text): ?string
{
    if (!preg_match('/^(\d{4})-(\d{2})(?:-(\d{2}))?$/', trim($text), $m) || (int) $m[2] < 1 || (int) $m[2] > 12 || (int) $m[1] < 2000 || (int) $m[1] > 2100) {
        return null;
    }
    return sprintf('%04d-%02d-01', (int) $m[1], (int) $m[2]);
}

/** Set a quota ($amount null removes it). Answers ['before' => amount|null, 'after' => amount|null, 'changed' => bool]. */
function quota_set(PDO $pdo, int $member, string $month, ?string $amount): array
{
    $before = quota_get($pdo, $member, $month);
    if ($amount === null) {
        $pdo->prepare('DELETE FROM quotas WHERE member_id = :m AND month = CAST(:d AS date)')->execute(['m' => $member, 'd' => $month]);
        return ['before' => $before, 'after' => null, 'changed' => $before !== null];
    }
    $pdo->prepare('INSERT INTO quotas (member_id, month, amount) VALUES (:m, CAST(:d AS date), CAST(:a AS numeric)) ON CONFLICT (member_id, month) DO UPDATE SET amount = EXCLUDED.amount')
        ->execute(['m' => $member, 'd' => $month, 'a' => $amount]);
    $after = quota_get($pdo, $member, $month);
    return ['before' => $before, 'after' => $after, 'changed' => $before === null || (float) $before !== (float) $after];
}
