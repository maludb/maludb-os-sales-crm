<?php
declare(strict_types=1);

/** Tag writes (tag_apply, tag_remove — one pair of handlers across kinds, slice 1's divergence 2). A new label makes a tag; the count is the trigger's. */

/** The tag a label names, made when new. Answers [tag_id, label, made]. */
function tag_ensure(PDO $pdo, string $label): array
{
    $label = trim(mb_substr($label, 0, 60));
    $slug = tag_slug($label);
    $st = $pdo->prepare('SELECT id, label FROM tags WHERE slug = :s OR lower(label) = lower(:l) ORDER BY (slug = :s) DESC LIMIT 1');
    $st->execute(['s' => $slug, 'l' => $label]);
    if (($r = $st->fetch()) !== false) {
        return [(int) $r['id'], (string) $r['label'], false];
    }
    $st = $pdo->prepare('INSERT INTO tags (slug, label) VALUES (:s, :l) ON CONFLICT (slug) DO UPDATE SET label = tags.label RETURNING id, label, (xmax = 0) AS made');
    $st->execute(['s' => $slug, 'l' => $label]);
    $r = $st->fetch();
    return [(int) $r['id'], (string) $r['label'], (bool) $r['made']];
}

/** Apply a tag to records of a kind: [applied, skipped] (skipped = already carried it). */
function tag_apply(PDO $pdo, string $kind, array $ids, int $tagId): array
{
    $st = $pdo->prepare('INSERT INTO record_tags (record_type, record_id, tag_id) VALUES (:k, :r, :t) ON CONFLICT DO NOTHING');
    $applied = [];
    $skipped = [];
    foreach ($ids as $id) {
        $st->execute(['k' => $kind, 'r' => $id, 't' => $tagId]);
        if ($st->rowCount() === 1) { $applied[] = $id; } else { $skipped[] = $id; }
    }
    return ['applied' => $applied, 'skipped' => $skipped];
}

/** Remove a tag from records of a kind: [removed, skipped]. */
function tag_remove(PDO $pdo, string $kind, array $ids, int $tagId): array
{
    $st = $pdo->prepare('DELETE FROM record_tags WHERE record_type = :k AND record_id = :r AND tag_id = :t');
    $removed = [];
    $skipped = [];
    foreach ($ids as $id) {
        $st->execute(['k' => $kind, 'r' => $id, 't' => $tagId]);
        if ($st->rowCount() === 1) { $removed[] = $id; } else { $skipped[] = $id; }
    }
    return ['removed' => $removed, 'skipped' => $skipped];
}
