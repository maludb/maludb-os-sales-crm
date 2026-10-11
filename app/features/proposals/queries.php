<?php
declare(strict_types=1);

/**
 * The Assistant's proposals (screen `proposals`; the records server's `assistant_proposals`): a next step on a deal with none, a lead's qualification and score, a possible duplicate, a deal that is rotting,
 * a draft — each for the person whose decision it is, accepted in one click (crm_proposal_accept, db/013) or dismissed. Read through mcp_assistant_proposals (db/016): the proposal's person, the agent that
 * made it, and a manager see it. A record the viewer cannot see is shown by the proposal's own title only, never its name from the book.
 */
const PROPOSAL_PAGE = 25;
const PROPOSAL_KINDS = ['next_step' => ['A next step', 'feather-calendar'], 'lead_qualify' => ['A lead to qualify', 'feather-user-check'], 'lead_unqualify' => ['A lead to set aside', 'feather-user-x'],
    'duplicate' => ['A possible duplicate', 'feather-copy'], 'email_draft' => ['A draft email', 'feather-mail'], 'rotting' => ['A deal going stale', 'feather-alert-triangle'],
    'reassign' => ['A new owner', 'feather-shuffle'], 'close_date' => ['A close date', 'feather-clock']];
/** Status → [words, tone]: waiting warning, accepted success, dismissed dark, expired secondary. */
const PROPOSAL_STATUS = ['proposed' => ['Waiting', 'warning'], 'accepted' => ['Accepted', 'success'], 'dismissed' => ['Dismissed', 'dark'], 'expired' => ['Expired', 'secondary']];

/** The list's filters from the query: kind, status (waiting by default; `all` for every status), who (me by default; `all` or a member id for a manager). */
function proposal_filters_from_request(): array
{
    $kind = (string) ($_GET['kind'] ?? '');
    $status = (string) ($_GET['status'] ?? 'proposed');
    $who = (string) ($_GET['who'] ?? 'me');
    if (!is_manager()) {
        $who = 'me';
    }
    return ['kind' => isset(PROPOSAL_KINDS[$kind]) ? $kind : null, 'status' => isset(PROPOSAL_STATUS[$status]) ? $status : null,
            'who' => $who === 'all' || ctype_digit($who) ? $who : 'me'];
}

/** The page of proposals (newest first): ['rows' => [...], 'total' => n]. */
function find_proposals(PDO $pdo, array $f, int $page): array
{
    $me = (int) current_member_id();
    $st = $pdo->prepare("SELECT p.proposal_id, p.kind, p.deal_id, p.lead_id, p.contact_id, p.account_id, p.for_member_id, p.title, p.payload, p.proposed_by, p.status, p.decided_by, p.decided_at, p.result_activity_id, p.created_at,
                                fm.display_name AS for_name, pm.display_name AS proposed_by_name, dm.display_name AS decided_by_name,
                                d.number AS deal_number, d.title AS deal_title, crm_lead_label(l) AS lead_label, c.name AS contact_name, a.name AS account_name, count(*) OVER () AS total
                           FROM mcp_assistant_proposals p
                           LEFT JOIN members fm ON fm.id = p.for_member_id LEFT JOIN members pm ON pm.id = p.proposed_by LEFT JOIN members dm ON dm.id = p.decided_by
                           LEFT JOIN mcp_deals d ON d.deal_id = p.deal_id LEFT JOIN mcp_leads ml ON ml.lead_id = p.lead_id LEFT JOIN leads l ON l.id = ml.lead_id
                           LEFT JOIN mcp_contacts c ON c.contact_id = p.contact_id LEFT JOIN mcp_accounts a ON a.account_id = p.account_id
                          WHERE (CAST(:k AS text) IS NULL OR p.kind = CAST(:k AS text)) AND (CAST(:s AS text) IS NULL OR p.status = CAST(:s AS text))
                            AND CASE WHEN CAST(:w AS text) = 'me' THEN p.for_member_id = :me WHEN CAST(:w AS text) = 'all' THEN true ELSE p.for_member_id = CAST(:wid AS bigint) END
                          ORDER BY (p.status = 'proposed') DESC, p.created_at DESC, p.proposal_id DESC LIMIT " . PROPOSAL_PAGE . ' OFFSET ' . (max(1, $page) - 1) * PROPOSAL_PAGE);
    $st->execute(['k' => $f['kind'], 's' => $f['status'], 'w' => $f['who'], 'wid' => ctype_digit($f['who']) ? $f['who'] : null, 'me' => $me]);
    $rows = $st->fetchAll();
    return ['rows' => $rows, 'total' => $rows === [] ? 0 : (int) $rows[0]['total']];
}

function find_proposal(PDO $pdo, int $id): ?array
{
    $st = $pdo->prepare('SELECT proposal_id, kind, deal_id, lead_id, contact_id, account_id, for_member_id, title, payload, proposed_by, status, decided_by, decided_at, result_activity_id, created_at
                           FROM mcp_assistant_proposals WHERE proposal_id = :id');
    $st->execute(['id' => $id]);
    $r = $st->fetch();
    return $r === false ? null : $r;
}

/** How many proposals wait on a person (the strip on the home, the tab's badge). */
function proposals_waiting(PDO $pdo, int $member): int
{
    return (int) one_value($pdo, "SELECT count(*) FROM mcp_assistant_proposals WHERE for_member_id = :m AND status = 'proposed'", ['m' => $member]);
}

/** Is the Assistant's proposing switched on (agents_settings_save → assistant_proposals)? */
function proposals_enabled(PDO $pdo): bool
{
    return (bool) one_value($pdo, 'SELECT assistant_proposals FROM crm_settings WHERE id = 1');
}

/**
 * Whose decision a proposal about a record owned by $owner is: the manager of the owner's department when that person holds the Sales Manager (or admin) role here, else the first such manager. Null when no
 * manager is admitted. (The database's walls give a manager every record, so the proposal can always be opened by the one it is for.)
 */
function proposal_manager_for(PDO $pdo, ?int $owner): ?int
{
    $id = one_value($pdo, "SELECT m.id FROM members m
                            WHERE m.member_kind = 'human' AND m.status = 'active' AND m.capability IS NOT NULL AND crm_member_roles(m.id) && ARRAY['manager', 'admin']
                            ORDER BY (m.id IN (SELECT d.manager_member_id FROM department_members dm JOIN departments d ON d.id = dm.department_id
                                                WHERE dm.member_id = CAST(:o AS bigint) AND dm.left_at IS NULL AND d.manager_member_id IS NOT NULL)) DESC, m.id LIMIT 1", ['o' => $owner]);
    return $id === null ? null : (int) $id;
}

/** An admitted person (human, active, with a grant) by id, or null. */
function proposal_person(PDO $pdo, ?int $id): ?int
{
    return $id !== null && one_value($pdo, "SELECT 1 FROM members WHERE id = :m AND member_kind = 'human' AND status = 'active' AND capability IS NOT NULL", ['m' => $id]) !== null ? $id : null;
}

/** Has a proposal been made about this pair already, in any status (a dismissed one included — it is not made again)? */
function duplicate_proposed(PDO $pdo, string $kind, int $a, int $b): bool
{
    $col = $kind . '_id';
    return one_value($pdo, "SELECT 1 FROM assistant_proposals WHERE kind = 'duplicate' AND $col = :a AND payload ->> 'kind' = :k AND (payload ->> 'other_id')::bigint = :b LIMIT 1", ['a' => $a, 'b' => $b, 'k' => $kind]) !== null;
}
