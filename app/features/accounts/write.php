<?php
declare(strict_types=1);

/**
 * The writes of accounts. The database is the referee (db/007): the domain derived from the website and unique among live accounts, the kind set
 * by hand only for partner, other and former (customer and prospect derive from the deals), the restriction refused when the setting is off,
 * the custom values typed, the name required. Columns not in ACCOUNT_COLUMNS are never written from a request.
 */
const ACCOUNT_COLUMNS = ['name', 'legal_name', 'domain', 'website', 'industry', 'employees', 'annual_revenue', 'currency', 'phone', 'email', 'billing_address', 'shipping_address',
    'city', 'region', 'country', 'kind', 'parent_account_id', 'owner_member_id', 'source_id', 'description', 'custom_values'];

/** Insert ($id null) or update the columns $f carries. Answers the id. */
function account_save(PDO $pdo, ?int $id, array $f, int $by): int
{
    $f = array_intersect_key($f, array_flip(ACCOUNT_COLUMNS));
    if (array_key_exists('custom_values', $f)) {
        $f['custom_values'] = json_encode($f['custom_values'] === [] ? (object) [] : $f['custom_values'], JSON_THROW_ON_ERROR);
    }
    $cast = static fn (string $c): string => $c === 'custom_values' ? 'CAST(:' . $c . ' AS jsonb)' : ':' . $c;
    if ($id === null) {
        $cols = array_keys($f);
        $st = $pdo->prepare('INSERT INTO accounts (' . implode(', ', $cols) . ', created_by) VALUES (' . implode(', ', array_map($cast, $cols)) . ', :by) RETURNING id');
        $st->execute($f + ['by' => $by]);
        return (int) $st->fetchColumn();
    }
    if ($f !== []) {
        $set = implode(', ', array_map(static fn (string $c): string => $c . ' = ' . $cast($c), array_keys($f)));
        $pdo->prepare("UPDATE accounts SET $set WHERE id = :id")->execute($f + ['id' => $id]);
    }
    return $id;
}

/** The stored row's writable columns, for "a field left out stays as it was" and the before/after diff. */
function account_current(PDO $pdo, int $id): array
{
    $st = $pdo->prepare('SELECT ' . implode(', ', array_map(static fn ($c) => $c === 'domain' || $c === 'email' ? "$c::text AS $c" : $c, ACCOUNT_COLUMNS)) . ' FROM accounts WHERE id = :id');
    $st->execute(['id' => $id]);
    $r = $st->fetch() ?: [];
    foreach (['parent_account_id', 'owner_member_id', 'source_id'] as $k) { $r[$k] = isset($r[$k]) ? (int) $r[$k] : null; }
    $r['annual_revenue'] = isset($r['annual_revenue']) ? (string) $r['annual_revenue'] : null;
    $r['custom_values'] = json_decode((string) ($r['custom_values'] ?? '{}'), true) ?: [];
    return $r;
}
