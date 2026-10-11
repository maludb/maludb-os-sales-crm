<?php
declare(strict_types=1);
/** /admin/settings (screen `admin-settings`; settings.manage): the application's settings in seven groups — the business, the pipeline, duplicates, reminders and the next step, imports and bulk changes, the API, records — one form with a Save. JSON: every setting by name, its group, kind and bounds. */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_once dirname(__DIR__, 2) . '/app/features/settings/admin_write.php';
require_once dirname(__DIR__, 2) . '/app/features/admin/present.php';
require_login();
require_right('settings.manage');
$pdo = db();
$row = settings($pdo);
log_screen_view($pdo, 'admin-settings');
if (wants_json()) {
    $catalogue = [];
    foreach (SETTINGS_FIELDS as $name => [$group, $kind, $label]) { $catalogue[$name] = ['group' => $group, 'kind' => $kind, 'label' => $label] + (settings_choices($name) === [] ? [] : ['choices' => array_keys(settings_choices($name))]); }
    respond_screen(['settings' => present_settings($row), 'catalogue' => $catalogue, 'groups' => SETTINGS_GROUPS, 'updated_at' => json_ts($row['updated_at'] ?? null)]);
}
$pipelines = pipelines_with_stages($pdo, false);
render_screen('Settings', view('admin/settings.php', ['row' => $row, 'pipelines' => $pipelines, 'notice' => crm_notice($_GET['notice'] ?? null, ['saved' => ['success', 'Saved the settings.'], 'nothing' => ['info', 'Nothing had changed.']])]),
    ['activeNav' => 'admin', 'screen' => 'admin-settings', 'entity' => 'settings']);
