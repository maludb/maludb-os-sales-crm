<?php
declare(strict_types=1);

/** Email's JSON shapes (the screens in JSON mode, the records tools' rows): whitelists, never a raw row — a body only where the view let the caller read it. */
require_once __DIR__ . '/queries.php';
require_once __DIR__ . '/../templates/queries.php';

function email_int(mixed $v): ?int
{
    return $v === null ? null : (int) $v;
}

/** One line of a list: who, what, when, how it was matched. */
function present_email_row(array $r): array
{
    return ['direction' => $r['direction'], 'email_id' => (int) $r['email_id'], 'at' => json_ts($r['at']), 'from_address' => $r['from_address'] ?? null, 'from_name' => $r['from_name'] ?? null,
        'to_addresses' => pg_text_array((string) ($r['to_addresses'] ?? '{}')), 'subject' => $r['subject'], 'sender_member_id' => email_int($r['sender_member_id'] ?? null),
        'deal_id' => email_int($r['deal_id'] ?? null), 'contact_ids' => pg_int_array((string) ($r['matched_contact_ids'] ?? '{}')), 'is_private' => (bool) ($r['is_private'] ?? false),
        'status' => $r['status'] ?? null] + (array_key_exists('body_text', $r) ? ['body_text' => $r['body_text']] : []);
}

/** One email in full (get_email): the row, the files, the activities it made, the conversation. */
function present_email(array $e, array $files, array $activities, array $thread, array $may): array
{
    return ['direction' => $e['direction'], 'email_id' => (int) $e['email_id'], 'at' => json_ts($e['at']), 'from_address' => $e['from_address'], 'from_name' => $e['from_name'],
        'to_addresses' => pg_text_array((string) $e['to_addresses']), 'cc_addresses' => pg_text_array((string) $e['cc_addresses']), 'subject' => $e['subject'],
        'body_text' => $e['body_text'], 'body_hidden' => $e['body_text'] === null, 'sender_member_id' => email_int($e['sender_member_id']), 'deal_id' => email_int($e['deal_id']),
        'contact_ids' => pg_int_array((string) $e['matched_contact_ids']), 'match_status' => $e['match_status'], 'status' => $e['status'], 'is_private' => (bool) $e['is_private'],
        'message_id' => $e['message_id'], 'in_reply_to' => $e['in_reply_to'], 'has_ics' => (bool) $e['has_ics'], 'error' => $e['error'],
        'attachments' => array_map(static fn (array $a): array => ['attachment_id' => (int) $a['attachment_id'], 'filename' => $a['filename'], 'mime_type' => $a['mime_type'], 'byte_size' => (int) $a['byte_size']], $files),
        'activities' => array_map(static fn (array $a): array => ['activity_id' => (int) $a['activity_id'], 'contact_id' => email_int($a['contact_id'])], $activities),
        'thread' => array_map(static fn (array $t): array => ['direction' => $t['direction'], 'email_id' => (int) $t['email_id'], 'at' => json_ts($t['at']), 'subject' => $t['subject']], $thread),
        'may' => $may];
}

/** A row of the unmatched screen (emails_unmatched). */
function present_unmatched_row(array $r): array
{
    return ['email_id' => (int) $r['email_id'], 'at' => json_ts($r['at']), 'from_address' => $r['from_address'], 'from_name' => $r['from_name'], 'subject' => $r['subject'],
        'match_status' => $r['match_status'], 'proposed_lead_id' => email_int($r['lead_id'] ?? null), 'attachments' => (int) ($r['files'] ?? 0)];
}

/** The compose screen in JSON: the people it can go to, the draft or the fields prefilled, the template's merge, the files and the meeting. */
function present_compose(array $c): array
{
    return ['contacts' => array_map(static fn (array $p): array => ['contact_id' => $p['contact_id'], 'name' => $p['name'], 'address' => $p['address'], 'account_name' => $p['account_name'],
            'opted_out' => $p['opted_out'], 'allowed' => $p['allowed'], 'selected' => $p['selected']], $c['contacts']),
        'draft_id' => $c['draft_id'], 'deal_id' => $c['deal_id'], 'template_id' => $c['template_id'], 'activity_id' => $c['activity_id'], 'reply_to' => $c['reply_to'],
        'subject' => $c['subject'], 'body' => $c['body'], 'merge' => $c['merge'], 'merge_fields' => array_keys(MERGE_FIELDS),
        'files' => array_map(static fn (array $a): array => ['attachment_id' => (int) $a['attachment_id'], 'filename' => $a['filename'], 'byte_size' => (int) $a['byte_size'], 'attached' => (bool) $a['attached']], $c['files']),
        'meeting' => $c['meeting'], 'reply_to_mailbox' => $c['mailbox'], 'templates' => $c['templates']];
}
