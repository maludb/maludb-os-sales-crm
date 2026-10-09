<?php
declare(strict_types=1);

/**
 * The verbs every record shares (one handler per verb across kinds — slice 1's divergence 1): restrict (the wall's two columns; the trigger
 * refuses when the setting is off), delete (to the tombstone — crm_record_delete keeps the account's counters and the next step), restore.
 */

/** Restrict a record to a member (the owner by default) and/or a department, or lift the restriction. Answers the before and after. */
function record_restrict(PDO $pdo, string $kind, int $id, ?int $member, ?int $dept, bool $lift): array
{
    $table = RECORD_KINDS[$kind]['table'];
    $st = $pdo->prepare("SELECT restricted_to_member_id AS member, restricted_to_department_id AS department FROM {$table} WHERE id = :id");
    $st->execute(['id' => $id]);
    $before = $st->fetch() ?: ['member' => null, 'department' => null];
    $after = $lift ? ['member' => null, 'department' => null] : ['member' => $member, 'department' => $dept];
    $pdo->prepare("UPDATE {$table} SET restricted_to_member_id = :m, restricted_to_department_id = :d WHERE id = :id")->execute(['m' => $after['member'], 'd' => $after['department'], 'id' => $id]);
    return ['before' => array_map(static fn ($v) => $v === null ? null : (int) $v, $before), 'after' => $after];
}

/** To the tombstone (deleted_at, deleted_by): out of every list and view for tombstone_days, then the worker purges it. */
function record_delete(PDO $pdo, string $kind, int $id): void
{
    $pdo->prepare('SELECT crm_record_delete(:k, :id, false)')->execute(['k' => $kind, 'id' => $id]);
}

/** Back from the tombstone. */
function record_restore(PDO $pdo, string $kind, int $id): void
{
    $pdo->prepare('SELECT crm_record_delete(:k, :id, true)')->execute(['k' => $kind, 'id' => $id]);
}
