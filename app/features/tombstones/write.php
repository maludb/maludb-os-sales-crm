<?php
declare(strict_types=1);

/**
 * records_purge (action; log records.purge with the count by kind): destroys the tombstones that have waited past the window — crm_tombstones_purge_kinds(), the SAME function the worker's
 * `tombstones_purge` pass calls. A tombstone inside the window survives: `before` (a date) can only narrow the cut-off to an older edge, never reach in. Answers the counts by kind.
 */
require_once __DIR__ . '/queries.php';

/** The stored files of the records a purge is about to take (the same cut-off the function uses): [storage_path …]. The rows go with the records; the bytes are this file's to remove afterwards. */
function purge_attachment_paths(PDO $pdo, ?string $kind, ?string $before): array
{
    $cut = "LEAST(now() - make_interval(days => crm_setting_int('tombstone_days')::integer), COALESCE(CAST(:b AS timestamptz), 'infinity'::timestamptz))";
    $tables = ['lead' => 'leads', 'account' => 'accounts', 'contact' => 'contacts', 'deal' => 'deals', 'activity' => 'activities'];
    $paths = [];
    foreach ($tables as $k => $table) {
        if ($kind !== null && $kind !== $k) { continue; }
        $extra = $k === 'account' ? ' AND NOT EXISTS (SELECT 1 FROM deals d WHERE d.account_id = r.id) AND NOT EXISTS (SELECT 1 FROM contacts c WHERE c.account_id = r.id)' : '';
        $st = $pdo->prepare("SELECT a.storage_path FROM attachments a JOIN $table r ON a.record_type = :k AND a.record_id = r.id WHERE r.deleted_at < $cut$extra");
        $st->execute(['k' => $k, 'b' => $before]);
        $paths = array_merge($paths, array_column($st->fetchAll(), 'storage_path'));
    }
    return array_values(array_unique($paths));
}

/** Remove the bytes of purged records: a file whose attachments row is gone (and that no other row names) is deleted, and a record's directory with it when it is empty. Answers how many files went. */
function purge_attachment_files(PDO $pdo, array $paths): int
{
    require_once dirname(__DIR__) . '/attachments/store.php';
    $root = attachment_storage_dir();
    $n = 0;
    foreach ($paths as $rel) {
        if ((int) one_value($pdo, 'SELECT count(*) FROM attachments WHERE storage_path = :p', ['p' => $rel]) > 0 || !preg_match('#^attachments/[a-z_]+/\d+/[^/]+$#', (string) $rel)) {
            continue;                                                                                // still named, or not a path the store makes
        }
        $abs = $root . '/' . $rel;
        if (is_file($abs) && @unlink($abs)) { $n++; }
        @rmdir(dirname($abs));
    }
    return $n;
}

/** Purge what is due and answer the counts by kind: [kind => count] (every kind, zero included), and, by reference, the stored files of the records taken (their rows went with them; their bytes are the caller's to remove after the commit). $kind null = all; $before a date or timestamp (narrows only). */
function records_purge_by_kind(PDO $pdo, ?string $kind, ?string $before, int $limit = 5000, ?array &$paths = null): array
{
    $paths = purge_attachment_paths($pdo, $kind, $before);       // the caller removes the bytes once the purge is committed: purge_attachment_files($pdo, $paths)
    $st = $pdo->prepare('SELECT crm_tombstones_purge_kinds(CAST(:k AS text), CAST(:b AS timestamptz), :l)');
    $st->bindValue('k', $kind);
    $st->bindValue('b', $before);
    $st->bindValue('l', max(1, $limit), PDO::PARAM_INT);
    $st->execute();
    $counts = array_map('intval', json_decode((string) $st->fetchColumn(), true) ?: []);
    return $counts;
}

/** The same, as the total (the spec's signature). */
function records_purge(PDO $pdo, ?string $kind, ?string $before): int
{
    return array_sum(records_purge_by_kind($pdo, $kind, $before));
}
