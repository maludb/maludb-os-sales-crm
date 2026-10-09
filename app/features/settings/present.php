<?php
declare(strict_types=1);

/** The JSON shapes of a person's settings — whitelists, never a raw row; never a token's value or hash, never the signature's words beyond the person's own screen. */

function present_prefs(array $p): array
{
    return ['email_enabled' => (bool) $p['email_enabled'], 'text_enabled' => (bool) $p['text_enabled'], 'kinds' => array_values($p['kinds']),
            'text_kinds' => array_values($p['text_kinds']), 'default_remind_minutes' => $p['default_remind_minutes'], 'morning_note' => (bool) $p['morning_note'],
            'morning_hour' => $p['morning_hour'], 'digest' => (bool) $p['digest'], 'saved' => (bool) ($p['saved'] ?? true)];
}

function present_token(array $t): array
{
    return ['token_id' => (int) $t['id'], 'label' => $t['label'], 'scope' => $t['scope'], 'created_at' => json_ts($t['created_at']),
            'last_used_at' => json_ts($t['last_used_at'] ?? null), 'expires_at' => json_ts($t['expires_at'] ?? null), 'revoked' => ($t['revoked_at'] ?? null) !== null];
}

function present_notification(array $n): array
{
    return ['notification_id' => (int) $n['notification_id'], 'kind' => $n['kind'], 'record_type' => $n['record_type'],
            'record_id' => $n['record_id'] !== null ? (int) $n['record_id'] : null, 'title' => $n['title'], 'body' => $n['body'],
            'deal_id' => ($n['deal_id'] ?? null) !== null ? (int) $n['deal_id'] : null, 'contact_id' => ($n['contact_id'] ?? null) !== null ? (int) $n['contact_id'] : null,
            'activity_id' => ($n['activity_id'] ?? null) !== null ? (int) $n['activity_id'] : null,
            'read' => $n['read_at'] !== null, 'created_at' => json_ts($n['created_at']), 'url' => notification_record_url($n)];
}

/** The screen a notification points at, by the record it names (null when none) — the one route map of the record kinds. */
function notification_record_url(array $n): ?string
{
    $id = ($n['record_id'] ?? null) !== null ? (int) $n['record_id'] : null;
    $byType = $id === null ? null : match ($n['record_type'] ?? null) {
        'lead' => '/leads/' . $id, 'account' => '/accounts/' . $id, 'contact' => '/contacts/' . $id, 'deal' => '/deals/' . $id,
        'activity' => '/activities/' . $id, 'campaign' => '/campaigns/' . $id, 'list' => '/lists/' . $id, 'import' => '/import/' . $id,
        'export' => '/exports', 'proposal' => '/proposals', 'email' => '/email/in/' . $id,
        default => null,
    };
    if ($byType !== null) {
        return $byType;
    }
    foreach (['activity_id' => '/activities/', 'deal_id' => '/deals/', 'contact_id' => '/contacts/'] as $k => $p) {
        if (($n[$k] ?? null) !== null) {
            return $p . (int) $n[$k];
        }
    }
    return null;
}
