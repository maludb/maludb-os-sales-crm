<?php
/** Helpers for the slice 4 proofs (activities-reminders.md, activities-calendar.md §8) — slice 3's helpers (deal_make, stage, post_as, get_as, pg) and a few of their own. */
require dirname(__DIR__) . '/slice3/lib.php';

/** An activity made through activity_create as a member: the answer (record_id under json). */
function act(int $m, array $f): array
{
    return post_as($m, '/activities/save.php', $f);
}
/** An activity's id made through activity_create (0 and the body on STDERR when refused). */
function act_id(int $m, array $f): int
{
    $r = act($m, $f);
    if (($r['json']['record_id'] ?? null) === null) { fwrite(STDERR, 'act failed: ' . $r['body'] . "\n"); }
    return (int) ($r['json']['record_id'] ?? 0);
}
/** A local time string for the form ('+1 day 10:00' → '2026-10-12T10:00') in UTC (the fixture's members are in UTC). */
function at(string $rel): string
{
    return (new DateTimeImmutable($rel, new DateTimeZone('UTC')))->format('Y-m-d\TH:i');
}
/** The calendar's JSON as a member (`data`). */
function cal(int $m, string $query): array
{
    return get_as($m, '/calendar?' . $query)['json']['data'] ?? [];
}
/** The activity ids on one date of a calendar's JSON. */
function on_day(array $cal, string $date): array
{
    foreach ($cal['days'] ?? [] as $d) { if ($d['date'] === $date) { return $d['activity_ids']; } }
    return [];
}
/** The calendar row of an activity in a calendar's JSON, or null. */
function cal_row(array $cal, int $id): ?array
{
    foreach ($cal['rows'] ?? [] as $r) { if ($r['activity_id'] === $id) { return $r; } }
    return null;
}
/** The worker's named passes with the clock set (CRM_WORKER_NOW): the JSON report. */
function worker_at(string $passes, ?string $now = null): array
{
    putenv('CRM_WORKER_NOW' . ($now === null ? '' : '=' . $now));
    $r = worker($passes);
    putenv('CRM_WORKER_NOW');
    return $r;
}
/** An activity's base row (as the application's role). */
function act_row(int $id): array
{
    return q('SELECT * FROM activities WHERE id = :id', ['id' => $id])[0] ?? [];
}
