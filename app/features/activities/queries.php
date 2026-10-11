<?php
declare(strict_types=1);

/**
 * Activities' reads (slice 4, THE SECOND EXEMPLAR; activities-calendar.md): one table holds every touch and every plan (db/010) — the reminder is
 * its columns, the next step on every record is kept by trigger. Every read goes through mcp_activities (the wall of the record it belongs to, a
 * private one to its seller and the managers) or crm_calendar() / crm_my_activities() (db/017, the same rules); PHP never re-implements them.
 */
require_once __DIR__ . '/../records/queries.php';

const ACTIVITY_KINDS = ['call' => 'Call', 'meeting' => 'Meeting', 'task' => 'Task', 'deadline' => 'Deadline', 'lunch' => 'Lunch', 'email' => 'Email'];
/** The kinds that happen over a span (a start and an end); the others are due at a moment. */
const ACTIVITY_TIMED = ['call', 'meeting', 'lunch'];
/** The kinds a person schedules on the form (an email is logged by slice 5's form or the mailbox). */
const ACTIVITY_FORM_KINDS = ['call', 'meeting', 'task', 'deadline', 'lunch'];
/** Each kind's outcomes, the default first (the trigger's default when none is said). */
const ACTIVITY_OUTCOMES = [
    'call' => ['connected', 'no_answer', 'left_message', 'busy', 'cancelled'],
    'meeting' => ['meeting_held', 'no_show', 'cancelled'],
    'lunch' => ['meeting_held', 'no_show', 'cancelled'],
    'task' => ['completed', 'cancelled'],
    'deadline' => ['completed', 'cancelled'],
    'email' => ['sent', 'replied'],
];
const OUTCOME_WORDS = ['connected' => 'Connected', 'no_answer' => 'No answer', 'left_message' => 'Left a message', 'busy' => 'Busy', 'meeting_held' => 'Held', 'no_show' => 'No-show',
    'sent' => 'Sent', 'replied' => 'Replied', 'completed' => 'Done', 'cancelled' => 'Cancelled'];
const ACTIVITY_STATE_WORDS = ['overdue' => 'Overdue', 'today' => 'Today', 'scheduled' => 'Planned', 'done' => 'Done', 'none' => '—'];
const ACTIVITY_STATES = ['open' => 'Open', 'today' => 'Today', 'overdue' => 'Overdue', 'done' => 'Done', 'all' => 'All'];
const ACTIVITY_PRIORITIES = ['low' => 'Low', 'normal' => 'Normal', 'high' => 'High'];
/** The reminder's choices on the form (minutes before; '' = the default). */
const ACTIVITY_REMIND_CHOICES = ['' => 'The default', '0' => 'At the time', '5' => '5 minutes before', '10' => '10 minutes before', '15' => '15 minutes before', '30' => '30 minutes before',
    '60' => '1 hour before', '120' => '2 hours before', '1440' => '1 day before', 'none' => 'No reminder'];
/** A recurring task's rules (an RRULE the worker expands one occurrence ahead). */
const RECURRENCE_CHOICES = ['' => 'Does not repeat', 'FREQ=DAILY' => 'Every day', 'FREQ=WEEKLY' => 'Every week', 'FREQ=WEEKLY;INTERVAL=2' => 'Every 2 weeks', 'FREQ=MONTHLY' => 'Every month'];
/** The next-step form's quick choices (log forms and Done): [value => words]. A date or a time is the fourth. */
const NEXT_WHEN_CHOICES = ['tomorrow' => 'Tomorrow 10:00', 'in3days' => 'In 3 days', 'nextweek' => 'Next week'];

/**
 * A time from a request in the person's zone, in UTC (ISO 8601): an instant with an offset or Z as it is; 'YYYY-MM-DDTHH:MM' (a datetime-local
 * input) or 'YYYY-MM-DD HH:MM' in $tz; 'YYYY-MM-DD' alone at $defaultTime in $tz. Null when empty, false when it is not a time.
 */
function activity_time(?string $v, string $tz, string $defaultTime = '09:00'): string|false|null
{
    $v = trim((string) $v);
    if ($v === '') {
        return null;
    }
    try {
        $zone = new DateTimeZone($tz !== '' ? $tz : 'UTC');
    } catch (Exception) {
        $zone = new DateTimeZone('UTC');
    }
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $v)) {
        $v .= 'T' . $defaultTime;
    }
    if (preg_match('/^(\d{4}-\d{2}-\d{2})[T ](\d{2}:\d{2})(:\d{2})?$/', $v, $m)) {
        $dt = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $m[1] . ' ' . $m[2] . ($m[3] ?? ':00'), $zone);
        if ($dt === false || $dt->format('Y-m-d H:i') !== $m[1] . ' ' . $m[2]) {
            return false;
        }
        return $dt->setTimezone(new DateTimeZone('UTC'))->format(DATE_ATOM);
    }
    if (preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}(:\d{2}(\.\d+)?)?(Z|[+-]\d{2}:?\d{2})$/', $v)) {
        try {
            return (new DateTimeImmutable($v))->setTimezone(new DateTimeZone('UTC'))->format(DATE_ATOM);
        } catch (Exception) {
            return false;
        }
    }
    return false;
}

/** The next step's quick choice as a time: tomorrow 10:00, in three days 10:00, next Monday 10:00 (in $tz), else activity_time(). */
function next_step_time(?string $v, string $tz, ?DateTimeImmutable $now = null): string|false|null
{
    $v = trim((string) $v);
    $zone = new DateTimeZone($tz !== '' ? $tz : 'UTC');
    $now = ($now ?? new DateTimeImmutable())->setTimezone($zone);
    $at = match ($v) {
        'tomorrow' => $now->modify('tomorrow 10:00'),
        'in3days' => $now->modify('+3 days')->setTime(10, 0),
        'nextweek' => $now->modify('next monday 10:00'),
        default => null,
    };
    return $at === null ? activity_time($v, $tz, '10:00') : $at->setTimezone(new DateTimeZone('UTC'))->format(DATE_ATOM);
}

/** A UTC time as the value of a datetime-local input in $tz ('' for none). */
function local_input(?string $utc, string $tz): string
{
    return $utc === null || $utc === '' ? '' : format_ts($utc, $tz, 'Y-m-d\TH:i');
}

/** The record an activity belongs to, the most specific first: [kind, id, name, url] or null for a bare task. */
function activity_record(array $a): ?array
{
    foreach ([['deal', 'deal_id', 'deal_title'], ['contact', 'contact_id', 'contact_name'], ['account', 'account_id', 'account_name'], ['lead', 'lead_id', 'lead_name']] as [$k, $col, $name]) {
        if (($a[$col] ?? null) !== null) {
            return [$k, (int) $a[$col], (string) ($a[$name] ?? ($a['record_name'] ?? '')), '/' . $k . 's/' . (int) $a[$col]];
        }
    }
    return null;
}

/** The record a calendar or agenda row belongs to (crm_calendar's columns): [kind, id, url] or null. */
function row_record(array $r): ?array
{
    foreach (['deal' => 'deal_id', 'contact' => 'contact_id', 'account' => 'account_id', 'lead' => 'lead_id'] as $k => $col) {
        if (($r[$col] ?? null) !== null) {
            return [$k, (int) $r[$col], '/' . $k . 's/' . (int) $r[$col]];
        }
    }
    return null;
}

/** One activity the caller may see (mcp_activities: the wall), with the names of its record, its owner and its maker; null when not. */
function find_activity(PDO $pdo, int $id): ?array
{
    return one_row($pdo, "SELECT a.*, o.display_name AS owner_name, o.member_kind AS owner_kind, mk.display_name AS created_by_name, db.display_name AS done_by_name,
                                 d.title AS deal_title, d.number AS deal_number, d.status AS deal_status, d.next_activity_id AS deal_next_activity_id,
                                 c.name AS contact_name, x.name AS account_name, l.label AS lead_name
                            FROM mcp_activities a
                            LEFT JOIN members o ON o.id = a.owner_member_id LEFT JOIN members mk ON mk.id = a.created_by LEFT JOIN members db ON db.id = a.done_by
                            LEFT JOIN mcp_deals d ON d.deal_id = a.deal_id LEFT JOIN mcp_contacts c ON c.contact_id = a.contact_id
                            LEFT JOIN mcp_accounts x ON x.account_id = a.account_id LEFT JOIN mcp_leads l ON l.lead_id = a.lead_id
                           WHERE a.activity_id = :id", ['id' => $id]);
}

/** Who else takes part: contacts (with their account) and colleagues, by name. [{contact_id|member_id, name, detail, response}] */
function activity_participants(PDO $pdo, int $id): array
{
    $st = $pdo->prepare("SELECT p.contact_id, p.member_id, p.response, COALESCE(c.name, m.display_name) AS name, CASE WHEN p.contact_id IS NOT NULL THEN c.account_name ELSE 'a colleague' END AS detail
                           FROM mcp_activity_participants p LEFT JOIN mcp_contacts c ON c.contact_id = p.contact_id LEFT JOIN members m ON m.id = p.member_id
                          WHERE p.activity_id = :id AND (p.member_id IS NOT NULL OR c.contact_id IS NOT NULL) ORDER BY p.member_id NULLS FIRST, 4");
    $st->execute(['id' => $id]);
    return $st->fetchAll();
}

/** A contact's open deals (the quick-log forms' radio buttons): the deals they are the primary contact of or take part in. */
function open_deals_of_contact(PDO $pdo, int $contact): array
{
    $st = $pdo->prepare("SELECT d.deal_id, d.number, d.title, d.stage_name, d.value, d.currency FROM mcp_deals d
                          WHERE d.status = 'open' AND (d.primary_contact_id = :c OR EXISTS (SELECT 1 FROM mcp_deal_contacts dc WHERE dc.deal_id = d.deal_id AND dc.contact_id = :c))
                          ORDER BY d.next_activity_at NULLS FIRST, d.updated_at DESC LIMIT 20");
    $st->execute(['c' => $contact]);
    return $st->fetchAll();
}

/**
 * The rules of one activity for the caller (activities-reminders.md "Who may do what"): own = the maker or the one who does it (the assignee).
 * edit/assign/participants: own with activities.write, or activities.any · complete/snooze: own or the assignee, or activities.any ·
 * reschedule: own, the assignee, or calendar.team.
 */
function activity_may(array $a): array
{
    $me = (int) current_member_id();
    $own = ((int) ($a['created_by'] ?? 0) === $me || (int) ($a['owner_member_id'] ?? 0) === $me);
    $any = has_right('activities.any');
    $write = has_right('activities.write');
    $open = ($a['done_at'] ?? null) === null;
    return [
        'edit' => ($own && $write) || $any,
        'complete' => $open && (($own && $write) || $any),
        'reopen' => !$open && (($own && $write) || $any),
        'snooze' => $open && (($own && $write) || $any) && ($a['owner_member_id'] ?? null) !== null,
        'reschedule' => $open && (($own && $write) || $any || has_right('calendar.team')),
        'assign' => ($own && $write) || $any,
        'delete' => ($own && $write) || $any,
    ];
}

/** Refused in words when the caller may not do $what to the activity (403). */
function require_activity_may(array $a, string $what): void
{
    $may = activity_may($a);
    if (!($may[$what] ?? false)) {
        if (in_array($what, ['complete', 'snooze', 'reschedule'], true) && ($a['done_at'] ?? null) !== null) {
            refuse(422, 'That activity is done already.');
        }
        if ($what === 'reopen' && ($a['done_at'] ?? null) === null) {
            refuse(422, 'That activity is still open.');
        }
        $who = (string) ($a['owner_name'] ?? '');
        refuse(403, 'Only ' . ($who !== '' ? $who . ', who does it,' : 'the one who does it') . ', the one who made it' . ($what === 'reschedule' ? ', the team\'s manager' : '') . ' or a manager may change this activity.');
    }
}

/** An activity's name for the picker and the log: "Call: Discovery". */
function activity_label(PDO $pdo, int $id): ?string
{
    $r = one_row($pdo, 'SELECT kind, subject FROM mcp_activities WHERE activity_id = :id', ['id' => $id]);
    return $r === null ? null : ACTIVITY_KINDS[$r['kind']] . ': ' . $r['subject'];
}

/** The picker: activities the caller may see, open first, by subject. */
function activities_for_pick(PDO $pdo, string $q, array $params, int $page = 1): array
{
    return crm_simple_pick($pdo, "SELECT a.activity_id, a.kind, a.subject, a.at, a.state, count(*) OVER () AS total FROM mcp_activities a
                                   WHERE (CAST(:deal AS bigint) IS NULL OR a.deal_id = CAST(:deal AS bigint)) AND (:q = '' OR a.subject ILIKE '%' || :q || '%')
                                   ORDER BY (a.done_at IS NULL) DESC, a.at DESC", $q, $page,
        static fn (array $r): array => ['id' => (int) $r['activity_id'], 'label' => ACTIVITY_KINDS[$r['kind']] . ': ' . $r['subject'], 'detail' => format_ts($r['at'], member_timezone(), 'M j, g:i A'),
            'badge' => ACTIVITY_STATE_WORDS[$r['state']] ?? null, 'color' => ['overdue' => 'danger', 'today' => 'warning', 'done' => 'secondary'][$r['state']] ?? 'success'], ['deal' => $params['deal'] ?? null]);
}

/** The picker: colleagues — the people (not agents) who hold a role here. */
function colleagues_for_pick(PDO $pdo, string $q, array $params, int $page = 1): array
{
    return crm_simple_pick($pdo, "SELECT member_id, display_name, job_title, count(*) OVER () AS total FROM mcp_members
                                   WHERE member_kind = 'human' AND (:q = '' OR display_name ILIKE '%' || :q || '%' OR email ILIKE '%' || :q || '%') ORDER BY display_name", $q, $page,
        static fn (array $r): array => ['id' => (int) $r['member_id'], 'label' => $r['display_name'], 'detail' => (string) ($r['job_title'] ?? '')]);
}

/**
 * Who a list or a calendar is for (`who`): 'me' (the default), a member's id (another seller: calendar.read), 'team:<department>' (calendar.team on
 * the calendar), 'all' (the list only). Answers [kind me|member|team|all, member, team, key, label]; a refusal in words when the caller may not.
 */
function activity_who(PDO $pdo, string $who, bool $allowAll = false, bool $teamRight = true): array
{
    $me = (int) current_member_id();
    $who = trim($who);
    if ($who === '' || $who === 'me' || $who === (string) $me) {
        return ['kind' => 'me', 'member' => $me, 'team' => null, 'key' => 'me', 'label' => 'Mine'];
    }
    if ($who === 'all' && $allowAll) {
        return ['kind' => 'all', 'member' => null, 'team' => null, 'key' => 'all', 'label' => 'Everyone\'s'];
    }
    if (preg_match('/^team:(\d+)$/', $who, $m)) {
        if ($teamRight && !has_right('calendar.team')) {
            refuse(403, 'Seeing a team\'s calendar needs the team calendar right — a Sales Manager\'s.');
        }
        $name = one_value($pdo, 'SELECT name FROM mcp_departments WHERE department_id = :d', ['d' => (int) $m[1]]);
        if ($name === null) {
            refuse(404, 'Team not found.');
        }
        return ['kind' => 'team', 'member' => null, 'team' => (int) $m[1], 'key' => 'team:' . (int) $m[1], 'label' => (string) $name];
    }
    if (ctype_digit($who)) {
        if (!has_right('calendar.read')) {
            refuse(403, 'Seeing a colleague\'s calendar needs the calendar right.');
        }
        $name = one_value($pdo, 'SELECT display_name FROM mcp_members WHERE member_id = :m', ['m' => (int) $who]);
        if ($name === null) {
            refuse(404, 'Person not found.');
        }
        return ['kind' => 'member', 'member' => (int) $who, 'team' => null, 'key' => $who, 'label' => (string) $name];
    }
    refuse(422, 'Say whose: me, a person, or team:<department>.');
}

/**
 * The activities list (screen `activity-list`; the find_activities resolver): filters who (me, a member, team:<dept>, all), state (open, today,
 * overdue, done, all), kind[], from/to (dates in the caller's zone), account, contact, deal, lead, q (the subject), source. 50 a page, the open
 * by when, the done most recent first. [rows, total, page, pages]
 */
function find_activities(PDO $pdo, array $f, int $page = 1): array
{
    $tz = member_timezone();
    $where = ['true'];
    $args = [];
    $who = $f['who'] ?? ['kind' => 'me', 'member' => (int) current_member_id()];
    if ($who['kind'] === 'me' || $who['kind'] === 'member') { $where[] = 'a.owner_member_id = :who'; $args['who'] = $who['member']; }
    elseif ($who['kind'] === 'team') { $where[] = 'a.owner_member_id IN (SELECT dm.member_id FROM department_members dm WHERE dm.department_id = :team AND dm.left_at IS NULL)'; $args['team'] = $who['team']; }
    $state = $f['state'] ?? 'open';
    $d0 = new DateTimeImmutable('today 00:00', new DateTimeZone($tz));
    $where[] = match ($state) {
        'done' => 'a.done_at IS NOT NULL',
        'overdue' => 'a.done_at IS NULL AND a.at < :d0',
        'today' => 'a.at >= :d0 AND a.at < :d1',
        'all' => 'true',
        default => 'a.done_at IS NULL',
    };
    if ($state === 'overdue' || $state === 'today') { $args['d0'] = $d0->format(DATE_ATOM); }
    if ($state === 'today') { $args['d1'] = $d0->modify('+1 day')->format(DATE_ATOM); }
    if (($f['kinds'] ?? []) !== []) { $where[] = 'a.kind = ANY (CAST(:kinds AS text[]))'; $args['kinds'] = pg_array_literal($f['kinds']); }
    foreach (['account', 'contact', 'deal', 'lead'] as $k) {
        if (($f[$k] ?? null) !== null) { $where[] = "a.{$k}_id = :$k"; $args[$k] = (int) $f[$k]; }
    }
    if (($f['from'] ?? null) !== null) { $where[] = 'a.at >= CAST(:from AS date) AT TIME ZONE :ftz'; $args['from'] = $f['from']; $args['ftz'] = $tz; }
    if (($f['to'] ?? null) !== null) { $where[] = 'a.at < (CAST(:to AS date) + 1) AT TIME ZONE :ttz'; $args['to'] = $f['to']; $args['ttz'] = $tz; }
    if (($f['q'] ?? '') !== '') { $where[] = "a.subject ILIKE '%' || :q || '%'"; $args['q'] = mb_substr((string) $f['q'], 0, 120); }
    if (($f['source'] ?? null) !== null) { $where[] = 'a.source = :source'; $args['source'] = $f['source']; }
    $order = $state === 'done' ? 'a.done_at DESC, a.activity_id DESC' : ($state === 'all' ? '(a.done_at IS NULL) DESC, a.at, a.activity_id' : 'a.at, a.activity_id');
    $size = 50;
    $page = max(1, $page);
    $sql = "SELECT a.activity_id, a.kind, a.subject, a.at, a.starts_at, a.ends_at, a.due_at, a.all_day, a.state, a.done_at, a.outcome, a.priority, a.owner_member_id, a.created_by,
                   a.deal_id, a.contact_id, a.account_id, a.lead_id, a.location, a.recurrence, a.source, a.remind_at, a.snoozed_until,
                   o.display_name AS owner_name, d.title AS deal_title, c.name AS contact_name, x.name AS account_name, l.label AS lead_name, count(*) OVER () AS total
              FROM mcp_activities a LEFT JOIN members o ON o.id = a.owner_member_id
              LEFT JOIN mcp_deals d ON d.deal_id = a.deal_id LEFT JOIN mcp_contacts c ON c.contact_id = a.contact_id
              LEFT JOIN mcp_accounts x ON x.account_id = a.account_id LEFT JOIN mcp_leads l ON l.lead_id = a.lead_id
             WHERE " . implode(' AND ', $where) . " ORDER BY $order LIMIT $size OFFSET " . (($page - 1) * $size);
    $st = $pdo->prepare($sql);
    $st->execute($args);
    $rows = $st->fetchAll();
    $total = $rows === [] ? 0 : (int) $rows[0]['total'];
    return ['rows' => $rows, 'total' => $total, 'page' => $page, 'pages' => max(1, (int) ceil($total / $size))];
}

/** The list's filters from the request (and a saved view's: owner → who, when → state, kind → kinds). */
function activity_filters_from_request(PDO $pdo, ?array $view = null): array
{
    $src = $_GET;
    if ($view !== null) {
        $vf = $view['filters'] ?? [];
        $src += ['who' => ($vf['owner'] ?? 'me') === 'me' ? 'me' : (string) ($vf['owner'] ?? 'me'), 'state' => ['today' => 'today', 'overdue' => 'overdue', 'done' => 'done'][$vf['when'] ?? ''] ?? ($vf['state'] ?? 'open')]
            + (isset($vf['kind']) ? ['kind' => $vf['kind']] : []);
    }
    $state = (string) ($src['state'] ?? 'open');
    $kinds = $src['kind'] ?? [];
    $kinds = array_values(array_intersect(is_array($kinds) ? $kinds : explode(',', (string) $kinds), array_keys(ACTIVITY_KINDS)));
    $date = static fn (string $k): ?string => isset($src[$k]) && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $src[$k]) ? (string) $src[$k] : null;
    $int = static fn (string $k): ?int => isset($src[$k]) && ctype_digit((string) $src[$k]) ? (int) $src[$k] : null;
    return [
        'who' => activity_who($pdo, (string) ($src['who'] ?? (($int('account') ?? $int('contact') ?? $int('deal') ?? $int('lead')) !== null ? 'all' : 'me')), true, false),
        'state' => array_key_exists($state, ACTIVITY_STATES) ? $state : 'open',
        'kinds' => $kinds, 'from' => $date('from'), 'to' => $date('to'),
        'account' => $int('account'), 'contact' => $int('contact'), 'deal' => $int('deal'), 'lead' => $int('lead'),
        'q' => mb_substr(trim((string) ($src['q'] ?? '')), 0, 120), 'view' => $view === null ? null : (int) $view['saved_view_id'],
    ];
}

/** The list's filters as a query string (for paging, the chips and a saved view). */
function activity_query(array $f, array $over = []): string
{
    $q = ['who' => $f['who']['key'], 'state' => $f['state'], 'kind' => $f['kinds'] === [] ? null : implode(',', $f['kinds']), 'from' => $f['from'], 'to' => $f['to'],
          'account' => $f['account'], 'contact' => $f['contact'], 'deal' => $f['deal'], 'lead' => $f['lead'], 'q' => $f['q'] === '' ? null : $f['q']];
    $q = array_merge($q, $over);
    if (($q['who'] ?? null) === 'me') { unset($q['who']); }
    if (($q['state'] ?? null) === 'open') { unset($q['state']); }
    return http_build_query(array_filter($q, static fn ($v) => $v !== null && $v !== ''));
}

/**
 * The agenda (the phone's calendar view and `my_activities`): overdue (open, before today — 90 days back), today (done ones too), tomorrow, the
 * rest of the week — for me, a colleague (calendar.read) or a team (calendar.team), through crm_calendar(). $at moves "today".
 */
function agenda(PDO $pdo, ?int $member, ?int $team = null, ?array $kinds = null, ?string $at = null): array
{
    $tz = member_timezone();
    $zone = new DateTimeZone($tz);
    $d0 = new DateTimeImmutable(($at ?? 'today') . ' 00:00', $zone);
    $d1 = $d0->modify('+1 day');
    $d2 = $d0->modify('+2 days');
    $d7 = $d0->modify('+7 days');
    $rows = calendar_query($pdo, $d0->modify('-90 days'), $d7, $member, $team, $kinds);
    $out = ['overdue' => [], 'today' => [], 'tomorrow' => [], 'week' => []];
    foreach ($rows as $r) {
        $t = new DateTimeImmutable($r['starts_at']);
        if ($t < $d0) {
            if (!$r['done']) { $out['overdue'][] = $r; }
        } elseif ($t < $d1) { $out['today'][] = $r; }
        elseif ($t < $d2) { $out['tomorrow'][] = $r; }
        else { $out['week'][] = $r; }
    }
    return $out;
}

/** crm_calendar() for a window and a who (the one query of the calendar, the agenda and the `calendar` tool). */
function calendar_query(PDO $pdo, DateTimeImmutable $from, DateTimeImmutable $to, ?int $member, ?int $team, ?array $kinds, bool $includeDone = true): array
{
    $st = $pdo->prepare('SELECT * FROM crm_calendar(:f, :t, :m, :team, CAST(:k AS text[]), :d)');
    $st->bindValue('f', $from->format(DATE_ATOM));
    $st->bindValue('t', $to->format(DATE_ATOM));
    $st->bindValue('m', $member, $member === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
    $st->bindValue('team', $team, $team === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
    $st->bindValue('k', $kinds === null || $kinds === [] ? null : pg_array_literal($kinds), $kinds === null || $kinds === [] ? PDO::PARAM_NULL : PDO::PARAM_STR);
    $st->bindValue('d', $includeDone, PDO::PARAM_BOOL);
    $st->execute();
    return $st->fetchAll();
}

/** The JSON shape of a calendar or agenda row (whitelisted). */
function present_calendar_row(array $r): array
{
    return ['activity_id' => (int) $r['activity_id'], 'kind' => $r['kind'], 'subject' => $r['subject'], 'starts_at' => json_ts($r['starts_at']), 'ends_at' => json_ts($r['ends_at']),
        'all_day' => (bool) $r['all_day'], 'owner_member_id' => $r['owner_member_id'] === null ? null : (int) $r['owner_member_id'], 'owner_name' => $r['owner_name'],
        'done' => (bool) $r['done'], 'state' => $r['state'], 'deal_id' => $r['deal_id'] === null ? null : (int) $r['deal_id'], 'contact_id' => $r['contact_id'] === null ? null : (int) $r['contact_id'],
        'account_id' => $r['account_id'] === null ? null : (int) $r['account_id'], 'lead_id' => $r['lead_id'] === null ? null : (int) $r['lead_id'],
        'record_name' => $r['record_name'], 'location' => $r['location'], 'priority' => $r['priority']];
}
