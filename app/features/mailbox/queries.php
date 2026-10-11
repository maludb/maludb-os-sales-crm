<?php
declare(strict_types=1);

/**
 * The logging mailbox's reads (screen admin-mailbox; slice 5 built the mail side). The row names the ENV KEY that holds its webhook secret (`webhook_secret_ref`) — never the secret: these reads
 * say whether that key is SET in the environment (a yes or a no), and nothing else about its value. The health is the row's own columns plus what arrived in a week and what waits to be matched.
 */

/** The mailbox the application logs through (crm_settings.mailbox_id, else the first active one), with its health; null when none is set up. */
function mailbox(PDO $pdo): ?array
{
    $r = one_row($pdo, "SELECT m.id, m.address::text AS address, m.display_name, m.provider, m.webhook_secret_ref, m.signature, m.poll_enabled, m.active, m.last_polled_at, m.last_webhook_at, m.last_error, m.last_error_at,
                               m.id = (SELECT mailbox_id FROM crm_settings WHERE id = 1) AS is_logging,
                               (SELECT count(*) FROM inbound_email i WHERE i.mailbox_id = m.id AND i.received_at > now() - interval '7 days') AS received_7d,
                               (SELECT count(*) FROM inbound_email i WHERE i.mailbox_id = m.id AND i.match_status = 'pending') AS pending
                          FROM mailboxes m ORDER BY (m.id = (SELECT mailbox_id FROM crm_settings WHERE id = 1)) DESC, m.active DESC, m.id LIMIT 1");
    if ($r === null) {
        return null;
    }
    $ref = (string) ($r['webhook_secret_ref'] ?? '');
    return ['id' => (int) $r['id'], 'poll_enabled' => (bool) $r['poll_enabled'], 'active' => (bool) $r['active'], 'is_logging' => (bool) $r['is_logging'], 'received_7d' => (int) $r['received_7d'], 'pending' => (int) $r['pending'],
        'secret_set' => $ref !== '' && (string) env($ref, '') !== ''] + $r;                 // whether the env key is set — never its value
}

/** The home card's line: ['state' => none|off|error|quiet|ok, 'words' => …, 'address' => …]. */
function mailbox_summary(PDO $pdo): array
{
    $m = mailbox($pdo);
    if ($m === null) {
        return ['state' => 'none', 'address' => null, 'words' => 'Not set up yet'];
    }
    if (!$m['active']) {
        return ['state' => 'off', 'address' => $m['address'], 'words' => 'Switched off'];
    }
    if ($m['last_error'] !== null) {
        return ['state' => 'error', 'address' => $m['address'], 'words' => 'Failing: ' . mb_substr((string) $m['last_error'], 0, 80)];
    }
    if ((string) $m['webhook_secret_ref'] === '' || !$m['secret_set']) {
        return ['state' => 'quiet', 'address' => $m['address'], 'words' => 'No webhook secret set, so the webhook is refused'];
    }
    if ($m['last_webhook_at'] === null && $m['last_polled_at'] === null) {
        return ['state' => 'quiet', 'address' => $m['address'], 'words' => 'Nothing has arrived yet'];
    }
    return ['state' => 'ok', 'address' => $m['address'], 'words' => 'Working'];
}

/** The whitelist for JSON: the address, the name of the secret's key and whether it is set, the health. Never a value. */
function present_mailbox(?array $m): ?array
{
    if ($m === null) {
        return null;
    }
    return ['mailbox_id' => $m['id'], 'address' => $m['address'], 'display_name' => $m['display_name'], 'provider' => $m['provider'], 'webhook_secret_ref' => $m['webhook_secret_ref'], 'secret_set' => $m['secret_set'],
        'signature' => $m['signature'], 'poll_enabled' => $m['poll_enabled'], 'active' => $m['active'], 'is_logging' => $m['is_logging'], 'last_polled_at' => json_ts($m['last_polled_at']),
        'last_webhook_at' => json_ts($m['last_webhook_at']), 'last_error' => $m['last_error'], 'last_error_at' => json_ts($m['last_error_at']), 'received_7d' => $m['received_7d'], 'pending' => $m['pending']];
}
