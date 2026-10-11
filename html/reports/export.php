<?php
declare(strict_types=1);
/**
 * Action `report_export` (log report.export — export.own; a seller exports what the report shows them, which is their own rows on the team reports): `report` (funnel, win_rate, cycle_time, sources,
 * campaigns, activity, leaderboard, lost_reasons or forecast) and its filters (from, to, by, pipeline, owner, status, team, month, months). The report is built AS THE CALLER, so the file holds what
 * the screen shows. A small report (under 2,000 rows) downloads at once — this POST answers the CSV as an attachment with nosniff and a sandbox CSP, formula cells made plain — and is
 * recorded as a finished, downloaded export; a larger one, `later=1`, or a tool's call (JSON) is queued as an export (kind report) for the worker, ready on /exports.
 */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_once dirname(__DIR__, 2) . '/app/features/reports/present.php';
crm_handler_begin();
require_right('records.read');
require_right('export.own');
$pdo = db();
$me = (int) current_member_id();
$name = report_name((string) (req_val('report') ?? ''));
if ($name === null) {
    crm_refuse_fields(['report' => 'Say which report: ' . implode(', ', array_keys(REPORTS)) . '.']);
}
$filters = report_filters_from_request($pdo, $name);
$later = crm_yes('later');
$r = report($pdo, $name, $filters);
$count = count($r['rows']);
$after = ['report' => $name, 'filters' => array_filter($filters, static fn ($v): bool => $v !== null), 'rows' => $count];
if ($later || wants_json() || $count >= REPORT_INLINE_ROWS) {
    $id = crm_guard($pdo, static function () use ($pdo, $name, $filters, $me, $after): int {
        $pdo->beginTransaction();
        $id = report_export($pdo, $name, $filters, $me);
        log_activity($pdo, 'report.export', 'export', $id, ['after' => $after + ['mode' => 'queued']]);
        $pdo->commit();
        return $id;
    });
    crm_done('Queued the ' . $r['title'] . ' report — ready in a minute or two', $id, crm_land('/exports', 'queued'), 'exportChanged', ['rows' => $count]);
}
$csv = report_csv($r);
$st = $pdo->prepare("INSERT INTO exports (kind, format, status, created_by, params, byte_size, item_count, finished_at, downloaded_at) VALUES ('report', 'csv', 'done', :by, CAST(:p AS jsonb), :b, :n, now(), now()) RETURNING id");
$st->execute(['by' => $me, 'p' => json_encode(report_export_params($name, $filters), JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), 'b' => strlen($csv), 'n' => $count]);
$id = (int) $st->fetchColumn();
log_activity($pdo, 'report.export', 'export', $id, ['after' => $after + ['mode' => 'download', 'byte_size' => strlen($csv)]]);
emit_action_status(true, ['did' => 'Downloaded the ' . $r['title'] . ' report', 'record_id' => $id, 'refresh' => '']);
session_write_close();
$file = 'sales-crm-report-' . str_replace('_', '-', $name) . '-' . ($filters['from'] ?? ($filters['month'] ?? date('Y-m-d'))) . '.csv';
header('X-Content-Type-Options: nosniff');
header('Content-Type: text/csv; charset=utf-8');
header('Content-Length: ' . (string) strlen($csv));
header('Content-Disposition: attachment; filename="' . $file . '"');
header('Content-Security-Policy: sandbox');
header('Cache-Control: no-store');
echo $csv;
exit;
