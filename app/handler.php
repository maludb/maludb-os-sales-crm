<?php
declare(strict_types=1);

/**
 * The prelude of every write handler (slice 1, the CRUD exemplar): the gates a write starts with, the request readers that
 * keep "a field left out stays as it was", the one guard that turns our own sentence or the database's RAISE into a 422, the
 * field-error refusal, and the landing — a browser lands on the record with a notice, HTMX follows HX-Location, JSON learns
 * `{ok, did, record_id, location}` from emit_action_status(). Required by app/bootstrap.php after http.php.
 */

/** POST + login + CSRF (an action token stands in): an anonymous POST is a 401, never a bare 403. */
function crm_handler_begin(): void
{
    require_post();
    require_login();
    verify_csrf();
}

function req_has(string $name): bool
{
    return array_key_exists($name, $_POST);
}

/** A trimmed request string, or null when the field was left out. */
function req_val(string $name): ?string
{
    if (!array_key_exists($name, $_POST)) {
        return null;
    }
    $v = $_POST[$name];
    return is_array($v) ? null : trim((string) $v);
}

/** A list field: `name[]` (a form) or a comma-separated string (an agent); empty strings dropped; null when left out. */
function request_list(string $name): ?array
{
    if (!array_key_exists($name, $_POST)) {
        return null;
    }
    $v = $_POST[$name];
    $v = is_array($v) ? $v : explode(',', (string) $v);
    return array_values(array_unique(array_filter(array_map(static fn ($x): string => trim((string) $x), $v), static fn (string $x): bool => $x !== '')));
}

/** A yes/no field: 1, true, on, yes = yes; 0, false, off, no, empty = no; anything else refused; $keep when left out. */
function crm_yes(string $name, ?bool $keep = null): bool
{
    if (!req_has($name)) {
        return $keep ?? false;
    }
    $v = strtolower((string) req_val($name));
    if (in_array($v, ['1', 'true', 'on', 'yes'], true)) {
        return true;
    }
    if (in_array($v, ['0', 'false', 'off', 'no', ''], true)) {
        return false;
    }
    refuse(422, 'Say yes or no for ' . str_replace('_', ' ', $name) . '.');
}

/** An integer field within bounds; $keep when left out; null when empty and $nullable. Adds to $errors instead of refusing. */
function crm_int(string $name, ?int $keep, int $min, int $max, string $label, array &$errors, bool $nullable = false): ?int
{
    if (!req_has($name)) {
        return $keep;
    }
    $v = (string) req_val($name);
    if ($v === '') {
        if ($nullable) {
            return null;
        }
        $errors[$name] = $label . ' is required.';
        return $keep;
    }
    if (filter_var($v, FILTER_VALIDATE_INT) === false || (int) $v < $min || (int) $v > $max) {
        $errors[$name] = $label . ' is a whole number from ' . $min . ' to ' . $max . '.';
        return $keep;
    }
    return (int) $v;
}

/** An id field naming a record of a table, or null when empty and $nullable; $keep when left out. */
function crm_ref(PDO $pdo, string $name, ?int $keep, string $sql, string $label, array &$errors, bool $nullable = true): ?int
{
    if (!req_has($name)) {
        return $keep;
    }
    $v = (string) req_val($name);
    if ($v === '' || $v === '0') {
        if ($nullable) {
            return null;
        }
        $errors[$name] = $label . ' is required.';
        return $keep;
    }
    if (filter_var($v, FILTER_VALIDATE_INT) === false) {
        $errors[$name] = 'Choose ' . $label . ' from the list.';
        return $keep;
    }
    $st = $pdo->prepare($sql);
    $st->execute(['id' => (int) $v]);
    if ($st->fetchColumn() === false) {
        $errors[$name] = 'That ' . $label . ' is not here.';
        return $keep;
    }
    return (int) $v;
}

/** Run a step: our own sentence (DomainException) or the database's RAISE is a 422 ('Not found.' a 404); a duplicate a 422. Anything else is a 500. */
function crm_guard(PDO $pdo, callable $step): mixed
{
    try {
        return $step();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        if ($e instanceof DomainException) {
            $m = $e->getMessage();
            if (str_contains($m, '|')) {                      // "sentence|id": a refusal that points at a record (a known address → its owner)
                [$m, $ref] = explode('|', $m, 2);
                emit_action_status(false, ['error' => $m, 'record_id' => (int) $ref]);
            }
            refuse($m === 'Not found.' ? 404 : 422, $m);
        }
        if ($e instanceof PDOException && (string) $e->getCode() === 'P0001') {
            refuse(422, db_message($e, 'That could not be done.'));
        }
        if ($e instanceof PDOException && (string) $e->getCode() === '23505') {
            refuse(422, 'That name is already taken.');
        }
        throw $e;
    }
}

/** A refusal with field errors: JSON gets {errors[], fields{}}; a browser the first sentence (422). $fields: name => sentence. */
function crm_refuse_fields(array $fields): never
{
    $errors = array_values($fields);
    emit_action_status(false, ['errors' => $errors, 'fields' => $fields]);
    if (wants_json()) {
        respond_invalid($errors, $fields);
    }
    refuse(422, (string) ($errors[0] ?? 'That could not be saved.'));
}

/** The path the form asked to land on (`return_to`), when it is local; else the default. */
function return_path(string $default): string
{
    return safe_local_path($_POST['return_to'] ?? null) ?? $default;
}

/** A landing URL with a notice key (and an anchor). A fragment already on the path is kept unless $anchor replaces it. */
function crm_land(string $path, ?string $notice = null, ?string $anchor = null): string
{
    $fragment = null;
    if (($hash = strpos($path, '#')) !== false) {
        $fragment = substr($path, $hash + 1);
        $path = substr($path, 0, $hash);
    }
    if ($notice !== null) {
        $path .= (str_contains($path, '?') ? '&' : '?') . 'notice=' . rawurlencode($notice);
    }
    $anchor ??= $fragment;
    return $anchor === null || $anchor === '' ? $path : $path . '#' . $anchor;
}

/** The notice banner for a key: [kind, sentence] or null. */
function crm_notice(?string $key, array $map): ?array
{
    return $key === null ? null : ($map[$key] ?? null);
}

/** The handler's end: what it did, the record, where to land; JSON gets it all, HTMX follows, a browser is redirected. */
function crm_done(string $did, int|string|null $recordId, string $land, string $event = '', array $extra = []): never
{
    emit_action_status(true, ['did' => $did, 'record_id' => $recordId, 'refresh' => $event] + $extra);
    if (wants_json()) {
        respond_saved(['did' => $did, 'record_id' => $recordId, 'location' => $land, 'refresh' => $event] + $extra);
    }
    saved_go($land, $event);
}

/** The changed keys of two states: ['before' => …, 'after' => …], each holding only what differs. */
function crm_diff(array $before, array $after): array
{
    $b = [];
    $a = [];
    foreach ($after as $k => $v) {
        if (!array_key_exists($k, $before) || $before[$k] !== $v) {
            $b[$k] = $before[$k] ?? null;
            $a[$k] = $v;
        }
    }
    return ['before' => $b, 'after' => $a];
}

/** "{1,2,3}" from a bigint[] column → [1, 2, 3]. */
function pg_int_array(?string $text): array
{
    $text = trim((string) $text, '{}');
    return $text === '' ? [] : array_map('intval', explode(',', $text));
}

/** A PHP list as a PostgreSQL array literal (text or int). */
function pg_array_literal(array $values): string
{
    return '{' . implode(',', array_map(static fn ($v): string => is_int($v) ? (string) $v : '"' . str_replace(['\\', '"'], ['\\\\', '\\"'], (string) $v) . '"', $values)) . '}';
}

/** The members a record can name (a salesperson, a watch's owner, a count's counter): active, admitted, people and agents. [{member_id, display_name, member_kind}] */
function members_for_pick(PDO $pdo): array
{
    return $pdo->query("SELECT m.id AS member_id, m.display_name, m.member_kind FROM members m WHERE m.status = 'active' AND m.capability IS NOT NULL ORDER BY m.member_kind, m.display_name")->fetchAll();
}

/** The live departments of the mirror. [{department_id, name}] */
function find_live_departments(PDO $pdo): array
{
    return $pdo->query('SELECT department_id, name FROM mcp_departments WHERE archived_at IS NULL ORDER BY name')->fetchAll();
}


/** Any one of several rights ("a|b") opens the screen; refused in the first right's sentence. */
function require_any_right(string $spec): void
{
    require_login();
    foreach (explode('|', $spec) as $right) {
        if (has_right($right)) {
            return;
        }
    }
    require_right(explode('|', $spec)[0]);
}
