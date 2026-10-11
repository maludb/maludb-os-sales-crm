<?php
declare(strict_types=1);

/**
 * Source and reason writes (actions source_save, source_delete, lost_reason_save, lost_reason_delete). A name is unique (the table says so; the sentence names it). Deleting one that is in use is refused by name,
 * counting what uses it; deactivating it is the way out (the records keep it, the picker stops offering it).
 */
require_once __DIR__ . '/queries.php';

const SOURCE_USES = ['leads' => ['lead', 'leads'], 'deals' => ['deal', 'deals'], 'accounts' => ['account', 'accounts'], 'contacts' => ['contact', 'contacts'], 'imports' => ['import', 'imports'],
    'keys' => ['lead-capture key', 'lead-capture keys'], 'rules' => ['assignment rule', 'assignment rules']];
const REASON_USES = ['deals' => ['deal', 'deals'], 'leads' => ['lead', 'leads']];

/** Save a source ($id null = new): name, kind (inbound|outbound), active, sort_order. ['id', 'before', 'after']. */
function source_save(PDO $pdo, ?int $id, array $f): array
{
    $cur = $id === null ? null : source_one($pdo, $id);
    if ($id !== null && $cur === null) { throw new DomainException('Not found.'); }
    if ((int) one_value($pdo, 'SELECT count(*) FROM lead_sources WHERE lower(name) = lower(:n) AND id <> :id', ['n' => $f['name'], 'id' => $id ?? 0]) > 0) {
        throw new DomainException('There is a source called ' . $f['name'] . ' already.');
    }
    $new = ['name' => $f['name'], 'kind' => $f['kind'], 'active' => (bool) $f['active'], 'sort_order' => $f['sort_order']];
    if ($cur === null) {
        $st = $pdo->prepare('INSERT INTO lead_sources (name, kind, active, sort_order) VALUES (:n, :k, CAST(:a AS boolean), :s) RETURNING id');
        $st->execute(['n' => $new['name'], 'k' => $new['kind'], 'a' => $new['active'] ? 't' : 'f', 's' => $new['sort_order']]);
        return ['id' => (int) $st->fetchColumn(), 'before' => [], 'after' => $new];
    }
    $d = crm_diff(['name' => $cur['name'], 'kind' => $cur['kind'], 'active' => $cur['active'], 'sort_order' => $cur['sort_order']], $new);
    if ($d['after'] !== []) {
        $pdo->prepare('UPDATE lead_sources SET name = :n, kind = :k, active = CAST(:a AS boolean), sort_order = :s WHERE id = :id')
            ->execute(['n' => $new['name'], 'k' => $new['kind'], 'a' => $new['active'] ? 't' : 'f', 's' => $new['sort_order'], 'id' => $id]);
    }
    return ['id' => $id] + $d;
}

/** Delete a source nothing names. */
function source_delete(PDO $pdo, int $id): array
{
    $s = source_one($pdo, $id);
    if ($s === null) { throw new DomainException('Not found.'); }
    if (($uses = vocab_uses_words($s, SOURCE_USES)) !== '') {
        throw new DomainException($s['name'] . ' is named by ' . $uses . ' — deactivate it instead of deleting it.');
    }
    $pdo->prepare('DELETE FROM lead_sources WHERE id = :id')->execute(['id' => $id]);
    return ['id' => $id, 'before' => ['name' => $s['name'], 'kind' => $s['kind']], 'after' => []];
}

/** Save a lost reason ($id null = new): kind (lead|deal, fixed once made), label, active, sort_order. */
function reason_save(PDO $pdo, ?int $id, array $f): array
{
    $cur = $id === null ? null : reason_one($pdo, $id);
    if ($id !== null && $cur === null) { throw new DomainException('Not found.'); }
    $kind = $cur === null ? (string) $f['kind'] : $cur['kind'];
    if ($cur !== null && (string) $f['kind'] !== $cur['kind']) {
        throw new DomainException('A reason stays a ' . $cur['kind'] . ' reason — add a new one for the other.');
    }
    if ((int) one_value($pdo, 'SELECT count(*) FROM lost_reasons WHERE kind = :k AND lower(label) = lower(:l) AND id <> :id', ['k' => $kind, 'l' => $f['label'], 'id' => $id ?? 0]) > 0) {
        throw new DomainException('There is a ' . $kind . ' reason called ' . $f['label'] . ' already.');
    }
    $new = ['kind' => $kind, 'label' => $f['label'], 'active' => (bool) $f['active'], 'sort_order' => $f['sort_order']];
    if ($cur === null) {
        $st = $pdo->prepare('INSERT INTO lost_reasons (kind, label, active, sort_order) VALUES (:k, :l, CAST(:a AS boolean), :s) RETURNING id');
        $st->execute(['k' => $kind, 'l' => $new['label'], 'a' => $new['active'] ? 't' : 'f', 's' => $new['sort_order']]);
        return ['id' => (int) $st->fetchColumn(), 'before' => [], 'after' => $new];
    }
    $d = crm_diff(['kind' => $cur['kind'], 'label' => $cur['label'], 'active' => $cur['active'], 'sort_order' => $cur['sort_order']], $new);
    if ($d['after'] !== []) {
        $pdo->prepare('UPDATE lost_reasons SET label = :l, active = CAST(:a AS boolean), sort_order = :s WHERE id = :id')->execute(['l' => $new['label'], 'a' => $new['active'] ? 't' : 'f', 's' => $new['sort_order'], 'id' => $id]);
    }
    return ['id' => $id] + $d;
}

/** Delete a reason nothing names. */
function reason_delete(PDO $pdo, int $id): array
{
    $r = reason_one($pdo, $id);
    if ($r === null) { throw new DomainException('Not found.'); }
    if (($uses = vocab_uses_words($r, REASON_USES)) !== '') {
        throw new DomainException($r['label'] . ' is named by ' . $uses . ' — deactivate it instead of deleting it.');
    }
    $pdo->prepare('DELETE FROM lost_reasons WHERE id = :id')->execute(['id' => $id]);
    return ['id' => $id, 'before' => ['kind' => $r['kind'], 'label' => $r['label']], 'after' => []];
}
