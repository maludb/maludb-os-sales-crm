<?php
declare(strict_types=1);

/**
 * One record's History (crm_timeline, db/015: activities, notes' first 200 characters, stage moves, the changes in the trail — the record's wall
 * is the function's: a record the caller may not see answers nothing). Newest first; `$before` pages back.
 */
function timeline(PDO $pdo, string $kind, int $id, int $limit = 50, ?string $before = null): array
{
    $st = $pdo->prepare('SELECT t.at, t.item_kind, t.item_id, t.title, t.detail, t.actor_member_id, m.display_name AS actor_name, COALESCE(m.is_agent, false) AS actor_is_agent, t.deal_id, t.contact_id
                           FROM crm_timeline(:k, :id, :l, CAST(:b AS timestamptz)) t LEFT JOIN members m ON m.id = t.actor_member_id ORDER BY t.at DESC');
    $st->bindValue('k', $kind);
    $st->bindValue('id', $id, PDO::PARAM_INT);
    $st->bindValue('l', max(1, min(500, $limit)), PDO::PARAM_INT);
    $st->bindValue('b', $before);
    $st->execute();
    return $st->fetchAll();
}
