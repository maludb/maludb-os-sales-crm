<?php
declare(strict_types=1);

/**
 * The import's writes (leads-and-conversion.md §4). Starting stores the file under storage/imports/<id>.csv (never under html/) and queues the dry run;
 * the worker runs it (runner.php). Running queues the real run of the same import (its dry-run rows cleared); rolling back tombstones what it created.
 */
require_once __DIR__ . '/csv.php';
require_once __DIR__ . '/queries.php';

/**
 * $f: kind, mapping [header => field], dedupe_policy, owner_member_id, source_id, list_id, template_id, dry_run; $file: [tmp, name]. Answers the import id.
 * Refuses (DomainException, a sentence): no header, more rows than the ceiling, a mapping naming a header the file lacks or a field the kind lacks.
 */
function import_start(PDO $pdo, array $f, array $file, int $by): int
{
    $sniff = csv_sniff($file['tmp']);
    if ($sniff['headers'] === [] || array_filter($sniff['headers'], static fn ($h) => $h !== '') === []) {
        throw new DomainException('The file has no header row.');
    }
    $rows = 0;
    $max = import_max_rows($pdo);
    foreach (csv_rows($file['tmp'], $sniff['delimiter'], $sniff['encoding']) as $_) {
        if (++$rows > $max) { throw new DomainException('A file is up to ' . number_format($max) . ' rows; this one has more.'); }
    }
    if ($rows === 0) {
        throw new DomainException('The file has a header and no rows.');
    }
    $mapping = $f['mapping'];
    foreach ($mapping as $h => $field) {
        if (!in_array($h, $sniff['headers'], true)) { throw new DomainException('The file has no column ' . $h . '.'); }
        if ($field !== 'skip' && !isset(IMPORT_FIELDS[$f['kind']][$field]) && !str_starts_with((string) $field, 'custom:')) { throw new DomainException('A ' . IMPORT_KINDS[$f['kind']] . ' import has no field ' . $field . '.'); }
    }
    if (array_filter($mapping, static fn ($v) => $v !== 'skip') === []) {
        throw new DomainException('No column is mapped to a field.');
    }
    $st = $pdo->prepare("INSERT INTO imports (kind, file_name, status, created_by, storage_path, byte_size, row_count, mapping, dedupe_policy, dry_run, owner_member_id, source_id, list_id, template_id)
                         VALUES (:k, :n, 'queued', :by, '', :b, :r, CAST(:m AS jsonb), :d, CAST(:dry AS boolean), :o, :s, :l, :t) RETURNING id");
    $st->execute(['k' => $f['kind'], 'n' => mb_substr((string) $file['name'], 0, 200), 'by' => $by, 'b' => filesize($file['tmp']), 'r' => $rows, 'm' => json_encode((object) $mapping, JSON_UNESCAPED_UNICODE),
                  'd' => $f['dedupe_policy'], 'dry' => $f['dry_run'] ? 't' : 'f', 'o' => $f['owner_member_id'], 's' => $f['source_id'], 'l' => $f['list_id'], 't' => $f['template_id']]);
    $id = (int) $st->fetchColumn();
    $rel = 'imports/' . $id . '.csv';
    $abs = attachment_storage_dir() . '/' . $rel;
    if (!is_dir(dirname($abs)) && !mkdir(dirname($abs), 0750, true) && !is_dir(dirname($abs))) {
        throw new RuntimeException('import store: cannot make imports/');
    }
    if (!(is_uploaded_file($file['tmp']) ? move_uploaded_file($file['tmp'], $abs) : copy($file['tmp'], $abs))) {
        throw new RuntimeException('import store: cannot write ' . $rel);
    }
    @chmod($abs, 0640);
    $pdo->prepare('UPDATE imports SET storage_path = :p WHERE id = :id')->execute(['p' => $rel, 'id' => $id]);
    return $id;
}

/** The real run of an import whose dry run is done: its dry-run rows cleared, queued for the worker. */
function import_run(PDO $pdo, int $id): void
{
    $pdo->prepare('DELETE FROM import_rows WHERE import_id = :id')->execute(['id' => $id]);
    $pdo->prepare("UPDATE imports SET dry_run = false, status = 'queued', rows_made = 0, rows_updated = 0, rows_skipped = 0, unsupported = 0, finished_at = NULL WHERE id = :id")->execute(['id' => $id]);
}

/** Every record the real run created to the tombstone; the import rolled_back. Answers how many. */
function import_rollback(PDO $pdo, int $id): int
{
    require_once __DIR__ . '/runner.php';
    $imp = $pdo->query('SELECT * FROM imports WHERE id = ' . (int) $id)->fetch();
    $n = import_rollback_execute($pdo, $imp);
    $pdo->prepare("UPDATE imports SET status = 'rolled_back', rolled_back_at = now() WHERE id = :id")->execute(['id' => $id]);
    return $n;
}

/** A saved mapping (import_templates): a new one, or the caller's own of that name and kind replaced. Answers the id. */
function template_save(PDO $pdo, string $name, string $kind, array $mapping, string $policy, bool $shared, int $by): int
{
    $id = one_value($pdo, 'SELECT id FROM import_templates WHERE lower(name) = lower(:n) AND kind = :k AND owner_member_id = :by', ['n' => $name, 'k' => $kind, 'by' => $by]);
    if ($id !== null && $id !== false) {
        $pdo->prepare('UPDATE import_templates SET mapping = CAST(:m AS jsonb), dedupe_policy = :d, is_shared = CAST(:s AS boolean), name = :n WHERE id = :id')
            ->execute(['m' => json_encode((object) $mapping, JSON_UNESCAPED_UNICODE), 'd' => $policy, 's' => $shared ? 't' : 'f', 'n' => $name, 'id' => $id]);
        return (int) $id;
    }
    $st = $pdo->prepare('INSERT INTO import_templates (name, kind, mapping, dedupe_policy, is_shared, owner_member_id) VALUES (:n, :k, CAST(:m AS jsonb), :d, CAST(:s AS boolean), :by) RETURNING id');
    $st->execute(['n' => $name, 'k' => $kind, 'm' => json_encode((object) $mapping, JSON_UNESCAPED_UNICODE), 'd' => $policy, 's' => $shared ? 't' : 'f', 'by' => $by]);
    return (int) $st->fetchColumn();
}
