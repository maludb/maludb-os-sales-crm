<?php
declare(strict_types=1);

/**
 * Contacts (screens `contact-list`, `contact-view`; the records server's find_contacts / get_contact / contacts_untouched read the same views). The
 * wall is mcp_contacts'. Lifecycle derives (db/008, db/009); every address is in contact_emails (the mailbox matches on them).
 */
const CONTACT_PAGE = 24;
const CONTACT_LIFECYCLES = ['lead' => 'Lead', 'prospect' => 'Prospect', 'customer' => 'Customer', 'churned' => 'Churned'];
const CONTACT_LIFECYCLE_BADGE = ['lead' => 'secondary', 'prospect' => 'primary', 'customer' => 'success', 'churned' => 'dark'];
const CONTACT_ROLES = ['decision_maker' => 'Decision maker', 'champion' => 'Champion', 'influencer' => 'Influencer', 'user' => 'User', 'finance' => 'Finance', 'procurement' => 'Procurement', 'other' => 'Other'];
const CONTACT_CHANNELS = ['email' => 'Email', 'phone' => 'Phone', 'sms' => 'Text message'];

function contact_filters_from_request(PDO $pdo): array
{
    $view = request_integer('view');
    $f = saved_view_filters($pdo, 'contact', $view);
    $pick = static fn (string $k) => ($v = request_string($k)) !== '' ? $v : ($f[$k] ?? null);
    $lc = $pick('lifecycle');
    $role = $pick('role');
    $opt = $pick('opt_out');
    $acc = $pick('account');
    return [
        'q' => mb_substr(request_string('q'), 0, 120),
        'account' => $acc !== null && ctype_digit((string) $acc) ? (int) $acc : null,
        'lifecycle' => is_string($lc) && isset(CONTACT_LIFECYCLES[$lc]) ? $lc : null,
        'owner' => (($o = $pick('owner')) === 'me' || (is_string($o) && ctype_digit($o))) ? (string) $o : null,
        'role' => is_string($role) && isset(CONTACT_ROLES[$role]) ? $role : null,
        'opt_out' => in_array($opt, ['email', 'sms'], true) ? $opt : null,
        'tag' => ($t = $pick('tag')) !== null ? mb_substr((string) $t, 0, 60) : null,
        'untouched_days' => ($u = $pick('untouched_days')) !== null && ctype_digit((string) $u) ? min(3650, (int) $u) : null,
        'view' => $view,
    ];
}

/** One page of contacts: ['rows', 'total']. Name order; a text query matches name, any address, the account's name, and ranks by likeness. */
function find_contacts(PDO $pdo, array $filters, int $page): array
{
    $w = [];
    $a = [];
    if (($filters['q'] ?? '') !== '') {
        $w[] = '(c.name ILIKE :ql OR c.email ILIKE :ql OR c.account_name ILIKE :ql OR c.name % :q OR EXISTS (SELECT 1 FROM contact_emails ce WHERE ce.contact_id = c.contact_id AND ce.address ILIKE :ql))';
        $a['q'] = $filters['q'];
        $a['ql'] = '%' . str_replace(['%', '_'], ['\\%', '\\_'], $filters['q']) . '%';
    }
    if (($filters['account'] ?? null) !== null) { $w[] = 'c.account_id = :acc'; $a['acc'] = (int) $filters['account']; }
    if (($filters['lifecycle'] ?? null) !== null) { $w[] = 'c.lifecycle = :lc'; $a['lc'] = $filters['lifecycle']; }
    if (($filters['owner'] ?? null) !== null) { $w[] = 'c.owner_member_id = :owner'; $a['owner'] = $filters['owner'] === 'me' ? (int) current_member_id() : (int) $filters['owner']; }
    if (($filters['role'] ?? null) !== null) { $w[] = 'c.role = :role'; $a['role'] = $filters['role']; }
    if (($filters['opt_out'] ?? null) === 'email') { $w[] = 'c.email_opt_out'; }
    if (($filters['opt_out'] ?? null) === 'sms') { $w[] = 'c.sms_opt_out'; }
    if (($filters['tag'] ?? null) !== null) { $w[] = 'EXISTS (SELECT 1 FROM record_tags rt JOIN tags t ON t.id = rt.tag_id WHERE rt.record_type = \'contact\' AND rt.record_id = c.contact_id AND (t.slug = :tag OR lower(t.label) = lower(:tag)))'; $a['tag'] = $filters['tag']; }
    if (($filters['untouched_days'] ?? null) !== null) { $w[] = 'COALESCE(c.last_touched_at, c.created_at) < now() - make_interval(days => :ud)'; $a['ud'] = (int) $filters['untouched_days']; }
    if (($filters['in_list'] ?? null) !== null) { $w[] = 'c.contact_id IN (SELECT record_id FROM list_members WHERE list_id = :inlist)'; $a['inlist'] = (int) $filters['in_list']; }       // slice 6: the members of a static list
    $order = ($filters['q'] ?? '') !== '' ? 'similarity(c.name, :q) DESC, c.name, c.contact_id' : 'c.last_name NULLS LAST, c.first_name, c.contact_id';
    $sql = 'SELECT c.contact_id, c.name, c.title, c.email, c.phone, c.mobile, c.account_id, c.account_name, c.lifecycle, c.role, c.is_primary, c.owner_member_id, m.display_name AS owner_name,
                   c.last_touched_at, c.next_activity_at, c.next_colour, c.email_opt_out, c.sms_opt_out, c.do_not_call, c.open_deals_count, c.restricted, c.tags, count(*) OVER () AS total
              FROM mcp_contacts c LEFT JOIN members m ON m.id = c.owner_member_id' . ($w ? ' WHERE ' . implode(' AND ', $w) : '') . " ORDER BY $order LIMIT " . CONTACT_PAGE . ' OFFSET ' . ((max(1, $page) - 1) * CONTACT_PAGE);
    $st = $pdo->prepare($sql);
    $st->execute($a);
    $rows = $st->fetchAll();
    return ['rows' => $rows, 'total' => $rows === [] ? 0 : (int) $rows[0]['total']];
}

/** One contact the caller may see, with the names around it. */
function find_contact(PDO $pdo, int $id): ?array
{
    $st = $pdo->prepare('SELECT c.*, m.display_name AS owner_name, s.name AS source_name, b.opt_out_reason, b.sms_opt_out_at,
                                b.restricted_to_member_id, b.restricted_to_department_id, rm.display_name AS restricted_member_name, rd.name AS restricted_department_name
                           FROM mcp_contacts c JOIN contacts b ON b.id = c.contact_id LEFT JOIN members m ON m.id = c.owner_member_id LEFT JOIN lead_sources s ON s.id = c.source_id
                           LEFT JOIN members rm ON rm.id = b.restricted_to_member_id LEFT JOIN departments rd ON rd.id = b.restricted_to_department_id
                          WHERE c.contact_id = :id');
    $st->execute(['id' => $id]);
    $r = $st->fetch();
    return $r === false ? null : $r;
}

/** Every address the contact uses (the mailbox matches on these), the primary first. */
function contact_addresses(PDO $pdo, int $id): array
{
    $st = $pdo->prepare('SELECT contact_email_id, address, is_primary, verified_at FROM mcp_contact_emails WHERE contact_id = :id ORDER BY is_primary DESC, address');
    $st->execute(['id' => $id]);
    return $st->fetchAll();
}

/** The contact's deals with their role on each (primary contact or a deal contact). */
function contact_deals(PDO $pdo, int $id): array
{
    $st = $pdo->prepare("SELECT d.deal_id, d.number, d.title, d.stage_name, d.status, d.value, d.currency, d.expected_close_date, d.next_colour,
                                COALESCE(dc.role, CASE WHEN d.primary_contact_id = :id THEN 'primary' END) AS role
                           FROM mcp_deals d LEFT JOIN mcp_deal_contacts dc ON dc.deal_id = d.deal_id AND dc.contact_id = :id
                          WHERE d.primary_contact_id = :id OR dc.contact_id IS NOT NULL ORDER BY (d.status = 'open') DESC, d.expected_close_date NULLS LAST");
    $st->execute(['id' => $id]);
    return $st->fetchAll();
}

/** The account a new contact's address places them at, by its domain (never a free-mail domain): an id the caller may see, or null. */
function account_by_email_domain(PDO $pdo, ?string $email): ?int
{
    $dom = strtolower((string) substr((string) strrchr((string) $email, '@'), 1));
    if ($dom === '' || in_array($dom, FREE_MAIL_DOMAINS, true)) {
        return null;
    }
    $v = one_value($pdo, 'SELECT account_id FROM mcp_accounts WHERE domain = :d ORDER BY account_id LIMIT 1', ['d' => $dom]);
    return $v === null || $v === false ? null : (int) $v;
}

/** The contacts a form can pick (an `account` param narrows to its people). */
function contacts_for_pick(PDO $pdo, string $q, array $params, int $page): array
{
    $size = 20;
    $acc = isset($params['account']) ? (int) $params['account'] : null;
    $st = $pdo->prepare("SELECT contact_id, name, email, title, account_name, count(*) OVER () AS total FROM mcp_contacts
                          WHERE (:q = '' OR name ILIKE '%' || :q || '%' OR email ILIKE '%' || :q || '%' OR name % :q) AND (CAST(:a AS bigint) IS NULL OR account_id = CAST(:a AS bigint))
                          ORDER BY CASE WHEN :q = '' THEN 0 ELSE 1 - similarity(name, :q) END, name LIMIT :l OFFSET :o");
    $st->bindValue('q', $q);
    $st->bindValue('a', $acc, $acc === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
    $st->bindValue('l', $size, PDO::PARAM_INT);
    $st->bindValue('o', (max(1, $page) - 1) * $size, PDO::PARAM_INT);
    $st->execute();
    $rows = $st->fetchAll();
    $total = $rows === [] ? 0 : (int) $rows[0]['total'];
    return ['rows' => array_map(static fn (array $r): array => ['id' => (int) $r['contact_id'], 'label' => $r['name'] !== '' ? $r['name'] : (string) $r['email'],
            'detail' => trim(($r['title'] ?? '') . ($r['account_name'] ? ' · ' . $r['account_name'] : ''), ' ·') ?: (string) $r['email']], $rows),
        'total' => $total, 'page' => max(1, $page), 'page_size' => $size, 'pages' => max(1, (int) ceil($total / $size))];
}

function contact_label(PDO $pdo, int $id): ?string
{
    $v = one_value($pdo, "SELECT COALESCE(NULLIF(name, ''), email) FROM mcp_contacts WHERE contact_id = :id", ['id' => $id]);
    return $v === null || $v === false ? null : (string) $v;
}
