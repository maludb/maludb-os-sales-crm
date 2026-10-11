<?php
declare(strict_types=1);

/**
 * Email's writes (slice 5; email.md): a DRAFT is an outbound_email row still `queued`, its sender's alone (divergence 1); a send fans a draft's other recipients out into
 * their own rows (one message per person: their own Message-ID, unsubscribe link and merge fields) and delivers each (send.php); a logged email is an activity; the
 * unmatched mail is attached to a contact, made a lead, or ignored; private is the sender's (or a manager's) to set. The database is the referee (db/011: the opt-out
 * check, the matcher, the unique Message-ID); PHP checks the rights and the visibility and says why in words. Logging is the handlers': ids and counts, never a body.
 */
require_once __DIR__ . '/queries.php';
require_once __DIR__ . '/send.php';
require_once __DIR__ . '/inbound.php';
require_once __DIR__ . '/matcher.php';
require_once __DIR__ . '/../leads/write.php';
require_once __DIR__ . '/../attachments/write.php';

/** A contact a form named: the facts a send needs, or null when the caller may not see them. */
function email_recipient(PDO $pdo, int $contact): ?array
{
    $r = one_row($pdo, 'SELECT contact_id, name, email_opt_out, crm_email_allowed(contact_id) AS allowed FROM mcp_contacts WHERE contact_id = :c', ['c' => $contact]);
    return $r === null ? null : ['contact_id' => (int) $r['contact_id'], 'name' => (string) $r['name'], 'opted_out' => (bool) $r['email_opt_out'], 'allowed' => (bool) $r['allowed'],
        'address' => email_contact_address($pdo, $contact)];
}

/**
 * The recipients of a draft or a send, judged: every contact visible to the caller and allowed ("refused by name" — Dana Reyes has opted out of email). Adds the
 * sentence to $errors['contact'] and answers the good ones as [{contact_id, name, address}].
 */
function email_validate_recipients(PDO $pdo, array $contactIds, array &$errors): array
{
    $ok = [];
    $seen = [];
    foreach ($contactIds as $cid) {
        if (isset($seen[$cid])) { continue; }
        $seen[$cid] = true;
        $r = email_recipient($pdo, (int) $cid);
        if ($r === null) { $errors['contact'] = 'That contact is not here.'; continue; }
        if (!$r['allowed']) { $errors['contact'] = $r['name'] . ($r['opted_out'] ? ' has opted out of email.' : ' has no email address.'); continue; }
        $ok[] = $r;
    }
    if ($ok === [] && !isset($errors['contact'])) { $errors['contact'] = 'Say who it is to.'; }
    return $ok;
}

/**
 * The fields of email_draft / email_send from the request, a field left out staying as the draft had it: recipients (`contact`, `contacts[]`), deal, template, subject,
 * body, attachments, activity (a meeting of the caller's), reply (an email answered). Adds sentences to $errors; answers email_draft_save()'s $f (attachments only when
 * the request names them — left out they stay).
 */
function email_collect_fields(PDO $pdo, ?array $draft, array &$errors): array
{
    $ids = array_values(array_filter(array_merge(request_list('contacts') ?? [], req_has('contact') && (string) req_val('contact') !== '' ? preg_split('/\s*,\s*/', (string) req_val('contact')) : []), static fn ($x): bool => $x !== ''));
    foreach ($ids as $x) { if (!ctype_digit((string) $x)) { $errors['contact'] = 'Choose the person from the list.'; } }
    if (!req_has('contact') && !req_has('contacts') && $draft !== null) {
        $ids = $draft['contact_id'] === null ? [] : [(string) $draft['contact_id']];
        foreach (pg_text_array((string) $draft['cc_addresses']) as $addr) { $cid = email_contact_for_address($pdo, $addr); if ($cid !== null) { $ids[] = (string) $cid; } }
    }
    $f = [];
    if (!isset($errors['contact'])) {
        $deal0 = req_has('deal') ? null : ($draft['deal_id'] ?? null);
        $dealId = crm_ref($pdo, 'deal', $draft === null || $deal0 === null ? null : (int) $deal0, 'SELECT 1 FROM mcp_deals WHERE deal_id = :id', 'deal', $errors);
        if ($ids === [] && $dealId !== null) {                                    // a deal alone: to its primary contact
            $p = one_value($pdo, 'SELECT primary_contact_id FROM mcp_deals WHERE deal_id = :d', ['d' => $dealId]);
            if ($p !== null && $p !== false) { $ids = [(string) $p]; }
        }
        $f['recipients'] = email_validate_recipients($pdo, array_map('intval', $ids), $errors);
        $f['deal_id'] = $dealId;
    }
    $f['template_id'] = crm_ref($pdo, 'template', $draft === null ? null : ($draft['template_id'] === null ? null : (int) $draft['template_id']),
        'SELECT 1 FROM mcp_email_templates WHERE template_id = :id AND archived_at IS NULL', 'template', $errors);
    $f['subject'] = (string) crm_text('subject', $draft['subject'] ?? null, 300, 'The subject', $errors, true);
    $f['body'] = (string) crm_text('body', $draft['body_text'] ?? null, 100000, 'The message', $errors, true);
    if ($draft === null && !req_has('subject')) { $errors['subject'] = 'The subject is required.'; }
    if ($draft === null && !req_has('body')) { $errors['body'] = 'The message is required.'; }
    $act = crm_ref($pdo, 'activity', $draft === null || $draft['activity_id'] === null ? null : (int) $draft['activity_id'], 'SELECT 1 FROM mcp_activities WHERE activity_id = :id AND kind = \'meeting\'', 'meeting', $errors);
    if ($act !== null && !isset($errors['activity'])) {
        $a = find_activity($pdo, $act);
        if ($a === null || !activity_may($a)['edit']) { $errors['activity'] = 'Only the one who made the meeting, who does it, or a manager sends its invitation.'; }
    }
    $f['activity_id'] = $act;
    $f['reply_to'] = crm_ref($pdo, 'reply', null, 'SELECT 1 FROM mcp_emails WHERE direction = \'in\' AND email_id = :id', 'email', $errors);
    if (req_has('attachments') || req_has('attachment')) {
        $f['attachments'] = array_values(array_filter(array_map('intval', array_merge(request_list('attachments') ?? [], request_list('attachment') ?? [])), static fn (int $x): bool => $x > 0));
    }
    return $f;
}

/** The contact an address belongs to among those the caller may see, or null. */
function email_contact_for_address(PDO $pdo, string $address): ?int
{
    $v = one_value($pdo, 'SELECT contact_id FROM mcp_contact_emails WHERE lower(address) = lower(:a) ORDER BY is_primary DESC, contact_email_id LIMIT 1', ['a' => $address]);
    return $v === null || $v === false ? null : (int) $v;
}

/**
 * Save a draft: insert ($id null) or change one of mine that is still to send. $f: recipients [{contact_id, address}…] (the first is To, the others go out as their own
 * messages when it is sent), deal_id, template_id, subject, body, attachments (attachment ids the caller may see), activity_id (a meeting: its ICS goes along),
 * reply_to (an inbound email id this answers). Answers the draft's id.
 */
function email_draft_save(PDO $pdo, ?int $id, array $f, int $me): int
{
    $first = $f['recipients'][0];
    $cc = array_values(array_map(static fn (array $r): string => $r['address'], array_slice($f['recipients'], 1)));
    $irt = null;
    $refs = [];
    if (($f['reply_to'] ?? null) !== null) {
        $in = one_row($pdo, 'SELECT message_id, references_ids::text AS refs FROM inbound_email WHERE id = :id', ['id' => (int) $f['reply_to']]);
        if ($in !== null && $in['message_id'] !== null) { $irt = $in['message_id']; $refs = array_merge(pg_text_array((string) $in['refs']), [$in['message_id']]); }
    }
    $args = ['to' => $first['address'], 'cc' => pg_array_literal($cc), 's' => $f['subject'], 'b' => $f['body'], 'c' => $first['contact_id'], 'd' => $f['deal_id'] ?? null, 't' => $f['template_id'] ?? null,
        'ics' => !empty($f['activity_id']) ? 't' : 'f', 'a' => $f['activity_id'] ?? null];
    if ($id === null) {
        $st = $pdo->prepare("INSERT INTO outbound_email (to_address, cc_addresses, subject, body_text, status, sender_member_id, contact_id, deal_id, template_id, has_ics, activity_id, in_reply_to, references_ids)
                             VALUES (:to, CAST(:cc AS text[]), :s, :b, 'queued', :me, :c, :d, :t, CAST(:ics AS boolean), :a, :irt, CAST(:refs AS text[])) RETURNING id");
        $st->execute($args + ['me' => $me, 'irt' => $irt, 'refs' => pg_array_literal(array_values(array_unique($refs)))]);
        $id = (int) $st->fetchColumn();
    } else {
        $set = "to_address = :to, cc_addresses = CAST(:cc AS text[]), subject = :s, body_text = :b, contact_id = :c, deal_id = :d, template_id = :t, has_ics = CAST(:ics AS boolean), activity_id = :a, status = 'queued', error = NULL";
        $extra = [];
        if (($f['reply_to'] ?? null) !== null) { $set .= ', in_reply_to = :irt, references_ids = CAST(:refs AS text[])'; $extra = ['irt' => $irt, 'refs' => pg_array_literal(array_values(array_unique($refs)))]; }
        $pdo->prepare("UPDATE outbound_email SET $set WHERE id = :id AND sender_member_id = :me AND status IN ('queued', 'failed')")->execute($args + $extra + ['id' => $id, 'me' => $me]);
    }
    if (array_key_exists('attachments', $f)) { email_set_attachments($pdo, $id, $f['attachments'], $me); }
    return $id;
}

/** The files on a draft become exactly these (attachment ids the caller may see — a record's file or a copy already on the draft): rows over the same stored bytes. */
function email_set_attachments(PDO $pdo, int $draft, array $ids, int $me): void
{
    $want = [];
    foreach ($ids as $aid) {
        $src = find_attachment($pdo, (int) $aid);
        if ($src !== null) { $want[$src['storage_path']] = $src; }
    }
    $present = [];
    foreach (find_attachments($pdo, 'outbound_email', $draft) as $a) {
        $row = find_attachment($pdo, (int) $a['attachment_id']);
        if ($row === null) { continue; }
        if (isset($want[$row['storage_path']])) { $present[$row['storage_path']] = true; continue; }
        $gone = attachment_delete($pdo, (int) $a['attachment_id']);                  // a copy not wanted any more: its bytes stay while another row names them
        if ($gone !== null) { attachment_unlink($gone); }
    }
    foreach ($want as $path => $src) {
        if (isset($present[$path])) { continue; }
        $pdo->prepare("INSERT INTO attachments (record_type, record_id, filename, mime_type, byte_size, sha256, storage_path, uploaded_by) VALUES ('outbound_email', :r, :f, :m, :n, :s, :p, :by)")
            ->execute(['r' => $draft, 'f' => $src['filename'], 'm' => $src['mime_type'], 'n' => $src['byte_size'], 's' => $src['sha256'], 'p' => $src['storage_path'], 'by' => $me]);
    }
}

/**
 * Before a send: the draft's other recipients (its cc_addresses that are contacts) get rows of their own — a copy of the draft to them, with the same files — and the
 * draft is left to its first recipient alone. Answers the ids to deliver, the draft's first. An address that is no contact the caller may see is dropped (a draft is
 * to contacts only).
 */
function email_fan_out(PDO $pdo, int $draft, int $me): array
{
    $d = one_row($pdo, "SELECT cc_addresses::text AS cc, subject, body_text, deal_id, template_id, has_ics, activity_id, in_reply_to, references_ids::text AS refs, is_private
                          FROM outbound_email WHERE id = :id AND sender_member_id = :me AND status IN ('queued', 'failed')", ['id' => $draft, 'me' => $me]);
    if ($d === null) { throw new DomainException('Not found.'); }
    $ids = [$draft];
    foreach (pg_text_array((string) $d['cc']) as $addr) {
        $cid = email_contact_for_address($pdo, $addr);
        if ($cid === null) { continue; }
        $st = $pdo->prepare("INSERT INTO outbound_email (to_address, subject, body_text, status, sender_member_id, contact_id, deal_id, template_id, has_ics, activity_id, in_reply_to, references_ids, is_private)
                             VALUES (:to, :s, :b, 'queued', :me, :c, :d, :t, CAST(:ics AS boolean), :a, :irt, CAST(:refs AS text[]), CAST(:p AS boolean)) RETURNING id");
        $st->execute(['to' => $addr, 's' => $d['subject'], 'b' => $d['body_text'], 'me' => $me, 'c' => $cid, 'd' => $d['deal_id'], 't' => $d['template_id'], 'ics' => $d['has_ics'] ? 't' : 'f', 'a' => $d['activity_id'],
            'irt' => $d['in_reply_to'], 'refs' => $d['refs'], 'p' => $d['is_private'] ? 't' : 'f']);
        $new = (int) $st->fetchColumn();
        $pdo->prepare("INSERT INTO attachments (record_type, record_id, filename, mime_type, byte_size, sha256, storage_path, uploaded_by)
                       SELECT 'outbound_email', :n, filename, mime_type, byte_size, sha256, storage_path, uploaded_by FROM attachments WHERE record_type = 'outbound_email' AND record_id = :d")
            ->execute(['n' => $new, 'd' => $draft]);
        $ids[] = $new;
    }
    $pdo->prepare("UPDATE outbound_email SET cc_addresses = '{}' WHERE id = :id")->execute(['id' => $draft]);
    return $ids;
}

/**
 * Log an email already sent or received (email_log): one done email activity per contact, the body the pasted text, at the time said. $f: contacts [ids], direction,
 * subject, body, deal_id, at (ISO). Answers the activity ids.
 */
function email_log_write(PDO $pdo, array $f, int $me): array
{
    $ids = [];
    $st = $pdo->prepare("INSERT INTO activities (kind, subject, body, contact_id, deal_id, owner_member_id, created_by, due_at, done_at, email_direction, source)
                         VALUES ('email', :s, :b, :c, :d, :me, :me, CAST(:at AS timestamptz), CAST(:at AS timestamptz), :dir, :src) RETURNING id");
    foreach ($f['contacts'] as $c) {
        $st->execute(['s' => $f['subject'], 'b' => $f['body'], 'c' => $c, 'd' => $f['deal_id'], 'me' => $me, 'at' => $f['at'], 'dir' => $f['direction'], 'src' => default_activity_kind_source()]);
        $ids[] = (int) $st->fetchColumn();
    }
    return $ids;
}

/** Private or not: the email's own flag and the activities it made (the timeline hides a private email's activity from everyone but its seller and the managers). */
function email_set_private(PDO $pdo, string $direction, int $id, bool $private): void
{
    $table = $direction === 'in' ? 'inbound_email' : 'outbound_email';
    $col = $direction === 'in' ? 'inbound_email_id' : 'outbound_email_id';
    $pdo->prepare("UPDATE $table SET is_private = CAST(:p AS boolean) WHERE id = :id")->execute(['p' => $private ? 't' : 'f', 'id' => $id]);
    $pdo->prepare("UPDATE activities SET is_private = CAST(:p AS boolean) WHERE $col = :id")->execute(['p' => $private ? 't' : 'f', 'id' => $id]);
}

/**
 * The party an unmatched or proposed email is about, who is neither the mailbox nor one of us: the sender when a stranger wrote it; when a seller of ours sent it (a BCC), the first
 * address they wrote to that is no mailbox and no member — with the name the header gave it. [address, name, from_a_member] or null when there is none.
 */
function email_party(PDO $pdo, int $emailId): ?array
{
    $e = one_row($pdo, 'SELECT from_address::text AS from_address, from_name, to_addresses::text AS to_a, cc_addresses::text AS cc_a, headers->>\'to\' AS to_h, headers->>\'cc\' AS cc_h FROM inbound_email WHERE id = :id', ['id' => $emailId]);
    if ($e === null) { return null; }
    $isMember = static fn (string $a): bool => one_value($pdo, 'SELECT 1 FROM members WHERE email = :a', ['a' => $a]) !== null;
    $isBox = static fn (string $a): bool => one_value($pdo, 'SELECT 1 FROM mailboxes WHERE address = :a', ['a' => $a]) !== null;
    $from = (string) $e['from_address'];
    if ($from !== '' && !$isMember($from) && !$isBox($from)) { return [$from, trim((string) $e['from_name']), false]; }
    $names = [];
    foreach ([(string) $e['to_h'], (string) $e['cc_h']] as $h) {
        foreach (preg_split('/,(?![^<]*>)/', $h) ?: [] as $part) { $a = mail_address_of($part); if ($a !== '') { $names[$a] = mail_name_of($part); } }
    }
    foreach (array_merge(pg_text_array((string) $e['to_a']), pg_text_array((string) $e['cc_a'])) as $a) {
        $a = strtolower($a);
        if ($a !== '' && !$isMember($a) && !$isBox($a)) { return [$a, $names[$a] ?? '', true]; }
    }
    return null;
}

/** The proposed lead still waiting on an email (not accepted, not dismissed), or null. */
function email_proposed_lead(PDO $pdo, int $emailId): ?int
{
    $v = one_value($pdo, 'SELECT id FROM leads WHERE proposed_from_email_id = :e AND is_proposed AND deleted_at IS NULL ORDER BY id DESC LIMIT 1', ['e' => $emailId]);
    return $v === null || $v === false ? null : (int) $v;
}

/**
 * A lead from the party an unmatched or proposed email is about (email_make_lead; email_party): the proposed lead is accepted as mine; with none, a lead is made from them (the
 * Mailbox source, the first lines of the message), mine. The email lands on the lead as a received email, and stops being unmatched. Answers [lead_id, made].
 */
function email_make_lead_write(PDO $pdo, int $emailId, int $me): array
{
    $e = one_row($pdo, 'SELECT subject, body_text, received_at, is_private, match_status FROM inbound_email WHERE id = :id', ['id' => $emailId]);
    if ($e === null) { throw new DomainException('Not found.'); }
    $lead = email_proposed_lead($pdo, $emailId);
    $made = false;
    $party = email_party($pdo, $emailId);
    if ($lead !== null) {
        lead_accept($pdo, $lead, $me);
    } else {
        if ($party === null) { throw new DomainException('That email names no one outside the business to make a lead of.'); }
        $parts = $party[1] === '' ? [] : preg_split('/\s+/', $party[1], 2);
        $lead = lead_save($pdo, null, ['first_name' => $parts[0] ?? null, 'last_name' => $parts[1] ?? null, 'email' => $party[0], 'message' => mb_substr((string) $e['body_text'], 0, 2000),
            'source_id' => ($sid = one_value($pdo, "SELECT id FROM lead_sources WHERE name = 'Mailbox'")) === null ? null : (int) $sid, 'owner_member_id' => $me], $me);
        $pdo->prepare('UPDATE leads SET proposed_from_email_id = :e WHERE id = :l')->execute(['e' => $emailId, 'l' => $lead]);
        $made = true;
    }
    $pdo->prepare("UPDATE inbound_email SET match_status = 'proposed_lead', status = 'processed', processed_at = COALESCE(processed_at, now()) WHERE id = :id")->execute(['id' => $emailId]);
    if (one_value($pdo, 'SELECT 1 FROM activities WHERE inbound_email_id = :e AND lead_id = :l', ['e' => $emailId, 'l' => $lead]) === null) {
        $pdo->prepare("INSERT INTO activities (kind, subject, lead_id, owner_member_id, created_by, due_at, done_at, email_direction, inbound_email_id, source, is_private)
                       VALUES ('email', :s, :l, :me, :me, :at, :at, :dir, :e, 'mailbox', CAST(:p AS boolean))")
            ->execute(['s' => (string) ($e['subject'] ?: '(no subject)'), 'l' => $lead, 'me' => $me, 'at' => $e['received_at'], 'dir' => $party !== null && $party[2] ? 'out' : 'in', 'e' => $emailId, 'p' => $e['is_private'] ? 't' : 'f']);
    }
    return [$lead, $made];
}

/**
 * Attach an unmatched or proposed email's party to a contact (email_attach_contact; email_party): their address is added to the contact's addresses, a lead the email had proposed is
 * dismissed, and the matcher runs on the email again — it lands on the contact (and the deal its thread names). Answers the matcher's word. A refusal says whose the address is.
 */
function email_attach_write(PDO $pdo, int $emailId, int $contact): string
{
    $party = email_party($pdo, $emailId);
    if ($party === null) { throw new DomainException('That email names no address outside the business to attach.'); }
    $owner = one_row($pdo, 'SELECT ce.contact_id, c.name FROM contact_emails ce JOIN contacts c ON c.id = ce.contact_id WHERE ce.address = :a', ['a' => $party[0]]);
    if ($owner !== null && (int) $owner['contact_id'] !== $contact) {
        throw new DomainException('That address belongs to ' . $owner['name'] . ' already.|' . (int) $owner['contact_id']);
    }
    if ($owner === null) {
        $pdo->prepare('INSERT INTO contact_emails (contact_id, address, is_primary) VALUES (:c, :a, false)')->execute(['c' => $contact, 'a' => $party[0]]);
    }
    if (($lead = email_proposed_lead($pdo, $emailId)) !== null) { lead_dismiss($pdo, $lead); }
    $pdo->prepare("UPDATE inbound_email SET match_status = 'pending', status = 'received', processed_at = NULL, matched_contact_ids = '{}' WHERE id = :id")->execute(['id' => $emailId]);
    return mail_match_one($pdo, $emailId);
}

/** Hide an unmatched or proposed email from the unmatched list (a lead it proposed stays in the inbox for a person to decide). */
function email_ignore_write(PDO $pdo, int $emailId): void
{
    $pdo->prepare("UPDATE inbound_email SET match_status = 'ignored', processed_at = COALESCE(processed_at, now()) WHERE id = :id")->execute(['id' => $emailId]);
}
