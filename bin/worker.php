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
 * `mail_poll`, `mail_match` slice 5's; `smart_lists`, `exports` slice 6's; `outbox`, `dispatches`, `proposals` slice 8's; `tombstones_purge`,
 * `key_usage_prune` slice 9's. REAL since slice 2: `imports`, `search_catch_up`; since slice 4: `reminders`, `recurring_tasks`, `next_step_flags`.
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

/**
 * Reminders due (activities-calendar.md §4; built in slice 4): crm_reminders_due(now) — not done, not deleted, not reminded, not snoozed into the future —
 * each in its own transaction: a person's through crm_deliver_reminder (the bell's row, the outbox rows their preferences ask for — email by default,
 * a text when they turned texts on for reminders — and reminded_at); an agent's as an agent_dispatches row of kind reminder (the dispatches pass sends
 * the chat turn) with reminded_at set. A snooze clears reminded_at, so the row comes back at its time. Logged once: reminder.deliver with the counts.
 */
function worker_pass_reminders(PDO $pdo, int $limit, DateTimeImmutable $now): array
{
    $st = $pdo->prepare('SELECT r.activity_id, r.member_id, r.at, r.deal_id, m.member_kind, a.created_by, a.lead_id FROM crm_reminders_due(:at, :l) r
                           JOIN members m ON m.id = r.member_id JOIN activities a ON a.id = r.activity_id');
    $st->execute(['at' => $now->format(DATE_ATOM), 'l' => $limit]);
    $people = 0;
    $agents = 0;
    $failed = 0;
    foreach ($st->fetchAll() as $r) {
        try {
            $pdo->beginTransaction();
            if ($r['member_kind'] === 'agent') {
                $pdo->prepare("INSERT INTO agent_dispatches (record_type, record_id, agent_member_id, kind, via, acting_member_id, deal_id, lead_id, status, detail)
                               VALUES ('activity', :a, :agent, 'reminder', 'chat', :by, :d, :l, 'sent', :detail)")
                    ->execute(['a' => $r['activity_id'], 'agent' => $r['member_id'], 'by' => $r['created_by'], 'd' => $r['deal_id'], 'l' => $r['lead_id'], 'detail' => 'due ' . $r['at']]);
                $pdo->prepare('UPDATE activities SET reminded_at = now(), snoozed_until = NULL WHERE id = :id')->execute(['id' => $r['activity_id']]);
                $agents++;
            } else {
                $pdo->prepare('SELECT crm_deliver_reminder(:id)')->execute(['id' => $r['activity_id']]);
                $people++;
            }
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) { $pdo->rollBack(); }
            $failed++;
            error_log('worker reminder ' . $r['activity_id'] . ': ' . $e->getMessage());
        }
    }
    if ($people + $agents + $failed > 0) {
        log_activity($pdo, 'reminder.deliver', null, null, ['source' => 'cron', 'actor_member_id' => null, 'after' => ['reminded' => $people, 'dispatched' => $agents, 'failed' => $failed]]);
    }
    return ['reminded' => $people, 'dispatched' => $agents, 'failed' => $failed];
}

/** An RRULE of a recurring task (FREQ=DAILY|WEEKLY|MONTHLY[;INTERVAL=n]) as a step for DateTimeImmutable::modify, or null when it is not one. */
function worker_recurrence_step(string $rule): ?string
{
    if (!preg_match('/^FREQ=(DAILY|WEEKLY|MONTHLY)(?:;INTERVAL=([1-9][0-9]?))?$/', $rule, $m)) {
        return null;
    }
    return '+' . (int) ($m[2] ?? 1) . ' ' . ['DAILY' => 'day', 'WEEKLY' => 'week', 'MONTHLY' => 'month'][$m[1]];
}

/**
 * Recurring tasks expanded one occurrence ahead (built in slice 4): a done task with a repeat and no next occurrence gets one — its due time stepped by
 * the rule past now (in the task's zone, so 09:00 stays 09:00), the same record, owner, reminder, priority and rule, `recurrence_parent_id` the done one.
 * Logged task.recur per occurrence.
 */
function worker_pass_recurring_tasks(PDO $pdo, int $limit, DateTimeImmutable $now): array
{
    $st = $pdo->prepare("SELECT a.id, a.subject, a.body, a.account_id, a.contact_id, a.deal_id, a.lead_id, a.owner_member_id, a.created_by, a.due_at, a.all_day, a.timezone,
                                a.remind_minutes_before, a.priority, a.recurrence, COALESCE(a.timezone, m.timezone, crm_setting_text('timezone')) AS tz
                           FROM activities a LEFT JOIN members m ON m.id = a.owner_member_id
                          WHERE a.kind = 'task' AND a.recurrence IS NOT NULL AND a.done_at IS NOT NULL AND a.deleted_at IS NULL AND a.due_at IS NOT NULL
                            AND NOT EXISTS (SELECT 1 FROM activities c WHERE c.recurrence_parent_id = a.id)
                          ORDER BY a.done_at LIMIT :l");
    $st->execute(['l' => $limit]);
    $made = 0;
    foreach ($st->fetchAll() as $a) {
        $step = worker_recurrence_step((string) $a['recurrence']);
        if ($step === null) { continue; }
        try { $zone = new DateTimeZone((string) $a['tz']); } catch (Exception) { $zone = new DateTimeZone('UTC'); }
        $due = (new DateTimeImmutable($a['due_at']))->setTimezone($zone)->modify($step);
        for ($i = 0; $due <= $now && $i < 1000; $i++) { $due = $due->modify($step); }
        $pdo->beginTransaction();
        $ins = $pdo->prepare("INSERT INTO activities (kind, subject, body, account_id, contact_id, deal_id, lead_id, owner_member_id, created_by, due_at, all_day, timezone,
                                                      remind_minutes_before, priority, recurrence, recurrence_parent_id, source)
                              VALUES ('task', :s, :b, :acc, :c, :d, :l, :o, :by, :due, :ad, :tz, :r, :p, :rule, :parent, 'cron') RETURNING id");
        $ins->execute(['s' => $a['subject'], 'b' => $a['body'], 'acc' => $a['account_id'], 'c' => $a['contact_id'], 'd' => $a['deal_id'], 'l' => $a['lead_id'], 'o' => $a['owner_member_id'],
            'by' => $a['created_by'], 'due' => $due->format(DATE_ATOM), 'ad' => $a['all_day'] ? 'true' : 'false', 'tz' => $a['timezone'], 'r' => $a['remind_minutes_before'], 'p' => $a['priority'],
            'rule' => $a['recurrence'], 'parent' => $a['id']]);
        $nid = (int) $ins->fetchColumn();
        if ($a['remind_minutes_before'] === null) { $pdo->prepare('UPDATE activities SET remind_minutes_before = NULL WHERE id = :id')->execute(['id' => $nid]); }
        log_activity($pdo, 'task.recur', 'activity', $nid, ['source' => 'cron', 'actor_member_id' => null, 'deal_id' => $a['deal_id'], 'contact_id' => $a['contact_id'], 'account_id' => $a['account_id'],
            'lead_id' => $a['lead_id'], 'after' => ['from_activity_id' => (int) $a['id'], 'due_at' => $due->setTimezone(new DateTimeZone('UTC'))->format(DATE_ATOM), 'rule' => $a['recurrence']]]);
        $pdo->commit();
        $made++;
    }
    return ['expanded' => $made];
}

/**
 * The next step mended (built in slice 4): the trigger keeps every record's next activity as activities are written; this finds the records whose stored
 * next step disagrees with their open activities (a tombstone restored, a row changed by hand, an interrupted write) and runs crm_refresh_next_activity
 * on them. The colours themselves are computed when read (crm_next_colour), so "today" turning into "overdue" at midnight needs nothing here.
 */
function worker_pass_next_step_flags(PDO $pdo, int $limit, DateTimeImmutable $now): array
{
    $n = 0;
    $open = static fn (string $col): string => "(SELECT min(COALESCE(a.starts_at, a.due_at)) FROM activities a WHERE a.$col = t.id AND a.done_at IS NULL AND a.deleted_at IS NULL)";
    foreach (['deal' => ["deals t WHERE t.status = 'open' AND", 'deal_id'], 'contact' => ['contacts t WHERE', 'contact_id'], 'account' => ['accounts t WHERE', 'account_id'], 'lead' => ['leads t WHERE', 'lead_id']] as $kind => [$from, $col]) {
        $ids = $pdo->query("SELECT t.id FROM $from t.deleted_at IS NULL AND t.next_activity_at IS DISTINCT FROM {$open($col)} LIMIT " . max(1, $limit))->fetchAll(PDO::FETCH_COLUMN);
        foreach ($ids as $id) {
            $args = ['account' => null, 'contact' => null, 'deal' => null, 'lead' => null];
            $args[$kind] = (int) $id;
            $pdo->prepare('SELECT crm_refresh_next_activity(:account, :contact, :deal, :lead)')->execute($args);
            $n++;
        }
    }
    return ['refreshed' => $n];
}
/** SLICE 5 — the logging mailbox polled for unread mail the webhook missed (once a minute); every message an inbound_email row. */
function worker_pass_mail_poll(PDO $pdo, int $limit, DateTimeImmutable $now): array { return ['received' => 0, 'stub' => true]; }
/** SLICE 5 — the matcher: inbound_email rows received → contacts by address, a deal by thread, activities of kind email; strangers → proposed leads; bounces and auto-replies marked. */
function worker_pass_mail_match(PDO $pdo, int $limit, DateTimeImmutable $now): array { return ['matched' => 0, 'proposed' => 0, 'ignored' => 0, 'stub' => true]; }
/** SLICE 6 — smart lists' member_count refreshed hourly. */
function worker_pass_smart_lists(PDO $pdo, int $limit, DateTimeImmutable $now): array { return ['counted' => 0, 'stub' => true]; }
/** crm_search_catch_up(): every record whose index row is older than its change (the triggers index as they write; this mends what they missed). Built in slice 2. */
function worker_pass_search_catch_up(PDO $pdo, int $limit, DateTimeImmutable $now): array
{
    return ['indexed' => (int) one_value($pdo, 'SELECT crm_search_catch_up(:l)', ['l' => $limit])];
}
/**
 * SLICE 2 — queued imports run (leads-and-conversion.md §4): a dry run in one rolled-back transaction, a real run in committed batches of 500 (an import
 * left `running` by an interrupted pass resumes after its last committed row). One import that fails is marked failed with the sentence; the next runs.
 * Logged import.dry_run / import.done with the counts (the maker's run; the pass's actor is none).
 */
function worker_pass_imports(PDO $pdo, int $limit, DateTimeImmutable $now): array
{
    require_once dirname(__DIR__) . '/app/features/imports/runner.php';
    $done = 0;
    $rows = 0;
    $failed = 0;
    $ids = $pdo->query("SELECT id FROM imports WHERE status IN ('queued', 'running') ORDER BY created_at, id LIMIT " . max(1, min(20, $limit)))->fetchAll(PDO::FETCH_COLUMN);
    foreach ($ids as $id) {
        $id = (int) $id;
        $pdo->prepare("UPDATE imports SET status = 'running' WHERE id = :id AND status IN ('queued', 'running')")->execute(['id' => $id]);
        $dry = (bool) one_value($pdo, 'SELECT dry_run FROM imports WHERE id = :id', ['id' => $id]);
        try {
            $r = import_execute($pdo, $id, $dry);
            $rows += $r['rows'];
            $done++;
            log_activity($pdo, $dry ? 'import.dry_run' : 'import.done', 'import', $id, ['source' => 'cron', 'actor_member_id' => null,
                'after' => ['created' => $r['created'], 'updated' => $r['updated'], 'skipped' => $r['skipped'], 'refused' => $r['refused'], 'dry_run' => $dry]]);
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) { $pdo->rollBack(); }
            $pdo->exec("SELECT set_config('app.member_id', '', false)");
            $failed++;
            $msg = $e instanceof DomainException ? $e->getMessage() : 'The import stopped: ' . db_message($e, 'an error the log names.');
            error_log('worker import ' . $id . ': ' . $e->getMessage());
            $pdo->prepare("UPDATE imports SET status = 'failed', finished_at = now(), log = :m WHERE id = :id")->execute(['m' => mb_substr($msg, 0, 2000), 'id' => $id]);
            log_activity($pdo, 'import.failed', 'import', $id, ['source' => 'cron', 'actor_member_id' => null, 'after' => ['message' => mb_substr($msg, 0, 200)]]);
        }
    }
    return ['finished' => $done, 'rows' => $rows, 'failed' => $failed];
}
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
