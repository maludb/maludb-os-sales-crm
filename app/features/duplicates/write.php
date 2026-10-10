<?php
declare(strict_types=1);

/** crm_records_merge(kind, survivor, loser): the survivor keeps its values and takes the loser's where empty; every child moves; the loser a tombstone pointing at it. */
function records_merge(PDO $pdo, string $kind, int $survivor, int $loser): void
{
    $pdo->prepare('SELECT crm_records_merge(:k, :s, :l)')->execute(['k' => $kind, 's' => $survivor, 'l' => $loser]);
}

/** "Not a duplicate": the pair is not shown again (duplicate_dismissals, the smaller id first). Answers false when it was dismissed before. */
function duplicate_dismiss(PDO $pdo, string $kind, int $a, int $b, int $by): bool
{
    $st = $pdo->prepare('INSERT INTO duplicate_dismissals (record_kind, a_id, b_id, dismissed_by) VALUES (:k, :a, :b, :by) ON CONFLICT DO NOTHING');
    $st->execute(['k' => $kind, 'a' => min($a, $b), 'b' => max($a, $b), 'by' => $by]);
    return $st->rowCount() === 1;
}
