<?php
declare(strict_types=1);
/**
 * GET /log/email (screen `log-email`; params contact, deal): the quick log of an email already sent or received elsewhere — who, which way, the subject, the pasted text, the
 * deal (the contact's open deals as buttons), when. POST — action `email_log` (log email.log — email.log and see on the contact; undo record_delete): `contact` (or
 * `contacts[]`), `direction` (in or out), `subject`, `body` (the pasted text, up to 100000 characters), `deal`, `at` (when it was sent — now by default, never ahead). One
 * done email activity per contact (a touch on the contact and the deal); record_id is the first activity. The text is the activity's body — seen only by those who may read it
 * (a private one by its seller and the managers) — and never in the log.
 */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_once dirname(__DIR__, 2) . '/app/features/email/write.php';
$post = ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';
if ($post) { crm_handler_begin(); } else { require_login(); }
require_right('email.log');
$pdo = db();
$me = (int) current_member_id();
$tz = member_timezone();
if (!$post) {
    $contact = null;
    $deal = null;
    if (($c = request_integer('contact')) !== null) { $contact = one_row($pdo, 'SELECT contact_id, name, email_opt_out FROM mcp_contacts WHERE contact_id = :c', ['c' => $c]); }
    if (($d = request_integer('deal')) !== null) { $deal = one_row($pdo, 'SELECT deal_id, title, primary_contact_id FROM mcp_deals WHERE deal_id = :d', ['d' => $d]); }
    if ($contact === null && $deal !== null && $deal['primary_contact_id'] !== null) { $contact = one_row($pdo, 'SELECT contact_id, name, email_opt_out FROM mcp_contacts WHERE contact_id = :c', ['c' => (int) $deal['primary_contact_id']]); }
    $deals = $contact !== null ? open_deals_of_contact($pdo, (int) $contact['contact_id']) : [];
    $back = safe_local_path($_GET['back'] ?? null) ?? ($deal !== null ? '/deals/' . $deal['deal_id'] : ($contact !== null ? '/contacts/' . $contact['contact_id'] : null));
    log_screen_view($pdo, 'log-email');
    if (wants_json()) {
        respond_screen(['contact' => $contact === null ? null : ['contact_id' => (int) $contact['contact_id'], 'name' => $contact['name']], 'deal_id' => $deal === null ? null : (int) $deal['deal_id'],
            'open_deals' => array_map(static fn (array $x): array => ['deal_id' => (int) $x['deal_id'], 'title' => $x['title']], $deals), 'directions' => ['in', 'out']]);
    }
    $n = new DateTimeImmutable('now', new DateTimeZone($tz));
    render_screen('Log an email', view('log/email.php', ['contact' => $contact, 'deal' => $deal, 'deals' => $deals, 'back' => $back, 'now' => $n->format('Y-m-d\TH:i'), 'tz' => $tz]),
        ['activeNav' => 'email', 'screen' => 'log-email', 'entity' => 'email']);
    exit;
}
$errors = [];
$ids = array_values(array_filter(array_merge(request_list('contacts') ?? [], req_has('contact') && (string) req_val('contact') !== '' ? preg_split('/\s*,\s*/', (string) req_val('contact')) : []), static fn ($x): bool => $x !== ''));
$contacts = [];
foreach (array_unique($ids) as $x) {
    if (!ctype_digit((string) $x) || ($c = one_row($pdo, 'SELECT contact_id, name FROM mcp_contacts WHERE contact_id = :c', ['c' => (int) $x])) === null) { $errors['contact'] = 'That contact is not here.'; continue; }
    $contacts[] = $c;
}
if ($contacts === [] && !isset($errors['contact'])) { $errors['contact'] = 'Say who the email was with.'; }
$direction = crm_choice('direction', null, ['in', 'out'], 'The direction', $errors, false);
if ($direction === null && !isset($errors['direction'])) { $errors['direction'] = 'Say which way it went: in (they wrote) or out (you wrote).'; }
$subject = (string) crm_text('subject', null, 300, 'The subject', $errors, true);
$body = (string) crm_text('body', null, 100000, 'The text', $errors);
$dealId = crm_ref($pdo, 'deal', null, 'SELECT 1 FROM mcp_deals WHERE deal_id = :id', 'deal', $errors);
$at = gmdate(DATE_ATOM);
if (req_has('at') && (string) req_val('at') !== '') {
    $t = activity_time((string) req_val('at'), $tz);
    if (!is_string($t)) { $errors['at'] = 'The time is a date and a time (YYYY-MM-DD HH:MM).'; }
    elseif ($t > gmdate(DATE_ATOM, time() + 300)) { $errors['at'] = 'An email already sent was sent before now.'; }
    else { $at = $t; }
}
if ($errors !== []) { crm_refuse_fields($errors); }
$made = crm_guard($pdo, static function () use ($pdo, $contacts, $direction, $subject, $body, $dealId, $at, $me): array {
    $pdo->beginTransaction();
    $acts = email_log_write($pdo, ['contacts' => array_map(static fn (array $c): int => (int) $c['contact_id'], $contacts), 'direction' => $direction, 'subject' => $subject, 'body' => $body === '' ? null : $body,
        'deal_id' => $dealId, 'at' => $at], $me);
    log_activity($pdo, 'email.log', 'activity', $acts[0], array_filter(['contact_id' => (int) $contacts[0]['contact_id'], 'deal_id' => $dealId], static fn ($v) => $v !== null)
        + ['after' => ['direction' => $direction, 'contacts' => count($contacts), 'activities' => $acts, 'deal_id' => $dealId, 'subject' => $subject]]);
    $pdo->commit();
    return $acts;
});
$land = return_path($dealId !== null ? '/deals/' . $dealId : '/contacts/' . (int) $contacts[0]['contact_id'] . '?tab=emails');
crm_done('Logged the email ' . ($direction === 'in' ? 'from ' : 'to ') . $contacts[0]['name'] . (count($contacts) > 1 ? ' and ' . (count($contacts) - 1) . ' more' : ''), $made[0], crm_land($land, 'email_logged'), 'activityChanged, emailChanged',
    ['activity_ids' => $made, 'undo' => 'record_delete']);
