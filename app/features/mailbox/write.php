<?php
declare(strict_types=1);

/**
 * mailbox_save (log mailbox.save — the address, the provider, the NAME of the secret's env key; never a value; people only). The application logs through ONE mailbox (crm_settings.mailbox_id): the sellers BCC it, replies
 * come to it. The row holds the NAME of the environment key that carries the webhook's secret (webhook_secret_ref, e.g. MAILBOX_CRM_WEBHOOK_SECRET); the secret itself lives in config/.env, put there by root (ROOT_STEPS step 3).
 * This form takes the name only: a value that is not shaped like a name is refused, so a secret pasted into the box is never stored or printed.
 */
require_once __DIR__ . '/queries.php';

/** Why a webhook secret reference is not an environment key's NAME, or null when it is: capital letters, digits and underscores, starting with a letter, with at least one underscore (a secret is not shaped so). */
function mailbox_secret_ref_error(string $ref): ?string
{
    if (!preg_match('/^[A-Z][A-Z0-9_]{2,63}$/', $ref) || !str_contains($ref, '_')) {
        return 'This is the NAME of the key in config/.env that holds the webhook secret, like MAILBOX_CRM_WEBHOOK_SECRET — capital letters, digits and underscores. Never the secret itself.';
    }
    return null;
}

/** Save the logging mailbox ($id null = make it). $f: address, display_name, provider, webhook_secret_ref (or null), signature (or null), poll_enabled, active. ['id', 'before', 'after']. */
function mailbox_save(PDO $pdo, ?int $id, array $f): array
{
    $cur = $id === null ? null : mailbox($pdo);
    if ($id !== null && ($cur === null || $cur['id'] !== $id)) {
        throw new DomainException('Not found.');
    }
    if ((int) one_value($pdo, 'SELECT count(*) FROM mailboxes WHERE address = :a AND id <> :id', ['a' => $f['address'], 'id' => $id ?? 0]) > 0) {
        throw new DomainException('The address ' . $f['address'] . ' is another mailbox\'s already.');
    }
    if ($f['webhook_secret_ref'] !== null && ($why = mailbox_secret_ref_error((string) $f['webhook_secret_ref'])) !== null) {
        throw new DomainException($why);
    }
    $new = ['address' => $f['address'], 'display_name' => $f['display_name'], 'provider' => $f['provider'], 'webhook_secret_ref' => $f['webhook_secret_ref'], 'poll_enabled' => (bool) $f['poll_enabled'], 'active' => (bool) $f['active']];
    $args = ['a' => $new['address'], 'n' => $new['display_name'], 'p' => $new['provider'], 'r' => $new['webhook_secret_ref'], 's' => $f['signature'], 'pe' => $new['poll_enabled'] ? 't' : 'f', 'ac' => $new['active'] ? 't' : 'f'];
    if ($cur === null) {
        $st = $pdo->prepare('INSERT INTO mailboxes (address, display_name, provider, webhook_secret_ref, signature, poll_enabled, active) VALUES (:a, :n, :p, :r, :s, CAST(:pe AS boolean), CAST(:ac AS boolean)) RETURNING id');
        $st->execute($args);
        $id = (int) $st->fetchColumn();
        $pdo->prepare('UPDATE crm_settings SET mailbox_id = :id WHERE id = 1')->execute(['id' => $id]);
        return ['id' => $id, 'before' => [], 'after' => $new];
    }
    $old = ['address' => $cur['address'], 'display_name' => $cur['display_name'], 'provider' => $cur['provider'], 'webhook_secret_ref' => $cur['webhook_secret_ref'], 'poll_enabled' => $cur['poll_enabled'], 'active' => $cur['active']];
    $d = crm_diff($old, $new);
    $signatureChanged = (string) $cur['signature'] !== (string) ($f['signature'] ?? '');
    if ($d['after'] !== [] || $signatureChanged) {
        $pdo->prepare('UPDATE mailboxes SET address = :a, display_name = :n, provider = :p, webhook_secret_ref = :r, signature = :s, poll_enabled = CAST(:pe AS boolean), active = CAST(:ac AS boolean) WHERE id = :id')
            ->execute($args + ['id' => $id]);
    }
    if (array_key_exists('address', $d['after'])) {                  // another mailbox: it is read from the beginning, and the old one's failure is not its
        $pdo->prepare('UPDATE mailboxes SET last_uid = NULL, last_polled_at = NULL, last_error = NULL, last_error_at = NULL WHERE id = :id')->execute(['id' => $id]);
    }
    if ($signatureChanged) {
        $d['before']['signature'] = '(changed)';
        $d['after']['signature'] = '(changed)';
    }
    if (!$cur['is_logging']) {
        $pdo->prepare('UPDATE crm_settings SET mailbox_id = :id WHERE id = 1')->execute(['id' => $id]);
    }
    return ['id' => $id] + $d;
}
