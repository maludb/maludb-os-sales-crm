<?php
declare(strict_types=1);
/**
 * /admin/rules/test (a GET partial of screen `admin-rules`; assignment.rules): a lead's fields — source, campaign, country, email, utm source — in; the rule that would take it, and who, out. crm_assign_lead_dry() answers: nothing is
 * assigned, no round-robin turn moves, no lead is made. Over HTMX it is the result box alone; a browser without scripts gets a small page; JSON gets {matched, rule}.
 */
require_once dirname(__DIR__, 3) . '/app/bootstrap.php';
require_once dirname(__DIR__, 3) . '/app/features/rules/test.php';
require_once dirname(__DIR__, 3) . '/app/features/rules/queries.php';
require_login();
require_right('assignment.rules');
$pdo = db();
$f = ['source_id' => ctype_digit(request_string('source')) ? (int) request_string('source') : null, 'campaign_id' => ctype_digit(request_string('campaign')) ? (int) request_string('campaign') : null,
      'country' => strtoupper(mb_substr(request_string('country'), 0, 2)) ?: null, 'email' => mb_substr(request_string('email'), 0, 254) ?: null, 'utm_source' => mb_substr(request_string('utm_source'), 0, 100) ?: null];
if ($f['email'] !== null && !preg_match('/^[^@\s]+@[^@\s]+\.[^@\s]+$/', $f['email'])) {
    refuse(422, 'The email is an address like pat@acme.com.');
}
$hit = rule_test($pdo, $f);
log_screen_view($pdo, 'admin-rules');
if (wants_json()) {
    respond_screen(['matched' => $hit !== null, 'rule' => $hit === null ? null : ['rule_id' => $hit['rule_id'], 'name' => $hit['rule_name'], 'position' => $hit['position'], 'assign_kind' => $hit['assign_kind'],
        'owner_member_id' => $hit['owner_member_id'], 'owner_name' => $hit['owner_name']]]);
}
$html = view('admin/partials/rule-test.php', ['hit' => $hit]);
if (is_htmx_request()) {
    header('Vary: HX-Request');
    echo $html;
    exit;
}
render_screen('Test a lead', view('shared/header.php', ['id' => 'rule-test-page', 'title' => 'Test a lead', 'crumbs' => [['Today', '/'], ['Admin', '/admin'], ['Assignment rules', '/admin/rules'], ['Test', null]], 'back' => ['/admin/rules', 'the rules']])
    . '<div class="main-content">' . $html . '</div>', ['activeNav' => 'admin', 'screen' => 'admin-rules', 'entity' => 'assignment_rule']);
