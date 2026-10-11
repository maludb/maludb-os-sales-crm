<?php
declare(strict_types=1);
/**
 * Action `email_send` (log email.send — email.send and see on the contact; PAUSES FOR AN AGENT — external_send): `draft` (a draft of mine) — or the fields of email_draft
 * to make one and send it — and `confirm` = yes. Every address must be allowed: an opted-out contact is refused by name, before anything is sent. One message goes to each
 * person (their own Message-ID, unsubscribe link and merge fields), From the business under the seller's name, Reply-To the logging mailbox; each sent one is logged as an
 * email activity on the contact and the deal. A failure leaves the draft to send again; record_id is the outbound email (the first when several).
 */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_once dirname(__DIR__, 2) . '/app/features/email/write.php';
crm_handler_begin();
require_right('email.send');
$pdo = db();
$me = (int) current_member_id();
if (req_val('confirm') !== 'yes') { crm_refuse_fields(['confirm' => 'Confirm it: send means it goes to them now (confirm = yes).']); }
$draftId = request_integer('draft');
$draft = $draftId === null ? null : find_draft($pdo, $draftId);
if ($draftId !== null && $draft === null) { refuse(404, 'That draft is not here.'); }
$errors = [];
$f = email_collect_fields($pdo, $draft, $errors);
if ($errors !== []) { crm_refuse_fields($errors); }
$ids = crm_guard($pdo, static function () use ($pdo, $draftId, $f, $me): array {
    $pdo->beginTransaction();
    $id = email_draft_save($pdo, $draftId, $f, $me);
    $ids = email_fan_out($pdo, $id, $me);
    $pdo->commit();
    return $ids;
});
$results = [];
foreach ($ids as $oid) {
    try {
        $results[] = email_deliver($pdo, (int) $oid);
    } catch (DomainException $e) {                                    // this one is refused in words (merge fields left, a file gone, being sent already); the others still go
        $results[] = ['state' => 'failed', 'error' => $e->getMessage(), 'outbound_id' => (int) $oid, 'contact_id' => null,
            'name' => (string) (one_value($pdo, 'SELECT c.name FROM outbound_email o JOIN contacts c ON c.id = o.contact_id WHERE o.id = :id', ['id' => (int) $oid]) ?: 'that person')];
    }
}
$sent = array_values(array_filter($results, static fn (array $r): bool => $r['state'] === 'sent'));
$bad = array_values(array_filter($results, static fn (array $r): bool => $r['state'] !== 'sent'));
$names = static fn (array $rs): string => implode(', ', array_map(static fn (array $r): string => $r['name'], $rs));
if ($sent === []) {
    refuse(422, $bad[0]['error'] ?? 'It could not be sent.');
}
$extra = ['draft_id' => $ids[0], 'outbound_email_ids' => array_map(static fn (array $r): int => $r['outbound_id'], $sent), 'sent' => count($sent), 'failed' => array_map(static fn (array $r): array => ['outbound_email_id' => $r['outbound_id'], 'name' => $r['name'], 'error' => $r['error']], $bad)];
$land = return_path($f['deal_id'] !== null ? '/deals/' . $f['deal_id'] : '/contacts/' . $sent[0]['contact_id'] . '?tab=emails');
crm_done('Sent to ' . $names($sent) . ($bad !== [] ? ' — not sent to ' . $names($bad) . ': ' . ($bad[0]['error'] ?? '') : ''), $sent[0]['outbound_id'], crm_land($land, $bad === [] ? 'email_sent' : 'email_partial'), 'emailChanged, activityChanged', $extra);
