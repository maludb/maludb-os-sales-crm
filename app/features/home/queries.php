<?php
declare(strict_types=1);

/**
 * The home (screen `home`; sso-shell.md "Query functions"; design §9 "Today"): what a seller looks at first in the morning.
 *   today        my activities due today and the open ones overdue (crm_my_activities('today'), db/017), overdue first
 *   overdue_count how many of those are overdue
 *   closing      my open deals expected to close this month: count, value, weighted (in the base currency; others counted apart)
 *   flags        my open deals with no next step and my rotting deals (crm_deals_at_risk, db/015) — each a link to the board filtered
 *   replies      emails received since yesterday matched to my deals or my contacts (mcp_emails; a private one is the view's to hide)
 *   note         the Sales Assistant's morning note for today, when it wrote one (a notification of kind morning_note)
 *   first_run    no lead, no account, no deal the person may see — the home is one button
 *   manager      for a holder of reports.team: this month's forecast for the team and the leaderboard's top three (who won something); else null
 * Every read goes through a view or a function that applies the wall; nothing here re-implements a rule.
 */
function home_summary(PDO $pdo, int $memberId): array
{
    $first = !(bool) one_value($pdo, 'SELECT EXISTS (SELECT 1 FROM mcp_leads) OR EXISTS (SELECT 1 FROM mcp_accounts) OR EXISTS (SELECT 1 FROM mcp_deals)');
    $today = $pdo->query("SELECT activity_id, kind, subject, at, state, deal_id, contact_id, account_id, lead_id, record_name, priority
                            FROM crm_my_activities('today') ORDER BY (state = 'overdue') DESC, (state = 'done'), at")->fetchAll();
    $overdue = count(array_filter($today, static fn (array $r): bool => $r['state'] === 'overdue'));
    $cur = base_currency($pdo);
    $st = $pdo->prepare("SELECT count(*) AS count, COALESCE(sum(value) FILTER (WHERE currency = :cur), 0) AS value,
                                COALESCE(sum(weighted_value) FILTER (WHERE currency = :cur), 0) AS weighted, count(*) FILTER (WHERE currency <> :cur) AS other_currency
                           FROM mcp_deals WHERE owner_member_id = :me AND status = 'open'
                            AND expected_close_date >= date_trunc('month', current_date) AND expected_close_date < date_trunc('month', current_date) + interval '1 month'");
    $st->execute(['me' => $memberId, 'cur' => $cur]);
    $closing = $st->fetch() ?: ['count' => 0, 'value' => 0, 'weighted' => 0, 'other_currency' => 0];
    $st = $pdo->prepare("SELECT reason, count(DISTINCT deal_id) AS n FROM crm_deals_at_risk(:me) WHERE reason IN ('no_next_step', 'rotting') GROUP BY reason");
    $st->execute(['me' => $memberId]);
    $flags = array_map('intval', $st->fetchAll(PDO::FETCH_KEY_PAIR)) + ['no_next_step' => 0, 'rotting' => 0];
    $st = $pdo->prepare("SELECT e.email_id, e.at, e.from_name, e.from_address, e.subject, e.deal_id, e.matched_contact_ids,
                                (SELECT title FROM mcp_deals d WHERE d.deal_id = e.deal_id) AS deal_title
                           FROM mcp_emails e
                          WHERE e.direction = 'in' AND e.match_status = 'matched' AND e.at > now() - interval '1 day'
                            AND (EXISTS (SELECT 1 FROM deals d WHERE d.id = e.deal_id AND d.owner_member_id = :me)
                                 OR EXISTS (SELECT 1 FROM contacts c WHERE c.id = ANY (e.matched_contact_ids) AND c.owner_member_id = :me))
                          ORDER BY e.at DESC LIMIT 8");
    $st->execute(['me' => $memberId]);
    $replies = $st->fetchAll();
    $note = one_value($pdo, "SELECT body FROM mcp_notifications WHERE kind = 'morning_note' AND created_at >= date_trunc('day', now()) ORDER BY created_at DESC LIMIT 1");
    $manager = null;
    if (has_right('reports.team')) {
        $f = $pdo->query("SELECT COALESCE(sum(open_value), 0) AS open_value, COALESCE(sum(weighted), 0) AS weighted, COALESCE(sum(won_value), 0) AS won_value,
                                 COALESCE(sum(deals), 0) AS deals, sum(quota) AS quota
                            FROM crm_forecast(NULL, 1, 'seller') WHERE month = date_trunc('month', current_date)::date")->fetch();
        $leaders = $pdo->query('SELECT member_id, display_name, won_value, won_count, quota, attainment FROM crm_leaderboard() WHERE won_value > 0 ORDER BY won_value DESC, won_count DESC, display_name LIMIT 3')->fetchAll();
        $manager = ['forecast' => $f ?: ['open_value' => 0, 'weighted' => 0, 'won_value' => 0, 'deals' => 0, 'quota' => null], 'leaders' => $leaders];
    }
    return [
        'today' => $today,
        'overdue_count' => $overdue,
        'closing' => ['count' => (int) $closing['count'], 'value' => (float) $closing['value'], 'weighted' => (float) $closing['weighted'], 'other_currency' => (int) $closing['other_currency']],
        'flags' => $flags,
        'replies' => $replies,
        'note' => $note === false || $note === null ? null : (string) $note,
        'first_run' => $first,
        'manager' => $manager,
        'currency' => $cur,
        'board' => pipeline_board_url(),
        'may' => ['lead' => has_right('leads.write'), 'import' => has_right('imports.own'), 'log' => has_right('activities.write'), 'admin' => nav_has_right(ADMIN_RIGHTS)],
    ];
}

/** The screen an activity on Today opens: its record when it has one (the deal, then the contact, the account, the lead), else the activity. */
function today_record_url(array $r): string
{
    foreach (['deal_id' => '/deals/', 'contact_id' => '/contacts/', 'account_id' => '/accounts/', 'lead_id' => '/leads/'] as $k => $p) {
        if (($r[$k] ?? null) !== null) {
            return $p . (int) $r[$k];
        }
    }
    return '/activities/' . (int) $r['activity_id'];
}

/** A kind's icon (the six kinds of activities, db/010). */
function activity_kind_icon(string $kind): string
{
    return ['call' => 'feather-phone', 'email' => 'feather-mail', 'meeting' => 'feather-users', 'task' => 'feather-check-square', 'deadline' => 'feather-flag', 'lunch' => 'feather-coffee'][$kind] ?? 'feather-circle';
}

/** The JSON shape of the home (whitelisted). */
function present_home(array $s, string $tz): array
{
    return [
        'first_run' => $s['first_run'],
        'today' => array_map(static fn (array $r): array => ['activity_id' => (int) $r['activity_id'], 'kind' => $r['kind'], 'subject' => $r['subject'], 'at' => json_ts($r['at']),
            'state' => $r['state'], 'deal_id' => $r['deal_id'] !== null ? (int) $r['deal_id'] : null, 'contact_id' => $r['contact_id'] !== null ? (int) $r['contact_id'] : null,
            'account_id' => $r['account_id'] !== null ? (int) $r['account_id'] : null, 'lead_id' => $r['lead_id'] !== null ? (int) $r['lead_id'] : null,
            'record_name' => $r['record_name'], 'priority' => $r['priority']], $s['today']),
        'overdue_count' => $s['overdue_count'],
        'closing' => $s['closing'] + ['currency' => $s['currency']],
        'flags' => $s['flags'],
        'replies' => array_map(static fn (array $e): array => ['email_id' => (int) $e['email_id'], 'at' => json_ts($e['at']), 'from_name' => $e['from_name'], 'from_address' => $e['from_address'],
            'subject' => $e['subject'], 'deal_id' => $e['deal_id'] !== null ? (int) $e['deal_id'] : null, 'deal_title' => $e['deal_title']], $s['replies']),
        'note' => $s['note'],
        'manager' => $s['manager'] === null ? null : [
            'forecast' => ['open_value' => (float) $s['manager']['forecast']['open_value'], 'weighted' => (float) $s['manager']['forecast']['weighted'],
                           'won_value' => (float) $s['manager']['forecast']['won_value'], 'deals' => (int) $s['manager']['forecast']['deals'],
                           'quota' => $s['manager']['forecast']['quota'] === null ? null : (float) $s['manager']['forecast']['quota']],
            'leaders' => array_map(static fn (array $l): array => ['member_id' => (int) $l['member_id'], 'display_name' => $l['display_name'], 'won_value' => (float) $l['won_value'],
                'won_count' => (int) $l['won_count'], 'quota' => $l['quota'] === null ? null : (float) $l['quota'], 'attainment' => $l['attainment'] === null ? null : (float) $l['attainment']], $s['manager']['leaders']),
        ],
        'board' => $s['board'],
        'may' => $s['may'],
        'timezone' => $tz,
    ];
}
