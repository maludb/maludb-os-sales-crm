<?php
declare(strict_types=1);

/**
 * Dispatches (design §5(c); agents-k7-api.md "The worker"): a request that wakes an agent — a lead from the website's form to qualify, a deal that entered a stage that asks the Assistant, a reminder that is an agent's
 * — sent as ONE chat turn on the kernel's chat endpoint as the acting person (A6; K8, the kernel's own wake, is owed — the turn is the stand-in). The worker's `dispatches` pass sends the rows still `sent`:
 *   - the acting person is the row's, else the lead's or deal's owner (a person admitted here); with no one to act as the row is `refused`, in words;
 *   - the first 200 characters of the reply are kept; the full reply stays with the run in the kernel's ledger;
 *   - a turn the kernel could not take (unreachable, 5xx, busy) is tried again after 1 and 4 minutes — THREE attempts in all (`attempts` counts the one being made), then `failed`;
 *   - a refusal in words (403, 404, 422) is `refused` at once — the agent is not here, or holds no access: trying again changes nothing;
 *   - a run that is still going (202) keeps its run id and stays `sent`; the next pass asks after it (GET ?run=) until it ends;
 *   - a run that paused for approval is `awaiting_approval` with the run's reply kept.
 * Logged dispatch.send (the turn was sent), dispatch.answered, dispatch.failed — the ids, the kind, the attempt, never the utterance or the reply.
 */
require_once __DIR__ . '/queries.php';

const DISPATCH_ATTEMPTS = 3;
const DISPATCH_EXCERPT = 200;

/** Wake the Assistant for a lead's first qualification (the website form). Does nothing when there is no Assistant, or the Assistant's proposals are off. Answers the dispatch id or null. */
function dispatch_lead_qualify(PDO $pdo, int $lead, ?int $owner): ?int
{
    $agent = assistant_agent($pdo);
    if ($agent === null || !(bool) one_value($pdo, 'SELECT assistant_proposals FROM crm_settings WHERE id = 1')) {
        return null;
    }
    $acting = $owner !== null && one_value($pdo, "SELECT 1 FROM members WHERE id = :m AND member_kind = 'human' AND status = 'active' AND capability IS NOT NULL", ['m' => $owner]) !== null ? $owner : current_member_id();
    $st = $pdo->prepare("INSERT INTO agent_dispatches (record_type, record_id, agent_member_id, kind, via, acting_member_id, lead_id, status, detail) VALUES ('lead', :l, :a, 'lead_qualify', 'chat', :w, :l, 'sent', 'from the website form') RETURNING id");
    $st->execute(['l' => $lead, 'a' => $agent, 'w' => $acting]);
    return (int) $st->fetchColumn();
}

/** The opening sentence of a dispatch's turn: what happened and what is asked — ids and names, never an email's body. */
function dispatch_utterance(PDO $pdo, array $d): string
{
    $deal = $d['deal_id'] === null ? null : one_row($pdo, 'SELECT d.number, d.title, s.name AS stage FROM deals d JOIN stages s ON s.id = d.stage_id WHERE d.id = :id', ['id' => $d['deal_id']]);
    $lead = $d['lead_id'] === null ? null : one_row($pdo, 'SELECT crm_lead_label(l) AS label, (SELECT name FROM lead_sources WHERE id = l.source_id) AS source FROM leads l WHERE l.id = :id', ['id' => $d['lead_id']]);
    $dealWords = $deal === null ? 'a deal' : 'deal ' . $deal['number'] . ' "' . $deal['title'] . '"';
    switch ($d['kind']) {
        case 'lead_qualify':
            return 'A new lead needs a first look: lead #' . (int) $d['lead_id'] . ($lead === null ? '' : ' (' . $lead['label'] . ($lead['source'] ? ', from ' . $lead['source'] : '') . ')')
                . '. Read it with your tools, score it from 0 to 100 from the source, the domain and the fields, and propose the qualification with lead_qualify_propose. Do not contact anyone.';
        case 'stage_entered':
        case 'next_step':
        case 'ask':
            return ucfirst($dealWords) . ($deal === null ? '' : ' has entered the stage ' . $deal['stage']) . '. If it has no next step, propose one with next_step_propose; if it has one, say it needs nothing.';
        case 'reminder':
            $a = $d['record_type'] === 'activity' && $d['record_id'] !== null ? one_row($pdo, 'SELECT kind, subject, COALESCE(starts_at, due_at) AS at FROM activities WHERE id = :id', ['id' => $d['record_id']]) : null;
            return 'A reminder is due for you' . ($a === null ? '' : ': the ' . $a['kind'] . ' "' . $a['subject'] . '"' . ($a['at'] ? ' at ' . gmdate('D j M H:i', strtotime((string) $a['at'])) . ' UTC' : '')) . ($deal === null ? '' : ' on ' . $dealWords)
                . '. Do what it asks with your tools, or say what you need.';
        default:
            return 'Run your proposal duty now: propose what the book needs, and say what you found.';
    }
}

/** The person to act as: the row's acting member, else the owner of the lead or the deal — a human admitted here — else null. */
function dispatch_actor(PDO $pdo, array $d): ?int
{
    foreach ([$d['acting_member_id'], $d['lead_id'] === null ? null : one_value($pdo, 'SELECT owner_member_id FROM leads WHERE id = :id', ['id' => $d['lead_id']]),
              $d['deal_id'] === null ? null : one_value($pdo, 'SELECT owner_member_id FROM deals WHERE id = :id', ['id' => $d['deal_id']])] as $m) {
        if ($m !== null && one_value($pdo, "SELECT 1 FROM members WHERE id = :m AND member_kind = 'human' AND status = 'active' AND capability IS NOT NULL", ['m' => (int) $m]) !== null) {
            return (int) $m;
        }
    }
    return null;
}

function dispatch_excerpt(string $reply): string
{
    return mb_substr(trim($reply), 0, DISPATCH_EXCERPT);
}

/** One failed attempt: another try after 1 or 4 minutes while attempts remain, else `failed` with the sentence. Answers 'retry' or 'failed'. */
function dispatch_attempt_failed(PDO $pdo, array $d, string $why, DateTimeImmutable $now): string
{
    $n = (int) $d['attempts'];
    if ($n >= DISPATCH_ATTEMPTS) {
        $pdo->prepare("UPDATE agent_dispatches SET status = 'failed', detail = :w, answered_at = now(), next_attempt_at = NULL WHERE id = :id")->execute(['w' => mb_substr($why, 0, 300), 'id' => $d['id']]);
        log_activity($pdo, 'dispatch.failed', 'agent_dispatch', (int) $d['id'], ['actor_member_id' => null, 'source' => 'cron', 'after' => ['kind' => $d['kind'], 'agent_member_id' => (int) $d['agent_member_id'], 'attempts' => $n]]);
        return 'failed';
    }
    $pdo->prepare('UPDATE agent_dispatches SET attempts = :n, run_id = NULL, detail = :w, next_attempt_at = CAST(:at AS timestamptz) + make_interval(mins => :m) WHERE id = :id')
        ->execute(['n' => $n + 1, 'w' => mb_substr($why, 0, 300), 'at' => $now->format(DATE_ATOM), 'm' => $n * $n, 'id' => $d['id']]);
    return 'retry';
}

/** Settle a dispatch from the run's state: answered, awaiting_approval, failed — or still going (null). */
function dispatch_settle(PDO $pdo, array $d, array $r, DateTimeImmutable $now): ?string
{
    if ($r['run_id'] !== null) {
        $pdo->prepare('UPDATE agent_dispatches SET run_id = :r WHERE id = :id')->execute(['r' => $r['run_id'], 'id' => $d['id']]);
    }
    if (!$r['finished']) {
        return null;
    }
    if ($r['status'] === 'failed' || ($r['error'] !== null && $r['status'] !== 'succeeded' && $r['status'] !== 'awaiting_approval')) {
        return dispatch_attempt_failed($pdo, $d, 'The run failed: ' . ($r['error'] ?? 'no reason given'), $now);
    }
    $awaiting = $r['status'] === 'awaiting_approval' || $r['approval'] !== null;
    $pdo->prepare("UPDATE agent_dispatches SET status = :s, reply_excerpt = :x, answered_at = CAST(:at AS timestamptz), detail = NULL, next_attempt_at = NULL WHERE id = :id")
        ->execute(['s' => $awaiting ? 'awaiting_approval' : 'answered', 'x' => dispatch_excerpt($r['reply']), 'at' => $now->format(DATE_ATOM), 'id' => $d['id']]);
    log_activity($pdo, 'dispatch.answered', 'agent_dispatch', (int) $d['id'], ['actor_member_id' => null, 'source' => 'cron', 'after' => ['kind' => $d['kind'], 'agent_member_id' => (int) $d['agent_member_id'], 'attempts' => (int) $d['attempts'], 'awaiting_approval' => $awaiting]]);
    return $awaiting ? 'awaiting_approval' : 'answered';
}

/** The pass: runs still going are asked after, then the dispatches due are sent. Answers the report's counts. */
function dispatches_run(PDO $pdo, int $limit, DateTimeImmutable $now): array
{
    $out = ['dispatched' => 0, 'answered' => 0, 'retried' => 0, 'failed' => 0, 'refused' => 0, 'running' => 0];
    $cols = 'id, record_type, record_id, agent_member_id, kind, acting_member_id, run_id, attempts, deal_id, lead_id';
    $go = $pdo->prepare("SELECT $cols FROM agent_dispatches WHERE status = 'sent' AND run_id IS NOT NULL ORDER BY id LIMIT :l");
    $go->bindValue('l', max(1, $limit), PDO::PARAM_INT);
    $go->execute();
    foreach ($go->fetchAll() as $d) {
        $r = kernel_chat_run((int) $d['run_id']);
        if ($r['http'] === 0 || $r['http'] >= 500) {                                         // the kernel did not answer just now: ask after the run again next time
            $out['running']++;
            continue;
        }
        $s = $r['http'] >= 400 ? dispatch_attempt_failed($pdo, $d, 'The kernel lost the run: ' . ($r['error'] ?? 'no such run'), $now) : dispatch_settle($pdo, $d, $r, $now);        // a run the kernel does not know is sent again
        match ($s) { 'answered', 'awaiting_approval' => $out['answered']++, 'failed' => $out['failed']++, 'retry' => $out['retried']++, default => $out['running']++ };
    }
    $due = $pdo->prepare("SELECT $cols FROM agent_dispatches WHERE status = 'sent' AND run_id IS NULL AND (next_attempt_at IS NULL OR next_attempt_at <= CAST(:at AS timestamptz)) ORDER BY id LIMIT :l");
    $due->bindValue('at', $now->format(DATE_ATOM));
    $due->bindValue('l', max(1, $limit), PDO::PARAM_INT);
    $due->execute();
    foreach ($due->fetchAll() as $d) {
        $actor = dispatch_actor($pdo, $d);
        if ($actor === null) {
            $pdo->prepare("UPDATE agent_dispatches SET status = 'refused', detail = 'No one to act as: the request names no person who is here.', answered_at = now() WHERE id = :id")->execute(['id' => $d['id']]);
            $out['refused']++;
            continue;
        }
        $d['id'] = (int) $d['id'];
        $r = kernel_chat_turn($actor, (int) $d['agent_member_id'], dispatch_utterance($pdo, $d), ['entity' => (string) $d['record_type'], 'record_id' => $d['record_id'] === null ? null : (int) $d['record_id'],
            'conversation_id' => 'crm-dispatch-' . $d['id']]);
        log_activity($pdo, 'dispatch.send', 'agent_dispatch', $d['id'], ['actor_member_id' => null, 'source' => 'cron', 'after' => ['kind' => $d['kind'], 'agent_member_id' => (int) $d['agent_member_id'], 'acting_member_id' => $actor, 'attempt' => (int) $d['attempts'], 'http' => $r['http']]]);
        $out['dispatched']++;
        if ($r['http'] >= 200 && $r['http'] < 300) {
            $s = dispatch_settle($pdo, $d, $r, $now);
            match ($s) { 'answered', 'awaiting_approval' => $out['answered']++, 'failed' => $out['failed']++, 'retry' => $out['retried']++, default => $out['running']++ };
        } elseif (in_array($r['http'], [403, 404, 422], true)) {
            $pdo->prepare("UPDATE agent_dispatches SET status = 'refused', detail = :w, answered_at = now() WHERE id = :id")->execute(['w' => mb_substr((string) $r['error'], 0, 300), 'id' => $d['id']]);
            $out['refused']++;
        } else {                                                                             // unreachable, 5xx, 401, busy (409): another try
            $s = dispatch_attempt_failed($pdo, $d, (string) ($r['error'] ?? 'The kernel did not answer.'), $now);
            $out[$s === 'failed' ? 'failed' : 'retried']++;
        }
    }
    return $out;
}
