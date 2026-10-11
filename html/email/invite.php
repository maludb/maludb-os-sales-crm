<?php
declare(strict_types=1);
/**
 * Action `meeting_invite_send` (log email.invite — own or activities.any; PAUSES FOR AN AGENT — external_send): `activity` (a meeting still ahead with contacts to invite —
 * its participants and the contact it is with), `message` (a line above the invitation, up to 2000 characters). One email goes to each contact with the meeting's .ics
 * (METHOD:REQUEST, the seller the organizer) — the mail client offers Accept/Decline — through the same send as an email (Reply-To the logging mailbox, the footer and
 * the unsubscribe link); an opted-out contact is refused by name before anything is sent. record_id is the activity.
 */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_once dirname(__DIR__, 2) . '/app/features/email/write.php';
crm_handler_begin();
$pdo = db();
$me = (int) current_member_id();
$id = request_integer('activity') ?? request_integer('id');
require_record($pdo, 'activity', $id);
$a = find_activity($pdo, $id);
require_activity_may($a, 'edit');
if ($a['kind'] !== 'meeting') { refuse(422, 'Only a meeting has an invitation to send.'); }
if ($a['done_at'] !== null || $a['starts_at'] === null) { refuse(422, 'That meeting is done; there is nothing to invite them to.'); }
$errors = [];
$message = (string) crm_text('message', null, 2000, 'The message', $errors);
$ids = [];
foreach (activity_participants($pdo, $id) as $p) { if ($p['contact_id'] !== null) { $ids[] = (int) $p['contact_id']; } }
if ($a['contact_id'] !== null) { $ids[] = (int) $a['contact_id']; }
$ids = array_values(array_unique($ids));
if ($ids === []) { $errors['activity'] = 'Add a contact to the meeting first — a participant, or the contact it is with.'; }
$people = $errors === [] ? email_validate_recipients($pdo, $ids, $errors) : [];
if ($errors !== []) { crm_refuse_fields($errors); }
$when = format_ts($a['starts_at'], (string) ($a['timezone'] ?: member_timezone()), 'D, M j');
$body = "Hi {{contact.first_name}},\n\n" . ($message !== '' ? $message . "\n\n" : '') . '**' . $a['subject'] . "**\n\nWhen: {{activity.date}} at {{activity.time}}\n"
    . ($a['location'] ? 'Where: ' . $a['location'] . "\n" : '') . ($a['meeting_url'] ? 'Join: ' . $a['meeting_url'] . "\n" : '')
    . "\nThe invitation is attached — accept it to put the meeting in your calendar.\n\n{{sender.signature}}";
$f = ['recipients' => $people, 'deal_id' => $a['deal_id'] === null ? null : (int) $a['deal_id'], 'template_id' => null, 'subject' => 'Invitation: ' . $a['subject'] . ' — ' . $when, 'body' => $body, 'activity_id' => $id, 'reply_to' => null];
$rows = crm_guard($pdo, static function () use ($pdo, $f, $me, $id, $people): array {
    $pdo->beginTransaction();
    $draft = email_draft_save($pdo, null, $f, $me);
    $rows = email_fan_out($pdo, $draft, $me);
    foreach ($people as $p) { participant_add($pdo, $id, $p['contact_id'], null); }
    $pdo->commit();
    return $rows;
});
$sent = [];
$bad = [];
foreach ($rows as $oid) {
    try {
        $r = email_deliver($pdo, (int) $oid, ['invite_message' => $message]);
    } catch (DomainException $e) {
        $r = ['state' => 'failed', 'error' => $e->getMessage(), 'name' => (string) (one_value($pdo, 'SELECT c.name FROM outbound_email o JOIN contacts c ON c.id = o.contact_id WHERE o.id = :id', ['id' => (int) $oid]) ?: 'that person')];
    }
    if ($r['state'] === 'sent') { $sent[] = $r; } else { $bad[] = $r; }
}
log_activity($pdo, 'email.invite', 'activity', $id, activity_log_keys($a) + ['after' => ['recipients' => count($people), 'sent' => count($sent), 'failed' => count($bad), 'subject' => $a['subject']]]);
if ($sent === []) { refuse(422, $bad[0]['error'] ?? 'The invitation could not be sent.'); }
$names = static fn (array $rs): string => implode(', ', array_map(static fn (array $r): string => $r['name'], $rs));
crm_done('Sent the invitation to ' . $names($sent) . ($bad !== [] ? ' — not sent to ' . $names($bad) . ': ' . ($bad[0]['error'] ?? '') : ''), $id, crm_land(return_path('/activities/' . $id), $bad === [] ? 'invited' : 'invited_partial'),
    'activityChanged, emailChanged', ['sent' => count($sent), 'failed' => count($bad)]);
