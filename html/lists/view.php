<?php
declare(strict_types=1);
/**
 * /lists/{id} (screen `list-view`; param page): one list's members as the record kind's cards (deals as rows) with the bulk bar; a smart list shows its filters as chips, Edit filters and its live count.
 * The members come through the kind's walled view: what the reader may not see is not there, and the live count says so.
 */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_once dirname(__DIR__, 2) . '/app/features/records/queries.php';
require_once dirname(__DIR__, 2) . '/app/features/lists/queries.php';
require_once dirname(__DIR__, 2) . '/app/features/lists/present.php';
require_once dirname(__DIR__, 2) . '/app/features/bulk/write.php';
require_once dirname(__DIR__, 2) . '/app/features/bulk/select.php';
require_login();
require_right('lists.own');
$pdo = db();
$id = request_integer('id');
$l = $id === null ? null : find_list($pdo, $id);
if ($l === null) { refuse(404, 'List not found.'); }
$page = max(1, request_integer('page') ?? 1);
$result = list_rows($pdo, $id, $page);
$may = list_may($l) + ['bulk' => has_right('records.bulk') || has_right('tags.apply') || has_right('campaigns.manage') || has_right('lists.own'), 'export' => has_right('export.own')];
log_screen_view($pdo, 'list-view');
if (wants_json()) {
    respond_screen(['list' => present_list($l, $result['total']), 'page' => $page, 'total' => $result['total'], 'more' => $page * filters_page($l['record_kind']) < $result['total'],
        'members' => array_map(static fn (array $r): array => present_list_member($l['record_kind'], $r), $result['rows']), 'may' => $may]);
}
$notices = ['updated' => ['success', 'Saved.'], 'created' => ['success', 'Added the list.'], 'added' => ['success', 'Added to the list.'], 'removed' => ['success', 'Taken out of the list.']];
render_screen($l['name'], view('lists/view.php', ['l' => $l, 'result' => $result, 'page' => $page, 'may' => $may, 'chips' => $l['kind'] === 'smart' ? filters_words($pdo, $l['record_kind'], $l['filters']) : [],
    'notice' => $notices[$_GET['notice'] ?? ''] ?? null, 'back' => here_url(), 'bulk_fields' => bulk_field_choices($pdo, $l['record_kind']), 'bulk_list_kind' => $l['record_kind'], 'currency' => base_currency($pdo)]),
    ['activeNav' => 'lists', 'screen' => 'list-view', 'entity' => 'list', 'recordId' => (string) $id]);
