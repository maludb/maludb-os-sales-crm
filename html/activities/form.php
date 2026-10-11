<?php
declare(strict_types=1);
/**
 * /activities/new and /activities/{id}/edit (screens `activity-add`, `activity-edit`; prefill params kind, deal, contact, account, lead, at — a date or a
 * date and an hour from a calendar cell — subject): to schedule — the kind, the subject, the record (pickers), when (a due time, or a start and an end),
 * all day, the reminder, who does it, where, the priority, the repeat (tasks), notes, people taking part (new), tags (new), custom fields. From a deal
 * the account and the primary contact come along. JSON: the values and the choices.
 */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_once dirname(__DIR__, 2) . '/app/features/activities/write.php';
require_once dirname(__DIR__, 2) . '/app/features/custom_fields/form.php';
require_login();
$pdo = db();
$id = request_integer('id');
$tz = member_timezone();
$a = null;
if ($id === null) {
    require_right('activities.write');
    $values = array_merge(array_fill_keys(ACTIVITY_COLUMNS, null), ['custom_values' => [], 'all_day' => false, 'priority' => 'normal', 'kind' => 'call', 'owner_member_id' => (int) current_member_id()]);
    $k = request_string('kind');
    if (in_array($k, ACTIVITY_FORM_KINDS, true)) { $values['kind'] = $k; }
    foreach (['deal' => ['mcp_deals', 'deal_id'], 'contact' => ['mcp_contacts', 'contact_id'], 'account' => ['mcp_accounts', 'account_id'], 'lead' => ['mcp_leads', 'lead_id']] as $key => [$v, $col]) {
        $rid = request_integer($key);
        if ($rid !== null && one_value($pdo, "SELECT 1 FROM $v WHERE $col = :id", ['id' => $rid]) !== null) { $values[$key . '_id'] = $rid; }
    }
    if ($values['deal_id'] !== null && ($d = one_row($pdo, 'SELECT account_id, primary_contact_id, title FROM mcp_deals WHERE deal_id = :d', ['d' => $values['deal_id']])) !== null) {
        $values['account_id'] ??= $d['account_id'] === null ? null : (int) $d['account_id'];
        $values['contact_id'] ??= $d['primary_contact_id'] === null ? null : (int) $d['primary_contact_id'];
    } elseif ($values['contact_id'] !== null && $values['account_id'] === null) {
        $acc = one_value($pdo, 'SELECT account_id FROM mcp_contacts WHERE contact_id = :c', ['c' => $values['contact_id']]);
        $values['account_id'] = $acc === null ? null : (int) $acc;
    }
    $at = request_string('at');
    $start = activity_time($at !== '' ? $at : null, $tz, '09:00');
    if (!is_string($start)) {                                        // the next whole hour
        $n = new DateTimeImmutable('now', new DateTimeZone($tz));
        $start = $n->setTime((int) $n->format('G'), 0)->modify('+1 hour')->setTimezone(new DateTimeZone('UTC'))->format(DATE_ATOM);
    }
    $values['starts_at'] = $start;
    $values['due_at'] = $start;
    $values['subject'] = mb_substr(request_string('subject'), 0, 300) ?: null;
} else {
    require_record($pdo, 'activity', $id);
    $a = find_activity($pdo, $id);
    require_activity_may($a, 'edit');
    $values = activity_current($pdo, $id);
    $values['starts_at'] ??= $values['due_at'];
    $values['due_at'] ??= $values['starts_at'];
}
$screen = $id === null ? 'activity-add' : 'activity-edit';
log_screen_view($pdo, $screen);
if (wants_json()) {
    respond_screen(['activity_id' => $id, 'values' => $values, 'kinds' => ACTIVITY_FORM_KINDS, 'timed_kinds' => ACTIVITY_TIMED, 'priorities' => array_keys(ACTIVITY_PRIORITIES),
        'reminders' => array_map('strval', array_keys(ACTIVITY_REMIND_CHOICES)), 'recurrences' => array_keys(RECURRENCE_CHOICES), 'custom_fields' => custom_field_defs($pdo, 'activity'), 'timezone' => $tz]);
}
$back = safe_local_path($_GET['back'] ?? null);
if ($back === null) {
    foreach (['deal', 'contact', 'account', 'lead'] as $k) { if ($id === null && $values[$k . '_id'] !== null && request_integer($k) !== null) { $back = '/' . $k . 's/' . $values[$k . '_id']; break; } }
}
render_screen($id === null ? 'Schedule' : 'Edit ' . $a['subject'], view('activities/form.php', ['a' => $a, 'values' => $values, 'tz' => $tz, 'back' => $back,
    'custom_html' => custom_fields_section($pdo, 'activity', $values['custom_values'], 'activity-form'), 'may_tag' => has_right('tags.apply'),
    'may_owner' => $id === null || activity_may($a)['assign']]), ['activeNav' => 'activities', 'screen' => $screen, 'entity' => 'activity', 'recordId' => $id === null ? '' : (string) $id]);
