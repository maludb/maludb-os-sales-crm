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
    if (!($e instanceof PDOException) || (string) $e->getCode() !== 'P0001') {
        return $fallback;
    }
    $text = $e->getMessage();
    $at = strpos($text, 'ERROR:');
    if ($at === false) {
        return $fallback;
    }
    $text = trim((string) (preg_split('/\R|CONTEXT:/', substr($text, $at + 6))[0] ?? ''));
    return $text === '' ? $fallback : $text;
}

/** The first column of the first row, or null. */
function one_value(PDO $pdo, string $sql, array $args = []): mixed
{
    $st = $pdo->prepare($sql);
    $st->execute($args);
    $v = $st->fetchColumn();
    return $v === false ? null : $v;
}
