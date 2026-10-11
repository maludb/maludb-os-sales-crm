<?php
declare(strict_types=1);
/**
 * /log/call (screen `log-call`; params contact, deal, account, lead): the quick log of a call made — one screen on a phone: who (the contact picker,
 * prefilled), the deal (the contact's open deals as buttons when three or fewer, else the picker), the outcome as large buttons, what was said (a
 * textarea the dictation lands in), when (now), and the next step. Posts `activity_create` with done = yes and the next step's fields (divergence 2):
 * the touch is done, the step open, one request. /log/meeting is the same form for a meeting held.
 */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_once dirname(__DIR__, 2) . '/app/features/activities/write.php';
require_login();
require_right('activities.write');
$pdo = db();
$kind = $GLOBALS['__log_kind'] ?? 'call';
$screen = 'log-' . $kind;
$tz = member_timezone();
$v = ['contact' => null, 'deal' => null, 'account' => null, 'lead' => null];
foreach (['deal' => ['mcp_deals', 'deal_id'], 'contact' => ['mcp_contacts', 'contact_id'], 'account' => ['mcp_accounts', 'account_id'], 'lead' => ['mcp_leads', 'lead_id']] as $k => [$view, $col]) {
    $rid = request_integer($k);
    if ($rid !== null && one_value($pdo, "SELECT 1 FROM $view WHERE $col = :id", ['id' => $rid]) !== null) { $v[$k] = $rid; }
}
$deal = $v['deal'] === null ? null : one_row($pdo, 'SELECT deal_id, title, status, account_id, primary_contact_id FROM mcp_deals WHERE deal_id = :d', ['d' => $v['deal']]);
if ($deal !== null && $v['contact'] === null && $deal['primary_contact_id'] !== null) { $v['contact'] = (int) $deal['primary_contact_id']; }
$contact = $v['contact'] === null ? null : one_row($pdo, 'SELECT contact_id, name, first_name, account_id, phone, mobile, do_not_call FROM mcp_contacts WHERE contact_id = :c', ['c' => $v['contact']]);
$deals = $contact !== null ? open_deals_of_contact($pdo, (int) $contact['contact_id']) : [];
$lead = $v['lead'] === null ? null : one_row($pdo, 'SELECT lead_id, label, first_name, phone FROM mcp_leads WHERE lead_id = :l', ['l' => $v['lead']]);
$first = $contact['first_name'] ?? ($lead['first_name'] ?? null);
$prompt = $deal !== null && $deal['status'] === 'open' && (bool) one_value($pdo, 'SELECT next_step_prompt FROM mcp_settings')
    && one_value($pdo, 'SELECT 1 FROM activities WHERE deal_id = :d AND done_at IS NULL AND deleted_at IS NULL LIMIT 1', ['d' => $deal['deal_id']]) === null;
$back = safe_local_path($_GET['back'] ?? null) ?? ($deal !== null ? '/deals/' . $deal['deal_id'] : ($contact !== null ? '/contacts/' . $contact['contact_id'] : ($lead !== null ? '/leads/' . $lead['lead_id'] : ($v['account'] !== null ? '/accounts/' . $v['account'] : null))));
log_screen_view($pdo, $screen);
if (wants_json()) {
    respond_screen(['kind' => $kind, 'contact' => $contact === null ? null : ['contact_id' => (int) $contact['contact_id'], 'name' => $contact['name'], 'do_not_call' => (bool) $contact['do_not_call']],
        'deal_id' => $deal === null ? null : (int) $deal['deal_id'], 'lead_id' => $lead === null ? null : (int) $lead['lead_id'], 'account_id' => $v['account'],
        'open_deals' => array_map(static fn (array $d): array => ['deal_id' => (int) $d['deal_id'], 'title' => $d['title']], $deals), 'outcomes' => ACTIVITY_OUTCOMES[$kind],
        'prompt_next_step' => $prompt, 'next_kinds' => ACTIVITY_FORM_KINDS, 'next_when' => array_keys(NEXT_WHEN_CHOICES)]);
}
$n = new DateTimeImmutable('now', new DateTimeZone($tz));
render_screen($kind === 'call' ? 'Log a call' : 'Log a meeting', view('log/' . $kind . '.php', ['v' => $v, 'deal' => $deal, 'contact' => $contact, 'deals' => $deals, 'lead' => $lead, 'tz' => $tz,
    'prompt' => $prompt, 'back' => $back, 'next_subject' => 'Follow up' . ($first ? ' with ' . $first : ''),
    'now' => $n->format('Y-m-d\TH:i'), 'hour_ago' => $n->setTime((int) $n->format('G'), 0)->modify($kind === 'meeting' ? '-1 hour' : '+0 hour')->format('Y-m-d\TH:i')]),
    ['activeNav' => 'activities', 'screen' => $screen, 'entity' => 'activity']);
