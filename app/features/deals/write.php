<?php
declare(strict_types=1);

/**
 * A deal's writes. The database is the referee (db/008): the number, the currency, the probability and weighted value, the status from the stage's
 * kind, THE REQUIRED-FIELDS RULE on entering a stage (refused with the fields in the HINT), the value from the lines, the history row, the account's
 * counters and kind, the contact's lifecycle. PHP sets what the request says and logs it; deal_guard() turns the stage's refusal into field errors.
 */
require_once __DIR__ . '/queries.php';
require_once __DIR__ . '/present.php';
require_once __DIR__ . '/../custom_fields/queries.php';

const DEAL_COLUMNS = ['title', 'account_id', 'primary_contact_id', 'pipeline_id', 'stage_id', 'value', 'currency', 'value_from_products', 'probability_override', 'expected_close_date',
    'owner_member_id', 'source_id', 'campaign_id', 'description', 'custom_values'];

/** Insert ($id null) or update the columns $f carries. Answers the id. */
function deal_save(PDO $pdo, ?int $id, array $f, int $by): int
{
    $f = array_intersect_key($f, array_flip(DEAL_COLUMNS));
    if (array_key_exists('custom_values', $f)) {
        $f['custom_values'] = json_encode($f['custom_values'] === [] ? (object) [] : $f['custom_values'], JSON_THROW_ON_ERROR);
    }
    if (array_key_exists('value_from_products', $f)) {
        $f['value_from_products'] = $f['value_from_products'] ? 'true' : 'false';
    }
    $cast = static fn (string $c): string => match ($c) { 'custom_values' => 'CAST(:custom_values AS jsonb)', 'value_from_products' => 'CAST(:value_from_products AS boolean)', 'value' => 'CAST(:value AS numeric)', default => ':' . $c };
    if ($id === null) {
        $cols = array_keys($f);
        $st = $pdo->prepare('INSERT INTO deals (' . implode(', ', $cols) . ', created_by) VALUES (' . implode(', ', array_map($cast, $cols)) . ', :by) RETURNING id');
        $st->execute($f + ['by' => $by]);
        return (int) $st->fetchColumn();
    }
    if ($f !== []) {
        $set = implode(', ', array_map(static fn (string $c): string => $c . ' = ' . $cast($c), array_keys($f)));
        $pdo->prepare("UPDATE deals SET $set WHERE id = :id")->execute($f + ['id' => $id]);
    }
    return $id;
}

/** The stored row's writable columns (for "a field left out stays as it was" and the diff). */
function deal_current(PDO $pdo, int $id): array
{
    $st = $pdo->prepare('SELECT ' . implode(', ', DEAL_COLUMNS) . ', status, number FROM deals WHERE id = :id');
    $st->execute(['id' => $id]);
    $r = $st->fetch() ?: [];
    foreach (['account_id', 'primary_contact_id', 'pipeline_id', 'stage_id', 'owner_member_id', 'source_id', 'campaign_id', 'probability_override'] as $k) { $r[$k] = isset($r[$k]) ? (int) $r[$k] : null; }
    $r['value'] = isset($r['value']) ? (string) $r['value'] : '0';
    $r['value_from_products'] = (bool) ($r['value_from_products'] ?? false);
    $r['custom_values'] = json_decode((string) ($r['custom_values'] ?? '{}'), true) ?: [];
    return $r;
}

/**
 * The stage's refusal in fields (pipeline-board.md §3): the trigger RAISEs "The stage X needs: a, b" with HINT "a,b". Answers [input name => sentence]
 * and the sentence "Proposal needs: primary contact, expected close date" — or null when the error is not that refusal.
 */
function deal_stage_refusal(PDO $pdo, Throwable $e): ?array
{
    if (!$e instanceof PDOException || (string) $e->getCode() !== '23514') {
        return null;
    }
    $text = $e->getMessage();
    if (!preg_match('/The stage (.+?) needs: /', $text, $m) || !preg_match('/HINT:\s+([a-z0-9_,]+)/', $text, $h)) {
        return null;
    }
    $words = [];
    $fields = [];
    foreach (explode(',', $h[1]) as $f) {
        $w = stage_field_words($pdo, $f);
        $words[] = $w;
        $fields[stage_field_input($f)] = ucfirst($w) . ' is required by the stage ' . $m[1] . '.';
    }
    return ['sentence' => $m[1] . ' needs: ' . implode(', ', $words) . '.', 'fields' => $fields, 'stage' => $m[1], 'missing' => explode(',', $h[1])];
}

/** crm_guard() with the stage's refusal answered as field errors (422 {errors, fields}) — the drag snaps back, the form shows the fields. */
function deal_guard(PDO $pdo, callable $step): mixed
{
    return crm_guard($pdo, static function () use ($pdo, $step) {
        try {
            return $step();
        } catch (PDOException $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();                      // before the refusal asks anything: a custom field's label is a query, and an aborted transaction answers none (slice 9 found it, with a custom key required by a stage)
            }
            $r = deal_stage_refusal($pdo, $e);
            if ($r === null) {
                throw $e;
            }
            emit_action_status(false, ['errors' => [$r['sentence']], 'fields' => $r['fields'], 'missing' => $r['missing']]);
            if (wants_json()) {
                respond_invalid([$r['sentence']], $r['fields']);
            }
            refuse(422, $r['sentence']);
        }
    });
}

/**
 * The board's move (D7): crm_deal_move — the new stage and the position, the others in the column shifted; the trigger enforces the fields. Answers
 * the log's payload {from_stage, to_stage, stage_name, seconds_in_stage, position}.
 */
function deal_move(PDO $pdo, int $id, int $stage, ?int $position): array
{
    $before = one_row($pdo, 'SELECT stage_id, stage_entered_at FROM deals WHERE id = :id', ['id' => $id]);
    $pdo->prepare('SELECT crm_deal_move(:d, :s, :p)')->execute(['d' => $id, 's' => $stage, 'p' => $position]);
    $h = one_row($pdo, 'SELECT seconds_in_from_stage FROM deal_stage_history WHERE deal_id = :id ORDER BY at DESC, id DESC LIMIT 1', ['id' => $id]);
    $moved = (int) $before['stage_id'] !== $stage;
    return ['from_stage' => (int) $before['stage_id'], 'to_stage' => $stage, 'stage_name' => (string) one_value($pdo, 'SELECT name FROM stages WHERE id = :s', ['s' => $stage]),
        'seconds_in_stage' => $moved ? (int) ($h['seconds_in_from_stage'] ?? 0) : null, 'position' => $position ?? 0];
}

/**
 * Close a deal (divergence 1): the form's fields set first — won: the value (unless it follows the lines) and the close date; lost: the reason and the
 * note — then crm_deal_move to the pipeline's Won or Lost stage, one database rule. A won deal closed on an earlier day keeps that day as won_at.
 */
function deal_close(PDO $pdo, int $id, string $as, array $f): array
{
    $d = deal_current($pdo, $id);
    $stage = pipeline_closed_stage($pdo, (int) $d['pipeline_id'], $as);
    if ($stage === null) {
        throw new DomainException('The pipeline has no ' . ucfirst($as) . ' stage.');
    }
    if ($as === 'won') {
        $set = ['expected_close_date' => $f['close_date']];
        if (!$d['value_from_products'] && isset($f['value'])) { $set['value'] = $f['value']; }
        deal_save($pdo, $id, $set, (int) current_member_id());
    } else {
        $pdo->prepare('UPDATE deals SET lost_reason_id = :r, lost_note = :n WHERE id = :id')->execute(['r' => $f['reason'], 'n' => $f['note'], 'id' => $id]);
    }
    deal_move($pdo, $id, $stage, 0);
    if ($as === 'won' && ($f['close_date'] ?? null) !== null && $f['close_date'] < (string) one_value($pdo, 'SELECT current_date::text')) {
        $pdo->prepare("UPDATE deals SET won_at = CAST(:d AS date) + time '12:00' WHERE id = :id")->execute(['d' => $f['close_date'], 'id' => $id]);
    }
    $r = one_row($pdo, 'SELECT value, currency, expected_close_date, status FROM deals WHERE id = :id', ['id' => $id]);
    return ['value' => (float) $r['value'], 'currency' => $r['currency'], 'close_date' => $r['expected_close_date']]
        + ($as === 'lost' ? ['reason' => (string) one_value($pdo, 'SELECT label FROM lost_reasons WHERE id = :r', ['r' => $f['reason']])] : []);
}

/** The stage a reopened deal returns to: the open stage it left for Won or Lost; that one archived or unknown → the pipeline's last open stage. */
function deal_reopen_stage(PDO $pdo, int $id): ?int
{
    $v = one_value($pdo, "SELECT h.from_stage_id FROM deal_stage_history h JOIN deals d ON d.id = h.deal_id JOIN stages s ON s.id = h.from_stage_id
                            WHERE h.deal_id = :id AND h.to_stage_id = d.stage_id AND s.kind = 'open' AND s.archived_at IS NULL ORDER BY h.at DESC, h.id DESC LIMIT 1", ['id' => $id]);
    $v ??= one_value($pdo, "SELECT s.id FROM stages s JOIN deals d ON d.pipeline_id = s.pipeline_id WHERE d.id = :id AND s.kind = 'open' AND s.archived_at IS NULL ORDER BY s.position DESC LIMIT 1", ['id' => $id]);
    return $v === null ? null : (int) $v;
}

/** Reopen a won or lost deal (a manager's): back to its last open stage; the trigger clears the close stamps and the reason. */
function deal_reopen(PDO $pdo, int $id): array
{
    $stage = deal_reopen_stage($pdo, $id) ?? throw new DomainException('The pipeline has no open stage to reopen the deal into.');
    return deal_move($pdo, $id, $stage, 0);
}

/** Give deals to an owner. Answers [id => previous owner]. */
function deal_assign(PDO $pdo, array $ids, ?int $owner): array
{
    $prev = [];
    $st = $pdo->prepare('UPDATE deals d SET owner_member_id = :o FROM (SELECT id, owner_member_id AS before FROM deals WHERE id = :id) b WHERE d.id = b.id RETURNING b.before');
    foreach ($ids as $id) {
        $st->execute(['o' => $owner, 'id' => $id]);
        $b = $st->fetchColumn();
        $prev[$id] = $b === null || $b === false ? null : (int) $b;
    }
    return $prev;
}

const DEAL_PRODUCT_COLUMNS = ['name', 'provider_app', 'provider_id', 'sku', 'quantity', 'unit_price', 'discount_percent', 'recurring', 'position'];

/** Add ($line null) or change a product line. The deal's value follows the lines when value_from_products (db/008's trigger). Answers the line's id. */
function deal_product_save(PDO $pdo, int $deal, ?int $line, array $f): int
{
    $f = array_intersect_key($f, array_flip(DEAL_PRODUCT_COLUMNS));
    if ($line === null) {
        $f['position'] ??= (int) one_value($pdo, 'SELECT COALESCE(max(position), -1) + 1 FROM deal_products WHERE deal_id = :d', ['d' => $deal]);
        $cols = array_keys($f);
        $st = $pdo->prepare('INSERT INTO deal_products (deal_id, ' . implode(', ', $cols) . ') VALUES (:deal_id, ' . implode(', ', array_map(static fn ($c) => ':' . $c, $cols)) . ') RETURNING id');
        $st->execute($f + ['deal_id' => $deal]);
        return (int) $st->fetchColumn();
    }
    if ($f !== []) {
        $set = implode(', ', array_map(static fn (string $c): string => "$c = :$c", array_keys($f)));
        $pdo->prepare("UPDATE deal_products SET $set WHERE id = :id AND deal_id = :deal")->execute($f + ['id' => $line, 'deal' => $deal]);
    }
    return $line;
}

/** A line's stored columns. */
function deal_product_current(PDO $pdo, int $line): array
{
    $r = one_row($pdo, 'SELECT deal_id, ' . implode(', ', DEAL_PRODUCT_COLUMNS) . ', line_total FROM deal_products WHERE id = :id', ['id' => $line]) ?? [];
    foreach (['quantity', 'unit_price', 'discount_percent', 'line_total'] as $k) { if (isset($r[$k])) { $r[$k] = (string) $r[$k]; } }
    if (isset($r['position'])) { $r['position'] = (int) $r['position']; }
    return $r;
}

function deal_product_remove(PDO $pdo, int $line): void
{
    $pdo->prepare('DELETE FROM deal_products WHERE id = :id')->execute(['id' => $line]);
}

/**
 * A person on the deal with a role; $primary makes them the deal's primary contact (the deal's column — db/008's trigger keeps deal_contacts in step).
 * Answers 'added' or 'updated'.
 */
function deal_contact_set(PDO $pdo, int $deal, int $contact, string $role, bool $primary): string
{
    $had = (bool) one_value($pdo, 'SELECT EXISTS (SELECT 1 FROM deal_contacts WHERE deal_id = :d AND contact_id = :c)', ['d' => $deal, 'c' => $contact]);
    $pdo->prepare('INSERT INTO deal_contacts (deal_id, contact_id, role) VALUES (:d, :c, :r) ON CONFLICT (deal_id, contact_id) DO UPDATE SET role = EXCLUDED.role')
        ->execute(['d' => $deal, 'c' => $contact, 'r' => $role]);
    if ($primary) {
        $pdo->prepare('UPDATE deals SET primary_contact_id = :c WHERE id = :d AND primary_contact_id IS DISTINCT FROM :c')->execute(['d' => $deal, 'c' => $contact]);
    }
    $pdo->prepare('SELECT crm_contact_lifecycle(:c)')->execute(['c' => $contact]);
    return $had ? 'updated' : 'added';
}

/** Take a person off the deal (never the primary — the handler refuses that first). */
function deal_contact_remove(PDO $pdo, int $deal, int $contact): void
{
    $pdo->prepare('DELETE FROM deal_contacts WHERE deal_id = :d AND contact_id = :c AND NOT is_primary')->execute(['d' => $deal, 'c' => $contact]);
    $pdo->prepare('SELECT crm_contact_lifecycle(:c)')->execute(['c' => $contact]);
}
