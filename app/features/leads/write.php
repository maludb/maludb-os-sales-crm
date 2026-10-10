<?php
declare(strict_types=1);

/**
 * The writes of leads. The database is the referee (db/009): the names trimmed, the address checked, the owner from the rules on insert when none
 * is given (crm_assign_lead), a converted lead read-only, an unqualified lead needing a reason, the conversion's finding and making in one transaction.
 */
const LEAD_COLUMNS = ['first_name', 'last_name', 'company', 'title', 'email', 'phone', 'website', 'address', 'city', 'region', 'country', 'message', 'source_id', 'campaign_id',
    'owner_member_id', 'custom_values', 'utm'];

function lead_save(PDO $pdo, ?int $id, array $f, int $by): int
{
    $f = array_intersect_key($f, array_flip(LEAD_COLUMNS));
    foreach (['custom_values', 'utm'] as $j) {
        if (array_key_exists($j, $f)) { $f[$j] = json_encode($f[$j] === [] ? (object) [] : $f[$j], JSON_THROW_ON_ERROR); }
    }
    $cast = static fn (string $c): string => in_array($c, ['custom_values', 'utm'], true) ? 'CAST(:' . $c . ' AS jsonb)' : ':' . $c;
    if ($id === null) {
        $cols = array_keys($f);
        $st = $pdo->prepare('INSERT INTO leads (' . implode(', ', $cols) . ', created_by) VALUES (' . implode(', ', array_map($cast, $cols)) . ', :by) RETURNING id');
        $st->execute($f + ['by' => $by]);
        return (int) $st->fetchColumn();
    }
    if ($f !== []) {
        $set = implode(', ', array_map(static fn (string $c): string => $c . ' = ' . $cast($c), array_keys($f)));
        $pdo->prepare("UPDATE leads SET $set WHERE id = :id")->execute($f + ['id' => $id]);
    }
    return $id;
}

function lead_current(PDO $pdo, int $id): array
{
    $st = $pdo->prepare('SELECT ' . implode(', ', array_map(static fn ($c) => $c === 'email' ? 'email::text AS email' : $c, LEAD_COLUMNS)) . ', status, converted_at, is_proposed, score FROM leads WHERE id = :id');
    $st->execute(['id' => $id]);
    $r = $st->fetch() ?: [];
    foreach (['source_id', 'campaign_id', 'owner_member_id'] as $k) { $r[$k] = isset($r[$k]) ? (int) $r[$k] : null; }
    $r['custom_values'] = json_decode((string) ($r['custom_values'] ?? '{}'), true) ?: [];
    $r['utm'] = json_decode((string) ($r['utm'] ?? '{}'), true) ?: [];
    return $r;
}

/** A score (0–100, null keeps it) and a status (new or working). */
function lead_qualify(PDO $pdo, int $id, ?int $score, string $status): void
{
    $pdo->prepare('UPDATE leads SET score = COALESCE(CAST(:s AS smallint), score), status = :st WHERE id = :id')->execute(['s' => $score, 'st' => $status, 'id' => $id]);
}

function lead_unqualify(PDO $pdo, int $id, int $reason, ?string $note): void
{
    $pdo->prepare("UPDATE leads SET status = 'unqualified', unqualified_reason_id = :r, unqualified_note = :n WHERE id = :id")->execute(['r' => $reason, 'n' => $note, 'id' => $id]);
}

function lead_requalify(PDO $pdo, int $id): void
{
    $pdo->prepare("UPDATE leads SET status = 'working', unqualified_reason_id = NULL, unqualified_note = NULL WHERE id = :id")->execute(['id' => $id]);
}

/**
 * crm_lead_convert(lead, account?, contact?, make_deal, pipeline?, title?, value?, close?) — the finding and the making in one transaction. $choices: account, contact
 * (ids or null = find), make_deal (bool), pipeline, deal_title, value, close_date. Answers [account_id, contact_id, deal_id, account_made, contact_made].
 */
function lead_convert(PDO $pdo, int $id, array $choices, int $by): array
{
    $st = $pdo->prepare('SELECT account_id, contact_id, deal_id, account_made, contact_made FROM crm_lead_convert(:l, :a, :c, CAST(:d AS boolean), :p, :t, CAST(:v AS numeric), CAST(:cl AS date))');
    $st->execute(['l' => $id, 'a' => $choices['account'] ?? null, 'c' => $choices['contact'] ?? null, 'd' => ($choices['make_deal'] ?? true) ? 't' : 'f', 'p' => $choices['pipeline'] ?? null,
                  't' => $choices['deal_title'] ?? null, 'v' => $choices['value'] ?? null, 'cl' => $choices['close_date'] ?? null]);
    $r = $st->fetch();
    return ['account_id' => $r['account_id'] !== null ? (int) $r['account_id'] : null, 'contact_id' => $r['contact_id'] !== null ? (int) $r['contact_id'] : null,
            'deal_id' => $r['deal_id'] !== null ? (int) $r['deal_id'] : null, 'account_made' => (bool) $r['account_made'], 'contact_made' => (bool) $r['contact_made']];
}

/** Give leads an owner (null = back to the inbox's unowned list). Answers the ids whose owner changed. */
function lead_assign(PDO $pdo, array $ids, ?int $owner): array
{
    $changed = [];
    $st = $pdo->prepare('UPDATE leads SET owner_member_id = :o WHERE id = :id AND owner_member_id IS DISTINCT FROM :o RETURNING id');
    foreach ($ids as $id) {
        $st->execute(['o' => $owner, 'id' => $id]);
        if ($st->fetchColumn() !== false) { $changed[] = $id; }
    }
    return $changed;
}

/** A proposed lead into the book: no longer proposed; the owner given, else the rules' (crm_assign_lead), else unowned. Answers the owner. */
function lead_accept(PDO $pdo, int $id, ?int $owner): ?int
{
    $st = $pdo->prepare('UPDATE leads SET is_proposed = false, owner_member_id = COALESCE(CAST(:o AS bigint), crm_assign_lead(leads)) WHERE id = :id RETURNING owner_member_id');
    $st->execute(['o' => $owner, 'id' => $id]);
    $v = $st->fetchColumn();
    return $v === null || $v === false ? null : (int) $v;
}

/** A proposed lead to the tombstone. */
function lead_dismiss(PDO $pdo, int $id): void
{
    $pdo->prepare("SELECT crm_record_delete('lead', :id, false)")->execute(['id' => $id]);
}
