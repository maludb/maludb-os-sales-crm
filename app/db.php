<?php
declare(strict_types=1);

/** PostgreSQL connection (the sales_crm_rw role). One PDO per request; app.member_id set from the session. */
function db(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }
    $dsn = sprintf('pgsql:host=%s;port=%s;dbname=%s', env('DB_HOST', '127.0.0.1'), env('DB_PORT', '5432'), env('DB_NAME', 'sales_crm'));
    $pdo = new PDO($dsn, env('DB_USER', 'sales_crm_rw'), (string) env('DB_PASSWORD', ''), [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ]);
    db_apply_context($pdo);
    return $pdo;
}

/** Connection-scoped acting member (memory.md §1). Anonymous = '' and every rule denies. */
function db_apply_context(PDO $pdo): void
{
    $memberId = isset($_SESSION['member_id']) ? (string) ((int) $_SESSION['member_id']) : '';
    $stmt = $pdo->prepare('SELECT set_config(?, ?, false)');
    $stmt->execute(['app.member_id', $memberId]);
    $pdo->exec("SET TIME ZONE 'UTC'");
}

/** One boolean from a gate function in SQL — the same rule the views enforce. */
function db_bool(PDO $pdo, string $sql, array $args = []): bool
{
    $st = $pdo->prepare($sql);
    $st->execute($args);
    return (bool) $st->fetchColumn();
}

/** A database error in words a person can act on: only our own RAISE (P0001) is shown. */
function db_message(Throwable $e, string $fallback): string
{
    error_log('db error: ' . $e->getMessage());
    if (!($e instanceof PDOException) || !in_array((string) $e->getCode(), DB_SENTENCE_CODES, true)) {
        return $fallback;
    }
    $text = $e->getMessage();
    $at = strpos($text, 'ERROR:');
    if ($at === false) {
        return $fallback;
    }
    $text = trim((string) (preg_split('/\R|CONTEXT:|DETAIL:|HINT:/', substr($text, $at + 6))[0] ?? ''));
    // PostgreSQL's own constraint text is not a sentence for a person: only a trigger's RAISE is (the schema raises sentences)
    if ($text === '' || preg_match('/violates (check|unique|foreign key|not-null) constraint|duplicate key value|null value in column/i', $text)) {
        return $fallback;
    }
    return $text;
}

/**
 * The codes the schema's triggers and functions raise with a sentence for a person (db/007–013): our own RAISE (P0001), a rule refused
 * (check_violation — 34 of them, a stage's required fields among them), an address another contact holds (unique_violation), a stage
 * that does not exist (foreign_key_violation), a deal or lead that does not (no_data_found). Found in Phase 2 (2026-10-09): the kit read
 * only P0001, so every other refusal would have answered 500 (Inventory found the check_violation half the same day).
 */
const DB_SENTENCE_CODES = ['P0001', '23514', '23505', '23503', 'P0002'];

/** The first column of the first row, or null. */
function one_value(PDO $pdo, string $sql, array $args = []): mixed
{
    $st = $pdo->prepare($sql);
    $st->execute($args);
    $v = $st->fetchColumn();
    return $v === false ? null : $v;
}

/** The first row, or null (slice 3). */
function one_row(PDO $pdo, string $sql, array $args = []): ?array
{
    $st = $pdo->prepare($sql);
    $st->execute($args);
    $r = $st->fetch();
    return $r === false ? null : $r;
}
