<?php
declare(strict_types=1);

/**
 * The lead sources and the lost reasons the admin keeps (screen admin-sources; actions source_*, lost_reason_*). A source or a reason that a record names is DEACTIVATED, never deleted — the picker stops offering
 * it, the records keep it; deleting one that is named is refused, naming what names it. The tags' admin is the tags feature's (app/features/tags): a vocabulary of the same kind, but one query layer.
 */

/** The sources and the reasons, each with where it is used: ['sources' => […], 'reasons' => […]] (the reasons by kind, leads' then deals'). */
function vocab(PDO $pdo): array
{
    $sources = $pdo->query("SELECT s.id, s.name, s.kind, s.active, s.sort_order,
            (SELECT count(*) FROM leads WHERE source_id = s.id) AS leads, (SELECT count(*) FROM deals WHERE source_id = s.id) AS deals, (SELECT count(*) FROM accounts WHERE source_id = s.id) AS accounts,
            (SELECT count(*) FROM contacts WHERE source_id = s.id) AS contacts, (SELECT count(*) FROM imports WHERE source_id = s.id) AS imports,
            (SELECT count(*) FROM mcp_access_tokens WHERE lead_source_id = s.id AND revoked_at IS NULL) AS keys,
            (SELECT count(*) FROM assignment_rules r WHERE jsonb_exists(r.match, 'source_id') AND (r.match -> 'source_id') @> to_jsonb(s.id)) AS rules
         FROM lead_sources s ORDER BY s.sort_order, s.name")->fetchAll();
    $reasons = $pdo->query("SELECT r.id, r.kind, r.label, r.active, r.sort_order,
            (SELECT count(*) FROM deals WHERE lost_reason_id = r.id) AS deals, (SELECT count(*) FROM leads WHERE unqualified_reason_id = r.id) AS leads
         FROM lost_reasons r ORDER BY r.kind DESC, r.sort_order, r.label")->fetchAll();      // 'lead' before 'deal' (DESC)
    $num = static fn (array $rows, array $cols): array => array_map(static function (array $r) use ($cols): array {
        foreach ($cols as $c) { $r[$c] = (int) $r[$c]; }
        $r['id'] = (int) $r['id'];
        $r['sort_order'] = (int) $r['sort_order'];
        $r['active'] = (bool) $r['active'];
        return $r;
    }, $rows);
    return ['sources' => $num($sources, ['leads', 'deals', 'accounts', 'contacts', 'imports', 'keys', 'rules']), 'reasons' => $num($reasons, ['deals', 'leads'])];
}

/** One source or null. */
function source_one(PDO $pdo, int $id): ?array
{
    foreach (vocab($pdo)['sources'] as $s) {
        if ($s['id'] === $id) { return $s; }
    }
    return null;
}

/** One reason or null. */
function reason_one(PDO $pdo, int $id): ?array
{
    foreach (vocab($pdo)['reasons'] as $r) {
        if ($r['id'] === $id) { return $r; }
    }
    return null;
}

/** "12 leads, 3 deals and an assignment rule" — what names a source, in words; '' when nothing does. */
function vocab_uses_words(array $row, array $labels): string
{
    $parts = [];
    foreach ($labels as $col => [$one, $many]) {
        $n = (int) ($row[$col] ?? 0);
        if ($n > 0) { $parts[] = $n . ' ' . ($n === 1 ? $one : $many); }
    }
    if (count($parts) > 1) {
        $last = array_pop($parts);
        return implode(', ', $parts) . ' and ' . $last;
    }
    return $parts[0] ?? '';
}
