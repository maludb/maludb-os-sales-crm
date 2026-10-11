<?php
declare(strict_types=1);
/**
 * Action `template_delete` (log template.delete — own, or templates.manage; PAUSES FOR AN AGENT — deletion; confirm in the page): `template`. A template never used is deleted;
 * one that has been used is ARCHIVED — out of the lists and the picker, still named by the emails sent with it.
 */
require_once dirname(__DIR__, 3) . '/app/bootstrap.php';
require_once dirname(__DIR__, 3) . '/app/features/templates/queries.php';
require_once dirname(__DIR__, 3) . '/app/features/templates/write.php';
crm_handler_begin();
$pdo = db();
$id = request_integer('template') ?? request_integer('id');
$t = $id === null ? null : find_email_template($pdo, $id);
if ($t === null) { refuse(404, 'Template not found.'); }
if (!email_template_may($t)['delete']) { refuse(403, 'Only ' . ($t['owner_name'] ?: 'its owner') . ', or someone who keeps the shared templates, may delete this template.'); }
$how = crm_guard($pdo, static function () use ($pdo, $id, $t): string {
    $pdo->beginTransaction();
    $how = email_template_remove($pdo, $id);
    log_activity($pdo, 'template.delete', 'email_template', $id, ['before' => ['name' => $t['name'], 'kind' => $t['kind'], 'is_shared' => (bool) $t['is_shared'], 'usage_count' => (int) $t['usage_count']], 'after' => ['outcome' => $how]]);
    $pdo->commit();
    return $how;
});
crm_done(($how === 'archived' ? 'Archived the template ' : 'Deleted the template ') . $t['name'] . ($how === 'archived' ? ' — it was used, so the emails sent with it still name it' : ''), $id, crm_land(return_path('/email/templates'), $how), 'templateChanged');
