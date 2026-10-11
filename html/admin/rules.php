<?php
declare(strict_types=1);
/**
 * /admin/rules (screen `admin-rules`; assignment.rules): the lead assignment rules in order — the first active rule a lead matches decides its owner — as ONE form (Save replaces the list), a blank rule to add, and a test box that
 * names the rule a lead's fields would match without assigning anything. JSON: the rules and the choices.
 */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_once dirname(__DIR__, 2) . '/app/features/rules/write.php';
require_once dirname(__DIR__, 2) . '/app/features/admin/present.php';
require_login();
require_right('assignment.rules');
$pdo = db();
$rules = rules($pdo);
$sources = $pdo->query('SELECT id, name FROM lead_sources WHERE active ORDER BY sort_order, name')->fetchAll();
log_screen_view($pdo, 'admin-rules');
if (wants_json()) {
    respond_screen(['rules' => array_map('present_rule', $rules), 'kinds' => RULE_KINDS, 'sources' => array_map(static fn (array $s): array => ['source_id' => (int) $s['id'], 'name' => $s['name']], $sources)]);
}
$notices = ['saved' => ['success', 'Saved the rules.']];
render_screen('Assignment rules', view('admin/rules.php', ['rules' => $rules, 'sources' => $sources, 'departments' => find_live_departments($pdo), 'notice' => crm_notice($_GET['notice'] ?? null, $notices)]),
    ['activeNav' => 'admin', 'screen' => 'admin-rules', 'entity' => 'assignment_rule']);
