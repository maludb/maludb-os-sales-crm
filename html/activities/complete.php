<?php
declare(strict_types=1);
/**
 * GET /activities/{id}/complete — Done as a page (part of `activity-view`): when, the outcome as large buttons, a note, and THE NEXT-STEP PROMPT — shown
 * open when the settings ask (next_step_prompt) and the activity belongs to an open deal that will have no next step after it; folded otherwise.
 * POST — action `activity_complete` (log activity.complete {outcome, next_activity_id} — own, the assignee, or activities.any): `done_at` (now by
 * default), `outcome` (the kind's — its default when omitted), `outcome_note` (up to 2000 characters), and `next_kind`, `next_subject`, `next_at`
 * (tomorrow, in3days, nextweek or a time): the next step made in the same request on the same records for the same person (divergence 1). The
 * answer's `record_id` is the activity, `next_activity_id` the step. The deal's card changes colour on the board's next poll.
 */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_once dirname(__DIR__, 2) . '/app/features/activities/write.php';
require_login();
$pdo = db();
$id = request_integer('activity') ?? request_integer('id');
require_record($pdo, 'activity', $id);
$a = find_activity($pdo, $id);
$tz = member_timezone();
$prompt = $a['deal_id'] !== null && $a['deal_status'] === 'open' && (bool) one_value($pdo, 'SELECT next_step_prompt FROM mcp_settings')
    && one_value($pdo, 'SELECT 1 FROM activities WHERE deal_id = :d AND id <> :id AND done_at IS NULL AND deleted_at IS NULL LIMIT 1', ['d' => $a['deal_id'], 'id' => $id]) === null;
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    require_activity_may($a, 'complete');
    log_screen_view($pdo, 'activity-view');
    $first = $a['contact_name'] !== null ? explode(' ', (string) $a['contact_name'])[0] : null;
    if (wants_json()) {
        respond_screen(['activity_id' => $id, 'kind' => $a['kind'], 'subject' => $a['subject'], 'outcomes' => ACTIVITY_OUTCOMES[$a['kind']], 'default_outcome' => ACTIVITY_OUTCOMES[$a['kind']][0],
            'prompt_next_step' => $prompt, 'next_kinds' => ACTIVITY_FORM_KINDS, 'next_when' => array_keys(NEXT_WHEN_CHOICES), 'next_subject' => 'Follow up' . ($first ? ' with ' . $first : '')]);
    }
    render_screen('Done: ' . $a['subject'], view('activities/complete.php', ['a' => $a, 'prompt' => $prompt, 'tz' => $tz, 'now' => local_input(gmdate(DATE_ATOM), $tz),
        'next_subject' => 'Follow up' . ($first ? ' with ' . $first : '')]), ['activeNav' => 'activities', 'screen' => 'activity-view', 'entity' => 'activity', 'recordId' => (string) $id]);
    exit;
}
crm_handler_begin();
require_activity_may($a, 'complete');
$errors = [];
$f = ['done_at' => gmdate(DATE_ATOM)];
if (req_has('done_at') && (string) req_val('done_at') !== '') {
    $t = activity_time(req_val('done_at'), $tz);
    if ($t === false) { $errors['done_at'] = 'When it was done is a date and a time (YYYY-MM-DD HH:MM).'; }
    elseif ($t > gmdate(DATE_ATOM, time() + 300)) { $errors['done_at'] = 'It is done now or earlier, never later.'; }
    else { $f['done_at'] = $t; }
}
$f['outcome'] = crm_choice('outcome', null, ACTIVITY_OUTCOMES[$a['kind']], 'The outcome', $errors);
$f['outcome_note'] = crm_text('outcome_note', null, 2000, 'The note', $errors);
$f['next'] = null;
if (($nk = (string) (req_val('next_kind') ?? '')) !== '' && $nk !== 'none') {
    if (!in_array($nk, ACTIVITY_FORM_KINDS, true)) { $errors['next_kind'] = 'The next step is a call, a meeting, a task, a deadline or a lunch.'; }
    $nv = (string) (req_val('next_at') ?? '');
    $nat = next_step_time($nv === '' || $nv === 'pick' ? req_val('next_at_time') : $nv, $tz);
    if ($nat === null) { $errors['next_at'] = 'Say when the next step is.'; }
    elseif ($nat === false) { $errors['next_at'] = 'The next step\'s time is a date and a time (YYYY-MM-DD HH:MM).'; }
    $ns = trim((string) (req_val('next_subject') ?? ''));
    if ($ns === '') { $errors['next_subject'] = 'Say what the next step is.'; }
    elseif (mb_strlen($ns) > 300) { $errors['next_subject'] = 'The next step\'s subject is up to 300 characters.'; }
    if ($errors === []) { $f['next'] = ['kind' => $nk, 'subject' => $ns, 'at' => $nat]; }
}
if ($errors !== []) { crm_refuse_fields($errors); }
$r = crm_guard($pdo, static function () use ($pdo, $id, $f): array {
    $pdo->beginTransaction();
    $r = activity_complete($pdo, $id, $f);
    $saved = activity_current($pdo, $id);
    log_activity($pdo, 'activity.complete', 'activity', $id, activity_log_keys($saved) + ['after' => ['kind' => $saved['kind'], 'subject' => $saved['subject'], 'outcome' => $saved['outcome'],
        'done_at' => $saved['done_at'], 'next_activity_id' => $r['next_activity_id']]]);
    if ($r['next_activity_id'] !== null) {
        $n = activity_current($pdo, $r['next_activity_id']);
        log_activity($pdo, 'activity.create', 'activity', $r['next_activity_id'], activity_log_keys($n) + ['after' => activity_log_facts($n) + ['next_of' => $id]]);
    }
    $pdo->commit();
    return $r;
});
crm_done('Done: ' . $a['subject'] . ($r['next_activity_id'] !== null ? ' — the next step is scheduled' : ''), $id, crm_land(return_path('/activities/' . $id . ($r['next_activity_id'] !== null ? '?next=' . $r['next_activity_id'] : '')), $r['next_activity_id'] !== null ? 'done_next' : 'done'),
    'activityChanged, dealChanged', ['next_activity_id' => $r['next_activity_id']]);
