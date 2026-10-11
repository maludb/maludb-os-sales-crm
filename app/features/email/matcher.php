<?php
declare(strict_types=1);

/**
 * The matcher's PHP side (email.md; the worker's `mail_match` pass): the decision is the DATABASE's — crm_match_inbound_email() (db/011) puts an email on the
 * contacts its addresses name and the deal its thread (or its subject's [D-000123]) names, proposes a stranger as a lead, and ignores a bounce or an auto-reply.
 * Here: one transaction per email around that call, the email's files copied onto each activity it made (the canonical store, the activity's own rows over the
 * same bytes), a contact's reply told to its owner (kind reply_received), and the log — `mail.matched|proposed|unmatched|ignored` with the message id and the
 * contact ids, never a body. One email that fails does not stop the next: it is marked `failed` with the sentence and left for a person.
 */

/** The received emails still to match (oldest first): not read as failed, match_status pending. */
function mail_pending_ids(PDO $pdo, int $limit): array
{
    $st = $pdo->prepare("SELECT id FROM inbound_email WHERE match_status = 'pending' AND status IN ('received', 'bounce', 'auto_reply', 'ignored', 'suppressed') ORDER BY received_at, id LIMIT :l");
    $st->bindValue('l', max(1, $limit), PDO::PARAM_INT);
    $st->execute();
    return array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
}

/** Match one received email now (inside a transaction of the caller's making or its own). Answers the matcher's word: matched | proposed_lead | unmatched | ignored | missing. */
function mail_match_one(PDO $pdo, int $id): string
{
    $own = !$pdo->inTransaction();
    if ($own) { $pdo->beginTransaction(); }
    try {
        $result = (string) one_value($pdo, 'SELECT crm_match_inbound_email(:id)', ['id' => $id]);
        $e = one_row($pdo, 'SELECT message_id, deal_id, matched_contact_ids::text AS contacts, match_status, status, from_address::text AS from_address FROM inbound_email WHERE id = :id', ['id' => $id]) ?? [];
        $contacts = pg_int_array($e['contacts'] ?? '');
        $acts = $pdo->prepare("SELECT id, contact_id, owner_member_id, email_direction FROM activities WHERE inbound_email_id = :id ORDER BY id");
        $acts->execute(['id' => $id]);
        $activities = $acts->fetchAll();
        if ($activities !== []) {
            $pdo->prepare("INSERT INTO attachments (record_type, record_id, filename, mime_type, byte_size, sha256, storage_path, uploaded_by)
                           SELECT 'activity', a.id, f.filename, f.mime_type, f.byte_size, f.sha256, f.storage_path, NULL
                             FROM activities a JOIN attachments f ON f.record_type = 'inbound_email' AND f.record_id = a.inbound_email_id
                            WHERE a.inbound_email_id = :id AND NOT EXISTS (SELECT 1 FROM attachments x WHERE x.record_type = 'activity' AND x.record_id = a.id AND x.storage_path = f.storage_path)")
                ->execute(['id' => $id]);
        }
        $keys = array_filter(['deal_id' => $e['deal_id'] ?? null, 'contact_id' => $contacts[0] ?? null], static fn ($v) => $v !== null);
        $opts = $keys + ['source' => 'cron', 'actor_member_id' => null];
        if ($result === 'matched') {
            log_activity($pdo, 'mail.matched', 'inbound_email', $id, $opts + ['after' => ['message_id' => $e['message_id'], 'contact_ids' => $contacts, 'deal_id' => $e['deal_id'] === null ? null : (int) $e['deal_id'],
                'activities' => array_map(static fn (array $a): int => (int) $a['id'], $activities)]]);
            mail_tell_reply($pdo, $id, $e, $activities);
        } elseif ($result === 'proposed_lead') {
            $lead = one_value($pdo, 'SELECT id FROM leads WHERE proposed_from_email_id = :id ORDER BY id DESC LIMIT 1', ['id' => $id]);
            log_activity($pdo, 'mail.proposed', 'inbound_email', $id, $opts + ['lead_id' => $lead === null ? null : (int) $lead, 'after' => ['message_id' => $e['message_id'], 'lead_id' => $lead === null ? null : (int) $lead]]);
        } elseif ($result === 'unmatched') {
            log_activity($pdo, 'mail.unmatched', 'inbound_email', $id, $opts + ['after' => ['message_id' => $e['message_id']]]);
        } elseif ($result === 'ignored') {
            log_activity($pdo, 'mail.ignored', 'inbound_email', $id, $opts + ['after' => ['message_id' => $e['message_id'], 'status' => $e['status'], 'match_status' => $e['match_status']]]);
        }
        if ($own) { $pdo->commit(); }
        return $result;
    } catch (Throwable $ex) {
        if ($own && $pdo->inTransaction()) { $pdo->rollBack(); }
        throw $ex;
    }
}

/** A contact's reply is told to the one whose deal or contact it is (a person; never the sender of a BCC): a `reply_received` notification, once per email. */
function mail_tell_reply(PDO $pdo, int $id, array $e, array $activities): void
{
    $in = array_values(array_filter($activities, static fn (array $a): bool => $a['email_direction'] === 'in'));
    if ($in === []) { return; }
    $owner = $e['deal_id'] !== null ? one_value($pdo, 'SELECT owner_member_id FROM deals WHERE id = :d', ['d' => (int) $e['deal_id']]) : null;
    $owner ??= $in[0]['owner_member_id'];
    if ($owner === null || $owner === false || one_value($pdo, "SELECT member_kind FROM members WHERE id = :m", ['m' => (int) $owner]) !== 'human') { return; }
    $who = (string) (one_value($pdo, 'SELECT name FROM contacts WHERE id = :c', ['c' => (int) $in[0]['contact_id']]) ?: $e['from_address']);
    $subject = (string) one_value($pdo, 'SELECT COALESCE(subject, \'\') FROM inbound_email WHERE id = :id', ['id' => $id]);
    $pdo->prepare("SELECT crm_notify(:m, 'reply_received', :t, NULL, 'email', :id, :d, :c, :a, NULL, :k)")
        ->execute(['m' => (int) $owner, 't' => 'Reply from ' . $who . ($subject !== '' ? ' — ' . mb_substr($subject, 0, 120) : ''), 'id' => $id, 'd' => $e['deal_id'], 'c' => (int) $in[0]['contact_id'],
                   'a' => (int) $in[0]['id'], 'k' => 'reply:' . $id]);
}

/** The worker's pass: match up to $limit pending emails, each its own transaction. Answers the counts by outcome. */
function mail_match_pending(PDO $pdo, int $limit): array
{
    $out = ['matched' => 0, 'proposed' => 0, 'unmatched' => 0, 'ignored' => 0, 'failed' => 0];
    foreach (mail_pending_ids($pdo, $limit) as $id) {
        try {
            $r = mail_match_one($pdo, $id);
            if ($r === 'proposed_lead') { $out['proposed']++; }
            elseif (isset($out[$r])) { $out[$r]++; }
        } catch (Throwable $e) {
            $out['failed']++;
            error_log('mail_match ' . $id . ': ' . $e->getMessage());
            $pdo->prepare("UPDATE inbound_email SET status = 'failed', error = :e WHERE id = :id")->execute(['e' => mb_substr($e->getMessage(), 0, 500), 'id' => $id]);
        }
    }
    return $out;
}
