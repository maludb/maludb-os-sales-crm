<?php
declare(strict_types=1);

/**
 * The agents working here, their dispatches and their settings (screen `admin-agents`; the records server's `agent_dispatches`). An agent is a member of kind agent admitted here (mcp_members); a
 * DISPATCH is a request that wakes one — a lead to qualify, a deal that entered an ask-the-Assistant stage, a reminder that is an agent's — sent as one chat turn by the worker's `dispatches` pass
 * (agent_dispatches, GL db/014). The screen is the admin's (`agents.settings`) and a person's.
 */
const DISPATCH_PAGE = 50;
/** Status words and tones: sent primary, answered success, refused and failed danger, awaiting_approval warning; a cancelled one is a refusal said in its own words. */
const DISPATCH_STATUS = ['sent' => ['Waiting', 'primary'], 'answered' => ['Answered', 'success'], 'refused' => ['Refused', 'danger'], 'failed' => ['Failed', 'danger'], 'awaiting_approval' => ['Waits for approval', 'warning']];
const DISPATCH_KINDS = ['lead_qualify' => 'Qualify a lead', 'next_step' => 'Propose a next step', 'reminder' => 'A reminder', 'stage_entered' => 'A deal entered a stage', 'ask' => 'A question', 'duty_proposal' => 'Its duty'];

/** The words and tone of a dispatch's status (a cancelled one reads "Cancelled"). */
function dispatch_status_words(array $d): array
{
    if ($d['status'] === 'refused' && str_starts_with((string) $d['detail'], 'Cancelled')) {
        return ['Cancelled', 'secondary'];
    }
    return DISPATCH_STATUS[$d['status']] ?? [(string) $d['status'], 'secondary'];
}

/** The Sales Assistant: the active agent admitted here whose name says assistant (the convention of db/013's stage trigger), or null. */
function assistant_agent(PDO $pdo): ?int
{
    $id = one_value($pdo, "SELECT id FROM members WHERE member_kind = 'agent' AND status = 'active' AND capability IS NOT NULL AND display_name ILIKE '%assistant%' ORDER BY id LIMIT 1");
    return $id === null ? null : (int) $id;
}

/** The agents admitted here with their last dispatch and what is waiting on them: [{member_id, display_name, job_title, roles, last_at, last_kind, last_status, waiting, answered_week, proposals_week}]. */
function agents_here(PDO $pdo): array
{
    $rows = $pdo->query("SELECT m.member_id, m.display_name, m.job_title, m.roles_resolved::text AS roles,
                                (SELECT d.created_at FROM mcp_agent_dispatches d WHERE d.agent_member_id = m.member_id ORDER BY d.created_at DESC, d.dispatch_id DESC LIMIT 1) AS last_at,
                                (SELECT d.kind FROM mcp_agent_dispatches d WHERE d.agent_member_id = m.member_id ORDER BY d.created_at DESC, d.dispatch_id DESC LIMIT 1) AS last_kind,
                                (SELECT d.status FROM mcp_agent_dispatches d WHERE d.agent_member_id = m.member_id ORDER BY d.created_at DESC, d.dispatch_id DESC LIMIT 1) AS last_status,
                                (SELECT count(*) FROM mcp_agent_dispatches d WHERE d.agent_member_id = m.member_id AND d.status = 'sent') AS waiting,
                                (SELECT count(*) FROM mcp_agent_dispatches d WHERE d.agent_member_id = m.member_id AND d.status = 'answered' AND d.answered_at > now() - interval '7 days') AS answered_week,
                                (SELECT count(*) FROM mcp_assistant_proposals p WHERE p.proposed_by = m.member_id AND p.created_at > now() - interval '7 days') AS proposals_week
                           FROM mcp_members m WHERE m.member_kind = 'agent' ORDER BY m.display_name")->fetchAll();
    foreach ($rows as &$r) {
        $r['roles'] = pg_text_array((string) $r['roles']);
    }
    return $rows;
}

/** Dispatches newest first: filters status, agent, kind; at most DISPATCH_PAGE. Through mcp_agent_dispatches (the admin sees all; an agent or the person acting sees theirs). */
function find_dispatches(PDO $pdo, array $f): array
{
    $st = $pdo->prepare("SELECT d.dispatch_id, d.record_type, d.record_id, d.agent_member_id, a.display_name AS agent_name, d.kind, d.via, d.acting_member_id, w.display_name AS acting_name, d.run_id, d.status, d.reply_excerpt,
                                d.detail, d.attempts, d.created_at, d.answered_at, d.deal_id, d.lead_id
                           FROM mcp_agent_dispatches d LEFT JOIN members a ON a.id = d.agent_member_id LEFT JOIN members w ON w.id = d.acting_member_id
                          WHERE (CAST(:s AS text) IS NULL OR d.status = CAST(:s AS text)) AND (CAST(:a AS bigint) IS NULL OR d.agent_member_id = CAST(:a AS bigint)) AND (CAST(:k AS text) IS NULL OR d.kind = CAST(:k AS text))
                          ORDER BY d.created_at DESC, d.dispatch_id DESC LIMIT " . DISPATCH_PAGE);
    $st->execute(['s' => $f['status'] ?? null, 'a' => $f['agent'] ?? null, 'k' => $f['kind'] ?? null]);
    return $st->fetchAll();
}

/** One dispatch's base row (the screen's Cancel needs its status). */
function find_dispatch(PDO $pdo, int $id): ?array
{
    $st = $pdo->prepare('SELECT dispatch_id, agent_member_id, kind, status, detail FROM mcp_agent_dispatches WHERE dispatch_id = :id');
    $st->execute(['id' => $id]);
    $r = $st->fetch();
    return $r === false ? null : $r;
}

/** The Assistant's settings (mcp_settings) and the stages that wake it on entry (mcp_stages.ask_assistant) with their pipelines. */
function agent_settings(PDO $pdo): array
{
    $s = $pdo->query('SELECT morning_note_enabled, morning_note_hour, assistant_proposals, lead_untouched_days, account_untouched_days FROM mcp_settings')->fetch() ?: [];
    $stages = $pdo->query("SELECT s.stage_id, s.name, s.ask_assistant, p.name AS pipeline_name FROM mcp_stages s JOIN mcp_pipelines p ON p.pipeline_id = s.pipeline_id
                            WHERE s.archived_at IS NULL AND p.archived_at IS NULL AND s.kind = 'open' ORDER BY p.position, s.position")->fetchAll();
    return ['morning_note_enabled' => (bool) ($s['morning_note_enabled'] ?? true), 'morning_note_hour' => (int) ($s['morning_note_hour'] ?? 6), 'assistant_proposals' => (bool) ($s['assistant_proposals'] ?? true),
            'lead_untouched_days' => (int) ($s['lead_untouched_days'] ?? 2), 'account_untouched_days' => (int) ($s['account_untouched_days'] ?? 30),
            'stages' => array_map(static fn (array $r): array => ['stage_id' => (int) $r['stage_id'], 'name' => $r['name'], 'pipeline' => $r['pipeline_name'], 'ask_assistant' => (bool) $r['ask_assistant']], $stages)];
}

function present_dispatch(array $d): array
{
    [$word, $tone] = dispatch_status_words($d);
    return ['dispatch_id' => (int) $d['dispatch_id'], 'agent_member_id' => (int) $d['agent_member_id'], 'agent_name' => $d['agent_name'] ?? null, 'kind' => $d['kind'], 'kind_label' => DISPATCH_KINDS[$d['kind']] ?? $d['kind'],
            'status' => $d['status'], 'status_label' => $word, 'tone' => $tone, 'attempts' => (int) $d['attempts'], 'acting_member_id' => $d['acting_member_id'] === null ? null : (int) $d['acting_member_id'], 'acting_name' => $d['acting_name'] ?? null,
            'record_type' => $d['record_type'], 'record_id' => $d['record_id'] === null ? null : (int) $d['record_id'], 'deal_id' => $d['deal_id'] === null ? null : (int) $d['deal_id'], 'lead_id' => $d['lead_id'] === null ? null : (int) $d['lead_id'],
            'reply_excerpt' => $d['reply_excerpt'], 'detail' => $d['detail'], 'created_at' => json_ts($d['created_at']), 'answered_at' => json_ts($d['answered_at'])];
}
