<?php
declare(strict_types=1);

/**
 * The Sales CRM worker — one pass a minute from sales_crm-worker.timer (design §14): reminders due (in-app, email, a text through
 * the kernel's K6), the outbox (email through MaluMail), the inbound mail poll and the matcher (emails onto contacts and deals, strangers
 * as proposed leads), the rotting and next-step flags, smart-list counts (hourly), the search index catch-up, imports and exports,
 * recurring tasks expanded, the tombstone purge window, dispatches to agents, key-usage pruning. Idempotent; advisory-locked so two
 * runs never overlap; each pass its own try (one failing never stops the next); the report is printed as one JSON line, logged once as
 * `worker.pass` (counts and sentences, never an email's body, a token or a key) and kept as a worker_passes row when the table exists (db/013).
 *   php bin/worker.php [--passes=a,b] [--limit=200]
 * With --passes the named passes run whether or not they are due; without it every pass runs. CRM_WORKER_NOW (ISO 8601) replaces the clock
 * a pass selects by; honoured only when APP_ENV is not prod. Exits 1 when a pass failed.
 *
 * PHASE 0: every pass is a STUB that changes nothing and answers 0 — the slice whose data it touches fills it in (design §10):
 * `reminders`, `recurring_tasks`, `next_step_flags` slice 4's; `mail_poll`, `mail_match` slice 5's; `smart_lists`, `imports`, `exports`
 * slice 6's; `search_catch_up` slice 1's; `outbox`, `dispatches`, `proposals` slice 8's; `tombstones_purge`, `key_usage_prune` slice 9's.
 */
if (PHP_SAPI !== 'cli') { fwrite(STDERR, "CLI only.\n"); exit(1); }
require_once dirname(__DIR__) . '/app/bootstrap.php';

/** The passes, in the order they run. A new pass is a name here and a function worker_pass_<name>(PDO, int $limit, DateTimeImmutable $now): array. */
const WORKER_PASSES = ['reminders', 'recurring_tasks', 'next_step_flags', 'mail_poll', 'mail_match', 'smart_lists', 'search_catch_up', 'imports', 'exports',
    'outbox', 'dispatches', 'proposals', 'tombstones_purge', 'key_usage_prune'];

$only = [];
$limit = 200;
foreach (array_slice($argv, 1) as $a) {
    if (str_starts_with($a, '--passes=')) {
        $only = array_values(array_filter(explode(',', substr($a, 9))));
        if (array_diff($only, WORKER_PASSES) !== []) {
            fwrite(STDERR, 'Unknown pass: ' . implode(', ', array_diff($only, WORKER_PASSES)) . '. Passes: ' . implode(', ', WORKER_PASSES) . ".\n");
            exit(2);
        }
    } elseif (str_starts_with($a, '--limit=')) {
        $limit = max(1, min(1000, (int) substr($a, 8)));
    } else {
        fwrite(STDERR, "Usage: php bin/worker.php [--passes=a,b] [--limit=N]\n");
        exit(2);
    }
}
$now = new DateTimeImmutable();
if (($fake = getenv('CRM_WORKER_NOW')) !== false && $fake !== '' && app_is_prod() === false) {
    $now = new DateTimeImmutable($fake);
}
$pdo = db();
if (!(bool) $pdo->query("SELECT pg_try_advisory_lock(hashtext('sales_crm_worker'))")->fetchColumn()) {
    echo json_encode(['skipped' => 'another pass holds the lock']) . "\n";
    exit(0);
}
$GLOBALS['__public_door'] = 'cron';                 // every row a pass writes is source cron, actor none
$started = microtime(true);
$report = ['at' => $now->format(DATE_ATOM), 'ran' => [], 'errors' => []];
foreach ($only === [] ? WORKER_PASSES : $only as $pass) {
    try {
        $report['ran'][$pass] = ('worker_pass_' . $pass)($pdo, $limit, $now);
    } catch (Throwable $e) {
        error_log('worker ' . $pass . ': ' . $e->getMessage());
        $report['errors'][] = $pass . ': ' . mb_substr($e->getMessage(), 0, 200);
    }
}
$report['seconds'] = round(microtime(true) - $started, 2);
log_activity($pdo, 'worker.pass', null, null, ['source' => 'cron', 'actor_member_id' => null, 'after' => $report]);
try {
    if ($pdo->query("SELECT to_regclass('public.worker_passes') IS NOT NULL")->fetchColumn()) {
        $pdo->prepare('INSERT INTO worker_passes (started_at, finished_at, steps, error) VALUES (:s, now(), :steps, :err)')
            ->execute(['s' => date(DATE_ATOM, (int) $started), 'steps' => json_encode($report['ran'], JSON_UNESCAPED_SLASHES),
                       'err' => $report['errors'] === [] ? null : implode('; ', $report['errors'])]);
    }
} catch (Throwable $e) {
    error_log('worker_passes: ' . $e->getMessage());
}
echo json_encode($report, JSON_UNESCAPED_SLASHES) . "\n";
$pdo->query("SELECT pg_advisory_unlock(hashtext('sales_crm_worker'))");
exit($report['errors'] === [] ? 0 : 1);

// ---- the passes (stubs until their slice) ------------------------------------------------------------------------------------------

/** SLICE 4 — activities whose remind_at has passed, not done, not snoozed: a notification row, the outbox when prefs say so, reminded_at set. */
function worker_pass_reminders(PDO $pdo, int $limit, DateTimeImmutable $now): array { return ['reminded' => 0, 'stub' => true]; }
/** SLICE 4 — recurring tasks (RRULE daily/weekly/monthly) expanded one occurrence ahead when the last is done. */
function worker_pass_recurring_tasks(PDO $pdo, int $limit, DateTimeImmutable $now): array { return ['expanded' => 0, 'stub' => true]; }
/** SLICE 4 — crm_refresh_next_activity() for records whose next activity changed outside the trigger (a reschedule across days, a done at midnight). */
function worker_pass_next_step_flags(PDO $pdo, int $limit, DateTimeImmutable $now): array { return ['refreshed' => 0, 'stub' => true]; }
/** SLICE 5 — the logging mailbox polled for unread mail the webhook missed (once a minute); every message an inbound_email row. */
function worker_pass_mail_poll(PDO $pdo, int $limit, DateTimeImmutable $now): array { return ['received' => 0, 'stub' => true]; }
/** SLICE 5 — the matcher: inbound_email rows received → contacts by address, a deal by thread, activities of kind email; strangers → proposed leads; bounces and auto-replies marked. */
function worker_pass_mail_match(PDO $pdo, int $limit, DateTimeImmutable $now): array { return ['matched' => 0, 'proposed' => 0, 'ignored' => 0, 'stub' => true]; }
/** SLICE 6 — smart lists' member_count refreshed hourly. */
function worker_pass_smart_lists(PDO $pdo, int $limit, DateTimeImmutable $now): array { return ['counted' => 0, 'stub' => true]; }
/** SLICE 1 — crm_search_catch_up(): every record whose index row is older than its change. */
function worker_pass_search_catch_up(PDO $pdo, int $limit, DateTimeImmutable $now): array { return ['indexed' => 0, 'stub' => true]; }
/** SLICE 6 — queued imports run in batches of 500 (dry run or real); bulk jobs beyond bulk_sync_rows. */
function worker_pass_imports(PDO $pdo, int $limit, DateTimeImmutable $now): array { return ['rows' => 0, 'finished' => 0, 'stub' => true]; }
/** SLICE 6 — queued exports built (CSV); expired exports' files removed. */
function worker_pass_exports(PDO $pdo, int $limit, DateTimeImmutable $now): array { return ['built' => 0, 'expired' => 0, 'stub' => true]; }
/** SLICE 8 — the outbox: email through MaluMail (malumail_send), texts to members through the kernel (kernel_send_text), retries with backoff. */
function worker_pass_outbox(PDO $pdo, int $limit, DateTimeImmutable $now): array { return ['sent' => 0, 'skipped' => 0, 'retried' => 0, 'failed' => 0, 'stub' => true]; }
/** SLICE 8 — agent dispatches: a lead to qualify, a deal entering an ask-the-Assistant stage, a reminder for an agent → one chat turn, at most three attempts. */
function worker_pass_dispatches(PDO $pdo, int $limit, DateTimeImmutable $now): array { return ['dispatched' => 0, 'retried' => 0, 'stub' => true]; }
/** SLICE 8 — the Assistant's standing proposals the worker can make without a model (a possible duplicate found, a deal rotting). */
function worker_pass_proposals(PDO $pdo, int $limit, DateTimeImmutable $now): array { return ['proposed' => 0, 'stub' => true]; }
/** SLICE 9 — tombstoned records older than tombstone_days purged with their children. */
function worker_pass_tombstones_purge(PDO $pdo, int $limit, DateTimeImmutable $now): array { return ['purged' => 0, 'stub' => true]; }
/** SLICE 9 — crm_prune_key_usage(): minute buckets older than a day, day buckets older than 35 days. */
function worker_pass_key_usage_prune(PDO $pdo, int $limit, DateTimeImmutable $now): array { return ['pruned' => 0, 'stub' => true]; }
