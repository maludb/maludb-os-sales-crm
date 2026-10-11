<?php
declare(strict_types=1);

/**
 * custom_field_save (log field.save): the definitions of the five records' custom fields (custom_field_defs, db/006). The readers — every form (custom_field_defs() in queries.php), the database's crm_validate_custom_values() —
 * read this table, so a field saved here is on the next form and refused on the next wrong value. Rules in words: a key is a slug and, once made, never changes (the values are stored under it); a field with
 * values keeps its type; a choice field needs its options; pipelines and "required to win" are for deals; ARCHIVED hides a field from the forms and keeps its values valid (db/024).
 */
require_once dirname(__DIR__) . '/admin/queries.php';

/** Save a definition ($id null = new). $f: applies_to, key, label, field_type, options[], pipelines[], required_to_win, sort_order, archived. ['id', 'before', 'after']. */
function field_save(PDO $pdo, ?int $id, array $f): array
{
    $cur = $id === null ? null : field_one($pdo, $id);
    if ($id !== null && $cur === null) { throw new DomainException('Not found.'); }
    $kind = $cur === null ? (string) $f['applies_to'] : $cur['applies_to'];
    $key = $cur === null ? (string) $f['key'] : $cur['key'];
    if ($cur !== null && (($f['key'] ?? $key) !== $key || ($f['applies_to'] ?? $kind) !== $kind)) {
        throw new DomainException('A field keeps its key and its record — the values are stored under them. Add a new field instead.');
    }
    if ($cur === null && (int) one_value($pdo, 'SELECT count(*) FROM custom_field_defs WHERE applies_to = :k AND key = :key', ['k' => $kind, 'key' => $key]) > 0) {
        throw new DomainException('A ' . $kind . ' field with the key ' . $key . ' exists already' . ' (it may be archived).');
    }
    if ($cur === null && $kind === 'deal' && isset(STAGE_REQUIRED_COLUMNS[$key])) {
        throw new DomainException($key . ' is a column of a deal, so it is no key for a custom field.');
    }
    $type = (string) ($f['field_type'] ?? $cur['field_type']);
    if ($cur !== null && $type !== $cur['field_type'] && $cur['in_use'] > 0) {
        throw new DomainException('Values of ' . $cur['label'] . ' are stored on ' . $cur['in_use'] . ' record' . ($cur['in_use'] === 1 ? '' : 's') . ', so it stays ' . strtolower(FIELD_TYPES[$cur['field_type']]) . ' — add a new field for the new type.');
    }
    $options = in_array($type, ['select', 'multi_select'], true) ? array_values(array_unique(array_filter(array_map('trim', (array) ($f['options'] ?? $cur['options_list'] ?? [])), static fn ($o): bool => $o !== ''))) : [];
    if (in_array($type, ['select', 'multi_select'], true) && $options === []) {
        throw new DomainException('A field of choices needs its choices — one a line.');
    }
    foreach ($options as $o) { if (mb_strlen($o) > 80 || str_contains($o, ',') || str_contains($o, '"') || str_contains($o, '{') || str_contains($o, '}') || str_contains($o, '\\')) { throw new DomainException('A choice is up to 80 characters without commas, quotes, braces or backslashes: ' . $o); } }
    $pipelines = $kind === 'deal' ? array_values(array_unique(array_map('intval', (array) ($f['pipelines'] ?? $cur['pipeline_ids'] ?? [])))) : [];
    foreach ($pipelines as $p) { if ((int) one_value($pdo, 'SELECT count(*) FROM pipelines WHERE id = :p', ['p' => $p]) === 0) { throw new DomainException('There is no pipeline ' . $p . '.'); } }
    $toWin = $kind === 'deal' && (bool) ($f['required_to_win'] ?? $cur['required_to_win'] ?? false);
    $isArchived = $cur !== null && $cur['archived_at'] !== null;
    $archive = (bool) ($f['archived'] ?? $isArchived);
    $new = ['label' => (string) $f['label'], 'field_type' => $type, 'options' => $options, 'pipelines' => $pipelines, 'required_to_win' => $toWin, 'sort_order' => (int) ($f['sort_order'] ?? $cur['sort_order'] ?? 100)];
    $args = ['l' => $new['label'], 't' => $type, 'o' => pg_array_literal($options), 'p' => pg_array_literal($pipelines), 'w' => $toWin ? 't' : 'f', 's' => $new['sort_order']];
    if ($cur === null) {
        $st = $pdo->prepare('INSERT INTO custom_field_defs (applies_to, key, label, field_type, options, type_ids, required_on_solve, sort_order, archived_at)
                             VALUES (:k, :key, :l, :t, CAST(:o AS text[]), CAST(:p AS bigint[]), CAST(:w AS boolean), :s, CASE WHEN CAST(:a AS boolean) THEN now() END) RETURNING id');
        $st->execute($args + ['k' => $kind, 'key' => $key, 'a' => $archive ? 't' : 'f']);
        return ['id' => (int) $st->fetchColumn(), 'before' => [], 'after' => ['applies_to' => $kind, 'key' => $key] + $new];
    }
    $d = crm_diff(['label' => $cur['label'], 'field_type' => $cur['field_type'], 'options' => $cur['options_list'], 'pipelines' => $cur['pipeline_ids'], 'required_to_win' => $cur['required_to_win'], 'sort_order' => $cur['sort_order']], $new);
    if ($d['after'] !== []) {
        $pdo->prepare('UPDATE custom_field_defs SET label = :l, field_type = :t, options = CAST(:o AS text[]), type_ids = CAST(:p AS bigint[]), required_on_solve = CAST(:w AS boolean), sort_order = :s WHERE id = :id')
            ->execute($args + ['id' => $id]);
    }
    if ($archive !== $isArchived) {
        $pdo->prepare('UPDATE custom_field_defs SET archived_at = CASE WHEN CAST(:a AS boolean) THEN now() ELSE NULL END WHERE id = :id')->execute(['a' => $archive ? 't' : 'f', 'id' => $id]);
        $d['before']['archived'] = $isArchived;
        $d['after']['archived'] = $archive;
    }
    return ['id' => $id, 'applies_to' => $kind, 'key' => $key] + $d;
}
