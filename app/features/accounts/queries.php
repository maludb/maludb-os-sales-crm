<?php
declare(strict_types=1);

/**
 * Accounts (screens `account-list`, `account-view`; the records server's find_accounts / get_account / account_contacts / account_deals /
 * accounts_untouched read the same views). Every read goes through mcp_accounts and its siblings: the wall is theirs.
 */
const ACCOUNT_PAGE = 24;
const ACCOUNT_KINDS = ['prospect' => 'Prospect', 'customer' => 'Customer', 'partner' => 'Partner', 'former' => 'Former customer', 'other' => 'Other'];
const ACCOUNT_KIND_BADGE = ['prospect' => 'secondary', 'customer' => 'success', 'partner' => 'info', 'former' => 'dark', 'other' => 'light'];
const EMPLOYEE_BANDS = ['1-10', '11-50', '51-200', '201-500', '501-1000', '1001-5000', '5000+'];

/** A saved view's filters for a kind (mcp_saved_views; slice 3 saves them): the view's filters under the request's own. */
function saved_view_filters(PDO $pdo, string $kind, ?int $viewId): array
{
    if ($viewId === null) {
        return [];
    }
    $st = $pdo->prepare('SELECT filters FROM mcp_saved_views WHERE saved_view_id = :id AND record_kind = :k');
    $st->execute(['id' => $viewId, 'k' => $kind]);
    $f = $st->fetchColumn();
    return $f === false ? [] : (json_decode((string) $f, true) ?: []);
}

/** The saved views of a kind the caller may use (system, global, their own). */
function saved_views_of(PDO $pdo, string $kind): array
{
    $st = $pdo->prepare('SELECT saved_view_id, name, is_system FROM mcp_saved_views WHERE record_kind = :k ORDER BY sort_order, name');
    $st->execute(['k' => $kind]);
    return $st->fetchAll();
}

/** The list's filters from the request (screen params), a saved view's beneath them. */
function account_filters_from_request(PDO $pdo): array
{
    $view = request_integer('view');
    $f = saved_view_filters($pdo, 'account', $view);
    $pick = static fn (string $k) => ($v = request_string($k)) !== '' ? $v : ($f[$k] ?? null);
    $kind = $pick('kind');
    return [
        'q' => mb_substr(request_string('q'), 0, 120),
        'kind' => is_string($kind) && isset(ACCOUNT_KINDS[$kind]) ? $kind : null,
        'owner' => (($o = $pick('owner')) === 'me' || (is_string($o) && ctype_digit($o)) || is_int($o)) ? (string) $o : null,
        'industry' => ($i = $pick('industry')) !== null ? mb_substr((string) $i, 0, 80) : null,
        'country' => ($c = $pick('country')) !== null && preg_match('/^[A-Za-z]{2}$/', (string) $c) ? strtoupper((string) $c) : null,
        'tag' => ($t = $pick('tag')) !== null ? mb_substr((string) $t, 0, 60) : null,
        'untouched_days' => ($u = $pick('untouched_days')) !== null && ctype_digit((string) $u) ? min(3650, (int) $u) : null,
        'view' => $view,
    ];
}

/** One page of accounts: ['rows', 'total']. Name order; a text query ranks by likeness (name, domain, trigram). */
function find_accounts(PDO $pdo, array $filters, int $page): array
{
    $w = [];
    $a = [];
    if (($filters['q'] ?? '') !== '') {
        $w[] = '(a.name ILIKE :ql OR a.domain ILIKE :ql OR a.name % :q OR a.legal_name ILIKE :ql)';
        $a['q'] = $filters['q'];
        $a['ql'] = '%' . str_replace(['%', '_'], ['\\%', '\\_'], $filters['q']) . '%';
    }
    if (($filters['kind'] ?? null) !== null) { $w[] = 'a.kind = :kind'; $a['kind'] = $filters['kind']; }
    if (($filters['owner'] ?? null) !== null) { $w[] = 'a.owner_member_id = :owner'; $a['owner'] = $filters['owner'] === 'me' ? (int) current_member_id() : (int) $filters['owner']; }
    if (($filters['industry'] ?? null) !== null) { $w[] = 'a.industry ILIKE :ind'; $a['ind'] = $filters['industry']; }
    if (($filters['country'] ?? null) !== null) { $w[] = 'a.country = :cty'; $a['cty'] = $filters['country']; }
    if (($filters['tag'] ?? null) !== null) { $w[] = 'EXISTS (SELECT 1 FROM record_tags rt JOIN tags t ON t.id = rt.tag_id WHERE rt.record_type = \'account\' AND rt.record_id = a.account_id AND (t.slug = :tag OR lower(t.label) = lower(:tag)))'; $a['tag'] = $filters['tag']; }
    if (($filters['untouched_days'] ?? null) !== null) { $w[] = 'COALESCE(a.last_touched_at, a.created_at) < now() - make_interval(days => :ud)'; $a['ud'] = (int) $filters['untouched_days']; }
    $order = ($filters['q'] ?? '') !== '' ? 'similarity(a.name, :q) DESC, a.name' : 'a.name';
    $sql = 'SELECT a.account_id, a.name, a.domain, a.kind, a.industry, a.city, a.country, a.owner_member_id, m.display_name AS owner_name, a.last_touched_at, a.next_activity_at, a.next_colour,
                   a.open_deals_count, a.open_deals_value, a.contacts_count, a.restricted, a.tags, count(*) OVER () AS total
              FROM mcp_accounts a LEFT JOIN members m ON m.id = a.owner_member_id' . ($w ? ' WHERE ' . implode(' AND ', $w) : '') . " ORDER BY $order LIMIT " . ACCOUNT_PAGE . ' OFFSET ' . ((max(1, $page) - 1) * ACCOUNT_PAGE);
    $st = $pdo->prepare($sql);
    $st->execute($a);
    $rows = $st->fetchAll();
    return ['rows' => $rows, 'total' => $rows === [] ? 0 : (int) $rows[0]['total']];
}

/** One account the caller may see, with the names around it (owner, parent, source, the restriction's member and team). */
function find_account(PDO $pdo, int $id): ?array
{
    $st = $pdo->prepare('SELECT a.*, m.display_name AS owner_name, p.name AS parent_name, s.name AS source_name,
                                b.restricted_to_member_id, b.restricted_to_department_id, rm.display_name AS restricted_member_name, rd.name AS restricted_department_name, b.kind_set_by_hand
                           FROM mcp_accounts a JOIN accounts b ON b.id = a.account_id LEFT JOIN members m ON m.id = a.owner_member_id LEFT JOIN mcp_accounts p ON p.account_id = a.parent_account_id
                           LEFT JOIN lead_sources s ON s.id = a.source_id LEFT JOIN members rm ON rm.id = b.restricted_to_member_id LEFT JOIN departments rd ON rd.id = b.restricted_to_department_id
                          WHERE a.account_id = :id');
    $st->execute(['id' => $id]);
    $r = $st->fetch();
    return $r === false ? null : $r;
}

/** The account at a glance: counts, the primary contact, the next step (the earliest open activity). */
function account_summary(PDO $pdo, int $id): array
{
    $st = $pdo->prepare('SELECT contact_id, name, title, email FROM mcp_contacts WHERE account_id = :id AND is_primary LIMIT 1');
    $st->execute(['id' => $id]);
    $primary = $st->fetch() ?: null;
    $st = $pdo->prepare("SELECT count(*) FILTER (WHERE status = 'open') AS open_deals, count(*) FILTER (WHERE status = 'won') AS won_deals, count(*) FILTER (WHERE status = 'lost') AS lost_deals FROM mcp_deals WHERE account_id = :id");
    $st->execute(['id' => $id]);
    $deals = $st->fetch();
    return ['primary' => $primary, 'deals' => array_map('intval', $deals), 'next' => next_step_of($pdo, 'account', $id),
            'contacts' => (int) one_value($pdo, 'SELECT count(*) FROM mcp_contacts WHERE account_id = :id', ['id' => $id])];
}

/** The next step of a record: its earliest open activity the caller may see ([activity_id, kind, subject, at, state]) or null. */
function next_step_of(PDO $pdo, string $kind, int $id): ?array
{
    $col = ['account' => 'account_id', 'contact' => 'contact_id', 'deal' => 'deal_id', 'lead' => 'lead_id'][$kind];
    $st = $pdo->prepare("SELECT activity_id, kind, subject, at, state, owner_member_id FROM mcp_activities WHERE $col = :id AND done_at IS NULL ORDER BY at NULLS LAST, activity_id LIMIT 1");
    $st->execute(['id' => $id]);
    $r = $st->fetch();
    return $r === false ? null : $r;
}

/** Who we know at the company, primary first. */
function account_contacts(PDO $pdo, int $id, int $limit = 100): array
{
    $st = $pdo->prepare('SELECT contact_id, name, title, email, phone, mobile, role, is_primary, lifecycle, email_opt_out, sms_opt_out, next_colour, last_touched_at, owner_member_id FROM mcp_contacts
                          WHERE account_id = :id ORDER BY is_primary DESC, name LIMIT :l');
    $st->bindValue('id', $id, PDO::PARAM_INT);
    $st->bindValue('l', $limit, PDO::PARAM_INT);
    $st->execute();
    return $st->fetchAll();
}

/** The account's deals: open first (by close date), then won and lost (most recent first). */
function account_deals(PDO $pdo, int $id, int $limit = 100): array
{
    $st = $pdo->prepare("SELECT deal_id, number, title, stage_name, status, value, currency, weighted_value, expected_close_date, won_at, lost_at, next_colour, owner_member_id FROM mcp_deals
                          WHERE account_id = :id ORDER BY (status = 'open') DESC, CASE WHEN status = 'open' THEN expected_close_date END NULLS LAST, COALESCE(won_at, lost_at) DESC NULLS LAST LIMIT :l");
    $st->bindValue('id', $id, PDO::PARAM_INT);
    $st->bindValue('l', $limit, PDO::PARAM_INT);
    $st->execute();
    return $st->fetchAll();
}

/** A record's activities as an agenda: the open ones by when (overdue first), then the last done. [open, done] */
function record_activities(PDO $pdo, string $kind, int $id, int $done = 20): array
{
    $col = ['account' => 'account_id', 'contact' => 'contact_id', 'deal' => 'deal_id', 'lead' => 'lead_id'][$kind];
    $st = $pdo->prepare("SELECT activity_id, kind, subject, at, state, done_at, outcome, owner_member_id FROM mcp_activities WHERE $col = :id AND done_at IS NULL ORDER BY at NULLS LAST LIMIT 50");
    $st->execute(['id' => $id]);
    $open = $st->fetchAll();
    $st = $pdo->prepare("SELECT activity_id, kind, subject, at, state, done_at, outcome, owner_member_id FROM mcp_activities WHERE $col = :id AND done_at IS NOT NULL ORDER BY done_at DESC LIMIT :l");
    $st->bindValue('id', $id, PDO::PARAM_INT);
    $st->bindValue('l', $done, PDO::PARAM_INT);
    $st->execute();
    return ['open' => $open, 'done' => $st->fetchAll()];
}

/** The emails of an account (to and from any of its contacts, or on its deals) or of a contact: subject, direction, date — never the body here. */
function record_emails(PDO $pdo, string $kind, int $id, int $limit = 50): array
{
    $cond = $kind === 'account'
        ? '(e.deal_id IN (SELECT deal_id FROM mcp_deals WHERE account_id = :id) OR e.matched_contact_ids && ARRAY(SELECT contact_id FROM mcp_contacts WHERE account_id = :id)::bigint[])'
        : ':id = ANY (e.matched_contact_ids)';
    $st = $pdo->prepare("SELECT e.direction, e.email_id, e.at, e.from_name, e.from_address, e.subject, e.deal_id, e.is_private, e.status FROM mcp_emails e
                          WHERE e.match_status = 'matched' AND $cond ORDER BY e.at DESC LIMIT :l");
    $st->bindValue('id', $id, PDO::PARAM_INT);
    $st->bindValue('l', $limit, PDO::PARAM_INT);
    $st->execute();
    return $st->fetchAll();
}

/** The live accounts a parent or a contact's account can be, for the picker (excluding $except and its own descendants is the database's CHECK; a cycle is refused there). */
function accounts_for_pick(PDO $pdo, string $q, array $params, int $page): array
{
    $size = 20;
    $except = isset($params['except']) ? (int) $params['except'] : null;
    $st = $pdo->prepare("SELECT account_id, name, domain, city, kind, count(*) OVER () AS total FROM mcp_accounts
                          WHERE (:q = '' OR name ILIKE '%' || :q || '%' OR domain ILIKE '%' || :q || '%' OR name % :q) AND account_id IS DISTINCT FROM :x
                          ORDER BY CASE WHEN :q = '' THEN 0 ELSE 1 - similarity(name, :q) END, name LIMIT :l OFFSET :o");
    $st->bindValue('q', $q);
    $st->bindValue('x', $except, $except === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
    $st->bindValue('l', $size, PDO::PARAM_INT);
    $st->bindValue('o', (max(1, $page) - 1) * $size, PDO::PARAM_INT);
    $st->execute();
    $rows = $st->fetchAll();
    $total = $rows === [] ? 0 : (int) $rows[0]['total'];
    return ['rows' => array_map(static fn (array $r): array => ['id' => (int) $r['account_id'], 'label' => $r['name'], 'detail' => trim(($r['domain'] ?? '') . ($r['city'] ? ' · ' . $r['city'] : ''), ' ·'),
            'badge' => ACCOUNT_KINDS[$r['kind']] ?? $r['kind'], 'color' => ACCOUNT_KIND_BADGE[$r['kind']] ?? 'secondary'], $rows),
        'total' => $total, 'page' => max(1, $page), 'page_size' => $size, 'pages' => max(1, (int) ceil($total / $size))];
}

/** An account's name for the picker's label (only one the caller may see). */
function account_label(PDO $pdo, int $id): ?string
{
    $v = one_value($pdo, 'SELECT name FROM mcp_accounts WHERE account_id = :id', ['id' => $id]);
    return $v === null || $v === false ? null : (string) $v;
}
