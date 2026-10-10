<?php
declare(strict_types=1);
/**
 * /views (screen `saved-views`; param edit = a view to change in place): my saved views and the shared ones — the name, the kind, the filters as chips, the layout;
 * Run · Edit · Delete (my own; a shared one with reports.team; never a system view). JSON: the views.
 */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_once dirname(__DIR__, 2) . '/app/features/deals/queries.php';
require_once dirname(__DIR__, 2) . '/app/features/views/queries.php';
require_login();
require_right('records.read');
$pdo = db();
$views = my_saved_views($pdo, null);
log_screen_view($pdo, 'saved-views');
if (wants_json()) {
    respond_screen(['views' => array_map(static fn (array $v): array => ['saved_view_id' => $v['saved_view_id'], 'name' => $v['name'], 'scope' => $v['scope'], 'record_kind' => $v['record_kind'], 'layout' => $v['layout'],
        'filters' => $v['filters'] === [] ? (object) [] : $v['filters'], 'columns' => $v['columns'], 'sort_by' => $v['sort_by'], 'is_system' => (bool) $v['is_system'], 'mine' => saved_view_mine($v), 'url' => saved_view_url($pdo, $v)], $views)]);
}
$edit = request_integer('edit');
render_screen('Saved views', view('views/index.php', ['views' => $views, 'edit' => $edit, 'may_share' => has_right('reports.team'),
    'notice' => crm_notice($_GET['notice'] ?? null, ['saved' => ['success', 'Saved the view.'], 'deleted' => ['success', 'Deleted the view.']])]), ['activeNav' => 'pipeline', 'screen' => 'saved-views']);
