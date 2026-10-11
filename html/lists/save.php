<?php
declare(strict_types=1);
/**
 * Actions `list_create` (no `list`; log list.create — lists.own; `is_shared` needs lists.shared) and `list_update` (`list` + any field; log list.update — its owner, or lists.shared): name, kind (static or
 * smart — chosen once), record_kind (lead, contact, account or deal — chosen once), filters (a smart list's: an object of the find_* vocabulary, or the form's filter[…]), is_shared, description. The
 * filters are read by the screens' own parsers (filters.php); an unknown key is dropped.
 */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_once dirname(__DIR__, 2) . '/app/features/records/queries.php';
require_once dirname(__DIR__, 2) . '/app/features/lists/queries.php';
require_once dirname(__DIR__, 2) . '/app/features/lists/write.php';
crm_handler_begin();
require_right('lists.own');
$pdo = db();
$me = (int) current_member_id();
$id = request_integer('list') ?? request_integer('id');
$cur = null;
if ($id !== null) {
    $cur = find_list($pdo, $id);
    if ($cur === null) { refuse(404, 'List not found.'); }
    if (!list_may($cur)['edit']) { refuse(403, 'Only ' . ($cur['owner_name'] ?? 'its owner') . ', or someone who keeps shared lists, changes this list.'); }
}
$errors = [];
$name = crm_text('name', $cur['name'] ?? null, 120, 'The name', $errors, true);
if ($name === null && !isset($errors['name'])) { $errors['name'] = 'The name is required.'; }
$kind = crm_choice('kind', $cur['kind'] ?? null, array_keys(LIST_KINDS), 'The kind', $errors, false);
if ($kind === null && !isset($errors['kind'])) { $errors['kind'] = 'Say static or smart.'; }
$rk = crm_choice('record_kind', $cur['record_kind'] ?? null, array_keys(LIST_RECORD_KINDS), 'The record kind', $errors, false);
if ($rk === null && !isset($errors['record_kind'])) { $errors['record_kind'] = 'Say which records: ' . implode(', ', array_keys(LIST_RECORD_KINDS)) . '.'; }
if ($cur !== null && ($kind !== $cur['kind'] || $rk !== $cur['record_kind']) && !isset($errors['kind'], $errors['record_kind'])) {
    $errors['kind'] = 'A list\'s kind and what it holds are chosen once; make a new list instead.';
}
$filters = $cur['filters'] ?? [];
if ($kind === 'smart' && $rk !== null) {
    if (isset($_POST['filter']) && is_array($_POST['filter'])) {
        $filters = filters_parse($rk, $_POST['filter']);
    } elseif (array_key_exists('filters', $_POST)) {
        $raw = $_POST['filters'];
        $given = is_array($raw) ? $raw : (trim((string) $raw) === '' ? [] : filters_decode($raw));
        if (!is_array($given) || (is_string($raw) && trim($raw) !== '' && $given === [] && trim($raw) !== '{}')) { $errors['filters'] = 'The filters are an object of the find_* filters.'; }
        else { $filters = filters_parse($rk, $given); }
    } elseif ($cur === null) {
        $filters = [];
    }
    if ($filters === [] && !isset($errors['filters'])) { $errors['filters'] = 'A smart list needs at least one filter; otherwise it is every ' . RECORD_KINDS[$rk]['noun'] . '.'; }
} elseif ($kind === 'static' && (isset($_POST['filter']) || (req_has('filters') && trim((string) req_val('filters')) !== '' && trim((string) req_val('filters')) !== '{}'))) {
    $errors['filters'] = 'A static list holds the records you put in it; filters belong to a smart list.';
}
$shared = crm_yes('is_shared', $cur === null ? false : (bool) $cur['is_shared']);
if ($shared && !has_right('lists.shared')) { $errors['is_shared'] = 'Sharing a list with everyone needs the right to keep shared lists.'; }
$description = crm_text('description', $cur['description'] ?? null, 1000, 'The description', $errors);
if ($errors !== []) { crm_refuse_fields($errors); }
$f = ['name' => $name, 'kind' => $kind, 'record_kind' => $rk, 'filters' => $filters, 'is_shared' => $shared, 'description' => $description];
$lid = crm_guard($pdo, static function () use ($pdo, $id, $f, $me, $cur): int {
    $pdo->beginTransaction();
    $lid = list_save($pdo, $id, $f, $me);
    log_activity($pdo, $id === null ? 'list.create' : 'list.update', 'list', $lid, ['before' => $cur === null ? null : ['name' => $cur['name'], 'is_shared' => (bool) $cur['is_shared'], 'filters' => $cur['filters']],
        'after' => ['name' => $f['name'], 'kind' => $f['kind'], 'record_kind' => $f['record_kind'], 'is_shared' => $f['is_shared'], 'filters' => $f['kind'] === 'smart' ? $f['filters'] : null]]);
    $pdo->commit();
    return $lid;
});
crm_done(($id === null ? 'Added the list ' : 'Saved the list ') . $name, $lid, crm_land(return_path('/lists/' . $lid), $id === null ? 'created' : 'updated'), 'listChanged');
