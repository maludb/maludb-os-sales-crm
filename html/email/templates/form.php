<?php
declare(strict_types=1);
/** /email/templates/new and /email/templates/{id}/edit (screens `template-add`, `template-edit`): a template — name, kind, subject and body (Markdown) with the merge fields listed, shared (templates.manage) or mine. */
require_once dirname(__DIR__, 3) . '/app/bootstrap.php';
require_once dirname(__DIR__, 3) . '/app/features/templates/queries.php';
require_login();
$pdo = db();
$id = request_integer('id');
$t = null;
if ($id !== null) {
    $t = find_email_template($pdo, $id);
    if ($t === null) { refuse(404, 'Template not found.'); }
    if (!email_template_may($t)['edit']) { refuse(403, 'Only ' . ($t['owner_name'] ?: 'its owner') . ', or someone who keeps the shared templates, may change this template.'); }
} else {
    require_any_right('email.send|email.log|templates.manage');
}
$values = $t ?? ['template_id' => null, 'name' => '', 'subject' => '', 'body' => '', 'kind' => 'general', 'is_shared' => false, 'owner_member_id' => (int) current_member_id(), 'usage_count' => 0, 'archived_at' => null];
$screen = $id === null ? 'template-add' : 'template-edit';
log_screen_view($pdo, $screen);
if (wants_json()) {
    respond_screen(['template' => $t === null ? null : present_email_template($t), 'kinds' => TEMPLATE_KINDS, 'merge_fields' => MERGE_FIELDS, 'may' => email_template_may($t)]);
}
render_screen($id === null ? 'New template' : 'Edit ' . $t['name'], view('templates/form.php', ['t' => $t, 'values' => $values, 'may' => email_template_may($t)]),
    ['activeNav' => 'email', 'screen' => $screen, 'entity' => 'template', 'recordId' => $id === null ? '' : (string) $id]);
