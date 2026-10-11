<?php
declare(strict_types=1);

/** export_create: the row that the worker's `exports` pass builds. Nothing is read here but the validity of what was asked; the file is made as the maker, later. */
require_once __DIR__ . '/queries.php';

/**
 * Queue an export. $f: kind (leads|contacts|accounts|deals|activities|list), format (csv|json), list (an id, for kind list), filters (an object — the find_* vocabulary). Rights: export.own to
 * export what you see of a filter or a list; export.shared to export every record of a kind (no filters at all). A DomainException says what is wrong in words. Answers the export's id.
 */
function export_create(PDO $pdo, array $f, int $by): int
{
    $kind = (string) ($f['kind'] ?? '');
    if ($kind === 'report') { throw new DomainException('A report is exported from its own page.'); }
    if (!isset(EXPORT_KINDS[$kind])) { throw new DomainException('Say what to export: ' . implode(', ', array_diff(array_keys(EXPORT_KINDS), ['report'])) . '.'); }
    $format = (string) ($f['format'] ?? 'csv');
    if (!isset(EXPORT_FORMATS[$format])) { throw new DomainException('The format is csv or json.'); }
    $params = [];
    $listId = null;
    if ($kind === 'list') {
        $listId = (int) ($f['list'] ?? 0);
        $l = $listId > 0 ? find_list($pdo, $listId) : null;
        if ($l === null) { throw new DomainException('Not found.'); }
        $params = ['list_id' => $listId, 'record_kind' => $l['record_kind']];
    } else {
        if (($f['list'] ?? null) !== null) { throw new DomainException('To export a list, say kind list.'); }
        $compact = filters_parse(EXPORT_RECORD_KIND[$kind], filters_decode($f['filters'] ?? []));
        if ($compact === [] && $kind !== 'activities' && !has_right('export.shared')) {
            throw new DomainException('Exporting every ' . EXPORT_RECORD_KIND[$kind] . ' needs the right to export everything; add a filter, or export a list.');
        }
        $params = ['filters' => (object) $compact];
        // activities are the caller's own unless a filter says whose (their parser decides); the count is checked where the file is built
        $n = filters_find($pdo, EXPORT_RECORD_KIND[$kind], $compact, 1)['total'];
        if ($n > EXPORT_MAX_ROWS) { throw new DomainException('That is ' . number_format($n) . ' rows and an export holds up to ' . number_format(EXPORT_MAX_ROWS) . '. Narrow the filters.'); }
    }
    $st = $pdo->prepare("INSERT INTO exports (kind, format, status, created_by, params, list_id) VALUES (:k, :f, 'queued', :by, CAST(:p AS jsonb), :l) RETURNING id");
    $st->execute(['k' => $kind, 'f' => $format, 'by' => $by, 'p' => json_encode($params, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), 'l' => $listId]);
    return (int) $st->fetchColumn();
}
