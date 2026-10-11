<?php
declare(strict_types=1);
/**
 * Action `assignment_rules_save` (log rules.save {before, after: the rules' names, added, removed}; pauses for an agent as `other`; assignment.rules): `rules[]` — the WHOLE ordered list; the lead inbox and every new lead follow it.
 * A form sends rules[n][name|position|active|source[]|campaign[]|country|domain_suffix|utm_source|assign_kind|member|department|members[]|remove]; an agent sends rules as a JSON list of {id?, name, match: {source_id[], campaign_id[],
 * country[], domain_suffix[], utm_source[]}, assign_kind, member_id | department_id | members[], active, position}. A rule with the `id` of one that exists keeps its turn; one without is new; one left out is removed.
 */
require_once dirname(__DIR__, 3) . '/app/bootstrap.php';
require_once dirname(__DIR__, 3) . '/app/features/rules/write.php';
crm_handler_begin();
require_right('assignment.rules');
$pdo = db();
$raw = $_POST['rules'] ?? null;
if (is_string($raw)) {
    $raw = json_decode($raw, true);
}
if (!is_array($raw)) {
    crm_refuse_fields(['rules' => 'Send the whole ordered list as rules[].']);
}
$rules = [];
$errors = [];
$filled = static function (array $row): bool {
    foreach (['name', 'country', 'domain_suffix', 'utm_source', 'member', 'member_id', 'department', 'department_id'] as $k) { if (trim((string) ($row[$k] ?? '')) !== '') { return true; } }
    foreach (['source', 'campaign', 'members', 'match'] as $k) { if (is_array($row[$k] ?? null) && array_filter($row[$k], static fn ($v) => is_array($v) ? $v !== [] : trim((string) $v) !== '') !== []) { return true; } }
    return false;
};
foreach ($raw as $row) {
    if (!is_array($row) || in_array(strtolower((string) ($row['remove'] ?? '')), ['1', 'yes', 'true', 'on'], true) || !$filled($row)) { continue; }
    [$rule, $errs] = rule_from_request($row, count($rules) + 1);
    $errors = array_merge($errors, $errs);
    $rules[] = $rule;
}
if ($errors !== []) {
    crm_refuse_fields(['rules' => implode(' ', $errors)]);
}
$r = crm_guard($pdo, static function () use ($pdo, $rules): array {
    $pdo->beginTransaction();
    $r = rules_save($pdo, $rules);
    log_activity($pdo, 'rules.save', 'assignment_rule', null, ['before' => ['rules' => $r['before']], 'after' => ['rules' => $r['after'], 'added' => $r['added'], 'removed' => $r['removed']]]);
    $pdo->commit();
    return $r;
});
crm_done('Saved ' . count($rules) . ' assignment rule' . (count($rules) === 1 ? '' : 's'), count($rules), crm_land('/admin/rules', 'saved'), 'rulesChanged');
