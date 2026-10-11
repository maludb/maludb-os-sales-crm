<?php
declare(strict_types=1);

/**
 * Email's reads (slice 5; email.md): the logging mailbox's mail and the mail the application sent, read through mcp_emails (db/016 — the wall of the contacts
 * it is on, a private email only to its sender and the managers, the body only when the asker may read it). A DRAFT is an outbound_email row still `queued`
 * (or `failed`) — its sender's alone; nobody else sees it, not even in a record's list. PHP never re-implements the wall.
 */
require_once __DIR__ . '/../records/queries.php';
require_once __DIR__ . '/../attachments/queries.php';
require_once __DIR__ . '/../activities/queries.php';

const EMAIL_PAGE = 25;
/** How a received email was matched, in words, with the badge colour: match_status => [words, colour]. */
const EMAIL_MATCH_WORDS = ['matched' => ['Matched', 'success'], 'proposed_lead' => ['Proposed as a lead', 'warning'], 'unmatched' => ['Not matched', 'secondary'],
    'bounce' => ['Bounced', 'danger'], 'ignored' => ['Ignored', 'secondary'], 'pending' => ['Waiting to be matched', 'secondary']];
/** An outbound email's state in words: status => [words, colour]. */
const EMAIL_OUT_WORDS = ['queued' => ['Draft', 'secondary'], 'sent' => ['Sent', 'primary'], 'failed' => ['Not sent', 'danger'], 'suppressed' => ['Not delivered', 'danger']];

/** The logging mailbox: the one the settings name, else the first active one; null when there is none (replies then cannot be logged). */
function email_logging_mailbox(PDO $pdo): ?array
{
    return one_row($pdo, 'SELECT m.id, m.address::text AS address, m.display_name, m.webhook_secret_ref, m.poll_enabled, m.last_uid, m.signature
                            FROM mailboxes m WHERE m.active ORDER BY (m.id = (SELECT mailbox_id FROM crm_settings WHERE id = 1)) DESC, m.id LIMIT 1');
}

/** The sender's address of a contact: the primary one on the contact, else the first they use; null when they have none. */
function email_contact_address(PDO $pdo, int $contact): ?string
{
    $v = one_value($pdo, "SELECT COALESCE(NULLIF(c.email, ''), (SELECT e.address FROM mcp_contact_emails e WHERE e.contact_id = c.contact_id ORDER BY e.is_primary DESC, e.contact_email_id LIMIT 1)) FROM mcp_contacts c WHERE c.contact_id = :c", ['c' => $contact]);
    return $v === null || $v === false || $v === '' ? null : strtolower((string) $v);
}

/**
 * The people an email from this record can go to (compose's chips): a contact → them and the others at their account; a deal → its people (the primary
 * first) and the account's; an account → its people (the primary first). Each: contact_id, name, address, account_name, opted_out, allowed (crm_email_allowed),
 * selected (the ones asked for, else the record's first). At most 12.
 */
function compose_contacts(PDO $pdo, ?int $contact, ?int $deal, ?int $account, array $chosen = []): array
{
    $ids = [];
    $d = $deal === null ? null : one_row($pdo, 'SELECT account_id, primary_contact_id FROM mcp_deals WHERE deal_id = :d', ['d' => $deal]);
    if ($contact !== null) { $ids[] = $contact; }
    if ($d !== null && $d['primary_contact_id'] !== null) { $ids[] = (int) $d['primary_contact_id']; }
    if ($deal !== null) {
        foreach ($pdo->query('SELECT contact_id FROM mcp_deal_contacts WHERE deal_id = ' . (int) $deal . ' ORDER BY is_primary DESC, added_at')->fetchAll(PDO::FETCH_COLUMN) as $c) { $ids[] = (int) $c; }
    }
    $acc = $account ?? ($d['account_id'] ?? null);
    if ($acc === null && $contact !== null) { $acc = one_value($pdo, 'SELECT account_id FROM mcp_contacts WHERE contact_id = :c', ['c' => $contact]); }
    if ($acc !== null) {
        $st = $pdo->prepare('SELECT contact_id FROM mcp_contacts WHERE account_id = :a ORDER BY is_primary DESC, last_name NULLS LAST, contact_id LIMIT 12');
        $st->execute(['a' => (int) $acc]);
        foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $c) { $ids[] = (int) $c; }
    }
    foreach ($chosen as $c) { $ids[] = (int) $c; }
    $ids = array_slice(array_values(array_unique($ids)), 0, 12);
    if ($ids === []) { return []; }
    $st = $pdo->prepare("SELECT c.contact_id, c.name, c.first_name, c.account_name, c.email_opt_out, crm_email_allowed(c.contact_id) AS allowed,
                                COALESCE(NULLIF(c.email, ''), (SELECT e.address FROM mcp_contact_emails e WHERE e.contact_id = c.contact_id ORDER BY e.is_primary DESC, e.contact_email_id LIMIT 1)) AS address
                           FROM mcp_contacts c WHERE c.contact_id = ANY (CAST(:ids AS bigint[]))");
    $st->execute(['ids' => pg_array_literal($ids)]);
    $by = [];
    foreach ($st->fetchAll() as $r) { $by[(int) $r['contact_id']] = $r; }
    $first = $chosen === [] ? ($contact ?? ($d['primary_contact_id'] ?? ($ids[0] ?? null))) : null;
    $out = [];
    foreach ($ids as $id) {
        if (!isset($by[$id])) { continue; }                       // behind the wall: not offered
        $r = $by[$id];
        $out[] = ['contact_id' => $id, 'name' => $r['name'] !== '' ? $r['name'] : (string) $r['address'], 'address' => $r['address'], 'account_name' => $r['account_name'],
            'opted_out' => (bool) $r['email_opt_out'], 'allowed' => (bool) $r['allowed'], 'selected' => in_array($id, $chosen, true) || ($chosen === [] && $first !== null && $id === (int) $first)];
    }
    return $out;
}

/**
 * One email the caller may see, either direction: the view's columns (the body only when they may read it) with what the page needs from the base row.
 * A draft (not yet sent, or one that did not go) is its sender's alone. Null when it does not exist or is not theirs to see — the same 404.
 */
function find_email(PDO $pdo, string $direction, int $id): ?array
{
    if ($direction === 'in') {
        $r = one_row($pdo, "SELECT 'in' AS direction, i.id AS email_id, i.received_at AS at, v.from_address, v.from_name, v.to_addresses, v.cc_addresses, v.subject, v.body_text, i.sender_member_id,
                                   i.deal_id, v.matched_contact_ids, v.match_status, i.status, v.is_private, i.message_id, i.in_reply_to, i.error, i.mailbox_id, i.processed_at, NULL::bigint AS template_id,
                                   false AS has_ics, NULL::bigint AS activity_id, NULL::bigint AS contact_id, NULL::timestamptz AS sent_at, mb.address::text AS mailbox_address
                              FROM inbound_email i JOIN mcp_emails v ON v.direction = 'in' AND v.email_id = i.id JOIN mailboxes mb ON mb.id = i.mailbox_id WHERE i.id = :id", ['id' => $id]);
    } else {
        $r = one_row($pdo, "SELECT 'out' AS direction, o.id AS email_id, COALESCE(o.sent_at, o.created_at) AS at, v.from_address, v.from_name, v.to_addresses, v.cc_addresses, o.subject, v.body_text, o.sender_member_id,
                                   o.deal_id, v.matched_contact_ids, 'matched' AS match_status, o.status, v.is_private, o.message_id, o.in_reply_to, o.error, NULL::bigint AS mailbox_id, NULL::timestamptz AS processed_at,
                                   o.template_id, o.has_ics, o.activity_id, o.contact_id, o.sent_at, NULL::text AS mailbox_address
                              FROM outbound_email o JOIN mcp_emails v ON v.direction = 'out' AND v.email_id = o.id WHERE o.id = :id", ['id' => $id]);
    }
    if ($r === null || ($direction === 'out' && $r['status'] !== 'sent' && (int) $r['sender_member_id'] !== (int) current_member_id())) {
        return null;
    }
    return $r;
}

/** A draft of mine (an outbound email still to send — queued, or one that did not go), with its columns. Null when it is not mine or not a draft. */
function find_draft(PDO $pdo, int $id): ?array
{
    return one_row($pdo, "SELECT o.id, o.to_address::text AS to_address, o.cc_addresses, o.subject, o.body_text, o.contact_id, o.deal_id, o.template_id, o.has_ics, o.activity_id, o.in_reply_to,
                                 o.references_ids, o.status, o.error, o.is_private, o.sender_member_id
                            FROM outbound_email o WHERE o.id = :id AND o.sender_member_id = :me AND o.status IN ('queued', 'failed')", ['id' => $id, 'me' => (int) current_member_id()]);
}

/** The people an email is on, by name (matched_contact_ids): [{contact_id, name, account_name, email_opt_out}] the caller may see. */
function email_contacts(PDO $pdo, array $ids): array
{
    if ($ids === []) { return []; }
    $st = $pdo->prepare('SELECT contact_id, name, account_name, email_opt_out FROM mcp_contacts WHERE contact_id = ANY (CAST(:ids AS bigint[])) ORDER BY name');
    $st->execute(['ids' => pg_array_literal(array_map('intval', $ids))]);
    return $st->fetchAll();
}

/** The files on an email (the canonical store: record type inbound_email or outbound_email). */
function email_attachments(PDO $pdo, string $direction, int $id): array
{
    return find_attachments($pdo, $direction === 'in' ? 'inbound_email' : 'outbound_email', $id);
}

/** The activities an email made (one per contact it is on): [{activity_id, contact_id, contact_name}]. */
function email_activities(PDO $pdo, string $direction, int $id): array
{
    $col = $direction === 'in' ? 'inbound_email_id' : 'outbound_email_id';
    $st = $pdo->prepare("SELECT a.activity_id, a.contact_id, c.name AS contact_name FROM mcp_activities a LEFT JOIN mcp_contacts c ON c.contact_id = a.contact_id WHERE a.$col = :id ORDER BY a.activity_id");
    $st->execute(['id' => $id]);
    return $st->fetchAll();
}

/**
 * The conversation an email belongs to: the messages that answer it or that it answers (Message-ID, In-Reply-To, References on either side), oldest first,
 * the caller's wall applied. [{direction, email_id, at, from_name, from_address, subject, status}]
 */
function email_thread(PDO $pdo, array $email): array
{
    $ids = [];
    foreach ([$email['message_id'] ?? null, $email['in_reply_to'] ?? null] as $m) { if ($m !== null && $m !== '') { $ids[] = $m; } }
    if (($email['direction'] ?? '') === 'in') {
        $refs = one_value($pdo, 'SELECT references_ids::text FROM inbound_email WHERE id = :id', ['id' => (int) $email['email_id']]);
        foreach (pg_text_array((string) $refs) as $r) { $ids[] = $r; }
    } else {
        $refs = one_value($pdo, 'SELECT references_ids::text FROM outbound_email WHERE id = :id', ['id' => (int) $email['email_id']]);
        foreach (pg_text_array((string) $refs) as $r) { $ids[] = $r; }
    }
    $ids = array_values(array_unique($ids));
    if ($ids === []) { return []; }
    $lit = pg_array_literal($ids);
    $st = $pdo->prepare("SELECT 'in' AS direction, i.id AS email_id FROM inbound_email i WHERE i.mailbox_id IS NOT NULL AND (i.message_id = ANY (CAST(:ids AS text[])) OR i.in_reply_to = ANY (CAST(:ids AS text[])) OR i.references_ids && CAST(:ids AS text[]))
                         UNION ALL
                         SELECT 'out', o.id FROM outbound_email o WHERE o.message_id = ANY (CAST(:ids AS text[])) OR o.in_reply_to = ANY (CAST(:ids AS text[])) OR o.references_ids && CAST(:ids AS text[])");
    $st->execute(['ids' => $lit]);
    $out = [];
    foreach ($st->fetchAll() as $r) {
        if ($r['direction'] === (string) ($email['direction'] ?? '') && (int) $r['email_id'] === (int) $email['email_id']) { continue; }
        $e = find_email($pdo, $r['direction'], (int) $r['email_id']);
        if ($e !== null) { $out[] = $e; }
    }
    usort($out, static fn (array $a, array $b): int => strcmp((string) $a['at'], (string) $b['at']));
    return array_slice($out, 0, 20);
}

/**
 * The unmatched screen's rows (email-unmatched; the `emails_unmatched` tool): received emails that matched no contact — and the strangers' emails that were
 * proposed as leads and whose lead still waits in the inbox. Newest first, 25 a page. [rows, total, page, pages]; each row's lead_id is the proposed lead.
 */
function unmatched_emails(PDO $pdo, int $page = 1, ?string $status = null): array
{
    $page = max(1, $page);
    $st = $pdo->prepare("SELECT e.email_id, e.at, e.from_address, e.from_name, e.subject, e.match_status, e.is_private, l.id AS lead_id, count(*) OVER () AS total,
                                (SELECT count(*) FROM attachments a WHERE a.record_type = 'inbound_email' AND a.record_id = e.email_id) AS files
                           FROM mcp_emails e LEFT JOIN leads l ON l.proposed_from_email_id = e.email_id AND l.is_proposed AND l.deleted_at IS NULL
                          WHERE e.direction = 'in' AND (e.match_status = 'unmatched' OR (e.match_status = 'proposed_lead' AND l.id IS NOT NULL))
                            AND (CAST(:st AS text) IS NULL OR e.match_status = CAST(:st AS text))
                          ORDER BY e.at DESC, e.email_id DESC LIMIT " . EMAIL_PAGE . ' OFFSET ' . (($page - 1) * EMAIL_PAGE));
    $st->execute(['st' => $status]);
    $rows = $st->fetchAll();
    $total = $rows === [] ? 0 : (int) $rows[0]['total'];
    return ['rows' => $rows, 'total' => $total, 'page' => $page, 'pages' => max(1, (int) ceil($total / EMAIL_PAGE))];
}

/** Emails by filters (find_emails): contact, deal, account, since, direction; newest first. [rows, total] — headers; the body only when asked and readable. */
function find_emails(PDO $pdo, array $f, int $limit = 50, int $offset = 0): array
{
    $w = ["(e.direction = 'in' OR e.status = 'sent' OR e.sender_member_id = :me)"];
    $a = ['me' => (int) current_member_id()];
    if (($f['contact'] ?? null) !== null) { $w[] = ':contact = ANY (e.matched_contact_ids)'; $a['contact'] = (int) $f['contact']; }
    if (($f['deal'] ?? null) !== null) { $w[] = 'e.deal_id = :deal'; $a['deal'] = (int) $f['deal']; }
    if (($f['account'] ?? null) !== null) {
        $w[] = '(e.deal_id IN (SELECT deal_id FROM mcp_deals WHERE account_id = :account) OR e.matched_contact_ids && ARRAY(SELECT contact_id FROM mcp_contacts WHERE account_id = :account)::bigint[])';
        $a['account'] = (int) $f['account'];
    }
    if (($f['since'] ?? null) !== null) { $w[] = 'e.at >= CAST(:since AS timestamptz)'; $a['since'] = $f['since']; }
    if (in_array($f['direction'] ?? null, ['in', 'out'], true)) { $w[] = 'e.direction = :dir'; $a['dir'] = $f['direction']; }
    $st = $pdo->prepare("SELECT e.direction, e.email_id, e.at, e.from_address, e.from_name, e.to_addresses, e.subject, e.sender_member_id, e.deal_id, e.matched_contact_ids, e.is_private, e.status,
                                CASE WHEN :body THEN left(e.body_text, 20000) END AS body_text, count(*) OVER () AS total
                           FROM mcp_emails e WHERE " . implode(' AND ', $w) . ' ORDER BY e.at DESC LIMIT :l OFFSET :o');
    foreach ($a as $k => $v) { $st->bindValue($k, $v, is_int($v) ? PDO::PARAM_INT : PDO::PARAM_STR); }
    $st->bindValue('body', !empty($f['with_body']), PDO::PARAM_BOOL);
    $st->bindValue('l', max(1, min(200, $limit)), PDO::PARAM_INT);
    $st->bindValue('o', max(0, $offset), PDO::PARAM_INT);
    $st->execute();
    $rows = $st->fetchAll();
    return ['rows' => $rows, 'total' => $rows === [] ? 0 : (int) $rows[0]['total']];
}

/** The logging mailbox's health for the admin (mailbox_health; needs mailbox.manage — the view says so): rows as mcp_mailboxes. */
function mailbox_health(PDO $pdo): array
{
    return $pdo->query('SELECT mailbox_id, address, provider, poll_enabled, last_polled_at, last_webhook_at, last_error, last_error_at, active, received_7d, pending FROM mcp_mailboxes ORDER BY mailbox_id')->fetchAll();
}

/**
 * What the caller may do with an email (email.md "Who may do what"): private_set — the sender, or a manager (deals.any); attach/make_lead/ignore — an unmatched
 * or proposed received email, with contacts.write / leads.write; reply — a received email with email.send; edit — a draft of mine.
 */
function email_may(array $e): array
{
    $me = (int) current_member_id();
    $in = $e['direction'] === 'in';
    $open = $in && in_array($e['match_status'], ['unmatched', 'proposed_lead'], true);
    return [
        'private_set' => (int) ($e['sender_member_id'] ?? 0) === $me || has_right('deals.any'),
        'attach' => $open && has_right('contacts.write'),
        'make_lead' => $open && has_right('leads.write'),
        'ignore' => $open && has_right('leads.write'),
        'reply' => $in && has_right('email.send') && !in_array($e['status'], ['bounce', 'auto_reply'], true),
        'edit' => !$in && in_array($e['status'], ['queued', 'failed'], true) && (int) ($e['sender_member_id'] ?? 0) === $me,
    ];
}

/** The text of a received email as a person reads it first: what they wrote above the quoted history (inbound.php's mail_strip_quoted), else the whole. */
function email_reading_text(?string $body): string
{
    $body = (string) $body;
    $cut = function_exists('mail_strip_quoted') ? mail_strip_quoted($body) : $body;
    return $cut !== '' ? $cut : $body;
}
