<?php
declare(strict_types=1);

/**
 * Exports (screen `export-list`; the records server's find_exports reads mcp_exports): a CSV or JSON file of what the caller sees of a kind, a filter or a list, built by the worker as the caller
 * (so a record they cannot see is not in it), kept seven days, downloaded ONCE. The views show an export to its maker and to the CRM admin; only the maker downloads it.
 */
require_once dirname(__DIR__) . '/lists/queries.php';
require_once dirname(__DIR__) . '/reports/queries.php';

const EXPORT_KINDS = ['leads' => 'Leads', 'contacts' => 'Contacts', 'accounts' => 'Accounts', 'deals' => 'Deals', 'activities' => 'Activities', 'list' => 'A list', 'report' => 'A report'];
const EXPORT_FORMATS = ['csv' => 'CSV', 'json' => 'JSON'];
const EXPORT_STATUS_BADGE = ['queued' => 'secondary', 'running' => 'info', 'done' => 'success', 'failed' => 'danger'];
/** The most rows one export holds; more is refused where it is made (narrow the filters), not cut off. */
const EXPORT_MAX_ROWS = 100000;
/** The singular record kind an export kind reads (the find_* the filters belong to). */
const EXPORT_RECORD_KIND = ['leads' => 'lead', 'contacts' => 'contact', 'accounts' => 'account', 'deals' => 'deal', 'activities' => 'activity'];

/** The caller's exports (everyone's for the CRM admin), newest first, with the state in words. */
function find_exports(PDO $pdo, int $limit = 100): array
{
    $st = $pdo->prepare('SELECT v.export_id, v.kind, v.format, v.status, v.byte_size, v.item_count, v.params, v.list_id, v.created_by, m.display_name AS maker, v.created_at, v.finished_at, v.expires_at, v.downloaded_at,
                                e.log, (e.storage_path IS NOT NULL) AS has_file, v.expires_at <= now() AS expired, (SELECT name FROM mcp_lists WHERE list_id = v.list_id) AS list_name
                           FROM mcp_exports v JOIN exports e ON e.id = v.export_id LEFT JOIN members m ON m.id = v.created_by ORDER BY v.created_at DESC, v.export_id DESC LIMIT ' . max(1, min(500, $limit)));
    $st->execute();
    return array_map('export_decorate', $st->fetchAll());
}

/** One export the caller may see (its maker; the CRM admin), or null. */
function find_export(PDO $pdo, int $id): ?array
{
    $st = $pdo->prepare('SELECT v.export_id, v.kind, v.format, v.status, v.byte_size, v.item_count, v.params, v.list_id, v.created_by, m.display_name AS maker, v.created_at, v.finished_at, v.expires_at, v.downloaded_at,
                                e.log, (e.storage_path IS NOT NULL) AS has_file, v.expires_at <= now() AS expired, (SELECT name FROM mcp_lists WHERE list_id = v.list_id) AS list_name
                           FROM mcp_exports v JOIN exports e ON e.id = v.export_id LEFT JOIN members m ON m.id = v.created_by WHERE v.export_id = :id');
    $st->execute(['id' => $id]);
    $r = $st->fetch();
    return $r === false ? null : export_decorate($r);
}

/** The state in words and whether it can be downloaded: ready (done, a file, not downloaded, not expired), downloaded, expired, being made, waiting, failed. */
function export_decorate(array $e): array
{
    $e['params'] = json_decode((string) $e['params'], true) ?: [];
    if ($e['status'] === 'failed') { $state = ['failed', 'Failed', 'danger']; }
    elseif ($e['status'] === 'queued') { $state = ['queued', 'Waiting its turn', 'secondary']; }
    elseif ($e['status'] === 'running') { $state = ['running', 'Being made', 'info']; }
    elseif ($e['downloaded_at'] !== null) { $state = ['downloaded', 'Downloaded', 'dark']; }
    elseif ($e['expired'] || !$e['has_file']) { $state = ['expired', 'Expired', 'dark']; }
    else { $state = ['ready', 'Ready to download', 'success']; }
    $e['state'] = $state[0];
    $e['state_words'] = $state[1];
    $e['state_badge'] = $state[2];
    $e['can_download'] = $state[0] === 'ready' && (int) $e['created_by'] === (int) current_member_id();
    return $e;
}

/** The export's title in words: "Contacts — owner Mine, untouched 30+ days" / "The list Hot leads". */
function export_title(PDO $pdo, array $e): string
{
    if ($e['kind'] === 'list') { return 'The list ' . ($e['list_name'] ?? ('#' . (int) $e['list_id'])); }
    if ($e['kind'] === 'report') {
        $name = function_exists('report_name') ? report_name((string) ($e['params']['report'] ?? '')) : null;
        $f = is_array($e['params']['filters'] ?? null) ? $e['params']['filters'] : [];
        return 'The ' . strtolower($name !== null ? REPORTS[$name][0] : 'a') . ' report' . (isset($f['from'], $f['to']) ? ' — ' . report_period_words($f) : (isset($f['month']) ? ' — ' . report_period_words($f) : ''));
    }
    $base = EXPORT_KINDS[$e['kind']] ?? $e['kind'];
    $rk = EXPORT_RECORD_KIND[$e['kind']] ?? null;
    $w = $rk !== null && isset($e['params']['filters']) && is_array($e['params']['filters']) && $rk !== 'activity' ? filters_words($pdo, $rk, $e['params']['filters']) : [];
    return $w === [] ? $base . ' — all of them' : $base . ' — ' . implode(', ', array_map(static fn (array $x): string => strtolower($x[0]) . ' ' . $x[1], $w));
}
