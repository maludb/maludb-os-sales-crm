<?php
declare(strict_types=1);

/**
 * Key writes. The raw key is `crm_` + 48 hex characters from random_bytes, handed back ONCE to the caller; the table keeps sha256(raw) and nothing else, and no log, no response but the one that
 * minted it, and no screen on a later visit ever holds it. The database is the referee for limits (the trigger of db/005 fills the defaults), rotation (crm_key_rotate: a new key, the old one living on for the overlap) and
 * resolution (crm_resolve_key). PHP's rules: a person's own key never raises a limit above the settings' (only a holder of tokens.manage may); at most KEY_MAX_LIVE live keys a person; a lead-capture key is
 * minted by the admin (slice 9) and is rotated and revoked like any other.
 */
require_once __DIR__ . '/queries.php';

/** Mint a key for $member: ['id', 'raw', 'label']. $perMin / $perDay null = the settings' default (set by the trigger). */
function key_mint(PDO $pdo, int $member, string $label, ?int $perMin, ?int $perDay, ?int $days): array
{
    $live = (int) one_value($pdo, "SELECT count(*) FROM mcp_access_tokens WHERE member_id = :m AND scope = 'api' AND revoked_at IS NULL AND (expires_at IS NULL OR expires_at > now())", ['m' => $member]);
    if ($live >= KEY_MAX_LIVE) {
        throw new DomainException('You hold ' . KEY_MAX_LIVE . ' live keys already — revoke one you no longer use first.');
    }
    $raw = KEY_PREFIX . bin2hex(random_bytes(24));
    $st = $pdo->prepare("INSERT INTO mcp_access_tokens (member_id, label, token_hash, scope, rate_per_minute, rate_per_day, expires_at)
                         VALUES (:m, :l, :h, 'api', :pm, :pd, CASE WHEN CAST(:d AS integer) IS NULL THEN NULL ELSE now() + make_interval(days => CAST(:d AS integer)) END) RETURNING id");
    $st->execute(['m' => $member, 'l' => $label, 'h' => hash('sha256', $raw), 'pm' => $perMin, 'pd' => $perDay, 'd' => $days]);
    return ['id' => (int) $st->fetchColumn(), 'raw' => $raw, 'label' => $label];
}

/** Rotate a live key: a new one for the same person, label, limits (and capture source and expiry); the old one keeps working for the overlap (24 h by default). ['id', 'raw']. */
function key_rotate(PDO $pdo, int $keyId): array
{
    $raw = KEY_PREFIX . bin2hex(random_bytes(24));
    $st = $pdo->prepare('SELECT crm_key_rotate(:id, :h)');
    $st->execute(['id' => $keyId, 'h' => hash('sha256', $raw)]);
    return ['id' => (int) $st->fetchColumn(), 'raw' => $raw];
}

/** Revoke a key at once. False when it is not there or was revoked already. */
function key_revoke(PDO $pdo, int $keyId): bool
{
    $st = $pdo->prepare("UPDATE mcp_access_tokens SET revoked_at = now() WHERE id = :id AND scope = 'api' AND revoked_at IS NULL RETURNING id");
    $st->execute(['id' => $keyId]);
    return $st->fetchColumn() !== false;
}
