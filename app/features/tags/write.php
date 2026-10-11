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

// ---- the admin's writes (slice 9: tag_save, tag_merge, tag_delete; the reads are queries.php's and admin/queries.php's tags_with_counts) ---------------------------------------------------------------

/** A saved view's or a smart list's `tag` filter names a tag by its label: it follows a rename or a merge (a deleted tag's filter is left — it simply matches nothing). */
function tag_filters_follow(PDO $pdo, string $oldLabel, string $newLabel): int
{
    $n = 0;
    foreach (['saved_views', 'lists'] as $table) {
        $st = $pdo->prepare("UPDATE $table SET filters = jsonb_set(filters, '{tag}', to_jsonb(CAST(:n AS text))) WHERE jsonb_typeof(filters -> 'tag') = 'string' AND lower(filters ->> 'tag') = lower(:o)");
        $st->execute(['n' => $newLabel, 'o' => $oldLabel]);
        $n += $st->rowCount();
    }
    return $n;
}

/** Rename a tag: the label changes and the slug follows. Another tag with that name is a merge, not a rename. ['id', 'before' => label, 'after' => label, 'slug']. */
function tag_save(PDO $pdo, int $id, string $label): array
{
    $cur = one_row($pdo, 'SELECT id, slug, label FROM tags WHERE id = :id', ['id' => $id]);
    if ($cur === null) {
        throw new DomainException('Not found.');
    }
    $label = trim(mb_substr($label, 0, 60));
    $slug = tag_slug($label);
    if ((int) one_value($pdo, 'SELECT count(*) FROM tags WHERE id <> :id AND (slug = :s OR lower(label) = lower(:l))', ['id' => $id, 's' => $slug, 'l' => $label]) > 0) {
        throw new DomainException('Another tag is called ' . $label . ' — merge them instead.');
    }
    if ($cur['label'] === $label && $cur['slug'] === $slug) {
        return ['id' => $id, 'before' => [], 'after' => [], 'slug' => $slug, 'reindexed' => 0];
    }
    $pdo->prepare('UPDATE tags SET label = :l, slug = :s WHERE id = :id')->execute(['l' => $label, 's' => $slug, 'id' => $id]);
    $n = (int) $pdo->query('SELECT count(*) FROM (SELECT crm_index_record(record_type, record_id) FROM record_tags WHERE tag_id = ' . (int) $id . " AND record_type IN ('lead', 'account', 'contact', 'deal')) x")->fetchColumn();      // the search finds them by the new name
    tag_filters_follow($pdo, (string) $cur['label'], $label);
    return ['id' => $id, 'before' => ['label' => $cur['label'], 'slug' => $cur['slug']], 'after' => ['label' => $label, 'slug' => $slug], 'slug' => $slug, 'reindexed' => $n];
}

/** Merge a tag into another: its records take the other (those that carry both keep one), then it goes. ['id' => the survivor, 'moved', 'already', 'from' => label, 'into' => label]. */
function tag_merge(PDO $pdo, int $from, int $into): array
{
    if ($from === $into) {
        throw new DomainException('Choose another tag to merge it into.');
    }
    $a = one_row($pdo, 'SELECT id, label FROM tags WHERE id = :id', ['id' => $from]);
    $b = one_row($pdo, 'SELECT id, label FROM tags WHERE id = :id', ['id' => $into]);
    if ($a === null || $b === null) {
        throw new DomainException('Not found.');
    }
    $total = (int) one_value($pdo, 'SELECT count(*) FROM record_tags WHERE tag_id = :f', ['f' => $from]);
    $ins = $pdo->prepare('INSERT INTO record_tags (record_type, record_id, tag_id) SELECT record_type, record_id, :i FROM record_tags WHERE tag_id = :f ON CONFLICT DO NOTHING');
    $ins->execute(['i' => $into, 'f' => $from]);
    $moved = $ins->rowCount();
    $pdo->prepare('DELETE FROM record_tags WHERE tag_id = :f')->execute(['f' => $from]);
    $pdo->prepare('DELETE FROM tags WHERE id = :f')->execute(['f' => $from]);
    tag_filters_follow($pdo, (string) $a['label'], (string) $b['label']);
    return ['id' => $into, 'moved' => $moved, 'already' => $total - $moved, 'from' => $a['label'], 'into' => $b['label']];
}

/** Delete a tag: it is taken off every record, then it goes. ['id', 'label', 'removed' => the records it was on]. */
function tag_delete(PDO $pdo, int $id): array
{
    $cur = one_row($pdo, 'SELECT id, label FROM tags WHERE id = :id', ['id' => $id]);
    if ($cur === null) {
        throw new DomainException('Not found.');
    }
    $st = $pdo->prepare('DELETE FROM record_tags WHERE tag_id = :id');
    $st->execute(['id' => $id]);
    $removed = $st->rowCount();
    $pdo->prepare('DELETE FROM tags WHERE id = :id')->execute(['id' => $id]);
    return ['id' => $id, 'label' => $cur['label'], 'removed' => $removed];
}
