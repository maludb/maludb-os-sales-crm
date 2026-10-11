<?php
declare(strict_types=1);

/**
 * The lead assignment rules (screen admin-rules; db/009): the ordered list, the first ACTIVE rule a lead matches decides its owner. A rule's `match` is a bounded vocabulary — source_id[], campaign_id[],
 * country[], domain_suffix[], utm_source[] — each a list of the values that satisfy it; a rule with none matches every lead. These reads name what a rule points at, so the screen prints words, not ids.
 */
const RULE_KINDS = ['member' => 'A person', 'department' => "A department's manager", 'round_robin' => 'In turn'];
const RULE_TONE = ['member' => 'primary', 'department' => 'info', 'round_robin' => 'warning'];
const RULE_MATCH_KEYS = ['source_id', 'campaign_id', 'country', 'domain_suffix', 'utm_source'];

/** The rules in order: {id, name, position, active, match{…}, assign_kind, member_id, member_name, department_id, department_name, members[{id,name}], last_index}. */
function rules(PDO $pdo): array
{
    $rows = $pdo->query('SELECT r.id, r.name, r.position, r.active, r.match::text AS match, r.assign_kind, r.member_id, m.display_name AS member_name, r.department_id, d.name AS department_name,
                                r.members::text AS members, r.last_index
                           FROM assignment_rules r LEFT JOIN members m ON m.id = r.member_id LEFT JOIN departments d ON d.id = r.department_id ORDER BY r.position, r.id')->fetchAll();
    $names = static function (PDO $pdo, string $sql, array $ids): array {
        if ($ids === []) { return []; }
        $out = [];
        foreach ($pdo->query($sql . ' WHERE id IN (' . implode(',', array_map('intval', $ids)) . ')')->fetchAll() as $r) { $out[(int) $r['id']] = (string) $r['name']; }
        return $out;
    };
    $memberIds = [];
    $sourceIds = [];
    $campaignIds = [];
    foreach ($rows as $r) {
        $memberIds = array_merge($memberIds, pg_int_array($r['members']));
        $match = json_decode((string) $r['match'], true) ?: [];
        $sourceIds = array_merge($sourceIds, array_map('intval', (array) ($match['source_id'] ?? [])));
        $campaignIds = array_merge($campaignIds, array_map('intval', (array) ($match['campaign_id'] ?? [])));
    }
    $mem = $names($pdo, 'SELECT id, display_name AS name FROM members', array_unique($memberIds));
    $src = $names($pdo, 'SELECT id, name FROM lead_sources', array_unique($sourceIds));
    $cmp = $names($pdo, 'SELECT id, name FROM campaigns', array_unique($campaignIds));
    return array_map(static function (array $r) use ($mem, $src, $cmp): array {
        $match = json_decode((string) $r['match'], true) ?: [];
        $ids = pg_int_array($r['members']);
        return ['id' => (int) $r['id'], 'position' => (int) $r['position'], 'active' => (bool) $r['active'], 'match' => $match, 'last_index' => (int) $r['last_index'],
            'member_id' => $r['member_id'] === null ? null : (int) $r['member_id'], 'department_id' => $r['department_id'] === null ? null : (int) $r['department_id'],
            'members' => array_map(static fn (int $i): array => ['id' => $i, 'name' => $mem[$i] ?? ('#' . $i)], $ids),
            'source_names' => array_map(static fn ($i): string => $src[(int) $i] ?? ('#' . $i), (array) ($match['source_id'] ?? [])),
            'campaign_names' => array_map(static fn ($i): string => $cmp[(int) $i] ?? ('#' . $i), (array) ($match['campaign_id'] ?? []))] + $r;
    }, $rows);
}

/** The match in words: "from Website · country US · email ends .edu"; "every lead" when it has no condition. */
function rule_match_words(array $rule): string
{
    $parts = [];
    if (($rule['source_names'] ?? []) !== []) { $parts[] = 'from ' . implode(' or ', $rule['source_names']); }
    if (($rule['campaign_names'] ?? []) !== []) { $parts[] = 'campaign ' . implode(' or ', $rule['campaign_names']); }
    if (($rule['match']['country'] ?? []) !== []) { $parts[] = 'country ' . implode(' or ', (array) $rule['match']['country']); }
    if (($rule['match']['domain_suffix'] ?? []) !== []) { $parts[] = 'email ends ' . implode(' or ', (array) $rule['match']['domain_suffix']); }
    if (($rule['match']['utm_source'] ?? []) !== []) { $parts[] = 'utm source ' . implode(' or ', (array) $rule['match']['utm_source']); }
    return $parts === [] ? 'every lead' : implode(' · ', $parts);
}

/** The whitelist of a rule for JSON. */
function present_rule(array $r): array
{
    return ['rule_id' => $r['id'], 'name' => $r['name'], 'position' => $r['position'], 'active' => $r['active'], 'match' => $r['match'], 'match_words' => rule_match_words($r), 'assign_kind' => $r['assign_kind'],
        'member_id' => $r['member_id'], 'member_name' => $r['member_name'], 'department_id' => $r['department_id'], 'department_name' => $r['department_name'], 'members' => $r['members']];
}
