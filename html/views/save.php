<?php
declare(strict_types=1);
/**
 * Action `saved_view_save` (log view.save — my own; a shared one needs reports.team): name, record_kind, filters (an object — the screen's), sort_by, sort_dir, columns[],
 * layout, pipeline, scope (personal or global), saved_view (to change one). Lands on the view run (its screen with ?view=) unless return_to says otherwise.
 */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_once dirname(__DIR__, 2) . '/app/features/deals/queries.php';
require_once dirname(__DIR__, 2) . '/app/features/views/queries.php';
require_once dirname(__DIR__, 2) . '/app/features/views/write.php';
crm_handler_begin();
require_right('records.read');
$pdo = db();
$id = request_integer('saved_view');
$cur = null;
if ($id !== null) {
    $cur = find_saved_view($pdo, $id);
    if ($cur === null) { refuse(404, 'Saved view not found.'); }
    if (!saved_view_mine($cur)) { refuse(403, $cur['is_system'] ? 'A built-in view is not changed; save a copy under another name.' : 'Only its owner — or a manager, for a shared one — changes this view.'); }
}
$errors = [];
$name = crm_text('name', $cur['name'] ?? null, 80, 'The name', $errors, true);
if ($name === null && !isset($errors['name'])) { $errors['name'] = 'The name is required.'; }
$kind = crm_choice('record_kind', $cur['record_kind'] ?? null, array_keys(SAVED_VIEW_KINDS), 'The kind', $errors, false);
if ($kind === null && !isset($errors['record_kind'])) { $errors['record_kind'] = 'Say which records: ' . implode(', ', array_keys(SAVED_VIEW_KINDS)) . '.'; }
$scope = crm_choice('scope', $cur['scope'] ?? 'personal', ['personal', 'global'], 'Who sees it', $errors, false) ?? 'personal';
if ($scope === 'global' && !has_right('reports.team')) { $errors['scope'] = 'Only a manager shares a view with everyone.'; }
$filters = $cur['filters'] ?? [];
if (array_key_exists('filters', $_POST)) {
    $raw = $_POST['filters'];
    $given = is_array($raw) ? $raw : json_decode((string) $raw, true);
    if (!is_array($given) || array_is_list($given) && $given !== []) { $errors['filters'] = 'The filters are an object of the screen\'s filters.'; }
    else { $filters = array_filter($given, static fn ($v) => $v !== null && $v !== '' && $v !== false); }
}
if (strlen((string) json_encode($filters)) > 4000) { $errors['filters'] = 'Those filters are too long to save.'; }
if (($p = request_integer('pipeline')) !== null) {
    if (one_value($pdo, 'SELECT 1 FROM mcp_pipelines WHERE pipeline_id = :p', ['p' => $p]) === null) { $errors['pipeline'] = 'That pipeline is not here.'; } else { $filters['pipeline'] = $p; }
}
$layout = crm_choice('layout', $cur['layout'] ?? 'list', array_keys(SAVED_VIEW_LAYOUTS), 'The layout', $errors, false) ?? 'list';
$sortBy = crm_text('sort_by', $cur['sort_by'] ?? 'updated_at', 40, 'The sort', $errors) ?? 'updated_at';
if (!preg_match('/^[a-z_]{1,40}$/', $sortBy)) { $errors['sort_by'] = 'The sort is a column name.'; }
$sortDir = crm_choice('sort_dir', $cur['sort_dir'] ?? 'desc', ['asc', 'desc'], 'The direction', $errors, false) ?? 'desc';
$columns = request_list('columns') ?? ($cur['columns'] ?? []);
if ($kind === 'deal') { $columns = array_values(array_intersect($columns, array_keys(DEAL_LIST_COLUMNS))); }
$columns = array_slice(array_values(array_filter($columns, static fn ($c) => preg_match('/^[a-z_]{1,30}$/', $c))), 0, 20);
if ($columns === []) { $columns = $kind === 'deal' ? DEAL_LIST_DEFAULT_COLUMNS : ['name']; }
if ($errors !== []) { crm_refuse_fields($errors); }
$f = ['name' => $name, 'record_kind' => $kind, 'scope' => $scope, 'filters' => $filters, 'layout' => $layout, 'sort_by' => $sortBy, 'sort_dir' => $sortDir, 'columns' => $columns];
$vid = crm_guard($pdo, static function () use ($pdo, $id, $f, $cur): int {
    $pdo->beginTransaction();
    $vid = saved_view_save($pdo, $id, $f, (int) current_member_id());
    log_activity($pdo, 'view.save', 'saved_view', $vid, ['before' => $cur === null ? null : ['name' => $cur['name'], 'scope' => $cur['scope'], 'filters' => $cur['filters']], 'after' => ['name' => $f['name'], 'record_kind' => $f['record_kind'], 'scope' => $f['scope'], 'layout' => $f['layout'], 'filters' => $f['filters']]]);
    $pdo->commit();
    return $vid;
});
$saved = find_saved_view($pdo, $vid);
$land = $id === null ? saved_view_url($pdo, $saved) : '/views';                      // a new view runs; a changed one is back among the views
crm_done('Saved the view ' . $name, $vid, crm_land($land, $id !== null ? 'saved' : 'view_saved'), 'viewChanged');
