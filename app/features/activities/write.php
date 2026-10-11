<?php
declare(strict_types=1);

/**
 * Activities' writes (slice 4). The database is the referee (db/010): the account and contact from the deal, the owner from the caller, the reminder's
 * moment (remind_at) from the minutes before, the default reminder from the person's preferences or the settings, a meeting's hour, the outcome's
 * default when done, reminded_at cleared when the time moves, the next step on every record it touches (crm_refresh_next_activity), the touch when done.
 * PHP sets what the request says and logs it.
 */
require_once __DIR__ . '/queries.php';
require_once __DIR__ . '/../custom_fields/queries.php';

const ACTIVITY_COLUMNS = ['kind', 'subject', 'body', 'account_id', 'contact_id', 'deal_id', 'lead_id', 'owner_member_id', 'due_at', 'starts_at', 'ends_at', 'all_day', 'timezone',
    'done_at', 'outcome', 'outcome_note', 'remind_minutes_before', 'location', 'meeting_url', 'priority', 'recurrence', 'email_direction', 'source', 'custom_values'];

/** Insert ($id null) or update the columns $f carries. Answers the id. */
function activity_save(PDO $pdo, ?int $id, array $f, int $by): int
{
    $f = array_intersect_key($f, array_flip(ACTIVITY_COLUMNS));
    if (array_key_exists('custom_values', $f)) {
        $f['custom_values'] = json_encode($f['custom_values'] === [] ? (object) [] : $f['custom_values'], JSON_THROW_ON_ERROR);
    }
    if (array_key_exists('all_day', $f)) {
        $f['all_day'] = $f['all_day'] ? 'true' : 'false';
    }
    $cast = static fn (string $c): string => match ($c) { 'custom_values' => 'CAST(:custom_values AS jsonb)', 'all_day' => 'CAST(:all_day AS boolean)',
        'due_at', 'starts_at', 'ends_at', 'done_at' => 'CAST(:' . $c . ' AS timestamptz)', default => ':' . $c };
    if ($id === null) {
        $cols = array_keys($f);
        $st = $pdo->prepare('INSERT INTO activities (' . implode(', ', $cols) . ', created_by) VALUES (' . implode(', ', array_map($cast, $cols)) . ', :by) RETURNING id');
        $st->execute($f + ['by' => $by]);
        return (int) $st->fetchColumn();
    }
    if ($f !== []) {
        $set = implode(', ', array_map(static fn (string $c): string => $c . ' = ' . $cast($c), array_keys($f)));
        $pdo->prepare("UPDATE activities SET $set WHERE id = :id")->execute($f + ['id' => $id]);
    }
    return $id;
}

/** The stored row's writable columns (for "a field left out stays as it was" and the diff), with the facts the rules read. */
function activity_current(PDO $pdo, int $id): array
{
    $r = one_row($pdo, 'SELECT ' . implode(', ', ACTIVITY_COLUMNS) . ', created_by, reminded_at, snoozed_until, remind_at, deleted_at FROM activities WHERE id = :id', ['id' => $id]) ?? [];
    foreach (['account_id', 'contact_id', 'deal_id', 'lead_id', 'owner_member_id', 'created_by', 'remind_minutes_before'] as $k) { $r[$k] = isset($r[$k]) ? (int) $r[$k] : null; }
    $r['all_day'] = (bool) ($r['all_day'] ?? false);
    $r['custom_values'] = json_decode((string) ($r['custom_values'] ?? '{}'), true) ?: [];
    foreach (['due_at', 'starts_at', 'ends_at', 'done_at'] as $k) { $r[$k] = ($r[$k] ?? null) === null ? null : (new DateTimeImmutable($r[$k]))->setTimezone(new DateTimeZone('UTC'))->format(DATE_ATOM); }
    return $r;
}

/** The fields of a new activity at a moment for its kind: a span's start (and the end when given) or a due time. */
function activity_when_fields(string $kind, string $at, ?string $ends = null): array
{
    return in_array($kind, ACTIVITY_TIMED, true) ? ['starts_at' => $at, 'ends_at' => $ends, 'due_at' => null] : ['due_at' => $at, 'starts_at' => null, 'ends_at' => null];
}

/**
 * Done (activities-calendar.md §3; divergence 1): the done time, the outcome (the kind's default when none — the trigger's), the note; then the next
 * step in the same transaction when one is asked for — on the same records, for the same person. Answers {activity_id, next_activity_id}.
 */
function activity_complete(PDO $pdo, int $id, array $f): array
{
    $st = $pdo->prepare('UPDATE activities SET done_at = CAST(:d AS timestamptz), outcome = :o, outcome_note = :n WHERE id = :id AND done_at IS NULL AND deleted_at IS NULL RETURNING id');
    $st->execute(['d' => $f['done_at'] ?? gmdate(DATE_ATOM), 'o' => $f['outcome'] ?? null, 'n' => $f['outcome_note'] ?? null, 'id' => $id]);
    if ($st->fetchColumn() === false) {
        throw new DomainException('That activity is done already.');
    }
    $next = null;
    if (($f['next'] ?? null) !== null) {
        $a = activity_current($pdo, $id);
        $n = $f['next'];
        $next = activity_save($pdo, null, ['kind' => $n['kind'], 'subject' => $n['subject'], 'deal_id' => $a['deal_id'], 'contact_id' => $a['contact_id'], 'account_id' => $a['account_id'],
            'lead_id' => $a['lead_id'], 'owner_member_id' => $a['owner_member_id'], 'source' => default_activity_kind_source()] + activity_when_fields($n['kind'], $n['at']), (int) current_member_id());
    }
    return ['activity_id' => $id, 'next_activity_id' => $next];
}

/** The activity's source column from the request's door (the log's source): an agent's run, the command bar's assistant, a key, a person. */
function default_activity_kind_source(): string
{
    return match (default_activity_source()) { 'agent' => 'agent', 'assistant' => 'command_bar', 'api' => 'api', 'cron' => 'cron', default => 'web' };
}

/** Done → open again (the trigger clears the outcome and who did it; the reminder's moment comes back). */
function activity_reopen(PDO $pdo, int $id): void
{
    $st = $pdo->prepare('UPDATE activities SET done_at = NULL, outcome_note = NULL WHERE id = :id AND done_at IS NOT NULL RETURNING id');
    $st->execute(['id' => $id]);
    if ($st->fetchColumn() === false) {
        throw new DomainException('That activity is still open.');
    }
}

/**
 * A new time (the calendar's drag, the activity's Reschedule): a span keeps its length unless an end is given; a due time moves; a snooze is
 * cleared; the trigger re-arms the reminder. Answers {before, after} for the log.
 */
function activity_reschedule(PDO $pdo, int $id, string $at, ?string $ends, ?bool $allDay): array
{
    $a = activity_current($pdo, $id);
    $before = ['starts_at' => $a['starts_at'], 'ends_at' => $a['ends_at'], 'due_at' => $a['due_at'], 'all_day' => $a['all_day']];
    if ($a['starts_at'] !== null) {
        $len = $a['ends_at'] === null ? null : (new DateTimeImmutable($a['ends_at']))->getTimestamp() - (new DateTimeImmutable($a['starts_at']))->getTimestamp();
        $set = ['starts_at' => $at, 'ends_at' => $ends ?? ($len === null ? null : (new DateTimeImmutable($at))->modify('+' . $len . ' seconds')->format(DATE_ATOM))];
    } else {
        $set = ['due_at' => $at];
    }
    if ($allDay !== null) {
        $set['all_day'] = $allDay;
    }
    if (isset($set['ends_at']) && $set['ends_at'] !== null && $set['ends_at'] < $set['starts_at']) {
        throw new DomainException('The end is before the start.');
    }
    activity_save($pdo, $id, $set, (int) current_member_id());
    $pdo->prepare('UPDATE activities SET snoozed_until = NULL WHERE id = :id')->execute(['id' => $id]);
    $after = activity_current($pdo, $id);
    return ['before' => $before, 'after' => ['starts_at' => $after['starts_at'], 'ends_at' => $after['ends_at'], 'due_at' => $after['due_at'], 'all_day' => $after['all_day']]];
}

/** A snooze's moment: 10m, 1h, tomorrow (09:00 in the owner's zone), or a time (in the caller's zone); null when it is none of these or not ahead. */
function snooze_until(string $v, string $ownerTz, string $tz, ?DateTimeImmutable $now = null): ?string
{
    $now ??= new DateTimeImmutable();
    $at = match (trim($v)) {
        '10m' => $now->modify('+10 minutes'),
        '1h' => $now->modify('+1 hour'),
        'tomorrow' => $now->setTimezone(new DateTimeZone($ownerTz !== '' ? $ownerTz : 'UTC'))->modify('tomorrow 09:00'),
        default => null,
    };
    if ($at === null) {
        $t = activity_time($v, $tz);
        if (!is_string($t)) {
            return null;
        }
        $at = new DateTimeImmutable($t);
    }
    return $at <= $now ? null : $at->setTimezone(new DateTimeZone('UTC'))->format(DATE_ATOM);
}

/** Snooze (crm_activity_snooze: the reminder fires again then). */
function activity_snooze(PDO $pdo, int $id, string $until): void
{
    $pdo->prepare('SELECT crm_activity_snooze(:id, CAST(:u AS timestamptz))')->execute(['id' => $id, 'u' => $until]);
}

/** Give the activity to someone else (a person or an agent); the new one hears of it. Answers the previous owner. */
function activity_assign(PDO $pdo, int $id, int $owner): ?int
{
    $prev = one_value($pdo, 'SELECT owner_member_id FROM activities WHERE id = :id', ['id' => $id]);
    $pdo->prepare('UPDATE activities SET owner_member_id = :o WHERE id = :id')->execute(['o' => $owner, 'id' => $id]);
    activity_tell_assignee($pdo, $id, $owner);
    return $prev === null ? null : (int) $prev;
}

/** The person an activity was given to (never the caller, never an agent — its reminder is a dispatch) hears of it: a notification of kind assigned. */
function activity_tell_assignee(PDO $pdo, int $id, ?int $owner): void
{
    $me = (int) current_member_id();
    if ($owner === null || $owner === $me || one_value($pdo, "SELECT member_kind FROM members WHERE id = :m", ['m' => $owner]) !== 'human') {
        return;
    }
    $a = one_row($pdo, 'SELECT kind, subject, deal_id, contact_id FROM activities WHERE id = :id', ['id' => $id]);
    $pdo->prepare("SELECT crm_notify(:m, 'assigned', :t, :b, 'activity', :id, :d, :c, :id, :me, NULL)")
        ->execute(['m' => $owner, 't' => 'Yours: ' . ACTIVITY_KINDS[$a['kind']] . ' — ' . $a['subject'], 'b' => (string) member_name($pdo, $me) . ' gave you this.', 'id' => $id,
                   'd' => $a['deal_id'], 'c' => $a['contact_id'], 'me' => $me]);
}

/** Add a participant (a contact or a colleague); already there → nothing. Answers whether a row was added. */
function participant_add(PDO $pdo, int $id, ?int $contact, ?int $member): bool
{
    $st = $pdo->prepare("INSERT INTO activity_participants (activity_id, contact_id, member_id, response) VALUES (:a, :c, :m, 'invited') ON CONFLICT DO NOTHING RETURNING activity_id");
    $st->execute(['a' => $id, 'c' => $contact, 'm' => $member]);
    return $st->fetchColumn() !== false;
}

/** Take a participant off. Answers whether a row went. */
function participant_remove(PDO $pdo, int $id, ?int $contact, ?int $member): bool
{
    $st = $pdo->prepare('DELETE FROM activity_participants WHERE activity_id = :a AND contact_id IS NOT DISTINCT FROM :c AND member_id IS NOT DISTINCT FROM :m RETURNING activity_id');
    $st->execute(['a' => $id, 'c' => $contact, 'm' => $member]);
    return $st->fetchColumn() !== false;
}

/** The log's picture of an activity: ids, kind, subject, times, owner — never the body. */
function activity_log_facts(array $a): array
{
    return ['kind' => $a['kind'], 'subject' => $a['subject'], 'at' => $a['starts_at'] ?? $a['due_at'], 'owner_member_id' => $a['owner_member_id'], 'deal_id' => $a['deal_id'],
        'contact_id' => $a['contact_id'], 'account_id' => $a['account_id'], 'lead_id' => $a['lead_id'], 'done' => $a['done_at'] !== null];
}

/** The audit keys an activity's log row carries (the record's timeline is one index). */
function activity_log_keys(array $a): array
{
    return array_filter(['deal_id' => $a['deal_id'], 'contact_id' => $a['contact_id'], 'account_id' => $a['account_id'], 'lead_id' => $a['lead_id']], static fn ($v) => $v !== null);
}
