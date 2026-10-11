<?php
declare(strict_types=1);
/**
 * Actions `activity_create` (no `activity`; log activity.create — activities.write and see on the record) and `activity_update` (`activity` + any field;
 * log activity.update — own or activities.any). Two shapes (activities-calendar.md §2): SCHEDULE (open — `due_at` for a task or a deadline, `starts_at` /
 * `ends_at` for a call, a meeting or a lunch; `at` stands for either) and LOG (`done` = yes: done now or at the time said, the outcome, the body) — the
 * quick-log forms /log/call and /log/meeting post here, and with `next_kind`, `next_subject`, `next_at` (tomorrow, in3days, nextweek or a time) the
 * next step is made in the same request (divergence 2): `record_id` is the touch, `next_activity_id` the step. The record: `deal` first (the trigger fills
 * the account and contact from it), else `contact`, `account`, `lead`; a bare task needs none. Times without a zone are the person's (or `timezone`'s).
 * `remind_minutes_before`: 0–10080, blank = the default, `none` = no reminder. A field left out stays as it was.
 */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_once dirname(__DIR__, 2) . '/app/features/activities/write.php';
require_once dirname(__DIR__, 2) . '/app/features/tags/queries.php';
require_once dirname(__DIR__, 2) . '/app/features/tags/write.php';
crm_handler_begin();
$pdo = db();
$me = (int) current_member_id();
$id = request_integer('activity') ?? request_integer('id');
if ($id === null) {
    require_right('activities.write');
    $cur = array_merge(array_fill_keys(ACTIVITY_COLUMNS, null), ['custom_values' => [], 'all_day' => false, 'priority' => 'normal', 'created_by' => $me]);
    $a = null;
} else {
    require_record($pdo, 'activity', $id);
    $a = find_activity($pdo, $id);
    require_activity_may($a, 'edit');
    $cur = activity_current($pdo, $id);
}
$errors = [];
$f = [];
$f['kind'] = crm_choice('kind', $cur['kind'], array_keys(ACTIVITY_KINDS), 'The kind', $errors, false);
if ($id === null && $f['kind'] === null && !isset($errors['kind'])) { $errors['kind'] = 'Say what kind: a call, a meeting, a task, a deadline or a lunch.'; }
$kind = (string) ($f['kind'] ?? 'task');
$done = $id === null && crm_yes('done');
$tzName = crm_text('timezone', $cur['timezone'], 64, 'The time zone', $errors);
if ($tzName !== null && !in_array($tzName, DateTimeZone::listIdentifiers(), true)) { $errors['timezone'] = 'The time zone is a name like Europe/London.'; $tzName = $cur['timezone']; }
$f['timezone'] = $tzName;
$tz = $tzName ?? member_timezone();
// The record: a deal (its account and primary contact follow), else a contact, an account, a lead — each one the caller may see.
foreach (['deal' => ['mcp_deals', 'deal_id', 'deal'], 'contact' => ['mcp_contacts', 'contact_id', 'contact'], 'account' => ['mcp_accounts', 'account_id', 'account'], 'lead' => ['mcp_leads', 'lead_id', 'lead']] as $k => [$v, $col, $noun]) {
    $f[$k . '_id'] = crm_ref($pdo, $k, $cur[$k . '_id'], "SELECT 1 FROM $v WHERE $col = :id", $noun, $errors);
}
if (req_has('deal') && $f['deal_id'] !== $cur['deal_id']) {                      // another deal: its account and contact unless the request names them
    if (!req_has('account')) { $f['account_id'] = null; }
    if (!req_has('contact')) { $f['contact_id'] = null; }
}
if ($f['deal_id'] !== null && $f['account_id'] !== null && !isset($errors['account'])
    && ($dacc = one_value($pdo, 'SELECT account_id FROM mcp_deals WHERE deal_id = :d', ['d' => $f['deal_id']])) !== null && (int) $dacc !== $f['account_id']) {
    $errors['account'] = 'The deal belongs to another account.';
}
if ($f['deal_id'] === null && $f['contact_id'] === null && $f['account_id'] === null && $f['lead_id'] === null && $kind !== 'task' && array_intersect_key($errors, array_flip(['kind', 'deal', 'contact', 'account', 'lead'])) === []) {
    $errors['deal'] = 'Say what it is about: a deal, a contact, an account or a lead (only a task may stand alone).';
}
// The subject: a log says what happened without one ("Call with Pat Lee").
$f['subject'] = crm_text('subject', $cur['subject'], 300, 'The subject', $errors, !$done);
if ($id === null && $f['subject'] === null && !isset($errors['subject'])) {
    if ($done) {
        $cid = $f['contact_id'] ?? ($f['deal_id'] !== null ? one_value($pdo, 'SELECT primary_contact_id FROM mcp_deals WHERE deal_id = :d', ['d' => $f['deal_id']]) : null);
        $who = $cid !== null ? (string) one_value($pdo, 'SELECT name FROM mcp_contacts WHERE contact_id = :c', ['c' => (int) $cid]) : '';
        $f['subject'] = ACTIVITY_KINDS[$kind] . ($who !== '' ? ' with ' . $who : '');
    } else {
        $errors['subject'] = 'The subject is required.';
    }
}
// When: a span's start and end, or a due time; `at` stands for either. A log is done now unless it says when.
$time = static function (string $name, ?string $keep) use ($tz, &$errors): ?string {
    if (!req_has($name)) { return $keep; }
    $t = activity_time(req_val($name), $tz);
    if ($t === false) { $errors[$name] = 'The time is a date and a time (YYYY-MM-DD HH:MM).'; return $keep; }
    return $t;
};
$sa = $time('starts_at', null);
$da = $time('due_at', null);
$am = $time('at', null);
$moment = in_array($kind, ACTIVITY_TIMED, true) ? ($sa ?? $am ?? $da) : ($da ?? $am ?? $sa);   // the field of the kind wins (a form sends both)
$curMoment = $cur['starts_at'] ?? $cur['due_at'];
$at = $moment ?? $curMoment;
$ends = $time('ends_at', $cur['ends_at']);
$doneAt = $done ? ($time('done_at', null) ?? $moment ?? gmdate(DATE_ATOM)) : null;
if ($at === null && $done) { $at = $doneAt; }
if ($at === null && !isset($errors['at']) && !isset($errors['starts_at']) && !isset($errors['due_at'])) {
    $errors[in_array($kind, ACTIVITY_TIMED, true) ? 'starts_at' : 'due_at'] = in_array($kind, ACTIVITY_TIMED, true) ? 'Say when it starts.' : 'Say when it is due.';
}
$f['all_day'] = crm_yes('all_day', $cur['all_day']);
if ($at !== null && $f['all_day']) {                                              // all day: the date at midnight in the zone
    $at = (new DateTimeImmutable($at))->setTimezone(new DateTimeZone($tz))->setTime(0, 0)->setTimezone(new DateTimeZone('UTC'))->format(DATE_ATOM);
    $ends = null;
}
if (!in_array($kind, ACTIVITY_TIMED, true)) { $ends = null; }
if ($at !== null && $ends !== null && $ends < $at) { $errors['ends_at'] = 'The end is before the start.'; }
if ($at !== null) { $f += activity_when_fields($kind, $at, $ends); }
if ($done) { $f['done_at'] = $doneAt; }
$f['body'] = crm_text('body', $cur['body'], 10000, 'What was said', $errors);
$f['location'] = crm_text('location', $cur['location'], 300, 'The place', $errors);
$f['meeting_url'] = crm_text('meeting_url', $cur['meeting_url'], 500, 'The meeting link', $errors);
if ($f['meeting_url'] !== null && !preg_match('#^https?://\S+$#i', $f['meeting_url'])) { $errors['meeting_url'] = 'The meeting link is a web address (https://…).'; }
$f['priority'] = crm_choice('priority', $cur['priority'], array_keys(ACTIVITY_PRIORITIES), 'The priority', $errors, false) ?? 'normal';
$noReminder = false;
if (req_has('remind_minutes_before')) {
    $r = (string) req_val('remind_minutes_before');
    if ($r === 'none') { $noReminder = true; $f['remind_minutes_before'] = null; }
    else { $f['remind_minutes_before'] = crm_int('remind_minutes_before', $cur['remind_minutes_before'], 0, 10080, 'The reminder (minutes before)', $errors, true); }
} else {
    $f['remind_minutes_before'] = $cur['remind_minutes_before'];
}
$f['recurrence'] = crm_text('recurrence', $cur['recurrence'], 100, 'The repeat', $errors);
if ($f['recurrence'] !== null) {
    if ($kind !== 'task') { $errors['recurrence'] = 'Only a task repeats.'; }
    elseif (!preg_match('/^FREQ=(DAILY|WEEKLY|MONTHLY)(;INTERVAL=[1-9][0-9]?)?$/', $f['recurrence'])) { $errors['recurrence'] = 'The repeat is every day, every week, every two weeks or every month.'; }
}
if ($kind === 'email') {
    $f['email_direction'] = crm_choice('direction', $cur['email_direction'] ?? 'out', ['in', 'out'], 'The direction', $errors, false) ?? 'out';
}
$f['outcome'] = crm_choice('outcome', $cur['outcome'], ACTIVITY_OUTCOMES[$kind], 'The outcome', $errors);
$f['outcome_note'] = crm_text('outcome_note', $cur['outcome_note'], 2000, 'The outcome note', $errors);
if (!$done && $id === null) { $f['outcome'] = null; $f['outcome_note'] = null; }
$f['owner_member_id'] = crm_ref($pdo, 'owner', $id === null ? $me : $cur['owner_member_id'], 'SELECT 1 FROM mcp_members WHERE member_id = :id', 'owner', $errors, $id !== null);
if ($id === null && $f['owner_member_id'] === null) { $f['owner_member_id'] = $me; }
if ($id !== null && $f['owner_member_id'] !== $cur['owner_member_id'] && !activity_may($a)['assign']) { $errors['owner'] = 'Only the one who made it, who does it, or a manager gives it to someone else.'; }
$custom = custom_values_from_request($pdo, 'activity', $cur['custom_values']);
$f['custom_values'] = $custom ?? $cur['custom_values'];
if ($id === null) { $f['source'] = default_activity_kind_source(); }
// Who else (create): contacts and colleagues by id.
$people = [];
foreach (['participants' => ['mcp_contacts', 'contact_id', 'contact'], 'members' => ['mcp_members', 'member_id', 'colleague']] as $name => [$v, $col, $noun]) {
    foreach ($id === null ? (request_list($name) ?? []) : [] as $pid) {
        if (!ctype_digit($pid) || one_value($pdo, "SELECT 1 FROM $v WHERE $col = :id", ['id' => (int) $pid]) === null) { $errors[$name] = 'That ' . $noun . ' is not here.'; continue; }
        $people[] = [$name === 'participants' ? (int) $pid : null, $name === 'members' ? (int) $pid : null];
    }
}
$tags = $id === null ? (request_list('tags') ?? []) : [];
if ($tags !== [] && !has_right('tags.apply')) { $errors['tags'] = 'You may not tag this.'; }
// The next step (a log, or a schedule made from a log form): its kind, subject and when.
$next = null;
if ($id === null && ($nk = (string) (req_val('next_kind') ?? '')) !== '' && $nk !== 'none') {
    if (!in_array($nk, ACTIVITY_FORM_KINDS, true)) { $errors['next_kind'] = 'The next step is a call, a meeting, a task, a deadline or a lunch.'; }
    $nv = (string) (req_val('next_at') ?? '');
    $nat = next_step_time($nv === '' || $nv === 'pick' ? req_val('next_at_time') : $nv, $tz);
    if ($nat === null) { $errors['next_at'] = 'Say when the next step is.'; }
    elseif ($nat === false) { $errors['next_at'] = 'The next step\'s time is a date and a time (YYYY-MM-DD HH:MM).'; }
    $ns = trim((string) (req_val('next_subject') ?? ''));
    if (mb_strlen($ns) > 300) { $errors['next_subject'] = 'The next step\'s subject is up to 300 characters.'; }
    if ($ns === '') {
        $cid = $f['contact_id'] ?? ($f['deal_id'] !== null ? one_value($pdo, 'SELECT primary_contact_id FROM mcp_deals WHERE deal_id = :d', ['d' => $f['deal_id']]) : null);
        $who = $cid !== null ? (string) one_value($pdo, 'SELECT first_name FROM mcp_contacts WHERE contact_id = :c', ['c' => (int) $cid]) : '';
        $ns = 'Follow up' . ($who !== '' ? ' with ' . $who : '');
    }
    if (is_string($nat)) { $next = ['kind' => $nk, 'subject' => $ns, 'at' => $nat]; }
}
if ($errors !== []) {
    crm_refuse_fields($errors);
}
$event = 'activityChanged, dealChanged';
if ($id === null) {
    $f = array_filter($f, static fn ($v) => $v !== null);
    [$newId, $nextId] = crm_guard($pdo, static function () use ($pdo, $f, $me, $tags, $people, $next, $noReminder, $done): array {
        $pdo->beginTransaction();
        $newId = activity_save($pdo, null, $f, $me);
        if ($noReminder) { $pdo->prepare('UPDATE activities SET remind_minutes_before = NULL WHERE id = :id')->execute(['id' => $newId]); }
        foreach ($people as [$c, $m]) { participant_add($pdo, $newId, $c, $m); }
        $labels = [];
        foreach ($tags as $t) { [$tid, $label] = tag_ensure($pdo, $t); tag_apply($pdo, 'activity', [$newId], $tid); $labels[] = $label; }
        $saved = activity_current($pdo, $newId);
        activity_tell_assignee($pdo, $newId, $saved['owner_member_id']);
        log_activity($pdo, 'activity.create', 'activity', $newId, activity_log_keys($saved) + ['after' => activity_log_facts($saved) + ['participants' => count($people), 'tags' => $labels]]);
        $nextId = null;
        if ($next !== null) {
            $nextId = activity_save($pdo, null, ['kind' => $next['kind'], 'subject' => $next['subject'], 'deal_id' => $saved['deal_id'], 'contact_id' => $saved['contact_id'], 'account_id' => $saved['account_id'],
                'lead_id' => $saved['lead_id'], 'owner_member_id' => $saved['owner_member_id'], 'source' => $saved['source'], 'timezone' => $saved['timezone']] + activity_when_fields($next['kind'], $next['at']), $me);
            $n = activity_current($pdo, $nextId);
            log_activity($pdo, 'activity.create', 'activity', $nextId, activity_log_keys($n) + ['after' => activity_log_facts($n) + ['next_of' => $newId]]);
        }
        $pdo->commit();
        return [$newId, $nextId];
    });
    $label = ACTIVITY_KINDS[$f['kind']];
    $did = $done ? 'Logged the ' . strtolower($label) . ($nextId !== null ? ' and the next step' : '') : 'Scheduled the ' . strtolower($label) . ' ' . $f['subject'];
    crm_done($did, $newId, crm_land(return_path('/activities/' . $newId . ($nextId !== null ? '?next=' . $nextId : '')), $done ? ($nextId !== null ? 'logged_next' : 'logged') : 'created'), $event, ['next_activity_id' => $nextId]);
}
$diff = crm_diff(array_intersect_key($cur, $f), $f);
if ($diff['after'] !== [] || $noReminder) {
    crm_guard($pdo, static function () use ($pdo, $id, $diff, $noReminder): void {
        $pdo->beginTransaction();
        activity_save($pdo, $id, $diff['after'], (int) current_member_id());
        if ($noReminder) { $pdo->prepare('UPDATE activities SET remind_minutes_before = NULL WHERE id = :id')->execute(['id' => $id]); }
        $saved = activity_current($pdo, $id);
        $b = $diff['before'];
        $a = $diff['after'];
        foreach (['body', 'custom_values'] as $k) { if (array_key_exists($k, $a)) { $b[$k] = '(changed)'; $a[$k] = '(changed)'; } }
        if (array_key_exists('owner_member_id', $a)) { activity_tell_assignee($pdo, $id, $saved['owner_member_id']); }
        log_activity($pdo, 'activity.update', 'activity', $id, activity_log_keys($saved) + ['before' => $b, 'after' => $a + ['subject' => $saved['subject']]]);
        $pdo->commit();
    });
}
crm_done($diff['after'] === [] && !$noReminder ? 'Nothing to change' : 'Saved the activity ' . ($f['subject'] ?? $cur['subject']), $id, crm_land(return_path('/activities/' . $id), 'updated'), $event);
