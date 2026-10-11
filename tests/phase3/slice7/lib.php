<?php
/**
 * Helpers for the slice 7 proofs (reports-home.md "Proof") — slice 6's (role actors, the worker, post_as, get_as, pg) and a few of their own: the seeded book (seed.sql: 200 deals, 70 leads, 150 done
 * activities, three campaigns, quotas for 26, 27 and 30 — `ensure_book()` loads it once), the window every report is asked for (the last 100 days, which holds every seeded close), the database
 * functions called AS A MEMBER on the proofs' own connection (so the screens are compared with what the schema itself answers), and a row comparer.
 * Actors: 1 the admin, 26 Priya a Rep, 27 Marco the Sales Manager, 29 Ann a Viewer, 30 Dana a Rep, 40 the Expert (an agent, Rep).
 */
require dirname(__DIR__) . '/slice6/lib.php';
require_once dirname(__DIR__, 3) . '/app/features/reports/periods.php';

/** Load the synthetic book once (as postgres); answers how many RPT deals there are. */
function ensure_book(): int
{
    $n = (int) pg("SELECT count(*) FROM deals WHERE title LIKE 'RPT deal %'");
    if ($n === 0) {
        $f = escapeshellarg(__DIR__ . '/seed.sql');
        shell_exec('sudo -n -u postgres psql -v ON_ERROR_STOP=1 -q -d ' . escapeshellarg(need('DB_NAME')) . ' -f ' . $f . ' 2>&1');
        $n = (int) pg("SELECT count(*) FROM deals WHERE title LIKE 'RPT deal %'");
    }
    return $n;
}
/** The window every report is asked for: the last 100 days to today (the database's own today). */
function win(): array
{
    return ['from' => (string) one('SELECT current_date - 100'), 'to' => (string) one('SELECT current_date')];
}
/** A report's screen JSON as a member: the `data`. */
function rep(int $m, string $name, array $q = []): array
{
    $r = get_as($m, '/reports/' . str_replace('_', '-', $name) . ($q === [] ? '' : '?' . http_build_query($q)));
    return $r['json']['data'] ?? ['_code' => $r['code'], '_error' => $r['json']['error'] ?? null];
}
/** A report's page as a member (HTML). */
function rep_html(int $m, string $name, array $q = [], array $headers = []): array
{
    return html_as($m, '/reports/' . str_replace('_', '-', $name) . ($q === [] ? '' : '?' . http_build_query($q)), $headers);
}
/** Rows of a query run as a member on the proofs' own connection (the database judges the wall). */
function as_rows(int $m, string $sql, array $args = []): array
{
    as_member($m);
    return q($sql, $args);
}
/** Whether screen rows equal function rows on the keys given ([screen key => function column]); numbers compare as numbers, null as null. */
function same_rows(array $got, array $want, array $keys): bool
{
    if (count($got) !== count($want)) { return false; }
    foreach (array_values($want) as $i => $w) {
        foreach ($keys as $sk => $fc) {
            $g = array_values($got)[$i][$sk] ?? null;
            $x = $w[$fc];
            if ($g === null || $x === null) { if ($g !== $x) { return false; } continue; }
            if (is_numeric($x) && is_numeric($g)) { if (abs((float) $g - (float) $x) > 0.0051) { return false; } continue; }
            if ((string) $g !== (string) $x) { return false; }
        }
    }
    return true;
}
/** A screen's row by a key's value, or null. */
function row_by(array $rows, string $key, $value): ?array
{
    foreach ($rows as $r) { if (($r[$key] ?? null) == $value) { return $r; } }
    return null;
}
/** The tone a quota attainment reads in: success from 100, warning from 70, else danger. */
function tone_of(?float $att): ?string { return $att === null ? null : ($att >= 100 ? 'success' : ($att >= 70 ? 'warning' : 'danger')); }
/** The first of this month and of a month n away, as the database's today counts it. */
function month_start(int $n = 0): string { return (string) one("SELECT (date_trunc('month', current_date) + make_interval(months => :n))::date", ['n' => $n]); }
