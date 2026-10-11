<?php
declare(strict_types=1);
/**
 * /admin/fields?kind=&field= (screen `admin-fields`; fields.manage): the custom fields of one record kind (lead, account, contact, deal, activity) and the form that adds one — or, with `field`, changes it. Every form of the
 * kind reads these on its next render; the database refuses a value of the wrong type. JSON: the kind's fields and the choices.
 */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_once dirname(__DIR__, 2) . '/app/features/admin/present.php';
require_login();
require_right('fields.manage');
$pdo = db();
$kind = isset(FIELD_KINDS[$k = request_string('kind')]) ? $k : 'lead';
$list = fields($pdo, $kind);
$livePipelines = $kind === 'deal' ? array_map(static fn (array $p): array => ['pipeline_id' => $p['id'], 'name' => $p['name']], pipelines_with_stages($pdo, false)) : [];
$edit = null;
if (($fid = request_integer('field')) !== null) {
    $edit = array_values(array_filter($list, static fn (array $f): bool => $f['id'] === $fid))[0] ?? null;
}
log_screen_view($pdo, 'admin-fields');
if (wants_json()) {
    respond_screen(['kind' => $kind, 'fields' => array_map('present_field', $list), 'kinds' => FIELD_KINDS, 'types' => FIELD_TYPES,
        'pipelines' => $livePipelines]);
}
$counts = [];
foreach (array_keys(FIELD_KINDS) as $kk) { $counts[$kk] = (int) one_value($pdo, 'SELECT count(*) FROM custom_field_defs WHERE applies_to = :k AND archived_at IS NULL', ['k' => $kk]); }
$notices = ['saved' => ['success', 'Saved the field.'], 'created' => ['success', 'Added the field. It is on the next form of its kind.']];
render_screen('Custom fields', view('admin/fields.php', ['kind' => $kind, 'list' => $list, 'edit' => $edit, 'counts' => $counts, 'pipelines' => $livePipelines, 'notice' => crm_notice($_GET['notice'] ?? null, $notices)]),
    ['activeNav' => 'admin', 'screen' => 'admin-fields', 'entity' => 'custom_field']);
