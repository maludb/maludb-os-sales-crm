<?php
/** Helpers for the slice 2 proofs (leads-import.md, leads-and-conversion.md §8) — slice 1's helpers (actors, post_as, get_as, upload_as) and a few of their own. */
require dirname(__DIR__) . '/slice1/lib.php';

/** Run the worker's named passes now (the import's dry run and run): the JSON report. */
function worker(string $passes = 'imports'): array
{
    $out = (string) shell_exec('php ' . escapeshellarg(dirname(__DIR__, 3) . '/bin/worker.php') . ' --passes=' . escapeshellarg($passes) . ' 2>&1');
    $lines = array_values(array_filter(explode("\n", trim($out))));
    return json_decode((string) end($lines), true) ?: ['raw' => $out];
}
/** A CSV written to a temp file: rows of arrays (the first the header). */
function csv_file(array $rows, string $delimiter = ',', ?string $encoding = null): string
{
    $f = tempnam(sys_get_temp_dir(), 'crmcsv') . '.csv';
    $fh = fopen($f, 'w');
    foreach ($rows as $r) {
        if ($encoding !== null) { $r = array_map(static fn ($v) => mb_convert_encoding((string) $v, $encoding, 'UTF-8'), $r); }
        fputcsv($fh, $r, $delimiter, '"', '');
    }
    fclose($fh);
    return $f;
}
/** Start an import as a member (the multipart door); the decoded answer. */
function import_as(int $m, string $kind, string $file, array $fields = []): array
{
    return upload_as($m, '/import/start.php', ['kind' => $kind] + $fields, [$file, 'text/csv', basename($file)]);
}
/** As postgres (a fixture the application's role never writes). */
function pg_run(string $sql): void
{
    exec('sudo -n -u postgres psql -q -v ON_ERROR_STOP=1 -d ' . escapeshellarg(need('DB_NAME')) . ' -c ' . escapeshellarg($sql) . ' 2>&1', $o, $rc);
    if ($rc !== 0) { fwrite(STDERR, "pg_exec failed: " . implode(' ', $o) . "\n"); }
}
