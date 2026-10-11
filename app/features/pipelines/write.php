<?php
declare(strict_types=1);

/**
 * The pipelines and stages the admin keeps (actions pipeline_save, pipeline_delete, stage_save, stage_reorder, stage_delete; admin-settings.md). The database holds the hard rules (one Won and one Lost stage a
 * pipeline, Won at 100 and Lost at 0, one default); these functions hold the rest, in words:
 *   - a pipeline or a stage that deals sit in is ARCHIVED, never deleted; one with OPEN deals is not archived either (the board is the only place a deal moves from — archiving would hide them);
 *   - the default pipeline, the last live pipeline, a pipeline's Won and Lost stages, and its last open stage cannot be archived or deleted;
 *   - a stage with deals keeps its kind; a changed probability is applied to the open deals in it at once (they keep their own override);
 *   - a new pipeline is made with its Won and Lost stages, so a deal can close in it from the first day; the admin adds the open stages.
 * Each function answers ['id' => …, 'before' => …, 'after' => …] (the changed keys) for the log, and throws a DomainException with the sentence on a refusal.
 */
require_once dirname(__DIR__) . '/admin/queries.php';

/** A key from a name: lower-case letters, digits and underscores, starting with a letter, up to 40. */
function pipeline_key_from(string $name): string
{
    $s = strtolower(trim((string) preg_replace('/[^a-z0-9]+/i', '_', iconv('UTF-8', 'ASCII//TRANSLIT', $name) ?: $name), '_'));
    $s = preg_match('/^[a-z]/', $s) ? $s : 'p_' . $s;
    return substr($s !== '' && $s !== 'p_' ? $s : 'pipeline', 0, 40);
}

/** Save a pipeline ($id null = a new one). $f: name, key, currency (or null), rotting_days (or null), is_default, position, archived. */
function pipeline_save(PDO $pdo, ?int $id, array $f): array
{
    $cur = $id === null ? null : pipeline_row($pdo, $id);
    if ($id !== null && $cur === null) {
        throw new DomainException('Not found.');
    }
    $key = (string) $f['key'];
    if ((int) one_value($pdo, 'SELECT count(*) FROM pipelines WHERE key = :k AND id <> :id', ['k' => $key, 'id' => $id ?? 0]) > 0) {
        throw new DomainException('Another pipeline already has the key ' . $key . '.');
    }
    $isArchived = $cur !== null && $cur['archived_at'] !== null;
    $archive = (bool) ($f['archived'] ?? $isArchived);
    $default = (bool) ($f['is_default'] ?? ($cur['is_default'] ?? false));
    if ($cur !== null && $cur['is_default'] && !$default) {
        throw new DomainException('This is the default pipeline — make another the default instead.');
    }
    if ($archive && !$isArchived) {
        if ($cur !== null && $cur['is_default']) { throw new DomainException('The default pipeline cannot be archived — make another the default first.'); }
        if ($cur !== null && $cur['deals_open'] > 0) { throw new DomainException($cur['name'] . ' holds ' . $cur['deals_open'] . ' open deal' . ($cur['deals_open'] === 1 ? '' : 's') . ' — move or close them before archiving it.'); }
        if ((int) one_value($pdo, 'SELECT count(*) FROM pipelines WHERE archived_at IS NULL AND id <> :id', ['id' => $id ?? 0]) === 0) { throw new DomainException('The last pipeline cannot be archived.'); }
    }
    if ($archive && $default) {
        throw new DomainException('An archived pipeline cannot be the default.');
    }
    $before = [];
    $after = [];
    if ($id === null) {
        $position = $f['position'] ?? ((int) one_value($pdo, 'SELECT COALESCE(max(position), 0) FROM pipelines') + 10);
        $st = $pdo->prepare('INSERT INTO pipelines (key, name, currency, rotting_days, position, is_default) VALUES (:k, :n, :c, :r, :p, false) RETURNING id');
        $st->execute(['k' => $key, 'n' => $f['name'], 'c' => $f['currency'], 'r' => $f['rotting_days'], 'p' => $position]);
        $id = (int) $st->fetchColumn();
        $pdo->prepare("INSERT INTO stages (pipeline_id, name, position, probability, kind, colour) VALUES (:p, 'Won', 90, 100, 'won', '#198754'), (:p, 'Lost', 99, 0, 'lost', '#dc3545')")->execute(['p' => $id]);
        $after = ['name' => $f['name'], 'key' => $key, 'currency' => $f['currency'], 'rotting_days' => $f['rotting_days'], 'position' => $position];
    } else {
        $new = ['name' => $f['name'], 'key' => $key, 'currency' => $f['currency'], 'rotting_days' => $f['rotting_days'], 'position' => $f['position'] ?? $cur['position']];
        $old = ['name' => $cur['name'], 'key' => $cur['key'], 'currency' => $cur['currency'], 'rotting_days' => $cur['rotting_days'], 'position' => $cur['position']];
        $d = crm_diff($old, $new);
        if ($d['after'] !== []) {
            $pdo->prepare('UPDATE pipelines SET name = :n, key = :k, currency = :c, rotting_days = :r, position = :p WHERE id = :id')
                ->execute(['n' => $new['name'], 'k' => $new['key'], 'c' => $new['currency'], 'r' => $new['rotting_days'], 'p' => $new['position'], 'id' => $id]);
        }
        $before = $d['before'];
        $after = $d['after'];
    }
    if ($archive !== $isArchived) {
        $pdo->prepare('UPDATE pipelines SET archived_at = CASE WHEN CAST(:a AS boolean) THEN now() ELSE NULL END WHERE id = :id')->execute(['a' => $archive ? 't' : 'f', 'id' => $id]);
        $before['archived'] = $isArchived;
        $after['archived'] = $archive;
    }
    $wasDefault = $cur !== null && $cur['is_default'];
    if ($default && !$wasDefault) {
        $pdo->prepare('UPDATE pipelines SET is_default = false WHERE is_default AND id <> :id')->execute(['id' => $id]);
        $pdo->prepare('UPDATE pipelines SET is_default = true WHERE id = :id')->execute(['id' => $id]);
        $pdo->prepare('UPDATE crm_settings SET default_pipeline_id = :id WHERE id = 1')->execute(['id' => $id]);
        $before['is_default'] = false;
        $after['is_default'] = true;
    }
    return ['id' => $id, 'before' => $before, 'after' => $after];
}

/** Delete a pipeline that no deal has ever sat in — else archive it. */
function pipeline_delete(PDO $pdo, int $id): array
{
    $p = pipeline_row($pdo, $id);
    if ($p === null) {
        throw new DomainException('Not found.');
    }
    if ($p['deals_all'] > 0) {
        throw new DomainException($p['name'] . ' has ' . $p['deals_all'] . ' deal' . ($p['deals_all'] === 1 ? '' : 's') . ' — archive it instead of deleting it.');
    }
    if ($p['is_default']) { throw new DomainException('The default pipeline cannot be deleted — make another the default first.'); }
    if ((int) one_value($pdo, 'SELECT count(*) FROM pipelines WHERE archived_at IS NULL AND id <> :id', ['id' => $id]) === 0 && $p['archived_at'] === null) { throw new DomainException('The last pipeline cannot be deleted.'); }
    $pdo->prepare('DELETE FROM pipelines WHERE id = :id')->execute(['id' => $id]);
    return ['id' => $id, 'before' => ['name' => $p['name'], 'key' => $p['key'], 'stages' => count($p['stages'])], 'after' => []];
}

/** Whether the requested required_fields are all choices (the deal's columns and its live custom fields). Answers the unknown ones. */
function stage_unknown_requirements(PDO $pdo, array $fields): array
{
    return array_values(array_diff($fields, array_keys(stage_required_choices($pdo))));
}

/**
 * Save a stage ($id null = a new one in $pipeline). $f: name, probability, kind, rotting_days, required_fields[], ask_assistant, colour, wip_limit, position, archived — each already validated for its shape by the handler;
 * the rules between them are here.
 */
function stage_save(PDO $pdo, ?int $id, int $pipeline, array $f): array
{
    $cur = $id === null ? null : stage_one($pdo, $id);
    if ($id !== null && $cur === null) {
        throw new DomainException('Not found.');
    }
    $pipeline = $cur === null ? $pipeline : $cur['pipeline_id'];
    $p = pipeline_row($pdo, $pipeline);
    if ($p === null) {
        throw new DomainException('Not found.');
    }
    if ($cur === null && $p['archived_at'] !== null) {
        throw new DomainException('Restore ' . $p['name'] . ' before adding a stage to it.');
    }
    $kind = (string) ($f['kind'] ?? ($cur['kind'] ?? 'open'));
    if ($cur !== null && $kind !== $cur['kind'] && $cur['deals_all'] > 0) {
        throw new DomainException('Deals sit in ' . $cur['name'] . ', so it stays ' . strtolower(STAGE_KINDS[$cur['kind']]) . '.');
    }
    if ($kind !== 'open' && (int) one_value($pdo, 'SELECT count(*) FROM stages WHERE pipeline_id = :p AND kind = :k AND id <> :id', ['p' => $pipeline, 'k' => $kind, 'id' => $id ?? 0]) > 0) {
        throw new DomainException($p['name'] . ' has its ' . $kind . ' stage already.');
    }
    $name = (string) $f['name'];
    if ((int) one_value($pdo, 'SELECT count(*) FROM stages WHERE pipeline_id = :p AND lower(name) = lower(:n) AND id <> :id', ['p' => $pipeline, 'n' => $name, 'id' => $id ?? 0]) > 0) {
        throw new DomainException('Another stage of ' . $p['name'] . ' is called ' . $name . '.');
    }
    $probability = $f['probability'] ?? ($kind === 'won' ? 100 : ($kind === 'lost' ? 0 : ($cur['probability'] ?? 10)));
    if (($kind === 'won' && $probability !== 100) || ($kind === 'lost' && $probability !== 0)) {
        throw new DomainException(($kind === 'won' ? 'A Won stage is 100' : 'A Lost stage is 0') . ' percent likely.');
    }
    $required = array_values(array_unique($f['required_fields'] ?? ($cur['required_fields'] ?? [])));
    if ($kind !== 'open' && $required !== []) {
        throw new DomainException('A ' . $kind . ' stage asks for nothing — the required fields are for open stages.');
    }
    if (($unknown = stage_unknown_requirements($pdo, $required)) !== []) {
        throw new DomainException('Not a field a deal has: ' . implode(', ', $unknown) . '.');
    }
    $ask = (bool) ($f['ask_assistant'] ?? ($cur['ask_assistant'] ?? false));
    if ($ask && $kind !== 'open') {
        throw new DomainException('The Assistant is asked when a deal enters an open stage.');
    }
    $position = $f['position'] ?? $cur['position'] ?? match ($kind) {
        'won' => 90, 'lost' => 99,
        default => (int) one_value($pdo, "SELECT COALESCE(max(position), 0) + 10 FROM stages WHERE pipeline_id = :p AND kind = 'open' AND archived_at IS NULL", ['p' => $pipeline]),
    };
    $isArchived = $cur !== null && $cur['archived_at'] !== null;
    $archive = (bool) ($f['archived'] ?? $isArchived);
    if ($archive && !$isArchived) {
        if ($kind !== 'open') { throw new DomainException('A pipeline needs its ' . $kind . ' stage — it cannot be archived.'); }
        if ($cur['deals_open'] > 0) { throw new DomainException($cur['name'] . ' holds ' . $cur['deals_open'] . ' open deal' . ($cur['deals_open'] === 1 ? '' : 's') . ' — move them before archiving it.'); }
        if ((int) one_value($pdo, "SELECT count(*) FROM stages WHERE pipeline_id = :p AND kind = 'open' AND archived_at IS NULL AND id <> :id", ['p' => $pipeline, 'id' => $id]) === 0) {
            throw new DomainException('A pipeline needs at least one open stage — add another before archiving this one.');
        }
    }
    $new = ['name' => $name, 'kind' => $kind, 'probability' => $probability, 'rotting_days' => $f['rotting_days'] ?? null, 'required_fields' => $required, 'ask_assistant' => $ask,
        'colour' => $f['colour'] ?? null, 'wip_limit' => $f['wip_limit'] ?? null, 'position' => $position];
    $args = ['n' => $name, 'k' => $kind, 'pr' => $probability, 'r' => $new['rotting_days'], 'req' => pg_array_literal($required), 'ask' => $ask ? 't' : 'f', 'c' => $new['colour'], 'w' => $new['wip_limit'], 'pos' => $position];
    if ($cur === null) {
        $st = $pdo->prepare('INSERT INTO stages (pipeline_id, name, kind, probability, rotting_days, required_fields, ask_assistant, colour, wip_limit, position)
                             VALUES (:p, :n, :k, :pr, :r, CAST(:req AS text[]), CAST(:ask AS boolean), :c, :w, :pos) RETURNING id');
        $st->execute($args + ['p' => $pipeline]);
        $id = (int) $st->fetchColumn();
        return ['id' => $id, 'before' => [], 'after' => $new + ['pipeline_id' => $pipeline]];
    }
    $old = ['name' => $cur['name'], 'kind' => $cur['kind'], 'probability' => $cur['probability'], 'rotting_days' => $cur['rotting_days'], 'required_fields' => $cur['required_fields'],
        'ask_assistant' => $cur['ask_assistant'], 'colour' => $cur['colour'], 'wip_limit' => $cur['wip_limit'], 'position' => $cur['position']];
    $d = crm_diff($old, $new);
    if ($d['after'] !== []) {
        $pdo->prepare('UPDATE stages SET name = :n, kind = :k, probability = :pr, rotting_days = :r, required_fields = CAST(:req AS text[]), ask_assistant = CAST(:ask AS boolean), colour = :c, wip_limit = :w, position = :pos WHERE id = :id')
            ->execute($args + ['id' => $id]);
    }
    if ($archive !== $isArchived) {
        $pdo->prepare('UPDATE stages SET archived_at = CASE WHEN CAST(:a AS boolean) THEN now() ELSE NULL END WHERE id = :id')->execute(['a' => $archive ? 't' : 'f', 'id' => $id]);
        $d['before']['archived'] = $isArchived;
        $d['after']['archived'] = $archive;
    }
    if (array_key_exists('probability', $d['after'])) {                // the open deals in the stage follow it (the deals' own trigger recomputes probability and weighted value; an override stays)
        $pdo->prepare("UPDATE deals SET value = value WHERE stage_id = :id AND status = 'open' AND deleted_at IS NULL")->execute(['id' => $id]);
    }
    return ['id' => $id, 'before' => $d['before'], 'after' => $d['after']];
}

/** Put the OPEN, live stages of a pipeline in the order given (every one of them, once): positions 10, 20, 30 …. Won and Lost keep theirs. */
function stage_reorder(PDO $pdo, int $pipeline, array $stageIds): array
{
    $p = pipeline_row($pdo, $pipeline);
    if ($p === null) {
        throw new DomainException('Not found.');
    }
    $open = array_values(array_map(static fn (array $s): int => $s['id'], array_filter($p['stages'], static fn (array $s): bool => $s['kind'] === 'open' && $s['archived_at'] === null)));
    $want = array_map('intval', $stageIds);
    $a = $open;
    $b = $want;
    sort($a);
    sort($b);
    if ($a !== $b) {
        throw new DomainException('Name every open stage of ' . $p['name'] . ' once, in the order you want.');
    }
    $st = $pdo->prepare('UPDATE stages SET position = :pos WHERE id = :id');
    foreach ($want as $i => $sid) {
        $st->execute(['pos' => ($i + 1) * 10, 'id' => $sid]);
    }
    return ['id' => $pipeline, 'before' => ['order' => $open], 'after' => ['order' => $want]];
}

/** Delete a stage that no deal sits in and none ever passed through (the funnel reads the history) — else archive it. */
function stage_delete(PDO $pdo, int $id): array
{
    $s = stage_one($pdo, $id);
    if ($s === null) {
        throw new DomainException('Not found.');
    }
    if ($s['kind'] !== 'open') { throw new DomainException('A pipeline needs its ' . $s['kind'] . ' stage — it cannot be deleted.'); }
    if ($s['deals_all'] > 0 || $s['history'] > 0) {
        throw new DomainException($s['name'] . ' has ' . max($s['deals_all'], $s['history']) . ' deal' . (max($s['deals_all'], $s['history']) === 1 ? '' : 's') . ' in its history — archive it instead of deleting it.');
    }
    if ($s['archived_at'] === null && (int) one_value($pdo, "SELECT count(*) FROM stages WHERE pipeline_id = :p AND kind = 'open' AND archived_at IS NULL AND id <> :id", ['p' => $s['pipeline_id'], 'id' => $id]) === 0) {
        throw new DomainException('A pipeline needs at least one open stage — add another before deleting this one.');
    }
    $pdo->prepare('DELETE FROM stages WHERE id = :id')->execute(['id' => $id]);
    return ['id' => $id, 'pipeline_id' => $s['pipeline_id'], 'before' => ['name' => $s['name'], 'pipeline' => $s['pipeline_name']], 'after' => []];
}
