<?php
declare(strict_types=1);

/** A note is a touch (the trigger touches the record, its account, its deal). */
function note_add(PDO $pdo, string $kind, int $id, string $body, int $by): int
{
    $st = $pdo->prepare('INSERT INTO notes (record_type, record_id, member_id, body) VALUES (:k, :r, :m, :b) RETURNING id');
    $st->execute(['k' => $kind, 'r' => $id, 'm' => $by, 'b' => $body]);
    return (int) $st->fetchColumn();
}

function note_delete(PDO $pdo, int $id): void
{
    $pdo->prepare('DELETE FROM notes WHERE id = :id')->execute(['id' => $id]);
}
