<?php
declare(strict_types=1);

/**
 * A deal's reads (slice 3, THE FIRST EXEMPLAR): the page's row, its summary (contacts with roles, product lines, the next step, the stage bar
 * with what each stage needs, the history strip with durations), the generic list, the pickers. Every read goes through a view: a deal the
 * caller may not see does not exist to them.
 */
require_once __DIR__ . '/../records/queries.php';
require_once __DIR__ . '/../accounts/queries.php';
require_once __DIR__ . '/../contacts/queries.php';
require_once __DIR__ . '/../pipeline/queries.php';

const DEAL_PAGE = 50;
const DEAL_RECURRING = ['none' => 'One-off', 'monthly' => 'Monthly', 'yearly' => 'Yearly'];

/** The deal's page row: mcp_deals + the names a page shows (owner, pipeline, source, campaign, closer). Null behind the wall. */
function find_deal(PDO $pdo, int $id): ?array
{
    $r = one_row($pdo, "SELECT d.*, d.tags::text AS tags, m.display_name AS owner_name, p.name AS pipeline_name, p.key AS pipeline_key, (SELECT name FROM lead_sources WHERE id = d.source_id) AS source_name,
                               (SELECT name FROM campaigns WHERE id = d.campaign_id) AS campaign_name, (SELECT display_name FROM members WHERE id = d.closed_by) AS closed_by_name,
                               x.restricted_to_member_id, x.restricted_to_department_id
                          FROM mcp_deals d JOIN deals x ON x.id = d.deal_id LEFT JOIN members m ON m.id = d.owner_member_id JOIN pipelines p ON p.id = d.pipeline_id
                         WHERE d.deal_id = :id", ['id' => $id]);
    if ($r === null) {
        return null;
    }
    $r['tags'] = pg_text_array((string) $r['tags']);
    $r['custom_values'] = json_decode((string) $r['custom_values'], true) ?: [];
    return $r;
}

/** The deal's people with their roles, the primary first. */
function deal_contacts(PDO $pdo, int $id): array
{
    $st = $pdo->prepare('SELECT dc.contact_id, dc.contact_name, dc.role, dc.is_primary, c.title, c.email, c.phone, c.account_name FROM mcp_deal_contacts dc
                           LEFT JOIN mcp_contacts c ON c.contact_id = dc.contact_id WHERE dc.deal_id = :id ORDER BY dc.is_primary DESC, dc.added_at, dc.contact_id');
    $st->execute(['id' => $id]);
    return $st->fetchAll();
}

/** The deal's product lines in order, with the total. */
function deal_products(PDO $pdo, int $id): array
{
    $st = $pdo->prepare('SELECT deal_product_id, position, name, provider_app, provider_id, sku, quantity, unit_price, discount_percent, line_total, recurring FROM mcp_deal_products
                          WHERE deal_id = :id ORDER BY position, deal_product_id');
    $st->execute(['id' => $id]);
    return $st->fetchAll();
}

/** One product line the caller may see, with its deal. */
function find_deal_product(PDO $pdo, int $line): ?array
{
    return one_row($pdo, 'SELECT * FROM mcp_deal_products WHERE deal_product_id = :id', ['id' => $line]);
}

/** The stage history, oldest first, with who moved it and the time spent in the stage left (the strip's durations). */
function deal_history(PDO $pdo, int $id): array
{
    $st = $pdo->prepare('SELECT h.history_id, h.from_stage_id, h.from_stage, h.to_stage_id, h.to_stage, h.member_id, m.display_name AS member_name, h.at, h.seconds_in_from_stage, h.source
                           FROM mcp_deal_stage_history h LEFT JOIN members m ON m.id = h.member_id WHERE h.deal_id = :id ORDER BY h.at, h.history_id');
    $st->execute(['id' => $id]);
    return $st->fetchAll();
}

/**
 * The strip: each stage the deal has been in, how long, the current one running. [{stage_id, stage, entered_at, seconds, current}]
 */
function deal_history_strip(array $history, ?string $now = null): array
{
    $out = [];
    $n = count($history);
    foreach ($history as $i => $h) {
        $next = $history[$i + 1] ?? null;
        $seconds = $next !== null ? (int) ($next['seconds_in_from_stage'] ?? 0) : max(0, (int) ((strtotime($now ?? 'now') ?: time()) - strtotime((string) $h['at'])));
        $out[] = ['stage_id' => (int) $h['to_stage_id'], 'stage' => $h['to_stage'], 'entered_at' => $h['at'], 'seconds' => $seconds, 'current' => $i === $n - 1, 'by' => $h['member_name']];
    }
    return $out;
}

/** "3 d", "5 h", "12 min" — how long in a stage. */
function duration_words(int $seconds): string
{
    if ($seconds >= 86400) { $d = intdiv($seconds, 86400); return $d . ' day' . ($d === 1 ? '' : 's'); }
    if ($seconds >= 3600) { $h = intdiv($seconds, 3600); return $h . ' hour' . ($h === 1 ? '' : 's'); }
    $m = max(1, intdiv($seconds, 60));
    return $m . ' min';
}

/**
 * The page's summary: contacts, products (and their total), the next step, the stage bar (every stage of the pipeline with what the deal lacks to
 * enter it), the fields the next stage needs, the history strip.
 */
function deal_summary(PDO $pdo, int $id, array $deal): array
{
    $stages = pipeline_stages($pdo, (int) $deal['pipeline_id']);
    $missing = [];
    $st = $pdo->prepare('SELECT crm_stage_requirements_check(:d, :s)::text');
    foreach ($stages as $s) {
        if ($s['kind'] !== 'open' || $s['required_fields'] === []) { $missing[$s['stage_id']] = []; continue; }
        $st->execute(['d' => $id, 's' => $s['stage_id']]);
        $missing[$s['stage_id']] = pg_text_array((string) $st->fetchColumn());
    }
    $current = null;
    foreach ($stages as $i => $s) { if ($s['stage_id'] === (int) $deal['stage_id']) { $current = $i; } }
    $nextStage = null;
    if ($deal['status'] === 'open' && $current !== null) {
        for ($i = $current + 1; $i < count($stages); $i++) { if ($stages[$i]['kind'] === 'open') { $nextStage = $stages[$i]; break; } }
    }
    $products = deal_products($pdo, $id);
    $history = deal_history($pdo, $id);
    return [
        'contacts' => deal_contacts($pdo, $id),
        'products' => $products,
        'products_total' => round(array_sum(array_map(static fn ($p) => (float) $p['line_total'], $products)), 2),
        'next_step' => next_step_of($pdo, 'deal', $id),
        'stages' => $stages,
        'missing' => $missing,
        'next_stage' => $nextStage,
        'missing_next' => $nextStage === null ? [] : ($missing[$nextStage['stage_id']] ?? []),
        'history' => $history,
        'strip' => deal_history_strip($history),
    ];
}

/** The deal's reasons a deal is lost (active, of kind deal). */
function find_deal_reasons(PDO $pdo): array
{
    return $pdo->query("SELECT lost_reason_id, label FROM mcp_lost_reasons WHERE kind = 'deal' AND active ORDER BY sort_order, label")->fetchAll();
}

/**
 * Deals across pipelines (the find_deals resolver and /deals in JSON): q (title, number, account), status (open by default; won, lost, all), owner,
 * account, contact, pipeline, stage; newest first. ['rows', 'total']
 */
function find_deals(PDO $pdo, array $filters, int $page): array
{
    $a = [];
    $w = board_where($filters, $a);
    $status = $filters['status'] ?? 'open';
    if ($status !== 'all') { $w[] = 'd.status = :status'; $a['status'] = $status; }
    foreach (['account' => 'd.account_id', 'pipeline' => 'd.pipeline_id', 'stage' => 'd.stage_id'] as $k => $col) {
        if (($filters[$k] ?? null) !== null) { $w[] = "$col = :$k"; $a[$k] = (int) $filters[$k]; }
    }
    if (($filters['in_list'] ?? null) !== null) { $w[] = 'd.deal_id IN (SELECT record_id FROM list_members WHERE list_id = :inlist)'; $a['inlist'] = (int) $filters['in_list']; }       // slice 6: the members of a static list
    if (($filters['contact'] ?? null) !== null) { $w[] = '(d.primary_contact_id = :contact OR EXISTS (SELECT 1 FROM deal_contacts dc WHERE dc.deal_id = d.deal_id AND dc.contact_id = :contact))'; $a['contact'] = (int) $filters['contact']; }
    $sql = 'SELECT d.deal_id, d.number, d.title, d.account_id, d.account_name, d.pipeline_id, d.stage_id, d.stage_name, d.value, d.currency, d.weighted_value, d.probability, d.expected_close_date,
                   d.status, d.owner_member_id, m.display_name AS owner_name, d.next_activity_at, d.next_colour, d.is_rotting, d.restricted, d.created_at, count(*) OVER () AS total
              FROM mcp_deals d LEFT JOIN members m ON m.id = d.owner_member_id WHERE ' . ($w === [] ? 'true' : implode(' AND ', $w))
         . ' ORDER BY d.created_at DESC, d.deal_id DESC LIMIT ' . DEAL_PAGE . ' OFFSET ' . ((max(1, $page) - 1) * DEAL_PAGE);
    $st = $pdo->prepare($sql);
    $st->execute($a);
    $rows = $st->fetchAll();
    return ['rows' => $rows, 'total' => $rows === [] ? 0 : (int) $rows[0]['total']];
}

/** The deal's name as a picker shows it: "D-0012 · Acme renewal". */
function deal_label(PDO $pdo, int $id): ?string
{
    $r = one_row($pdo, 'SELECT number, title FROM mcp_deals WHERE deal_id = :id', ['id' => $id]);
    return $r === null ? null : $r['number'] . ' · ' . $r['title'];
}

/** The picker: deals (open first), `account` narrows to its deals. */
function deals_for_pick(PDO $pdo, string $q, array $params, int $page = 1): array
{
    return crm_simple_pick($pdo, "SELECT deal_id, number, title, account_name, stage_name, status, count(*) OVER () AS total FROM mcp_deals
                                    WHERE (CAST(:acc AS bigint) IS NULL OR account_id = CAST(:acc AS bigint)) AND (:q = '' OR title ILIKE '%' || :q || '%' OR number ILIKE '%' || :q || '%' OR account_name ILIKE '%' || :q || '%')
                                    ORDER BY (status = 'open') DESC, updated_at DESC", $q, $page,
        static fn (array $r): array => ['id' => (int) $r['deal_id'], 'label' => $r['number'] . ' · ' . $r['title'], 'detail' => trim(($r['account_name'] ?? '') . ' · ' . $r['stage_name'], ' ·'),
            'badge' => $r['status'] === 'open' ? null : DEAL_STATUSES[$r['status']], 'color' => DEAL_STATUS_BADGE[$r['status']]], ['acc' => $params['account'] ?? null]);
}

/**
 * The picker over the products a sibling sells (D11): Inventory's availability_index and the ledger's items_index through the kernel's K7 read
 * when a super-admin approved the connection; none approved → no rows, and the line is written by name. Each row's id is "app:provider_id".
 */
function products_for_pick(PDO $pdo, string $q, array $params, int $page = 1): array
{
    $rows = [];
    foreach ([['inventory', 'availability_index'], ['gl', 'items_index']] as [$app, $tool]) {
        $r = k7_read($pdo, $app, $tool, ['q' => $q, 'limit' => 20]);
        if (($r['status'] ?? '') !== 'ok') { continue; }
        foreach ($r['rows'] as $p) {
            $pid = (string) ($p['variant_id'] ?? $p['item_id'] ?? $p['id'] ?? '');
            if ($pid === '') { continue; }
            $rows[] = ['id' => $app . ':' . $pid, 'label' => (string) ($p['name'] ?? $p['title'] ?? $pid), 'detail' => trim((string) ($p['sku'] ?? '') . (isset($p['price']) ? ' · ' . money($p['price']) : ''), ' ·'),
                'badge' => $app === 'gl' ? 'ledger' : 'inventory'];
        }
    }
    return ['rows' => $rows, 'total' => count($rows), 'page' => 1, 'page_size' => max(20, count($rows)), 'pages' => 1];
}

/** Is a sibling's product catalog reachable (an approved connection)? The form offers the picker only then. */
function products_connected(PDO $pdo): bool
{
    foreach ([['inventory', 'availability_index'], ['gl', 'items_index']] as [$app, $tool]) {
        if ((k7_read($pdo, $app, $tool, ['q' => '', 'limit' => 1])['status'] ?? '') === 'ok') { return true; }
    }
    return false;
}
