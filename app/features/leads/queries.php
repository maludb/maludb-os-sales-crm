<?php
declare(strict_types=1);

/**
 * Leads (screens `lead-list`, `lead-inbox`, `lead-view`, `lead-convert`; the records server's find_leads / get_lead / leads_inbox read the same
 * views). A lead is one row until it is converted into the relationship's three records (leads-and-conversion.md §1). The wall is mcp_leads'.
 */
const LEAD_PAGE = 24;
const LEAD_STATUSES = ['new' => 'New', 'working' => 'Working', 'qualified' => 'Converted', 'unqualified' => 'Unqualified'];
const LEAD_STATUS_BADGE = ['new' => 'primary', 'working' => 'warning', 'qualified' => 'success', 'unqualified' => 'dark'];

function lead_filters_from_request(PDO $pdo): array
{
    $view = request_integer('view');
    $f = saved_view_filters($pdo, 'lead', $view);
    $pick = static fn (string $k) => ($v = request_string($k)) !== '' ? $v : ($f[$k] ?? null);
    $status = $pick('status');
    if (is_array($status)) { $status = $status[0] ?? null; }
    $src = $pick('source');
    $camp = $pick('campaign');
    return [
        'q' => mb_substr(request_string('q'), 0, 120),
        'status' => is_string($status) && isset(LEAD_STATUSES[$status]) ? $status : null,
        'owner' => (($o = $pick('owner')) === 'me' || $o === 'none' || (is_string($o) && ctype_digit($o))) ? (string) $o : null,
        'source' => $src !== null && ctype_digit((string) $src) ? (int) $src : null,
        'campaign' => $camp !== null && ctype_digit((string) $camp) ? (int) $camp : null,
        'tag' => ($t = $pick('tag')) !== null ? mb_substr((string) $t, 0, 60) : null,
        'untouched_days' => ($u = $pick('untouched_days')) !== null && ctype_digit((string) $u) ? min(3650, (int) $u) : null,
        'view' => $view,
    ];
}

/** One page of leads (never the proposed ones — they are the inbox's): ['rows', 'total']. Newest first; a text query ranks by likeness. */
function find_leads(PDO $pdo, array $filters, int $page): array
{
    $w = ['NOT l.is_proposed'];
    $a = [];
    if (($filters['q'] ?? '') !== '') {
        $w[] = '(l.label ILIKE :ql OR l.email ILIKE :ql OR l.company ILIKE :ql OR l.label % :q)';
        $a['q'] = $filters['q'];
        $a['ql'] = '%' . str_replace(['%', '_'], ['\\%', '\\_'], $filters['q']) . '%';
    }
    if (($filters['status'] ?? null) !== null) { $w[] = 'l.status = :st'; $a['st'] = $filters['status']; }
    elseif (($filters['q'] ?? '') === '') { $w[] = "l.status IN ('new', 'working')"; }
    if (($filters['owner'] ?? null) === 'none') { $w[] = 'l.owner_member_id IS NULL'; }
    elseif (($filters['owner'] ?? null) !== null) { $w[] = 'l.owner_member_id = :owner'; $a['owner'] = $filters['owner'] === 'me' ? (int) current_member_id() : (int) $filters['owner']; }
    if (($filters['source'] ?? null) !== null) { $w[] = 'l.source_id = :src'; $a['src'] = (int) $filters['source']; }
    if (($filters['campaign'] ?? null) !== null) { $w[] = 'l.campaign_id = :camp'; $a['camp'] = (int) $filters['campaign']; }
    if (($filters['tag'] ?? null) !== null) { $w[] = 'EXISTS (SELECT 1 FROM record_tags rt JOIN tags t ON t.id = rt.tag_id WHERE rt.record_type = \'lead\' AND rt.record_id = l.lead_id AND (t.slug = :tag OR lower(t.label) = lower(:tag)))'; $a['tag'] = $filters['tag']; }
    if (($filters['untouched_days'] ?? null) !== null) { $w[] = 'COALESCE(l.last_touched_at, l.created_at) < now() - make_interval(days => :ud)'; $a['ud'] = (int) $filters['untouched_days']; }
    $order = ($filters['q'] ?? '') !== '' ? 'similarity(l.label, :q) DESC, l.created_at DESC' : 'l.created_at DESC, l.lead_id DESC';
    $sql = 'SELECT l.lead_id, l.label, l.first_name, l.last_name, l.company, l.title, l.email, l.status, l.score, l.source, l.source_id, l.owner_member_id, m.display_name AS owner_name,
                   l.last_touched_at, l.next_activity_at, l.next_colour, l.restricted, l.tags, l.created_at, l.converted_at, count(*) OVER () AS total
              FROM mcp_leads l LEFT JOIN members m ON m.id = l.owner_member_id WHERE ' . implode(' AND ', $w) . " ORDER BY $order LIMIT " . LEAD_PAGE . ' OFFSET ' . ((max(1, $page) - 1) * LEAD_PAGE);
    $st = $pdo->prepare($sql);
    $st->execute($a);
    $rows = $st->fetchAll();
    return ['rows' => $rows, 'total' => $rows === [] ? 0 : (int) $rows[0]['total']];
}

/** One lead the caller may see, with the names around it and the three records it became. */
function find_lead(PDO $pdo, int $id): ?array
{
    $st = $pdo->prepare('SELECT l.*, b.unqualified_note, b.proposed_from_email_id, b.restricted_to_member_id, b.restricted_to_department_id, b.converted_by, b.address,
                                m.display_name AS owner_name, r.label AS unqualified_reason, c.name AS campaign_name,
                                ca.name AS converted_account_name, cc.name AS converted_contact_name, cd.title AS converted_deal_title, cd.number AS converted_deal_number,
                                rm.display_name AS restricted_member_name, rd.name AS restricted_department_name
                           FROM mcp_leads l JOIN leads b ON b.id = l.lead_id LEFT JOIN members m ON m.id = l.owner_member_id LEFT JOIN lost_reasons r ON r.id = l.unqualified_reason_id
                           LEFT JOIN campaigns c ON c.id = l.campaign_id LEFT JOIN mcp_accounts ca ON ca.account_id = l.converted_account_id
                           LEFT JOIN mcp_contacts cc ON cc.contact_id = l.converted_contact_id LEFT JOIN mcp_deals cd ON cd.deal_id = l.converted_deal_id
                           LEFT JOIN members rm ON rm.id = b.restricted_to_member_id LEFT JOIN departments rd ON rd.id = b.restricted_to_department_id
                          WHERE l.lead_id = :id');
    $st->execute(['id' => $id]);
    $r = $st->fetch();
    return $r === false ? null : $r;
}

/** The inbox: proposed leads (from the mailbox and the form, newest first) and the unowned ones in the book. */
function inbox(PDO $pdo): array
{
    $proposed = $pdo->query("SELECT l.lead_id, l.label, l.email, l.company, l.message, l.source, l.created_at, b.proposed_from_email_id,
                                    (SELECT e.subject FROM mcp_emails e WHERE e.direction = 'in' AND e.email_id = b.proposed_from_email_id) AS email_subject
                               FROM mcp_leads l JOIN leads b ON b.id = l.lead_id WHERE l.is_proposed AND l.converted_at IS NULL ORDER BY l.created_at DESC LIMIT 200")->fetchAll();
    $unowned = $pdo->query("SELECT lead_id, label, email, company, source, status, created_at FROM mcp_leads
                             WHERE NOT is_proposed AND owner_member_id IS NULL AND status IN ('new', 'working') ORDER BY created_at DESC LIMIT 200")->fetchAll();
    return ['proposed' => $proposed, 'unowned' => $unowned];
}

/**
 * What the conversion will find and make (leads-and-conversion.md §3), as crm_lead_convert() will decide it: the account by the address's domain
 * (never free mail), then by the company's exact name; the contact by any address; the deal's defaults (the default pipeline, a title, its currency).
 * Only what the caller may see is named "Found"; the function decides the same way, so a found record the caller cannot see is never offered.
 */
function convert_preview(PDO $pdo, int $leadId): array
{
    $l = q1($pdo, 'SELECT first_name, last_name, company, email::text AS email, title, phone FROM leads WHERE id = :id', ['id' => $leadId]);
    $dom = $l['email'] ? strtolower((string) substr((string) strrchr((string) $l['email'], '@'), 1)) : null;
    if ($dom !== null && in_array($dom, FREE_MAIL_DOMAINS, true)) { $dom = null; }
    $account = null;
    if ($dom !== null) {
        $account = q1($pdo, 'SELECT account_id, name, domain FROM mcp_accounts WHERE domain = :d LIMIT 1', ['d' => $dom]) ?: null;
        if ($account) { $account['by'] = 'domain'; }
    }
    if ($account === null && $l['company']) {
        $account = q1($pdo, 'SELECT account_id, name, domain FROM mcp_accounts WHERE lower(name) = lower(:n) ORDER BY account_id LIMIT 1', ['n' => $l['company']]) ?: null;
        if ($account) { $account['by'] = 'name'; }
    }
    if ($account === null && ($dom !== null || $l['company'])) {
        // the function finds as the application: an account restricted from the caller would be found too — said so, never named
        $hidden = one_value($pdo, 'SELECT id FROM accounts WHERE deleted_at IS NULL AND merged_into_id IS NULL AND ((CAST(:d AS text) IS NOT NULL AND domain = CAST(:d AS text)) OR (CAST(:n AS text) IS NOT NULL AND lower(name) = lower(CAST(:n AS text)))) LIMIT 1',
            ['d' => $dom, 'n' => $l['company']]);
        if ($hidden !== null && $hidden !== false) { $account = ['account_id' => null, 'name' => null, 'domain' => null, 'by' => 'restricted', 'hidden' => true]; }
    }
    $contact = null;
    if ($l['email']) {
        $cid = one_value($pdo, 'SELECT crm_contact_by_address(CAST(:e AS citext))', ['e' => $l['email']]);
        if ($cid !== null && $cid !== false) {
            $contact = q1($pdo, 'SELECT contact_id, name, email, account_id, account_name FROM mcp_contacts WHERE contact_id = :id', ['id' => (int) $cid]) ?: ['contact_id' => null, 'name' => null, 'hidden' => true];
        }
    }
    $person = trim(($l['first_name'] ?? '') . ' ' . ($l['last_name'] ?? ''));
    $pipeline = q1($pdo, 'SELECT p.pipeline_id, p.name, p.currency FROM mcp_pipelines p WHERE p.pipeline_id = (SELECT default_pipeline_id FROM mcp_settings) OR (p.is_default AND p.archived_at IS NULL) ORDER BY (p.pipeline_id = (SELECT default_pipeline_id FROM mcp_settings)) DESC LIMIT 1') ?: null;
    $accountName = $account['name'] ?? ($l['company'] ?: $dom);
    return [
        'account' => $account, 'account_new' => $account === null && ($l['company'] || $dom) ? ['name' => $l['company'] ?: $dom, 'domain' => $dom] : null,
        'contact' => $contact, 'contact_new' => $contact === null && ($person !== '' || $l['email']) ? ['name' => $person !== '' ? $person : (string) $l['email'], 'email' => $l['email']] : null,
        'deal_defaults' => ['pipeline_id' => $pipeline ? (int) $pipeline['pipeline_id'] : null, 'pipeline_name' => $pipeline['name'] ?? null,
            'currency' => ($pipeline['currency'] ?? null) ?: base_currency($pdo), 'title' => ($accountName ?: $person) . ' — new business'],
    ];
}

/** One row as an array, or null (a small helper the leads' reads share). */
function q1(PDO $pdo, string $sql, array $args = []): ?array
{
    $st = $pdo->prepare($sql);
    $st->execute($args);
    $r = $st->fetch();
    return $r === false ? null : $r;
}

/** Possible duplicates of a lead: other open leads with its address, and a contact who already has it (the lead's page warning). */
function lead_duplicates(PDO $pdo, int $id): array
{
    $l = q1($pdo, 'SELECT email::text AS email FROM leads WHERE id = :id', ['id' => $id]);
    if (!$l || !$l['email']) {
        return [];
    }
    $out = [];
    $st = $pdo->prepare("SELECT lead_id AS id, label AS name FROM mcp_leads WHERE lower(email) = lower(:e) AND lead_id <> :id AND converted_at IS NULL LIMIT 5");
    $st->execute(['e' => $l['email'], 'id' => $id]);
    foreach ($st->fetchAll() as $r) { $out[] = ['id' => (int) $r['id'], 'name' => $r['name'], 'matched_on' => 'email', 'detail' => 'another lead', 'url' => '/leads/' . (int) $r['id'], 'kind' => 'lead']; }
    $cid = one_value($pdo, 'SELECT crm_contact_by_address(CAST(:e AS citext))', ['e' => $l['email']]);
    if ($cid !== null && $cid !== false && ($n = contact_label($pdo, (int) $cid)) !== null) {
        $out[] = ['id' => (int) $cid, 'name' => $n, 'matched_on' => 'email', 'detail' => 'already a contact', 'url' => '/contacts/' . (int) $cid, 'kind' => 'contact'];
    }
    return $out;
}

/** The lead sources (the source picker and the form's select). */
function find_sources(PDO $pdo, bool $activeOnly = true): array
{
    return $pdo->query('SELECT source_id, name, kind FROM mcp_lead_sources' . ($activeOnly ? ' WHERE active' : '') . ' ORDER BY sort_order, name')->fetchAll();
}

/** The reasons a lead is unqualified (lost_reasons of kind lead). */
function find_lead_reasons(PDO $pdo): array
{
    return $pdo->query("SELECT lost_reason_id, label FROM mcp_lost_reasons WHERE kind = 'lead' AND active ORDER BY sort_order, label")->fetchAll();
}

function leads_for_pick(PDO $pdo, string $q, array $params, int $page): array
{
    $size = 20;
    $st = $pdo->prepare("SELECT lead_id, label, email, status, count(*) OVER () AS total FROM mcp_leads
                          WHERE NOT is_proposed AND converted_at IS NULL AND (:q = '' OR label ILIKE '%' || :q || '%' OR email ILIKE '%' || :q || '%' OR label % :q)
                          ORDER BY CASE WHEN :q = '' THEN 0 ELSE 1 - similarity(label, :q) END, created_at DESC LIMIT :l OFFSET :o");
    $st->bindValue('q', $q);
    $st->bindValue('l', $size, PDO::PARAM_INT);
    $st->bindValue('o', (max(1, $page) - 1) * $size, PDO::PARAM_INT);
    $st->execute();
    $rows = $st->fetchAll();
    $total = $rows === [] ? 0 : (int) $rows[0]['total'];
    return ['rows' => array_map(static fn (array $r): array => ['id' => (int) $r['lead_id'], 'label' => $r['label'], 'detail' => (string) ($r['email'] ?? ''), 'badge' => LEAD_STATUSES[$r['status']] ?? $r['status'], 'color' => LEAD_STATUS_BADGE[$r['status']] ?? 'secondary'], $rows),
        'total' => $total, 'page' => max(1, $page), 'page_size' => $size, 'pages' => max(1, (int) ceil($total / $size))];
}

function lead_label(PDO $pdo, int $id): ?string
{
    $v = one_value($pdo, 'SELECT label FROM mcp_leads WHERE lead_id = :id', ['id' => $id]);
    return $v === null || $v === false ? null : (string) $v;
}
