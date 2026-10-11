<?php
declare(strict_types=1);
/**
 * /lists/new and /lists/{id}/edit (screens `list-add`, `list-edit`; prefill params record_kind, kind, filters — a JSON object or a query string, e.g. the screen's own filters). lists.own. A smart list's
 * filter builder is the find_* vocabulary as form fields; ?preview=1 (HTMX, on change) answers the live count alone. The kind and the record kind are chosen once and kept.
 */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_once dirname(__DIR__, 2) . '/app/features/records/queries.php';
require_once dirname(__DIR__, 2) . '/app/features/lists/queries.php';
require_login();
require_right('lists.own');
$pdo = db();
$id = request_integer('id');
$l = null;
if ($id !== null) {
    $l = find_list($pdo, $id) ?? null;
    if ($l === null) { refuse(404, 'List not found.'); }
    if (!list_may($l)['edit']) { refuse(403, 'Only ' . ($l['owner_name'] ?? 'its owner') . ', or someone who keeps shared lists, changes this list.'); }
    $values = ['name' => $l['name'], 'kind' => $l['kind'], 'record_kind' => $l['record_kind'], 'filters' => $l['filters'], 'is_shared' => (bool) $l['is_shared'], 'description' => $l['description']];
} else {
    $rk = isset(LIST_RECORD_KINDS[$k = request_string('record_kind')]) ? $k : 'contact';
    $values = ['name' => mb_substr(request_string('name'), 0, 200), 'kind' => isset(LIST_KINDS[$k2 = request_string('kind')]) ? $k2 : (request_string('filters') !== '' ? 'smart' : 'static'), 'record_kind' => $rk,
               'filters' => filters_parse($rk, filters_decode($_GET['filters'] ?? '')), 'is_shared' => false, 'description' => null];
}
if (request_string('preview') === '1') {                                              // the live count as the builder's fields stand (Pattern A; nothing logged)
    header('Vary: HX-Request');
    $rk = isset(LIST_RECORD_KINDS[$k = request_string('record_kind')]) ? $k : $values['record_kind'];
    $compact = filters_parse($rk, is_array($_GET['filter'] ?? null) ? $_GET['filter'] : []);
    echo view('lists/partials/preview.php', ['n' => filters_find($pdo, $rk, $compact, 1)['total'], 'kind' => $rk]);
    exit;
}
$screen = $id === null ? 'list-add' : 'list-edit';
log_screen_view($pdo, $screen);
if (wants_json()) {
    respond_screen(['list_id' => $id, 'values' => $values, 'kinds' => LIST_KINDS, 'record_kinds' => LIST_RECORD_KINDS,
        'vocabulary' => array_map(static fn (array $v): array => ['label' => $v[0], 'type' => $v[1], 'options' => is_array($v[2]) ? $v[2] : null], filter_vocabulary($values['record_kind'])), 'may' => ['share' => has_right('lists.shared')]]);
}
render_screen($id === null ? 'New list' : 'Edit ' . $l['name'], view('lists/form.php', ['l' => $l, 'values' => $values, 'may' => ['share' => has_right('lists.shared')]]),
    ['activeNav' => 'lists', 'screen' => $screen, 'entity' => 'list', 'recordId' => $id === null ? '' : (string) $id]);
