<?php
declare(strict_types=1);

/**
 * List writes (list_create, list_update, list_delete, list_add, list_remove). The count of a static list is the trigger's (db/012). A smart list's filters are stored as filters_json(); a static list holds none.
 * A list is deleted to the tombstone (deleted_at) — its members go with the row only when the worker purges it.
 */
require_once __DIR__ . '/queries.php';

/** Insert ($id null) or change a list. $f: name, kind, record_kind, filters (compact), is_shared, description. Answers the id. */
function list_save(PDO $pdo, ?int $id, array $f, int $by): int
{
    $args = ['name' => $f['name'], 'filters' => filters_json($f['kind'] === 'smart' ? $f['filters'] : []), 'shared' => $f['is_shared'] ? 't' : 'f', 'description' => $f['description']];
    if ($id === null) {
        $st = $pdo->prepare('INSERT INTO lists (name, kind, record_kind, filters, owner_member_id, is_shared, description, created_by)
                             VALUES (:name, :kind, :rk, CAST(:filters AS jsonb), :by, CAST(:shared AS boolean), :description, :by) RETURNING id');
        $st->execute($args + ['kind' => $f['kind'], 'rk' => $f['record_kind'], 'by' => $by]);
        $id = (int) $st->fetchColumn();
        if ($f['kind'] === 'smart') { list_recount($pdo, $id); }
        return $id;
    }
    $pdo->prepare('UPDATE lists SET name = :name, filters = CAST(:filters AS jsonb), is_shared = CAST(:shared AS boolean), description = :description WHERE id = :id AND deleted_at IS NULL')->execute($args + ['id' => $id]);
    if ($f['kind'] === 'smart') { list_recount($pdo, $id); }
    return $id;
}

/** A smart list's stored count, as the caller sees it now (the worker refreshes it hourly as the list's owner). */
function list_recount(PDO $pdo, int $id): int
{
    $l = find_list($pdo, $id);
    if ($l === null || $l['kind'] !== 'smart') { return 0; }
    $n = filters_find($pdo, $l['record_kind'], $l['filters'], 1)['total'];
    $pdo->prepare('UPDATE lists SET member_count = :n, counted_at = now() WHERE id = :id')->execute(['n' => $n, 'id' => $id]);
    return $n;
}

/** To the tombstone. */
function list_delete(PDO $pdo, int $id): void
{
    $pdo->prepare('UPDATE lists SET deleted_at = now() WHERE id = :id AND deleted_at IS NULL')->execute(['id' => $id]);
}

/**
 * Put records in a static list: only ones of the list's kind the caller may SEE (the kind's view is the wall), once each. Answers [added (new members), already (were there)].
 * A record that is not visible is refused by the caller before this (the handler names it); here it is simply not inserted.
 */
function list_add(PDO $pdo, int $list, array $ids): array
{
    $l = find_list($pdo, $list) ?? throw new DomainException('Not found.');
    if ($l['kind'] !== 'static') { throw new DomainException('A smart list has no members of its own; change its filters.'); }
    $k = RECORD_KINDS[$l['record_kind']];
    $ids = array_values(array_unique(array_map('intval', $ids)));
    if ($ids === []) { return ['added' => 0, 'already' => 0]; }
    $st = $pdo->prepare("INSERT INTO list_members (list_id, record_id, added_by) SELECT :l, v.{$k['id']}, :by FROM {$k['view']} v WHERE v.{$k['id']} = ANY (CAST(:ids AS bigint[])) ON CONFLICT DO NOTHING");
    $st->execute(['l' => $list, 'by' => (int) current_member_id(), 'ids' => pg_array_literal($ids)]);
    $added = $st->rowCount();
    return ['added' => $added, 'already' => count($ids) - $added];
}

/** Take records out of a static list. Answers how many were members. */
function list_remove(PDO $pdo, int $list, array $ids): int
{
    $ids = array_values(array_unique(array_map('intval', $ids)));
    if ($ids === []) { return 0; }
    $st = $pdo->prepare('DELETE FROM list_members WHERE list_id = :l AND record_id = ANY (CAST(:ids AS bigint[]))');
    $st->execute(['l' => $list, 'ids' => pg_array_literal($ids)]);
    return $st->rowCount();
}
