<?php
declare(strict_types=1);
/**
 * /admin/purge — GET is the screen `admin-purge` (records.purge): the tombstones — what waits in its window and what is due — by kind, with the date each is purged; POST is the action `records_purge` (log records.purge with the count by
 * kind; confirm; pauses for an agent as `deletion`; records.purge): destroys the tombstones that have waited past the window (crm_tombstones_purge_kinds — the same function the worker's pass calls), all of a `kind` (lead|account|contact|deal|
 * activity) or every kind. `before` (a date) can only move the cut-off earlier than the window, never into it: a tombstone inside the window survives. The answer carries the counts by kind.
 */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_once dirname(__DIR__, 2) . '/app/features/tombstones/write.php';
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    crm_handler_begin();
    require_right('records.purge');
    $pdo = db();
    $errors = [];
    $kind = crm_choice('kind', null, array_keys(TOMBSTONE_KINDS), 'The kind', $errors);
    $before = null;
    if (req_has('before') && (string) req_val('before') !== '') {
        $b = (string) req_val('before');
        if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $b, $m) || !checkdate((int) $m[2], (int) $m[3], (int) $m[1])) { $errors['before'] = 'The date is year-month-day, like 2026-11-30.'; } else { $before = $b; }
    }
    if ($errors !== []) { crm_refuse_fields($errors); }
    $paths = [];
    $counts = crm_guard($pdo, static function () use ($pdo, $kind, $before, &$paths): array {
        $pdo->beginTransaction();
        $counts = records_purge_by_kind($pdo, $kind, $before, 5000, $paths);
        log_activity($pdo, 'records.purge', 'records', null, ['after' => ['kind' => $kind ?? 'all', 'before' => $before, 'purged' => $counts, 'total' => array_sum($counts), 'files' => count($paths)]]);
        $pdo->commit();
        return $counts;
    });
    $files = purge_attachment_files($pdo, $paths);                    // the bytes go once the rows are gone for good
    $total = array_sum($counts);
    $words = implode(', ', array_map(static fn (string $k, int $n): string => $n . ' ' . ($n === 1 ? $k : ($k === 'activity' ? 'activities' : $k . 's')), array_keys(array_filter($counts)), array_filter($counts)));
    crm_done($total === 0 ? 'Nothing was due to be purged' : 'Purged ' . $total . ' record' . ($total === 1 ? '' : 's') . ' (' . $words . ')', $total, crm_land('/admin/purge', $total === 0 ? 'none' : 'purged'), 'tombstonesChanged', ['purged' => $counts, 'files' => $files]);
}
require_login();
require_right('records.purge');
$pdo = db();
$kind = isset(TOMBSTONE_KINDS[$k = request_string('kind')]) ? $k : null;
$counts = tombstone_counts($pdo);
$rows = tombstones($pdo, $kind, 200);
log_screen_view($pdo, 'admin-purge');
if (wants_json()) {
    respond_screen(['days' => $counts['days'], 'kinds' => $counts['kinds'], 'due' => $counts['due'], 'waiting' => $counts['waiting'], 'next_purge_at' => json_ts($counts['next_purge_at']), 'tombstones' => array_map('present_tombstone', $rows)]);
}
$notices = ['purged' => ['success', 'Purged what was due.'], 'none' => ['info', 'Nothing was due to be purged.']];
render_screen('Deleted records', view('admin/purge.php', ['counts' => $counts, 'rows' => $rows, 'kind' => $kind, 'tz' => member_timezone(), 'notice' => crm_notice($_GET['notice'] ?? null, $notices)]),
    ['activeNav' => 'admin', 'screen' => 'admin-purge', 'entity' => 'tombstone']);
