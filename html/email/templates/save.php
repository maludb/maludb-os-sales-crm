<?php
declare(strict_types=1);
/**
 * Action `template_save` (log template.save — own, or templates.manage; undo restore prior): `name`, `subject`, `body` (Markdown with merge fields), `kind` (general, intro,
 * follow_up, proposal, meeting, thank_you, renewal, win_back), `is_shared` (yes or no — a shared template needs templates.manage), `template` (to change an existing one).
 * A field left out stays as it was. record_id is the template.
 */
require_once dirname(__DIR__, 3) . '/app/bootstrap.php';
require_once dirname(__DIR__, 3) . '/app/features/templates/queries.php';
require_once dirname(__DIR__, 3) . '/app/features/templates/write.php';
crm_handler_begin();
require_any_right('email.send|email.log|templates.manage');
$pdo = db();
$me = (int) current_member_id();
$id = request_integer('template') ?? request_integer('id');
$t = null;
if ($id !== null) {
    $t = find_email_template($pdo, $id);
    if ($t === null) { refuse(404, 'Template not found.'); }
    if (!email_template_may($t)['edit']) { refuse(403, 'Only ' . ($t['owner_name'] ?: 'its owner') . ', or someone who keeps the shared templates, may change this template.'); }
}
$errors = [];
$cur = $t ?? ['name' => null, 'subject' => null, 'body' => null, 'kind' => 'general', 'is_shared' => false];
$f = [];
$f['name'] = (string) crm_text('name', $cur['name'], 120, 'The name', $errors, true);
$f['subject'] = (string) crm_text('subject', $cur['subject'], 300, 'The subject', $errors, true);
$f['body'] = (string) crm_text('body', $cur['body'], 20000, 'The message', $errors, true);
$f['kind'] = crm_choice('kind', $cur['kind'], array_keys(TEMPLATE_KINDS), 'The kind', $errors, false) ?? 'general';
$f['is_shared'] = crm_yes('is_shared', (bool) $cur['is_shared']);
if ($id === null) {
    foreach (['name', 'subject', 'body'] as $k) { if (!req_has($k) && !isset($errors[$k])) { $errors[$k] = ucfirst($k === 'body' ? 'the message' : 'the ' . $k) . ' is required.'; } }
}
if ($f['is_shared'] !== (bool) $cur['is_shared'] && !has_right('templates.manage')) { $errors['is_shared'] = 'Shared templates are kept by Marketing and the Sales Manager; make it yours, or ask them.'; }
if ($errors !== []) { crm_refuse_fields($errors); }
$id = crm_guard($pdo, static function () use ($pdo, $id, $f, $me, $t): int {
    $pdo->beginTransaction();
    $saved = email_template_write($pdo, $id, $f, $me);
    log_activity($pdo, 'template.save', 'email_template', $saved, ['before' => $t === null ? null : ['name' => $t['name'], 'kind' => $t['kind'], 'is_shared' => (bool) $t['is_shared']],
        'after' => ['name' => $f['name'], 'kind' => $f['kind'], 'is_shared' => $f['is_shared'], 'merge_fields' => merge_fields_in($f['subject'], $f['body'])]]);
    $pdo->commit();
    return $saved;
});
crm_done(($t === null ? 'Saved the template ' : 'Saved the changes to ') . $f['name'], $id, crm_land(return_path('/email/templates'), $t === null ? 'created' : 'updated'), 'templateChanged');
