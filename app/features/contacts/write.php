<?php
declare(strict_types=1);

/**
 * The writes of contacts. The database is the referee (db/007): the names trimmed, the address checked and mirrored into contact_emails, the first
 * contact of an account its primary (one primary per account), the duplicates policy (block_exact_email refuses an address another contact holds,
 * naming it), the opt-outs' moments, the restriction, the custom values.
 */
const CONTACT_COLUMNS = ['account_id', 'first_name', 'last_name', 'title', 'department_text', 'email', 'email_alt', 'phone', 'mobile', 'role', 'is_primary', 'linkedin_url', 'address',
    'city', 'region', 'country', 'timezone', 'preferred_channel', 'birthday', 'do_not_call', 'owner_member_id', 'source_id', 'custom_values'];

function contact_save(PDO $pdo, ?int $id, array $f, int $by): int
{
    $f = array_intersect_key($f, array_flip(CONTACT_COLUMNS));
    foreach (['is_primary', 'do_not_call'] as $b) {
        if (array_key_exists($b, $f)) { $f[$b] = $f[$b] ? 't' : 'f'; }
    }
    if (array_key_exists('custom_values', $f)) {
        $f['custom_values'] = json_encode($f['custom_values'] === [] ? (object) [] : $f['custom_values'], JSON_THROW_ON_ERROR);
    }
    $cast = static fn (string $c): string => match ($c) { 'custom_values' => 'CAST(:' . $c . ' AS jsonb)', 'is_primary', 'do_not_call' => 'CAST(:' . $c . ' AS boolean)', default => ':' . $c };
    // a second primary is the swap's (contact_set_primary) — saving is_primary yes clears the account's other primary first, in the same transaction
    if (($f['is_primary'] ?? null) === 't') {
        $acc = $f['account_id'] ?? ($id !== null ? one_value($pdo, 'SELECT account_id FROM contacts WHERE id = :id', ['id' => $id]) : null);
        if ($acc !== null) {
            $pdo->prepare('UPDATE contacts SET is_primary = false WHERE account_id = :a AND is_primary AND id IS DISTINCT FROM :id')->execute(['a' => $acc, 'id' => $id]);
        }
    }
    if ($id === null) {
        $cols = array_keys($f);
        $st = $pdo->prepare('INSERT INTO contacts (' . implode(', ', $cols) . ', created_by) VALUES (' . implode(', ', array_map($cast, $cols)) . ', :by) RETURNING id');
        $st->execute($f + ['by' => $by]);
        return (int) $st->fetchColumn();
    }
    if ($f !== []) {
        $set = implode(', ', array_map(static fn (string $c): string => $c . ' = ' . $cast($c), array_keys($f)));
        $pdo->prepare("UPDATE contacts SET $set WHERE id = :id")->execute($f + ['id' => $id]);
    }
    return $id;
}

function contact_current(PDO $pdo, int $id): array
{
    $st = $pdo->prepare('SELECT ' . implode(', ', array_map(static fn ($c) => in_array($c, ['email', 'email_alt'], true) ? "$c::text AS $c" : ($c === 'birthday' ? 'birthday::text AS birthday' : $c), CONTACT_COLUMNS)) . ' FROM contacts WHERE id = :id');
    $st->execute(['id' => $id]);
    $r = $st->fetch() ?: [];
    foreach (['account_id', 'owner_member_id', 'source_id'] as $k) { $r[$k] = isset($r[$k]) ? (int) $r[$k] : null; }
    foreach (['is_primary', 'do_not_call'] as $k) { $r[$k] = (bool) ($r[$k] ?? false); }
    $r['custom_values'] = json_decode((string) ($r['custom_values'] ?? '{}'), true) ?: [];
    return $r;
}

/** Make this contact its account's primary (the other one loses it). False when the contact has no account. */
function contact_set_primary(PDO $pdo, int $id): bool
{
    $acc = one_value($pdo, 'SELECT account_id FROM contacts WHERE id = :id', ['id' => $id]);
    if ($acc === null || $acc === false) {
        return false;
    }
    $pdo->prepare('UPDATE contacts SET is_primary = false WHERE account_id = :a AND is_primary AND id <> :id')->execute(['a' => $acc, 'id' => $id]);
    $pdo->prepare('UPDATE contacts SET is_primary = true WHERE id = :id')->execute(['id' => $id]);
    return true;
}

/** Record (or lift, $undo) an opt-out from email or texts; the moment is the trigger's. */
function contact_opt_out(PDO $pdo, int $id, string $channel, ?string $reason, bool $undo): void
{
    $col = $channel === 'sms' ? 'sms_opt_out' : 'email_opt_out';
    $at = $channel === 'sms' ? 'sms_opt_out_at' : 'email_opt_out_at';
    $pdo->prepare("UPDATE contacts SET $col = :v, $at = CASE WHEN :v THEN $at ELSE NULL END, opt_out_reason = CASE WHEN :v THEN COALESCE(:r, opt_out_reason) ELSE opt_out_reason END WHERE id = :id")
        ->execute(['v' => $undo ? 'f' : 't', 'r' => $reason, 'id' => $id]);
}
