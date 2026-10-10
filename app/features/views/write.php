<?php
declare(strict_types=1);

/** Save ($id null = new) a view. $f: name, record_kind, filters (array), sort_by, sort_dir, columns (list), layout, pipeline, scope. Answers the id. */
function saved_view_save(PDO $pdo, ?int $id, array $f, int $by): int
{
    $scope = $f['scope'];
    $args = ['name' => $f['name'], 'scope' => $scope, 'owner' => $scope === 'personal' ? $by : null, 'kind' => $f['record_kind'],
        'filters' => json_encode($f['filters'] === [] ? (object) [] : $f['filters'], JSON_THROW_ON_ERROR), 'sort_by' => $f['sort_by'], 'sort_dir' => $f['sort_dir'],
        'columns' => pg_array_literal($f['columns']), 'layout' => $f['layout']];
    if ($id === null) {
        $st = $pdo->prepare('INSERT INTO saved_views (name, scope, owner_member_id, record_kind, filters, sort_by, sort_dir, columns, layout)
                             VALUES (:name, :scope, :owner, :kind, CAST(:filters AS jsonb), :sort_by, :sort_dir, CAST(:columns AS text[]), :layout) RETURNING id');
        $st->execute($args);
        return (int) $st->fetchColumn();
    }
    $pdo->prepare('UPDATE saved_views SET name = :name, scope = :scope, owner_member_id = :owner, record_kind = :kind, filters = CAST(:filters AS jsonb), sort_by = :sort_by, sort_dir = :sort_dir,
                          columns = CAST(:columns AS text[]), layout = :layout WHERE id = :id')->execute($args + ['id' => $id]);
    return $id;
}

/** A view goes (archived — the row kept, as the canonical table's other kinds are). */
function saved_view_delete(PDO $pdo, int $id): void
{
    $pdo->prepare('UPDATE saved_views SET archived_at = now() WHERE id = :id AND archived_at IS NULL')->execute(['id' => $id]);
}
