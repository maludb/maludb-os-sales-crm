<?php
declare(strict_types=1);

/**
 * A person's own settings (screens `settings`, `notifications`, `settings-tokens`; actions prefs_save, signature_save, notification_read,
 * notifications_read_all, token_mint, token_revoke): how they are told and reminded (notification_prefs, db/013 — the reminder's default
 * lead time, the morning note and its hour, the digest), their signature under the emails they send (members.email_signature), their
 * in-app notifications, their tokens for the two MCP servers (scope mcp; an API key acting as them is slice 8's key_mint).
 * The application's settings (settings_save) are slice 9's, in app/features/admin.
 */

/** Every event a person can be told about, with the sentence the settings screen shows (db/013 notifications.kind — all fifteen). */
const NOTICE_KINDS = [
    'reminder'        => 'An activity of mine is due (its reminder)',
    'assigned'        => 'A deal, account or contact is assigned to me',
    'lead_assigned'   => 'A lead is routed to me',
    'reply_received'  => 'A contact of mine replies',
    'deal_won'        => 'A deal of mine is won',
    'deal_lost'       => 'A deal of mine is lost',
    'deal_moved'      => 'A deal I follow moves to another stage',
    'mention'         => 'Someone mentions me in a note',
    'proposal'        => 'The Sales Assistant proposes something',
    'morning_note'    => 'The Sales Assistant\'s morning note',
    'import_done'     => 'An import of mine finishes',
    'export_ready'    => 'An export of mine is ready',
    'duplicate_found' => 'A record of mine looks like a duplicate',
    'stage_wip'       => 'A stage passes its limit of deals',
    'digest'          => 'The daily digest',
];

/** The reminder's default lead times a person picks from (minutes; null = the business's setting). */
const REMIND_CHOICES = [0 => 'At the time', 5 => '5 minutes before', 10 => '10 minutes before', 15 => '15 minutes before', 30 => '30 minutes before',
    60 => '1 hour before', 120 => '2 hours before', 1440 => '1 day before', 2880 => '2 days before', 10080 => '1 week before'];

/** The person's choices, or the table's defaults when they never saved any. */
function find_prefs(PDO $pdo, int $memberId): array
{
    $st = $pdo->prepare('SELECT email_enabled, text_enabled, kinds, text_kinds, default_remind_minutes, morning_note, morning_hour, digest FROM notification_prefs WHERE member_id = :m');
    $st->execute(['m' => $memberId]);
    $r = $st->fetch();
    if ($r === false) {
        $d = $pdo->query("SELECT column_name, column_default FROM information_schema.columns WHERE table_name = 'notification_prefs' AND column_name IN ('kinds', 'text_kinds')")->fetchAll(PDO::FETCH_KEY_PAIR);
        $lit = static fn (string $def): string => preg_match("/'(\\{[^']*\\})'/", $def, $m) ? $m[1] : '{}';
        return ['email_enabled' => true, 'text_enabled' => false, 'kinds' => pg_text_array($lit((string) ($d['kinds'] ?? ''))),
                'text_kinds' => pg_text_array($lit((string) ($d['text_kinds'] ?? ''))), 'default_remind_minutes' => null, 'morning_note' => true, 'morning_hour' => null,
                'digest' => false, 'saved' => false];
    }
    return ['email_enabled' => (bool) $r['email_enabled'], 'text_enabled' => (bool) $r['text_enabled'], 'kinds' => pg_text_array((string) $r['kinds']),
            'text_kinds' => pg_text_array((string) $r['text_kinds']), 'default_remind_minutes' => $r['default_remind_minutes'] === null ? null : (int) $r['default_remind_minutes'],
            'morning_note' => (bool) $r['morning_note'], 'morning_hour' => $r['morning_hour'] === null ? null : (int) $r['morning_hour'], 'digest' => (bool) $r['digest'], 'saved' => true];
}

/** The business's defaults a person's blank choice falls back to (crm_settings via mcp_settings). */
function settings_defaults(PDO $pdo): array
{
    $r = $pdo->query('SELECT default_remind_minutes, morning_note_hour, morning_note_enabled FROM mcp_settings')->fetch() ?: [];
    return ['default_remind_minutes' => (int) ($r['default_remind_minutes'] ?? 30), 'morning_hour' => (int) ($r['morning_note_hour'] ?? 6), 'morning_note_enabled' => (bool) ($r['morning_note_enabled'] ?? true)];
}

/** The same, by the spec's name. */
function my_prefs(PDO $pdo, int $memberId): array
{
    return find_prefs($pdo, $memberId);
}

/** Save the choices ($prefs keys present are changed; absent are kept). Answers ['before' => …, 'after' => …] of the changed keys. */
function save_prefs(PDO $pdo, int $memberId, array $prefs): array
{
    $before = find_prefs($pdo, $memberId);
    unset($before['saved']);
    $after = array_intersect_key($prefs, $before) + $before;
    $lit = static fn (array $a): string => '{' . implode(',', array_map(static fn (string $k): string => preg_replace('/[^a-z_]/', '', $k), $a)) . '}';
    $pdo->prepare('INSERT INTO notification_prefs (member_id, email_enabled, text_enabled, kinds, text_kinds, default_remind_minutes, morning_note, morning_hour, digest)
                   VALUES (:m, :e, :t, CAST(:k AS text[]), CAST(:tk AS text[]), :rm, :mn, :mh, :dg)
                   ON CONFLICT (member_id) DO UPDATE SET email_enabled = EXCLUDED.email_enabled, text_enabled = EXCLUDED.text_enabled, kinds = EXCLUDED.kinds,
                        text_kinds = EXCLUDED.text_kinds, default_remind_minutes = EXCLUDED.default_remind_minutes, morning_note = EXCLUDED.morning_note,
                        morning_hour = EXCLUDED.morning_hour, digest = EXCLUDED.digest')
        ->execute(['m' => $memberId, 'e' => $after['email_enabled'] ? 't' : 'f', 't' => $after['text_enabled'] ? 't' : 'f', 'k' => $lit($after['kinds']), 'tk' => $lit($after['text_kinds']),
                   'rm' => $after['default_remind_minutes'], 'mn' => $after['morning_note'] ? 't' : 'f', 'mh' => $after['morning_hour'], 'dg' => $after['digest'] ? 't' : 'f']);
    $changed = array_keys(array_filter($after, static fn ($v, string $k): bool => $v !== $before[$k], ARRAY_FILTER_USE_BOTH));
    return ['before' => array_intersect_key($before, array_flip($changed)), 'after' => array_intersect_key($after, array_flip($changed)), 'prefs' => $after];
}

/** The words under the emails I send (members.email_signature); '' when none. */
function my_signature(PDO $pdo, int $memberId): string
{
    return (string) (one_value($pdo, 'SELECT email_signature FROM members WHERE id = :m', ['m' => $memberId]) ?? '');
}

/** Save it ('' clears it). Answers the length before and after — never the words (they are a person's text). */
function save_signature(PDO $pdo, int $memberId, string $text): void
{
    $pdo->prepare('UPDATE members SET email_signature = NULLIF(:t, \'\') WHERE id = :m')->execute(['t' => $text, 'm' => $memberId]);
}

/** My ICS link's state (calendar_feeds, db/010): null when I have none — feed_rotate (slice 4) makes and replaces it. */
function my_feed(PDO $pdo, int $memberId): ?array
{
    $st = $pdo->prepare('SELECT created_at, last_used_at FROM calendar_feeds WHERE member_id = :m AND revoked_at IS NULL');
    $st->execute(['m' => $memberId]);
    $r = $st->fetch();
    return $r === false ? null : $r;
}

/**
 * What the kernel last said about this person's texts, from our own outbox (this application never sees a phone number):
 * 'no_verified_phone', 'opted_out', 'no_sender' or null when the latest text was sent (or none was ever tried).
 */
function last_text_refusal(PDO $pdo, int $memberId): ?string
{
    $st = $pdo->prepare("SELECT status, detail FROM notification_outbox WHERE member_id = :m AND channel = 'text' AND status IN ('sent', 'skipped', 'failed') ORDER BY id DESC LIMIT 1");
    $st->execute(['m' => $memberId]);
    $r = $st->fetch();
    if ($r === false || $r['status'] === 'sent') {
        return null;
    }
    foreach (['no_verified_phone', 'opted_out', 'no_sender'] as $code) {
        if (str_contains((string) $r['detail'], $code)) {
            return $code;
        }
    }
    return null;
}

// ---- notifications (the bell) ----------------------------------------------------------------------------------------
function find_my_notifications(PDO $pdo, int $memberId, bool $unreadOnly = false, int $limit = 50, int $page = 1): array
{
    $st = $pdo->prepare('SELECT notification_id, kind, record_type, record_id, title, body, read_at, created_at FROM mcp_notifications'
        . ($unreadOnly ? ' WHERE read_at IS NULL' : '') . ' ORDER BY (read_at IS NULL) DESC, created_at DESC LIMIT ' . max(1, min(200, $limit)) . ' OFFSET ' . (max(1, $page) - 1) * max(1, min(200, $limit)));
    $st->execute();
    return $st->fetchAll();
}

/** Mark one of the member's own notifications read, or every unread one (null). Returns how many changed. */
function mark_notifications_read(PDO $pdo, int $memberId, ?int $id): int
{
    if ($id === null) {
        $st = $pdo->prepare('UPDATE notifications SET read_at = now() WHERE member_id = :m AND read_at IS NULL');
        $st->execute(['m' => $memberId]);
    } else {
        $st = $pdo->prepare('UPDATE notifications SET read_at = now() WHERE member_id = :m AND id = :id AND read_at IS NULL');
        $st->execute(['m' => $memberId, 'id' => $id]);
    }
    return $st->rowCount();
}

// ---- tokens -----------------------------------------------------------------------------------------------------------
function find_my_tokens(PDO $pdo, int $memberId): array
{
    $st = $pdo->prepare("SELECT id, label, scope, last_used_at, expires_at, revoked_at, created_at FROM mcp_access_tokens WHERE member_id = :m AND scope = 'mcp' ORDER BY created_at DESC");
    $st->execute(['m' => $memberId]);
    return $st->fetchAll();
}

function find_my_token(PDO $pdo, int $memberId, int $tokenId): ?array
{
    $st = $pdo->prepare("SELECT id, label, scope, last_used_at, expires_at, revoked_at, created_at FROM mcp_access_tokens WHERE member_id = :m AND id = :id AND scope = 'mcp'");
    $st->execute(['m' => $memberId, 'id' => $tokenId]);
    $r = $st->fetch();
    return $r === false ? null : $r;
}

/** Mint a person's own MCP token (read-only): `mcp_` + 48 hex, shown once; only the hash is stored. A key (scope api, acting as the person) is slice 8's key_mint. */
function mint_token(PDO $pdo, int $memberId, string $label, string $scope, ?int $expiresInDays = null): array
{
    $raw = 'mcp_' . bin2hex(random_bytes(24));
    $st = $pdo->prepare('INSERT INTO mcp_access_tokens (member_id, label, token_hash, scope, expires_at)
                         VALUES (:m, :l, :h, :s, CASE WHEN CAST(:d AS integer) IS NULL THEN NULL ELSE now() + make_interval(days => CAST(:d AS integer)) END) RETURNING id');
    $st->execute(['m' => $memberId, 'l' => $label, 'h' => hash('sha256', $raw), 's' => $scope, 'd' => $expiresInDays]);
    return ['id' => (int) $st->fetchColumn(), 'raw' => $raw, 'label' => $label, 'scope' => $scope, 'expires_in_days' => $expiresInDays];
}

/** Revoke one of the member's own live tokens. False when there is none such. */
function revoke_token(PDO $pdo, int $memberId, int $tokenId): bool
{
    $st = $pdo->prepare("UPDATE mcp_access_tokens SET revoked_at = now() WHERE id = :id AND member_id = :m AND scope = 'mcp' AND revoked_at IS NULL RETURNING id");
    $st->execute(['id' => $tokenId, 'm' => $memberId]);
    return $st->fetchColumn() !== false;
}
