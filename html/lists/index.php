<?php
declare(strict_types=1);
/** /lists (screen `list-list`; params record_kind, kind, q): my lists and the shared ones — static or smart, of what, how many, whose. JSON: the lists. lists.own. */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_once dirname(__DIR__, 2) . '/app/features/records/queries.php';
require_once dirname(__DIR__, 2) . '/app/features/lists/queries.php';
require_once dirname(__DIR__, 2) . '/app/features/lists/present.php';
require_login();
require_right('lists.own');
$pdo = db();
$f = ['record_kind' => isset(LIST_RECORD_KINDS[$k = request_string('record_kind')]) ? $k : null, 'kind' => isset(LIST_KINDS[$k2 = request_string('kind')]) ? $k2 : null, 'q' => mb_substr(request_string('q'), 0, 120)];
$rows = find_lists($pdo, $f);
log_screen_view($pdo, 'list-list');
if (wants_json()) {
    respond_screen(['filters' => $f, 'lists' => array_map(static fn (array $l): array => present_list($l), $rows)]);
}
$notices = ['deleted' => ['success', 'Deleted the list.'], 'created' => ['success', 'Added the list.']];
render_screen('Lists', view('lists/index.php', ['rows' => $rows, 'filters' => $f, 'me' => (int) current_member_id(), 'notice' => $notices[$_GET['notice'] ?? ''] ?? null]),
    ['activeNav' => 'lists', 'screen' => 'list-list', 'entity' => 'list']);
