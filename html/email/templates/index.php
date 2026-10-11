<?php
declare(strict_types=1);
/**
 * /email/templates (screen `template-list`; the `find_templates` resolver): the email templates the caller may use — the business's shared ones and their own — as cards with the
 * kind, the subject, who keeps it and how often it was used; Use (opens compose with it) · Edit · Delete by right; New. Filters q, kind, mine. JSON: the rows with their merge fields.
 */
require_once dirname(__DIR__, 3) . '/app/bootstrap.php';
require_once dirname(__DIR__, 3) . '/app/features/templates/queries.php';
require_any_right('email.send|email.log|templates.manage');
$pdo = db();
$f = ['q' => mb_substr(request_string('q'), 0, 120), 'kind' => isset(TEMPLATE_KINDS[request_string('kind')]) ? request_string('kind') : null, 'mine' => request_bool('mine')];
$list = find_email_templates($pdo, $f);
log_screen_view($pdo, 'template-list');
if (wants_json()) {
    respond_screen(['rows' => array_map('present_email_template', $list['rows']), 'total' => $list['total'], 'kinds' => array_keys(TEMPLATE_KINDS), 'merge_fields' => array_keys(MERGE_FIELDS)]);
}
render_screen('Email templates', view('templates/index.php', ['rows' => $list['rows'], 'total' => $list['total'], 'f' => $f, 'me' => (int) current_member_id(), 'may' => ['use' => has_right('email.send'), 'manage' => has_right('templates.manage')],
    'notice' => crm_notice($_GET['notice'] ?? null, ['created' => ['success', 'Saved the template.'], 'updated' => ['success', 'Saved the template.'], 'deleted' => ['success', 'Deleted the template.'], 'archived' => ['success', 'Archived: it was used, so it is kept for the emails sent with it, out of the lists.']])]),
    ['activeNav' => 'email', 'screen' => 'template-list', 'entity' => 'template']);
