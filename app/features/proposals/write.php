<?php
declare(strict_types=1);

/**
 * Proposals' writes. propose() stores what an agent (or a person) suggests for someone to decide; proposal_accept() is crm_proposal_accept (db/013): a next step becomes the DECIDER's activity (source `proposal`,
 * linked back), a qualification sets the score, a close date sets the date — in one statement with the status change; proposal_dismiss() closes it. The database does not know who may decide — PHP's rule is in the
 * handlers: the person it is FOR, or a manager. Nothing here sends a word to a contact.
 */
require_once __DIR__ . '/queries.php';
require_once dirname(__DIR__) . '/agents/queries.php';

/**
 * Store a proposal. $f: deal_id, lead_id, contact_id, account_id, for_member_id, title, payload (array). One OPEN next step per deal (the unique index of db/013) — a second is a DomainException in words.
 * Answers the proposal id.
 */
function propose(PDO $pdo, string $kind, array $f, int $by): int
{
    if (!isset(PROPOSAL_KINDS[$kind])) {
        throw new DomainException('Not a kind of proposal.');
    }
    try {
        $st = $pdo->prepare('INSERT INTO assistant_proposals (kind, deal_id, lead_id, contact_id, account_id, for_member_id, title, payload, proposed_by) VALUES (:k, :d, :l, :c, :a, :f, :t, CAST(:p AS jsonb), :by) RETURNING id');
        $st->execute(['k' => $kind, 'd' => $f['deal_id'] ?? null, 'l' => $f['lead_id'] ?? null, 'c' => $f['contact_id'] ?? null, 'a' => $f['account_id'] ?? null, 'f' => $f['for_member_id'] ?? null,
            't' => mb_substr((string) $f['title'], 0, 250), 'p' => json_encode($f['payload'] ?? (object) [], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), 'by' => $by > 0 ? $by : null]);
    } catch (PDOException $e) {
        if ((string) $e->getCode() === '23505') {
            throw new DomainException('That deal already has a next step proposed — decide it first.');
        }
        throw $e;
    }
    return (int) $st->fetchColumn();
}

/** Accept: the database does what the kind means. Answers the activity a next step made (null for the other kinds). The caller has judged who may. */
function proposal_accept(PDO $pdo, int $id, int $by): ?int
{
    $st = $pdo->prepare('SELECT crm_proposal_accept(:id)');
    $st->execute(['id' => $id]);
    $aid = $st->fetchColumn();
    return $aid === false || $aid === null ? null : (int) $aid;
}

/** Dismiss a proposal that is waiting. False when it was not. */
function proposal_dismiss(PDO $pdo, int $id, int $by): bool
{
    $st = $pdo->prepare("UPDATE assistant_proposals SET status = 'dismissed', decided_by = :by, decided_at = now() WHERE id = :id AND status = 'proposed' RETURNING id");
    $st->execute(['id' => $id, 'by' => $by]);
    return $st->fetchColumn() !== false;
}

/** May the current member decide this proposal (accept or dismiss)? Who it is for, or a manager. */
function proposal_may_decide(array $p): bool
{
    return (int) $p['for_member_id'] === (int) current_member_id() || is_manager();
}

/**
 * The Assistant's standing proposals the worker makes without a model (the `proposals` pass): (1) a `duplicate` for each pair crm_duplicates finds that nobody has dealt with — not set aside as "not a duplicate" and never proposed
 * before IN ANY STATUS, so a dismissed one is not made again — for the manager of the owner's department; (2) a `rotting` for each open deal that is newly rotting, for its owner (a manager when it has none), once per deal per stage.
 * Both stop while the Assistant's proposals are switched off. At most $limit new proposals per kind per pass. Logged proposal.duplicate | proposal.rotting (source cron). Answers the counts.
 */
function standing_proposals_run(PDO $pdo, int $limit, DateTimeImmutable $now): array
{
    $out = ['proposed' => 0, 'duplicates' => 0, 'rotting' => 0, 'no_manager' => 0];
    if (!proposals_enabled($pdo)) {
        return $out + ['off' => true];
    }
    $by = assistant_agent($pdo) ?? 0;
    $owner = ['contact' => 'contacts', 'account' => 'accounts', 'lead' => 'leads'];
    foreach (['contact', 'account', 'lead'] as $kind) {
        $col = $kind . '_id';
        $st = $pdo->prepare("SELECT LEAST(x.a_id, x.b_id) AS a, GREATEST(x.a_id, x.b_id) AS b, x.matched_on FROM crm_duplicates(:k, 1000) x
                              WHERE NOT EXISTS (SELECT 1 FROM duplicate_dismissals dd WHERE dd.record_kind = :k AND dd.a_id = LEAST(x.a_id, x.b_id) AND dd.b_id = GREATEST(x.a_id, x.b_id))
                                AND NOT EXISTS (SELECT 1 FROM assistant_proposals p WHERE p.kind = 'duplicate' AND p.payload ->> 'kind' = :k AND p.$col = LEAST(x.a_id, x.b_id) AND (p.payload ->> 'other_id')::bigint = GREATEST(x.a_id, x.b_id))
                              ORDER BY 1, 2 LIMIT :l");
        $st->bindValue('k', $kind);
        $st->bindValue('l', max(1, $limit), PDO::PARAM_INT);
        $st->execute();
        $seen = [];
        foreach ($st->fetchAll() as $pair) {
            $a = (int) $pair['a'];
            $b = (int) $pair['b'];
            if (isset($seen["$a-$b"])) { continue; }                                      // the same pair matched on two things: one proposal
            $seen["$a-$b"] = true;
            $ownerId = one_value($pdo, "SELECT owner_member_id FROM {$owner[$kind]} WHERE id = :id", ['id' => $a]);
            $for = proposal_manager_for($pdo, $ownerId === null ? null : (int) $ownerId);
            if ($for === null) { $out['no_manager']++; continue; }
            $names = $kind === 'lead' ? [one_value($pdo, 'SELECT crm_lead_label(l) FROM leads l WHERE id = :id', ['id' => $a]), one_value($pdo, 'SELECT crm_lead_label(l) FROM leads l WHERE id = :id', ['id' => $b])]
                : [one_value($pdo, "SELECT name FROM {$owner[$kind]} WHERE id = :id", ['id' => $a]), one_value($pdo, "SELECT name FROM {$owner[$kind]} WHERE id = :id", ['id' => $b])];
            try {
                $pdo->beginTransaction();
                $id = propose($pdo, 'duplicate', [$col => $a, 'for_member_id' => $for, 'title' => 'Possible duplicate ' . $kind . 's: ' . $names[0] . ' and ' . $names[1],
                    'payload' => ['kind' => $kind, 'other_id' => $b, 'matched_on' => $pair['matched_on']]], $by);
                log_activity($pdo, 'proposal.duplicate', 'assistant_proposal', $id, [$col => $a, 'actor_member_id' => $by > 0 ? $by : null, 'source' => 'cron',
                    'after' => ['kind' => $kind, 'a' => $a, 'b' => $b, 'matched_on' => $pair['matched_on'], 'for_member_id' => $for]]);
                $pdo->commit();
                $out['duplicates']++;
                $out['proposed']++;
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) { $pdo->rollBack(); }
                error_log('worker proposals duplicate ' . $kind . ' ' . $a . '/' . $b . ': ' . $e->getMessage());
            }
        }
    }
    $st = $pdo->prepare("SELECT d.id, d.number, d.title, d.owner_member_id, d.stage_id, s.name AS stage, extract(day FROM CAST(:at AS timestamptz) - d.stage_entered_at)::integer AS days FROM deals d JOIN stages s ON s.id = d.stage_id
                          WHERE d.deleted_at IS NULL AND crm_deal_is_rotting(d.status, d.stage_id, d.stage_entered_at)
                            AND NOT EXISTS (SELECT 1 FROM assistant_proposals p WHERE p.kind = 'rotting' AND p.deal_id = d.id AND p.payload ->> 'stage_id' = d.stage_id::text)
                          ORDER BY d.stage_entered_at, d.id LIMIT :l");
    $st->bindValue('at', $now->format(DATE_ATOM));
    $st->bindValue('l', max(1, $limit), PDO::PARAM_INT);
    $st->execute();
    foreach ($st->fetchAll() as $d) {
        $for = proposal_person($pdo, $d['owner_member_id'] === null ? null : (int) $d['owner_member_id']) ?? proposal_manager_for($pdo, null);
        if ($for === null) { $out['no_manager']++; continue; }
        try {
            $pdo->beginTransaction();
            $id = propose($pdo, 'rotting', ['deal_id' => (int) $d['id'], 'for_member_id' => $for, 'title' => $d['number'] . ' has sat in ' . $d['stage'] . ' for ' . (int) $d['days'] . ' days',
                'payload' => ['stage_id' => (int) $d['stage_id'], 'stage' => $d['stage'], 'days' => (int) $d['days']]], $by);
            log_activity($pdo, 'proposal.rotting', 'assistant_proposal', $id, ['deal_id' => (int) $d['id'], 'actor_member_id' => $by > 0 ? $by : null, 'source' => 'cron',
                'after' => ['stage_id' => (int) $d['stage_id'], 'days' => (int) $d['days'], 'for_member_id' => $for]]);
            $pdo->commit();
            $out['rotting']++;
            $out['proposed']++;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) { $pdo->rollBack(); }
            error_log('worker proposals rotting ' . $d['id'] . ': ' . $e->getMessage());
        }
    }
    return $out;
}
