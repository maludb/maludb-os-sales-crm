<?php
declare(strict_types=1);

/** Store the bytes and record the row. Answers the id. */
function attachment_add(PDO $pdo, string $kind, int $id, array $file, int $by): int
{
    $s = attachment_store($pdo, $kind, $id, $file);
    $st = $pdo->prepare('INSERT INTO attachments (record_type, record_id, filename, mime_type, byte_size, sha256, storage_path, uploaded_by) VALUES (:k, :r, :f, :m, :n, :s, :p, :by) RETURNING id');
    $st->execute(['k' => $kind, 'r' => $id, 'f' => $s['filename'], 'm' => $s['mime_type'], 'n' => $s['byte_size'], 's' => $s['sha256'], 'p' => $s['storage_path'], 'by' => $by]);
    return (int) $st->fetchColumn();
}

/** The row goes; the bytes go when no other row names the same path. Answers the path removed (or null). */
function attachment_delete(PDO $pdo, int $id): ?string
{
    $st = $pdo->prepare('DELETE FROM attachments WHERE id = :id RETURNING storage_path');
    $st->execute(['id' => $id]);
    $path = $st->fetchColumn();
    if ($path === false) {
        return null;
    }
    return (int) one_value($pdo, 'SELECT count(*) FROM attachments WHERE storage_path = :p', ['p' => $path]) === 0 ? (string) $path : null;
}
