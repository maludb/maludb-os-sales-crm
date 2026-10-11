<?php
declare(strict_types=1);
/** /admin/sources (screen `admin-sources`; sources.manage): the lead sources and the lost reasons (leads' and deals') as two lists with an inline add — order, active, what names each. JSON: the same, whitelisted. */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_once dirname(__DIR__, 2) . '/app/features/vocab/write.php';
require_once dirname(__DIR__, 2) . '/app/features/admin/present.php';
require_login();
require_right('sources.manage');
$pdo = db();
$v = vocab($pdo);
log_screen_view($pdo, 'admin-sources');
if (wants_json()) {
    respond_screen(['sources' => array_map('present_source', $v['sources']), 'reasons' => array_map('present_reason', $v['reasons'])]);
}
$notices = ['saved' => ['success', 'Saved.'], 'created' => ['success', 'Added.'], 'deleted' => ['success', 'Deleted.']];
render_screen('Sources and reasons', view('admin/sources.php', ['v' => $v, 'notice' => crm_notice($_GET['notice'] ?? null, $notices)]), ['activeNav' => 'admin', 'screen' => 'admin-sources', 'entity' => 'lead_source']);
