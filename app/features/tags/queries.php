<?php
declare(strict_types=1);

/** Tags (Help Desk's vocabulary reused, record_tags the one join; db/006): the labels of a record, the vocabulary for the picker. */

/** The tags of one record: [{tag_id, slug, label}]. */
function record_tags(PDO $pdo, string $kind, int $id): array
{
    $st = $pdo->prepare('SELECT t.id AS tag_id, t.slug, t.label FROM record_tags rt JOIN tags t ON t.id = rt.tag_id WHERE rt.record_type = :k AND rt.record_id = :id ORDER BY t.label');
    $st->execute(['k' => $kind, 'id' => $id]);
    return $st->fetchAll();
}

/** The vocabulary by text: [{tag_id, slug, label, usage_count}], most used first. */
function find_tags(PDO $pdo, string $q = '', int $limit = 50, int $offset = 0): array
{
    $st = $pdo->prepare("SELECT tag_id, slug, label, usage_count, count(*) OVER () AS total FROM mcp_tags
                          WHERE :q = '' OR label ILIKE '%' || :q || '%' OR slug ILIKE '%' || :q || '%' ORDER BY usage_count DESC, label LIMIT :l OFFSET :o");
    $st->bindValue('q', $q);
    $st->bindValue('l', $limit, PDO::PARAM_INT);
    $st->bindValue('o', $offset, PDO::PARAM_INT);
    $st->execute();
    return $st->fetchAll();
}

/** A label's slug: lower-case letters, digits, dashes; up to 50. */
function tag_slug(string $label): string
{
    $s = strtolower(trim((string) preg_replace('/[^a-z0-9]+/i', '-', iconv('UTF-8', 'ASCII//TRANSLIT', $label) ?: $label), '-'));
    return substr($s !== '' ? $s : 'tag', 0, 50);
}
