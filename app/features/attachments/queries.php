<?php
declare(strict_types=1);

/** The files on a record (mcp_attachments applies the record's wall). */
function find_attachments(PDO $pdo, string $kind, int $id, int $limit = 100): array
{
    $st = $pdo->prepare('SELECT a.attachment_id, a.filename, a.mime_type, a.byte_size, a.uploaded_by, m.display_name AS uploader, a.created_at FROM mcp_attachments a LEFT JOIN members m ON m.id = a.uploaded_by
                          WHERE a.record_type = :k AND a.record_id = :id ORDER BY a.created_at DESC, a.attachment_id DESC LIMIT :l');
    $st->bindValue('k', $kind);
    $st->bindValue('id', $id, PDO::PARAM_INT);
    $st->bindValue('l', $limit, PDO::PARAM_INT);
    $st->execute();
    return $st->fetchAll();
}

/** One attachment the caller may see — with the storage path from the base table (the view never shows it). */
function find_attachment(PDO $pdo, int $id): ?array
{
    $st = $pdo->prepare('SELECT a.id AS attachment_id, a.record_type, a.record_id, a.filename, a.mime_type, a.byte_size, a.sha256, a.storage_path, a.uploaded_by, a.created_at
                           FROM attachments a WHERE a.id = :id AND EXISTS (SELECT 1 FROM mcp_attachments v WHERE v.attachment_id = a.id)');
    $st->execute(['id' => $id]);
    $r = $st->fetch();
    return $r === false ? null : $r;
}

/** A size a person reads: 840 KB, 3.2 MB. */
function bytes_label(int $b): string
{
    $u = ['B', 'KB', 'MB', 'GB'];
    $i = 0;
    $v = (float) $b;
    while ($v >= 1024 && $i < 3) { $v /= 1024; $i++; }
    return ($i === 0 ? (string) $b : number_format($v, $v < 10 ? 1 : 0)) . ' ' . $u[$i];
}
