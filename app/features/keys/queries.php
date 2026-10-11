<?php
declare(strict_types=1);

/**
 * API keys (screen `settings-tokens`'s second card; actions key_mint / key_rotate / key_revoke; agents-k7-api.md "The API"). A KEY is a person's own door for a script or an outside system: it acts AS
 * that person (every read and write is judged by the database as them), is limited per minute and per day, and is shown ONCE at mint or rotate — only its SHA-256 is stored. A LEAD-CAPTURE key
 * (lead_source_id set, minted in slice 9's admin) creates leads under its source and reads nothing. These queries read `mcp_tokens` (db/016) so the wall is the view's: a person's own keys, or every key for
 * `tokens.manage`. The raw value is never read back — it is not stored.
 */
const KEY_PREFIX = 'crm_';
const KEY_MAX_LIVE = 10;

/** The keys (scope api) of one person, or of everyone (null — the view only shows others' to a holder of tokens.manage). Newest first. */
function find_keys(PDO $pdo, ?int $member): array
{
    $st = $pdo->prepare("SELECT t.key_id, t.member_id, m.display_name AS member_name, t.label, t.rate_per_minute, t.rate_per_day, t.lead_source_id, s.name AS source_name, t.rotated_from, t.calls_today,
                                t.created_at, t.last_used_at, t.expires_at, t.revoked_at
                           FROM mcp_tokens t JOIN members m ON m.id = t.member_id LEFT JOIN lead_sources s ON s.id = t.lead_source_id
                          WHERE t.scope = 'api' AND (CAST(:m AS bigint) IS NULL OR t.member_id = CAST(:m AS bigint)) ORDER BY t.created_at DESC, t.key_id DESC");
    $st->execute(['m' => $member]);
    return $st->fetchAll();
}

function find_key(PDO $pdo, int $id): ?array
{
    $st = $pdo->prepare("SELECT t.key_id, t.member_id, t.label, t.rate_per_minute, t.rate_per_day, t.lead_source_id, t.rotated_from, t.expires_at, t.revoked_at
                           FROM mcp_tokens t WHERE t.scope = 'api' AND t.key_id = :id");
    $st->execute(['id' => $id]);
    $r = $st->fetch();
    return $r === false ? null : $r;
}

/** Is a key usable now (not revoked, not past its expiry)? */
function key_is_live(array $k): bool
{
    return $k['revoked_at'] === null && ($k['expires_at'] === null || strtotime((string) $k['expires_at']) > time());
}

/** The whitelist of a key for JSON: never the hash, never a value. */
function present_key(array $k): array
{
    return ['key_id' => (int) $k['key_id'], 'label' => $k['label'], 'member_id' => (int) $k['member_id'], 'member_name' => $k['member_name'] ?? null, 'kind' => $k['lead_source_id'] === null ? 'person' : 'lead_capture',
            'lead_source_id' => $k['lead_source_id'] === null ? null : (int) $k['lead_source_id'], 'source_name' => $k['source_name'] ?? null, 'rate_per_minute' => (int) $k['rate_per_minute'], 'rate_per_day' => (int) $k['rate_per_day'],
            'calls_today' => (int) ($k['calls_today'] ?? 0), 'rotated_from' => $k['rotated_from'] === null ? null : (int) $k['rotated_from'], 'created_at' => json_ts($k['created_at'] ?? null),
            'last_used_at' => json_ts($k['last_used_at'] ?? null), 'expires_at' => json_ts($k['expires_at'] ?? null), 'revoked' => ($k['revoked_at'] ?? null) !== null, 'live' => key_is_live($k)];
}

/** The settings' default and ceiling for a person's own key: [per_minute, per_day]. */
function key_default_limits(PDO $pdo): array
{
    $r = one_row($pdo, 'SELECT key_rate_per_minute, key_rate_per_day FROM mcp_settings') ?? [];
    return [(int) ($r['key_rate_per_minute'] ?? 60), (int) ($r['key_rate_per_day'] ?? 5000)];
}
