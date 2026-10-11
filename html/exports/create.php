<?php
declare(strict_types=1);
/**
 * Action `export_create` (log export.create — export.own; export.shared for every record of a kind): `kind` (leads, contacts, accounts, deals, activities or list), `format` (csv or json), `list` (for kind list),
 * `filters` (the find_* vocabulary, as an object — or the bulk bar's JSON). The worker builds the file as the caller within a minute or two; /exports shows when it is ready. Nothing is built here.
 */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_once dirname(__DIR__, 2) . '/app/features/records/queries.php';
require_once dirname(__DIR__, 2) . '/app/features/exports/write.php';
crm_handler_begin();
require_right('export.own');
$pdo = db();
$me = (int) current_member_id();
$errors = [];
$kind = (string) (req_val('kind') ?? '');
if (($plural = array_search($kind, EXPORT_RECORD_KIND, true)) !== false) {           // the bulk bar names the record kind (lead, contact …): a list's page means that list, a screen means its kind
    $kind = (req_val('list') ?? '') !== '' ? 'list' : $plural;
}
if (!isset(EXPORT_KINDS[$kind])) { $errors['kind'] = 'Say what to export: leads, contacts, accounts, deals, activities or list.'; }
$format = crm_choice('format', 'csv', array_keys(EXPORT_FORMATS), 'The format', $errors, false) ?? 'csv';
if ($errors !== []) { crm_refuse_fields($errors); }
$f = ['kind' => $kind, 'format' => $format, 'list' => req_val('list') === '' ? null : req_val('list'), 'filters' => $_POST['filters'] ?? []];
$id = crm_guard($pdo, static function () use ($pdo, $f, $me): int {
    $pdo->beginTransaction();
    $id = export_create($pdo, $f, $me);
    log_activity($pdo, 'export.create', 'export', $id, ['after' => ['kind' => $f['kind'], 'format' => $f['format'], 'list_id' => $f['list'] === null ? null : (int) $f['list'], 'filters' => filters_decode($f['filters'])]]);
    $pdo->commit();
    return $id;
});
crm_done('Queued the ' . strtoupper($format) . ' export — ready in a minute or two', $id, crm_land('/exports', 'queued'), 'exportChanged');
