<?php
declare(strict_types=1);

/**
 * assignment_rules_save (log rules.save): the WHOLE ordered list in one transaction. A row that carries an `id` of a rule that exists is updated in place (a round-robin rule keeps its turn); a row without one is
 * new; a rule not in the list is removed. Positions are renumbered 10, 20, 30 … in the order of the list. Each row is checked with its number in the sentence ("Rule 2: …"), and the database's CHECKs
 * (a member rule names a member, a department rule a department, a round-robin rule at least one member) stay the last word.
 */
require_once __DIR__ . '/queries.php';

/**
 * One rule as the request carries it, made into the shape rules_save() takes. Accepts a form row (`source[]`, `campaign[]`, `country`, `domain_suffix`, `utm_source` as text, `member`, `department`, `members[]`)
 * and an agent's row (`match` an object of the five lists, `member_id`, `department_id`, `members` a list). Answers [rule, errors].
 */
function rule_from_request(array $row, int $n): array
{
    $errors = [];
    $list = static fn ($v): array => array_values(array_unique(array_filter(array_map(static fn ($x): string => trim((string) $x), is_array($v) ? $v : explode(',', (string) $v)), static fn (string $x): bool => $x !== '')));
    $ints = static fn ($v): array => array_values(array_unique(array_map('intval', array_filter($list($v), static fn (string $x): bool => ctype_digit($x) && (int) $x > 0))));
    $match = is_array($row['match'] ?? null) ? $row['match'] : [];
    $m = [];
    $sources = $ints($match['source_id'] ?? $row['source'] ?? $row['source_id'] ?? []);
    $campaigns = $ints($match['campaign_id'] ?? $row['campaign'] ?? $row['campaign_id'] ?? []);
    $countries = array_map('strtoupper', $list($match['country'] ?? $row['country'] ?? []));
    $suffixes = array_map('strtolower', $list($match['domain_suffix'] ?? $row['domain_suffix'] ?? []));
    $utm = $list($match['utm_source'] ?? $row['utm_source'] ?? []);
    foreach ($countries as $c) { if (!preg_match('/^[A-Z]{2}$/', $c)) { $errors[] = "Rule $n: a country is a two-letter code like US — not $c."; } }
    foreach ($suffixes as $s) { if (!preg_match('/^[a-z0-9.-]{2,100}$/', $s)) { $errors[] = "Rule $n: an email ending is like .edu or example.com — not $s."; } }
    foreach ($utm as $u) { if (mb_strlen($u) > 100) { $errors[] = "Rule $n: a utm source is up to 100 characters."; } }
    foreach (['source_id' => $sources, 'campaign_id' => $campaigns, 'country' => $countries, 'domain_suffix' => $suffixes, 'utm_source' => $utm] as $k => $v) {
        if ($v !== []) { $m[$k] = $v; }
    }
    $kind = (string) ($row['assign_kind'] ?? $row['kind'] ?? 'member');
    if (!isset(RULE_KINDS[$kind])) { $errors[] = "Rule $n: it assigns to a person, a department's manager or in turn."; $kind = 'member'; }
    $name = trim((string) ($row['name'] ?? ''));
    if ($name === '') { $errors[] = "Rule $n: give it a name."; }
    if (mb_strlen($name) > 80) { $errors[] = "Rule $n: the name is up to 80 characters."; }
    $member = $ints($row['member_id'] ?? $row['member'] ?? [])[0] ?? null;
    $department = $ints($row['department_id'] ?? $row['department'] ?? [])[0] ?? null;
    $members = $ints($row['members'] ?? []);
    $rule = ['id' => ctype_digit((string) ($row['id'] ?? $row['rule'] ?? '')) && (int) ($row['id'] ?? $row['rule']) > 0 ? (int) ($row['id'] ?? $row['rule']) : null, 'name' => $name, 'match' => $m, 'assign_kind' => $kind,
        'member_id' => $kind === 'member' ? $member : null, 'department_id' => $kind === 'department' ? $department : null, 'members' => $kind === 'round_robin' ? $members : [],
        'active' => !in_array(strtolower((string) ($row['active'] ?? 'yes')), ['0', 'no', 'false', 'off'], true), 'position' => isset($row['position']) && is_numeric($row['position']) ? (int) $row['position'] : null];
    if ($kind === 'member' && $member === null) { $errors[] = "Rule $n: choose the person it assigns to."; }
    if ($kind === 'department' && $department === null) { $errors[] = "Rule $n: choose the department."; }
    if ($kind === 'round_robin' && $members === []) { $errors[] = "Rule $n: choose at least one person to take turns."; }
    return [$rule, $errors];
}

/** Replace the list: $rules as rule_from_request() made them (an empty list removes every rule). Answers ['before' => names, 'after' => names, 'removed' => n, 'added' => n]. */
function rules_save(PDO $pdo, array $rules): array
{
    $existing = [];
    foreach (rules($pdo) as $r) { $existing[$r['id']] = $r; }
    $order = $rules;
    usort($order, static fn (array $a, array $b): int => ($a['position'] ?? PHP_INT_MAX) <=> ($b['position'] ?? PHP_INT_MAX));      // stable: ties keep their order in the list
    $keep = [];
    foreach ($order as $i => $r) {
        $n = $i + 1;
        if ($r['member_id'] !== null && (int) one_value($pdo, 'SELECT count(*) FROM mcp_members WHERE member_id = :m', ['m' => $r['member_id']]) === 0) { throw new DomainException("Rule $n: that person does not work here."); }
        if ($r['department_id'] !== null && (int) one_value($pdo, 'SELECT count(*) FROM mcp_departments WHERE department_id = :d AND archived_at IS NULL', ['d' => $r['department_id']]) === 0) { throw new DomainException("Rule $n: that department is not here."); }
        foreach ($r['members'] as $m) { if ((int) one_value($pdo, 'SELECT count(*) FROM mcp_members WHERE member_id = :m', ['m' => $m]) === 0) { throw new DomainException("Rule $n: someone in the turns does not work here."); } }
        foreach ($r['match']['source_id'] ?? [] as $s) { if ((int) one_value($pdo, 'SELECT count(*) FROM lead_sources WHERE id = :s', ['s' => $s]) === 0) { throw new DomainException("Rule $n: there is no source $s."); } }
        foreach ($r['match']['campaign_id'] ?? [] as $c) { if ((int) one_value($pdo, 'SELECT count(*) FROM campaigns WHERE id = :c', ['c' => $c]) === 0) { throw new DomainException("Rule $n: there is no campaign $c."); } }
        if ($r['id'] !== null && !isset($existing[$r['id']])) { throw new DomainException("Rule $n: that rule is not there any more — reload and try again."); }
        if ($r['id'] !== null && isset($keep[$r['id']])) { throw new DomainException("Rule $n: the same rule is listed twice."); }
        $args = ['n' => $r['name'], 'pos' => ($n) * 10, 'a' => $r['active'] ? 't' : 'f', 'm' => json_encode($r['match'] === [] ? new stdClass() : $r['match'], JSON_THROW_ON_ERROR), 'k' => $r['assign_kind'],
                 'mem' => $r['member_id'], 'dep' => $r['department_id'], 'ms' => pg_array_literal($r['members'])];
        if ($r['id'] === null) {
            $st = $pdo->prepare('INSERT INTO assignment_rules (name, position, active, match, assign_kind, member_id, department_id, members) VALUES (:n, :pos, CAST(:a AS boolean), CAST(:m AS jsonb), :k, :mem, :dep, CAST(:ms AS bigint[])) RETURNING id');
            $st->execute($args);
            $keep[(int) $st->fetchColumn()] = true;
        } else {
            $pdo->prepare('UPDATE assignment_rules SET name = :n, position = :pos, active = CAST(:a AS boolean), match = CAST(:m AS jsonb), assign_kind = :k, member_id = :mem, department_id = :dep, members = CAST(:ms AS bigint[]) WHERE id = :id')
                ->execute($args + ['id' => $r['id']]);
            $keep[$r['id']] = true;
        }
    }
    $gone = array_values(array_diff(array_keys($existing), array_keys($keep)));
    if ($gone !== []) {
        $pdo->prepare('DELETE FROM assignment_rules WHERE id = ANY (CAST(:ids AS bigint[]))')->execute(['ids' => pg_array_literal($gone)]);
    }
    return ['before' => array_map(static fn (array $r): string => (string) $r['name'], array_values($existing)), 'after' => array_map(static fn (array $r): string => (string) $r['name'], $order),
        'removed' => count($gone), 'added' => count(array_filter($order, static fn (array $r): bool => $r['id'] === null))];
}
