<?php
declare(strict_types=1);

/**
 * Campaigns (screens `campaign-list`, `campaign-view`, `campaign-add`, `campaign-edit`; the records server's find_campaigns / get_campaign / campaign_results / campaign_members read the same
 * views). A campaign is a named effort with members (leads and contacts, each with a status), and its results are DERIVED: the leads and deals that carry its id, what was won and what is
 * open, the cost per lead and per won deal (crm_campaign_results, db/015). Campaigns are open to everyone who belongs here (mcp_campaigns); running them is campaigns.manage.
 */
const CAMPAIGN_PAGE = 24;
const CAMPAIGN_MEMBER_PAGE = 50;
const CAMPAIGN_KINDS = ['email' => 'Email', 'event' => 'Event', 'webinar' => 'Webinar', 'ads' => 'Ads', 'content' => 'Content', 'outbound' => 'Outbound', 'referral' => 'Referral', 'other' => 'Other'];
const CAMPAIGN_STATUSES = ['planned' => 'Planned', 'active' => 'Active', 'done' => 'Done', 'cancelled' => 'Cancelled'];
const CAMPAIGN_STATUS_BADGE = ['planned' => 'secondary', 'active' => 'primary', 'done' => 'success', 'cancelled' => 'dark'];
const CAMPAIGN_MEMBER_STATUSES = ['planned' => 'Planned', 'sent' => 'Sent', 'opened' => 'Opened', 'responded' => 'Responded', 'attended' => 'Attended', 'converted' => 'Converted', 'unsubscribed' => 'Unsubscribed'];
const CAMPAIGN_MEMBER_BADGE = ['planned' => 'secondary', 'sent' => 'info', 'opened' => 'primary', 'responded' => 'warning', 'attended' => 'warning', 'converted' => 'success', 'unsubscribed' => 'dark'];
/** The statuses a person sets by hand (an unsubscribe is the contact's own act, through the link in the email). */
const CAMPAIGN_MEMBER_SETTABLE = ['planned', 'sent', 'opened', 'responded', 'attended', 'converted'];

/** The list's filters from the request: status, kind, owner (me or a member), q. */
function campaign_filters_from_request(): array
{
    $status = request_string('status');
    $kind = request_string('kind');
    $owner = request_string('owner');
    return [
        'status' => isset(CAMPAIGN_STATUSES[$status]) ? $status : null,
        'kind' => isset(CAMPAIGN_KINDS[$kind]) ? $kind : null,
        'owner' => $owner === 'me' || ctype_digit($owner) && $owner !== '' ? $owner : null,
        'q' => mb_substr(request_string('q'), 0, 120),
    ];
}

/** One page of campaigns with their results (the card): ['rows', 'total']. Active first, then the newest. */
function find_campaigns(PDO $pdo, array $f, int $page = 1): array
{
    $w = ['true'];
    $a = [];
    if (($f['status'] ?? null) !== null) { $w[] = 'c.status = :st'; $a['st'] = $f['status']; }
    if (($f['kind'] ?? null) !== null) { $w[] = 'c.kind = :k'; $a['k'] = $f['kind']; }
    if (($f['owner'] ?? null) !== null) { $w[] = 'c.owner_member_id = :o'; $a['o'] = $f['owner'] === 'me' ? (int) current_member_id() : (int) $f['owner']; }
    if (($f['q'] ?? '') !== '') { $w[] = "(c.name ILIKE '%' || :q || '%' OR c.utm_campaign ILIKE '%' || :q || '%')"; $a['q'] = mb_substr((string) $f['q'], 0, 120); }
    $st = $pdo->prepare('SELECT c.campaign_id, c.name, c.kind, c.status, c.starts_on, c.ends_on, c.budget, c.currency, c.expected_leads, c.utm_campaign, c.owner_member_id, m.display_name AS owner_name, c.description, c.created_at,
                                r.members, r.responded, r.leads, r.converted, r.deals, r.won, r.won_value, r.open_value, r.cost_per_lead, r.cost_per_won, count(*) OVER () AS total
                           FROM mcp_campaigns c LEFT JOIN members m ON m.id = c.owner_member_id LEFT JOIN LATERAL crm_campaign_results(c.campaign_id) r ON true
                          WHERE ' . implode(' AND ', $w) . " ORDER BY (c.status = 'active') DESC, (c.status = 'planned') DESC, c.starts_on DESC NULLS LAST, c.campaign_id DESC LIMIT " . CAMPAIGN_PAGE . ' OFFSET ' . ((max(1, $page) - 1) * CAMPAIGN_PAGE));
    $st->execute($a);
    $rows = $st->fetchAll();
    return ['rows' => $rows, 'total' => $rows === [] ? 0 : (int) $rows[0]['total']];
}

/** One campaign the caller may see, with its owner's name; null otherwise. */
function find_campaign(PDO $pdo, int $id): ?array
{
    return one_row($pdo, 'SELECT c.campaign_id, c.name, c.kind, c.status, c.starts_on, c.ends_on, c.budget, c.currency, c.expected_leads, c.utm_campaign, c.owner_member_id, m.display_name AS owner_name, c.description, c.created_at, c.updated_at
                            FROM mcp_campaigns c LEFT JOIN members m ON m.id = c.owner_member_id WHERE c.campaign_id = :id', ['id' => $id]);
}

/** The derived results of one campaign (crm_campaign_results): members, responded, leads, converted, deals, won, won_value, open_value, cost_per_lead, cost_per_won. */
function campaign_results(PDO $pdo, int $id): array
{
    return one_row($pdo, 'SELECT members, responded, leads, converted, deals, won, won_value, open_value, cost_per_lead, cost_per_won FROM crm_campaign_results(:id)', ['id' => $id])
        ?? ['members' => 0, 'responded' => 0, 'leads' => 0, 'converted' => 0, 'deals' => 0, 'won' => 0, 'won_value' => 0, 'open_value' => 0, 'cost_per_lead' => null, 'cost_per_won' => null];
}

/** How many members are in each status: [status => n]. */
function campaign_member_counts(PDO $pdo, int $id): array
{
    $st = $pdo->prepare('SELECT status, count(*) AS n FROM mcp_campaign_members WHERE campaign_id = :id GROUP BY status');
    $st->execute(['id' => $id]);
    $out = [];
    foreach ($st->fetchAll() as $r) { $out[$r['status']] = (int) $r['n']; }
    return $out;
}

/**
 * One page of a campaign's members with who they are (a lead by its label, a contact by name — a record the reader may not see is "a restricted record"): the status, when added and when it responded,
 * and for a contact whether email reaches them. $status narrows. ['rows', 'total'].
 */
function campaign_members(PDO $pdo, int $id, ?string $status = null, int $page = 1): array
{
    $st = $pdo->prepare('SELECT cm.campaign_member_id, cm.lead_id, cm.contact_id, cm.status, cm.added_by, cm.added_at, cm.responded_at, COALESCE(c.name, l.label) AS name, c.account_name, l.company,
                                (cm.contact_id IS NOT NULL AND c.contact_id IS NULL) OR (cm.lead_id IS NOT NULL AND l.lead_id IS NULL) AS hidden, c.email_opt_out,
                                CASE WHEN cm.contact_id IS NOT NULL THEN crm_email_allowed(cm.contact_id) END AS email_allowed, count(*) OVER () AS total
                           FROM mcp_campaign_members cm LEFT JOIN mcp_contacts c ON c.contact_id = cm.contact_id LEFT JOIN mcp_leads l ON l.lead_id = cm.lead_id
                          WHERE cm.campaign_id = :id AND (CAST(:s AS text) IS NULL OR cm.status = CAST(:s AS text))
                          ORDER BY cm.added_at DESC, cm.campaign_member_id DESC LIMIT ' . CAMPAIGN_MEMBER_PAGE . ' OFFSET ' . ((max(1, $page) - 1) * CAMPAIGN_MEMBER_PAGE));
    $st->execute(['id' => $id, 's' => $status]);
    $rows = $st->fetchAll();
    return ['rows' => $rows, 'total' => $rows === [] ? 0 : (int) $rows[0]['total']];
}

/** The leads and the deals that carry the campaign's id (what it produced), newest first, the wall applied: ['leads' => rows, 'deals' => rows]. */
function campaign_produced(PDO $pdo, int $id, int $limit = 10): array
{
    $l = $pdo->prepare('SELECT lead_id, label, company, status, created_at FROM mcp_leads WHERE campaign_id = :id ORDER BY created_at DESC, lead_id DESC LIMIT ' . $limit);
    $l->execute(['id' => $id]);
    $d = $pdo->prepare('SELECT deal_id, number, title, account_name, value, currency, status FROM mcp_deals WHERE campaign_id = :id ORDER BY created_at DESC, deal_id DESC LIMIT ' . $limit);
    $d->execute(['id' => $id]);
    return ['leads' => $l->fetchAll(), 'deals' => $d->fetchAll()];
}

/**
 * The campaign's email: what was queued for it and where each stands — [queued, sent, failed (failed or suppressed), the latest failures: [contact name, address, error]]. Read from the table
 * (a queued campaign message is nobody's draft and not in mcp_emails), but only for a campaign the caller sees.
 */
function campaign_email_batches(PDO $pdo, int $id): array
{
    $st = $pdo->prepare("SELECT status, count(*) AS n FROM outbound_email WHERE campaign_id = :id GROUP BY status");
    $st->execute(['id' => $id]);
    $by = [];
    foreach ($st->fetchAll() as $r) { $by[$r['status']] = (int) $r['n']; }
    $f = $pdo->prepare("SELECT o.id, o.to_address::text AS address, o.status, o.error, o.attempts, c.name FROM outbound_email o LEFT JOIN contacts c ON c.id = o.contact_id
                         WHERE o.campaign_id = :id AND o.status IN ('failed', 'suppressed') ORDER BY o.created_at DESC, o.id DESC LIMIT 5");
    $f->execute(['id' => $id]);
    return ['queued' => $by['queued'] ?? 0, 'sent' => $by['sent'] ?? 0, 'failed' => ($by['failed'] ?? 0) + ($by['suppressed'] ?? 0), 'latest_failures' => $f->fetchAll()];
}

/** The campaign's name for the picker's label, the chips and the notices. */
function campaign_label(PDO $pdo, int $id): ?string
{
    $v = one_value($pdo, 'SELECT name FROM mcp_campaigns WHERE campaign_id = :id', ['id' => $id]);
    return $v === null || $v === false ? null : (string) $v;
}

/** The campaign's dates in words: "Oct 1 – Oct 31", "from Oct 1", "until Oct 31", or ''. */
function campaign_dates_words(array $c): string
{
    $s = $c['starts_on'] === null ? null : format_date($c['starts_on']);
    $e = $c['ends_on'] === null ? null : format_date($c['ends_on']);
    return $s !== null && $e !== null ? $s . ' – ' . $e : ($s !== null ? 'from ' . $s : ($e !== null ? 'until ' . $e : ''));
}
