<?php
declare(strict_types=1);

/** Notes on a record (GL's `notes` reused; db/013): mcp_notes applies the record's wall. */
function find_notes(PDO $pdo, string $kind, int $id, int $limit = 50): array
{
    $st = $pdo->prepare('SELECT n.note_id, n.member_id, m.display_name AS author, n.body, n.created_at FROM mcp_notes n LEFT JOIN members m ON m.id = n.member_id
                          WHERE n.record_type = :k AND n.record_id = :id ORDER BY n.created_at DESC, n.note_id DESC LIMIT :l');
    $st->bindValue('k', $kind);
    $st->bindValue('id', $id, PDO::PARAM_INT);
    $st->bindValue('l', $limit, PDO::PARAM_INT);
    $st->execute();
    return $st->fetchAll();
}

/** One note the caller may see, with the record it hangs on. */
function find_note(PDO $pdo, int $id): ?array
{
    $st = $pdo->prepare('SELECT note_id, record_type, record_id, member_id, created_at FROM mcp_notes WHERE note_id = :id');
    $st->execute(['id' => $id]);
    $r = $st->fetch();
    return $r === false ? null : $r;
}
