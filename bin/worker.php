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
 * PHASE 0: every pass was a STUB that changes nothing and answers 0 — the slice whose data it touches fills it in (design §10); none is a stub now. REAL since slice 2: `imports`,
 * `search_catch_up`; since slice 4: `reminders`, `recurring_tasks`, `next_step_flags`; since slice 5: `mail_poll`, `mail_match`; since slice 6: `smart_lists`, `exports` and the campaign half of `outbox`; since slice 8: the
 * notification half of `outbox`, `dispatches` and `proposals`; since slice 9: `tombstones_purge` and `key_usage_prune`.
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

// ---- the passes ------------------------------------------------------------------------------------------

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
/**
 * The logging mailbox polled for unread mail the webhook missed (built in slice 5; email.md): each active mailbox with poll_enabled — MaluMail's mailbox MCP lists its unread
 * messages and every one becomes an inbound_email row (a message the webhook brought already is recognised by its uid and its Message-ID; one that could not be read leaves a
 * `failed` row and is tried again). last_polled_at and last_error are kept on the mailbox for the admin's health card. Without a MaluMail key it does nothing (and says so).
 * Never matches — mail_match does.
 */
function worker_pass_mail_poll(PDO $pdo, int $limit, DateTimeImmutable $now): array
{
    require_once dirname(__DIR__) . '/app/features/email/inbound.php';
    $out = ['received' => 0, 'duplicate' => 0, 'failed' => 0, 'mailboxes' => 0, 'errors' => 0];
    if ((string) env('MALUMAIL_API_KEY', '') === '') {
        return $out + ['skipped' => 'no MaluMail key'];
    }
    $boxes = $pdo->query("SELECT id, address::text AS address, last_uid, webhook_secret_ref FROM mailboxes WHERE active AND poll_enabled AND provider = 'malumail' ORDER BY id")->fetchAll();
    foreach ($boxes as $mb) {
        try {
            $r = mail_poll_mailbox($pdo, $mb, min(50, $limit));
            foreach (['received', 'duplicate', 'failed'] as $k) { $out[$k] += $r[$k]; }
            $out['mailboxes']++;
        } catch (Throwable $e) {
            $out['errors']++;
            error_log('worker mail_poll ' . $mb['id'] . ': ' . $e->getMessage());
            mail_mailbox_error($pdo, (int) $mb['id'], $e->getMessage());
        }
    }
    return $out;
}
/**
 * The matcher (built in slice 5; email.md): the received emails still pending go through crm_match_inbound_email() (db/011) one transaction each — onto the contacts their addresses
 * name and the deal their thread (or [D-000123]) names as email activities (the files copied onto each), a stranger proposed as a lead, a bounce or an auto-reply ignored — and
 * a contact's reply told to its owner. Logged mail.matched|proposed|unmatched|ignored per email (the message id and the contact ids, never the body).
 */
function worker_pass_mail_match(PDO $pdo, int $limit, DateTimeImmutable $now): array
{
    require_once dirname(__DIR__) . '/app/features/email/matcher.php';
    return mail_match_pending($pdo, $limit);
}
/**
 * Smart lists' counts refreshed hourly (built in slice 6; campaigns-lists.md): each smart list not counted for an hour is counted AS ITS OWNER (a shared list with no owner as the one who made it) —
 * the filter run through the kind's walled view, so the number is what the owner would see. A list whose owner can no longer see anything (not a member any more) is left as it was and counted as `failed`.
 */
function worker_pass_smart_lists(PDO $pdo, int $limit, DateTimeImmutable $now): array
{
    require_once dirname(__DIR__) . '/app/features/lists/write.php';
    $st = $pdo->prepare("SELECT id, COALESCE(owner_member_id, created_by) AS actor FROM lists WHERE kind = 'smart' AND deleted_at IS NULL AND (counted_at IS NULL OR counted_at < CAST(:at AS timestamptz) - interval '1 hour')
                          ORDER BY counted_at NULLS FIRST, id LIMIT :l");
    $st->bindValue('at', $now->format(DATE_ATOM));
    $st->bindValue('l', max(1, $limit), PDO::PARAM_INT);
    $st->execute();
    $counted = 0;
    $failed = 0;
    foreach ($st->fetchAll() as $l) {
        if ($l['actor'] === null) { $failed++; continue; }
        worker_act_as((int) $l['actor']);
        try {
            if (find_list($pdo, (int) $l['id']) === null) { $failed++; continue; }
            list_recount($pdo, (int) $l['id']);
            $counted++;
        } catch (Throwable $e) {
            $failed++;
            error_log('worker smart_lists ' . $l['id'] . ': ' . $e->getMessage());
        } finally {
            worker_act_as(null);
        }
    }
    return ['counted' => $counted, 'failed' => $failed];
}

/**
 * Act as a member for the rows that follow (the schema's walls and the screens' parsers both read who is asking): app.member_id and the session's member. null = nobody again. A pass that acts as
 * a member must call it with null when it is done — and must not call has_right()/is_manager(), which remember the first answer for the process.
 */
function worker_act_as(?int $member): void
{
    db()->prepare("SELECT set_config('app.member_id', :m, false)")->execute(['m' => $member === null ? '' : (string) $member]);
    if ($member === null) { unset($_SESSION['member_id']); } else { $_SESSION['member_id'] = $member; }
}
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
/**
 * Exports built and spent files removed (built in slice 6): each queued (or interrupted running) export is made AS ITS MAKER — export_build writes storage/exports/<id>.csv|json through the same find_*
 * as the screens, so what the maker may not see is not in it — and marked done; one that cannot be made is `failed` with the sentence. Then the files of exports that were downloaded or have expired are
 * removed (the row is kept, with no file). Logged export.build / export.failed with the counts, never the rows.
 */
function worker_pass_exports(PDO $pdo, int $limit, DateTimeImmutable $now): array
{
    require_once dirname(__DIR__) . '/app/features/exports/build.php';
    $built = 0;
    $rows = 0;
    $failed = 0;
    foreach ($pdo->query("SELECT id FROM exports WHERE status IN ('queued', 'running') ORDER BY created_at, id LIMIT " . max(1, min(10, $limit)))->fetchAll(PDO::FETCH_COLUMN) as $id) {
        $id = (int) $id;
        $pdo->prepare("UPDATE exports SET status = 'running' WHERE id = :id")->execute(['id' => $id]);
        try {
            $r = export_build($pdo, $id);
            $built++;
            $rows += $r['rows'];
            log_activity($pdo, 'export.build', 'export', $id, ['source' => 'cron', 'actor_member_id' => null, 'after' => ['item_count' => $r['rows'], 'byte_size' => $r['bytes']]]);
        } catch (Throwable $e) {
            $failed++;
            $msg = $e instanceof DomainException ? $e->getMessage() : 'The export could not be made: ' . db_message($e, 'an error the log names.');
            error_log('worker export ' . $id . ': ' . $e->getMessage());
            $pdo->prepare("UPDATE exports SET status = 'failed', finished_at = now(), log = :m WHERE id = :id")->execute(['m' => mb_substr($msg, 0, 1000), 'id' => $id]);
            log_activity($pdo, 'export.failed', 'export', $id, ['source' => 'cron', 'actor_member_id' => null, 'after' => ['message' => mb_substr($msg, 0, 200)]]);
        }
    }
    $expired = 0;
    $st = $pdo->prepare("SELECT id, storage_path FROM exports WHERE storage_path IS NOT NULL AND (downloaded_at IS NOT NULL OR expires_at < CAST(:at AS timestamptz)) ORDER BY id LIMIT 200");
    $st->execute(['at' => $now->format(DATE_ATOM)]);
    foreach ($st->fetchAll() as $e) {
        attachment_unlink((string) $e['storage_path']);
        $pdo->prepare('UPDATE exports SET storage_path = NULL WHERE id = :id')->execute(['id' => $e['id']]);
        $expired++;
    }
    return ['built' => $built, 'rows' => $rows, 'failed' => $failed, 'expired' => $expired];
}
/**
 * The outbox. THE CAMPAIGN HALF is slice 6's (built): the queued outbound_email rows that carry a campaign_id — and only those — are sent one by one through email_deliver() (the same footer, unsubscribe
 * link and Message-ID as a seller's own send), AS THE SENDER who queued them, at most the pass's limit per run: sent (the member goes from planned to sent), retried (the provider said 429, 502, 503, 504 or
 * could not be reached — still queued, tried again after a minute, four, nine …, five tries in all), failed (a permanent answer, or the tries used up), skipped (the contact opted out since, or the campaign
 * was cancelled — nothing goes). THE OTHER HALF (slice 8) — the notification_outbox rows: an email through MaluMail with render_mail() (the reminder and morning-note bodies, else the plain notice), a text to a member through
 * the kernel's K6 (`no_sender` and the other refusals are SKIPPED with the code in `detail`) — adds its counts to the same four keys: sent, skipped, retried (a try that will be made again), failed (three attempts used or a permanent
 * answer); outbox.send|skip|fail are logged per row, never the words. A person's own draft is never touched here.
 */
function worker_pass_outbox(PDO $pdo, int $limit, DateTimeImmutable $now): array
{
    require_once dirname(__DIR__) . '/app/features/email/send.php';
    require_once dirname(__DIR__) . '/app/features/notify/send.php';
    $out = ['sent' => 0, 'skipped' => 0, 'retried' => 0, 'failed' => 0];
    $st = $pdo->prepare("SELECT o.id, o.sender_member_id, o.contact_id, o.campaign_id, c.status AS campaign_status, c.deleted_at AS campaign_deleted
                           FROM outbound_email o LEFT JOIN campaigns c ON c.id = o.campaign_id
                          WHERE o.campaign_id IS NOT NULL AND o.status = 'queued' AND (o.retry_at IS NULL OR o.retry_at <= CAST(:at AS timestamptz)) ORDER BY o.created_at, o.id LIMIT :l");
    $st->bindValue('at', $now->format(DATE_ATOM));
    $st->bindValue('l', max(1, $limit), PDO::PARAM_INT);
    $st->execute();
    $mark = static function (int $id, string $status, string $why) use ($pdo): void {
        $pdo->prepare("UPDATE outbound_email SET status = :s, error = :e WHERE id = :id AND status = 'queued'")->execute(['s' => $status, 'e' => mb_substr($why, 0, 300), 'id' => $id]);
    };
    foreach ($st->fetchAll() as $row) {
        $id = (int) $row['id'];
        if ($row['campaign_deleted'] !== null || in_array($row['campaign_status'], ['cancelled'], true)) { $mark($id, 'failed', 'The campaign was cancelled, so this was not sent.'); $out['skipped']++; continue; }
        if ($row['sender_member_id'] === null) { $mark($id, 'failed', 'The person who queued it is gone, so this was not sent.'); $out['failed']++; continue; }
        worker_act_as((int) $row['sender_member_id']);
        try {
            $r = email_deliver($pdo, $id);
            if ($r['state'] === 'sent') { $out['sent']++; }
            elseif ($r['state'] === 'retry') { $out['retried']++; }
            elseif ($r['state'] === 'suppressed') { $out['skipped']++; }
            else { $out['failed']++; }
        } catch (DomainException $e) {                                                  // a refusal in words: opted out since it was queued, no address, a merge field left, being sent already
            $m = $e->getMessage();
            if (str_contains($m, 'being sent already')) { continue; }
            $optedOut = $row['contact_id'] !== null && !(bool) one_value($pdo, 'SELECT crm_email_allowed(:c)', ['c' => $row['contact_id']]);
            $mark($id, $optedOut ? 'suppressed' : 'failed', $m);
            $out[$optedOut ? 'skipped' : 'failed']++;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) { $pdo->rollBack(); }
            error_log('worker outbox ' . $id . ': ' . $e->getMessage());
            $mark($id, 'failed', 'It could not be sent: ' . db_message($e, 'an error the log names.'));
            $out['failed']++;
        } finally {
            worker_act_as(null);
        }
    }
    // ---- the notification_outbox half (slice 8): email through MaluMail with render_mail(), texts through the kernel's K6; three attempts, a retry after 1 and 4 minutes ----
    $nst = $pdo->prepare("SELECT id, channel, member_id, to_email::text AS to_email, kind, record_type, record_id, subject, body, attempts FROM notification_outbox
                           WHERE status = 'queued' AND send_after <= CAST(:at AS timestamptz) ORDER BY send_after, id LIMIT :l");
    $nst->bindValue('at', $now->format(DATE_ATOM));
    $nst->bindValue('l', max(1, $limit), PDO::PARAM_INT);
    $nst->execute();
    foreach ($nst->fetchAll() as $row) {
        try {
            $out[match (outbox_notification_deliver($pdo, $row, $now)) { 'sent' => 'sent', 'skipped' => 'skipped', 'retried' => 'retried', default => 'failed' }]++;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) { $pdo->rollBack(); }
            error_log('worker outbox notification ' . $row['id'] . ': ' . $e->getMessage());
            $pdo->prepare("UPDATE notification_outbox SET status = 'failed', detail = :d, attempts = attempts + 1 WHERE id = :id AND status = 'queued'")->execute(['d' => 'It could not be sent: ' . db_message($e, 'an error the log names.'), 'id' => $row['id']]);
            $out['failed']++;
        }
    }
    return $out;
}
/**
 * Agent dispatches (slice 8; agents/dispatch.php): the rows `sent` — a lead from the website's form to qualify, a deal that entered an ask-the-Assistant stage, a reminder that is an agent's — each become ONE chat turn on the kernel's
 * chat endpoint as the acting person; the first 200 characters of the reply are kept; three attempts (1 and 4 minutes between), a refusal in words is final, a run still going is asked after on the next pass.
 */
function worker_pass_dispatches(PDO $pdo, int $limit, DateTimeImmutable $now): array
{
    require_once dirname(__DIR__) . '/app/features/agents/dispatch.php';
    return dispatches_run($pdo, $limit, $now);
}
/**
 * The Assistant's standing proposals the worker can make without a model (slice 8; proposals/write.php): a `duplicate` for each new pair crm_duplicates finds — for the manager of the owner's department, never twice for a pair in any
 * status — and a `rotting` for each deal newly rotting, for its owner, once per deal per stage. Nothing when the Assistant's proposals are switched off.
 */
function worker_pass_proposals(PDO $pdo, int $limit, DateTimeImmutable $now): array
{
    require_once dirname(__DIR__) . '/app/features/proposals/write.php';
    return standing_proposals_run($pdo, $limit, $now);
}
/**
 * The tombstone purge (slice 9; tombstones/write.php): crm_tombstones_purge_kinds() — the SAME function the admin's records_purge calls — destroys, with their notes, files and tags, the deleted leads, accounts, contacts, deals and activities
 * that have waited past tombstone_days; $limit of each kind a pass. One `records.purge` is logged when anything went, with the count by kind (never a name). A record still inside its window is never touched.
 */
function worker_pass_tombstones_purge(PDO $pdo, int $limit, DateTimeImmutable $now): array
{
    require_once dirname(__DIR__) . '/app/features/tombstones/write.php';
    $paths = [];
    $counts = records_purge_by_kind($pdo, null, null, $limit, $paths);
    $files = purge_attachment_files($pdo, $paths);
    if (array_sum($counts) > 0) {
        log_activity($pdo, 'records.purge', 'records', null, ['source' => 'cron', 'actor_member_id' => null, 'after' => ['kind' => 'all', 'purged' => $counts, 'total' => array_sum($counts), 'files' => $files]]);
    }
    return ['purged' => array_sum($counts), 'by_kind' => $counts, 'files' => $files];
}
/**
 * Key-usage pruning (slice 9): crm_prune_key_usage() (db/005) — the window the schema defines: a minute bucket older than a day, a day bucket older than 35 days (today's count and the last month's chart stay). Nothing a key's limits need is lost:
 * the rate rule reads the current minute and the current day only.
 */
function worker_pass_key_usage_prune(PDO $pdo, int $limit, DateTimeImmutable $now): array
{
    return ['pruned' => (int) $pdo->query('SELECT crm_prune_key_usage()')->fetchColumn()];
}
