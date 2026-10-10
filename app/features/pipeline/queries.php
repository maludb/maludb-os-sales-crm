<?php
declare(strict_types=1);

/**
 * The pipeline's three views — the board, the list, the forecast — over ONE query (pipeline-board.md §4): find_board_deals() is what the board's
 * columns, the list's rows and the counts read; the wall is the view's (mcp_deals). The column heads count what the filters leave, in the business's
 * currency (a deal in another currency is counted apart, never converted — (a)); with no filter they are crm_pipeline_summary()'s numbers. Won and
 * Lost are the month's (the summary's). The forecast is crm_forecast() laid out as a grid.
 */
const BOARD_CLOSE = ['overdue' => 'Close date passed', 'this_month' => 'Closing this month', 'next_month' => 'Closing next month', 'this_quarter' => 'Closing this quarter',
    'next_90' => 'Closing in 90 days', 'none' => 'No close date'];
const DEAL_SORTS = ['position' => 'd.position, d.deal_id', 'close' => 'd.expected_close_date NULLS LAST, d.deal_id', 'value' => 'd.value DESC, d.deal_id', 'weighted' => 'd.weighted_value DESC, d.deal_id',
    'next' => 'd.next_activity_at NULLS FIRST, d.deal_id', 'entered' => 'd.stage_entered_at, d.deal_id', 'updated' => 'd.updated_at DESC, d.deal_id', 'title' => 'lower(d.title), d.deal_id'];
const DEAL_LIST_COLUMNS = ['number' => 'Number', 'title' => 'Deal', 'account' => 'Account', 'contact' => 'Contact', 'stage' => 'Stage', 'value' => 'Value', 'weighted' => 'Weighted',
    'probability' => 'Probability', 'close' => 'Close', 'owner' => 'Owner', 'next' => 'Next step', 'entered' => 'In stage', 'tags' => 'Tags'];
const DEAL_LIST_DEFAULT_COLUMNS = ['title', 'account', 'stage', 'value', 'close', 'owner', 'next'];
const DEAL_STATUSES = ['open' => 'Open', 'won' => 'Won', 'lost' => 'Lost'];
const DEAL_STATUS_BADGE = ['open' => 'secondary', 'won' => 'success', 'lost' => 'danger'];

/** A pipeline named by id or key (the routing form); the default when none is named; the same 404 for an archived or unknown one. */
function pipeline_from_request(PDO $pdo, ?string $ref = null): array
{
    $ref = $ref ?? request_string('pipeline');
    if ($ref === '' || $ref === 'default') {
        $row = one_row($pdo, 'SELECT p.* FROM mcp_pipelines p WHERE p.archived_at IS NULL ORDER BY (p.pipeline_id = (SELECT default_pipeline_id FROM crm_settings)) DESC, p.is_default DESC, p.position, p.pipeline_id LIMIT 1');
    } elseif (ctype_digit($ref)) {
        $row = one_row($pdo, 'SELECT * FROM mcp_pipelines WHERE pipeline_id = :id AND archived_at IS NULL', ['id' => (int) $ref]);
    } else {
        $row = one_row($pdo, 'SELECT * FROM mcp_pipelines WHERE key = :k AND archived_at IS NULL', ['k' => strtolower($ref)]);
    }
    if ($row === null) {
        refuse(404, 'Pipeline not found.');
    }
    $row['pipeline_id'] = (int) $row['pipeline_id'];
    $row['currency'] = $row['currency'] ?? base_currency($pdo);
    return $row;
}

/** The live pipelines, the default first. [{pipeline_id, key, name, is_default, currency}] */
function find_pipelines(PDO $pdo): array
{
    return $pdo->query('SELECT pipeline_id, key, name, is_default, currency FROM mcp_pipelines WHERE archived_at IS NULL
                         ORDER BY (pipeline_id = (SELECT default_pipeline_id FROM crm_settings)) DESC, is_default DESC, position, name')->fetchAll();
}

/** A pipeline's live stages in order (open, then Won, then Lost). [{stage_id, name, position, probability, kind, rotting_days, required_fields[], colour, wip_limit}] */
function pipeline_stages(PDO $pdo, int $pipeline): array
{
    $st = $pdo->prepare("SELECT stage_id, name, position, probability, kind, rotting_days, required_fields::text AS required_fields, colour, wip_limit FROM mcp_stages
                          WHERE pipeline_id = :p AND archived_at IS NULL ORDER BY CASE kind WHEN 'open' THEN 0 WHEN 'won' THEN 1 ELSE 2 END, position, stage_id");
    $st->execute(['p' => $pipeline]);
    return array_map(static fn (array $s): array => ['stage_id' => (int) $s['stage_id'], 'position' => (int) $s['position'], 'probability' => (int) $s['probability'],
        'rotting_days' => $s['rotting_days'] === null ? null : (int) $s['rotting_days'], 'wip_limit' => $s['wip_limit'] === null ? null : (int) $s['wip_limit'],
        'required_fields' => pg_text_array((string) $s['required_fields'])] + $s, $st->fetchAll());
}

/** The pipeline's Won or Lost stage. */
function pipeline_closed_stage(PDO $pdo, int $pipeline, string $kind): ?int
{
    $v = one_value($pdo, "SELECT stage_id FROM mcp_stages WHERE pipeline_id = :p AND kind = :k AND archived_at IS NULL", ['p' => $pipeline, 'k' => $kind]);
    return $v === null ? null : (int) $v;
}

/** A stage of a pipeline by id or by its name (an agent says "Proposal"), or null. */
function stage_resolve(PDO $pdo, int $pipeline, string $ref): ?array
{
    $row = ctype_digit($ref)
        ? one_row($pdo, 'SELECT stage_id, name, kind, pipeline_id, archived_at FROM mcp_stages WHERE stage_id = :id', ['id' => (int) $ref])
        : one_row($pdo, 'SELECT stage_id, name, kind, pipeline_id, archived_at FROM mcp_stages WHERE pipeline_id = :p AND lower(name) = lower(:n) AND archived_at IS NULL', ['p' => $pipeline, 'n' => $ref]);
    return $row;
}

/** The words of a required field: "primary contact", "expected close date", a custom field's label. */
function stage_field_words(PDO $pdo, string $field): string
{
    static $words = ['primary_contact_id' => 'primary contact', 'account_id' => 'account', 'value' => 'value', 'expected_close_date' => 'expected close date',
        'description' => 'description', 'source_id' => 'source', 'products' => 'products'];
    if (isset($words[$field])) {
        return $words[$field];
    }
    $label = one_value($pdo, "SELECT label FROM mcp_custom_fields WHERE applies_to = 'deal' AND key = :k", ['k' => $field]);
    return $label === null ? str_replace('_', ' ', $field) : (string) $label;
}

/** The form field that carries a required stage field (the deal form's names): primary_contact_id → primary_contact; a custom key → custom[key]. */
function stage_field_input(string $field): string
{
    return ['primary_contact_id' => 'primary_contact', 'account_id' => 'account', 'source_id' => 'source', 'expected_close_date' => 'expected_close_date', 'value' => 'value',
        'description' => 'description', 'products' => 'products'][$field] ?? 'custom[' . $field . ']';
}

/**
 * The board's and the list's filters from the request, a saved view's beneath them (`view`). owner: me | none | an id; team: a department (the
 * owners in it); close: BOARD_CLOSE; tag; source; campaign; min_value / max_value; no_next_step; rotting; q; and for the list status, group, sort.
 */
function board_filters_from_request(PDO $pdo, ?array $src = null): array
{
    $get = static fn (string $k): string => $src === null ? request_string($k) : trim(is_scalar($src[$k] ?? null) ? (string) $src[$k] : '');
    $view = ($v = $get('view')) !== '' && ctype_digit($v) ? (int) $v : null;
    $f = saved_view_filters($pdo, 'deal', $view);
    $pick = static fn (string $k) => ($v = $get($k)) !== '' ? $v : (isset($f[$k]) && !is_array($f[$k]) ? (string) $f[$k] : (($f[$k] ?? null) === true ? '1' : null));
    $num = static function (?string $v): ?string { $v = $v === null ? null : str_replace([',', ' '], '', $v); return $v !== null && is_numeric($v) && (float) $v >= 0 ? $v : null; };
    $id = static fn (?string $v): ?int => $v !== null && ctype_digit($v) ? (int) $v : null;
    $yes = static fn (?string $v): bool => in_array((string) $v, ['1', 'yes', 'true', 'on'], true);
    $owner = $pick('owner');
    $close = $pick('close');
    $status = $pick('status');
    $group = $pick('group');
    $sort = $pick('sort') ?? (['expected_close_date' => 'close', 'stage_entered_at' => 'entered', 'value' => 'value', 'updated_at' => 'updated', 'title' => 'title'][$view === null ? '' : (string) one_value($pdo, 'SELECT sort_by FROM mcp_saved_views WHERE saved_view_id = :v', ['v' => $view])] ?? null);
    return [
        'q' => mb_substr($get('q') !== '' ? $get('q') : (string) ($f['q'] ?? ''), 0, 120),
        'owner' => $owner === 'me' || $owner === 'none' || ($owner !== null && ctype_digit($owner)) ? $owner : null,
        'team' => $id($pick('team')),
        'close' => $close !== null && isset(BOARD_CLOSE[$close]) ? $close : null,
        'tag' => ($t = $pick('tag')) !== null ? mb_substr($t, 0, 60) : null,
        'source' => $id($pick('source')),
        'campaign' => $id($pick('campaign')),
        'min_value' => $num($pick('min_value')),
        'max_value' => $num($pick('max_value')),
        'no_next_step' => $yes($pick('no_next_step')),
        'rotting' => $yes($pick('rotting')),
        'status' => $status === 'all' || ($status !== null && isset(DEAL_STATUSES[$status])) ? $status : null,
        'group' => in_array($group, ['stage', 'owner'], true) ? $group : null,
        'sort' => $sort !== null && isset(DEAL_SORTS[$sort]) ? $sort : null,
        'close_month' => ($cm = $pick('close_month')) !== null && preg_match('/^\d{4}-\d{2}$/', $cm) ? $cm : null,
        'view' => $view,
    ];
}

/** Are any of the filters that narrow the deals set (beyond the view's own id)? */
function board_filtered(array $f): bool
{
    foreach (['owner', 'team', 'close', 'tag', 'source', 'campaign', 'min_value', 'max_value', 'close_month'] as $k) {
        if ($f[$k] ?? null) { return true; }
    }
    return ($f['q'] ?? '') !== '' || !empty($f['no_next_step']) || !empty($f['rotting']);
}

/** The filters as a query string, without the empty ones ($over replaces). */
function board_query(array $f, array $over = []): string
{
    $keep = array_merge(array_intersect_key($f, array_flip(['q', 'owner', 'team', 'close', 'tag', 'source', 'campaign', 'min_value', 'max_value', 'no_next_step', 'rotting', 'status', 'group', 'sort', 'close_month', 'view'])), $over);
    $keep = array_filter($keep, static fn ($v) => $v !== null && $v !== '' && $v !== false);
    foreach ($keep as $k => $v) { if ($v === true) { $keep[$k] = '1'; } }
    return http_build_query($keep);
}

/** The WHERE of the filters over mcp_deals d (the wall is the view's). */
function board_where(array $f, array &$a): array
{
    $w = [];
    if (($f['q'] ?? '') !== '') {
        $w[] = '(d.title ILIKE :ql OR d.number ILIKE :ql OR d.account_name ILIKE :ql OR d.primary_contact_name ILIKE :ql)';
        $a['ql'] = '%' . str_replace(['%', '_'], ['\\%', '\\_'], $f['q']) . '%';
    }
    if (($f['owner'] ?? null) === 'none') { $w[] = 'd.owner_member_id IS NULL'; }
    elseif (($f['owner'] ?? null) !== null) { $w[] = 'd.owner_member_id = :owner'; $a['owner'] = $f['owner'] === 'me' ? (int) current_member_id() : (int) $f['owner']; }
    if (($f['team'] ?? null) !== null) { $w[] = 'd.owner_member_id IN (SELECT member_id FROM department_members WHERE department_id = :team AND left_at IS NULL)'; $a['team'] = (int) $f['team']; }
    $w[] = match ($f['close'] ?? null) {
        'overdue' => "d.expected_close_date < current_date",
        'this_month' => "d.expected_close_date >= date_trunc('month', current_date) AND d.expected_close_date < date_trunc('month', current_date) + interval '1 month'",
        'next_month' => "d.expected_close_date >= date_trunc('month', current_date) + interval '1 month' AND d.expected_close_date < date_trunc('month', current_date) + interval '2 months'",
        'this_quarter' => "d.expected_close_date >= date_trunc('quarter', current_date) AND d.expected_close_date < date_trunc('quarter', current_date) + interval '3 months'",
        'next_90' => "d.expected_close_date BETWEEN current_date AND current_date + 90",
        'none' => 'd.expected_close_date IS NULL',
        default => 'true',
    };
    if (($f['tag'] ?? null) !== null) { $w[] = "EXISTS (SELECT 1 FROM record_tags rt JOIN tags t ON t.id = rt.tag_id WHERE rt.record_type = 'deal' AND rt.record_id = d.deal_id AND (t.slug = :tag OR lower(t.label) = lower(:tag)))"; $a['tag'] = $f['tag']; }
    if (($f['source'] ?? null) !== null) { $w[] = 'd.source_id = :src'; $a['src'] = (int) $f['source']; }
    if (($f['campaign'] ?? null) !== null) { $w[] = 'd.campaign_id = :camp'; $a['camp'] = (int) $f['campaign']; }
    if (($f['min_value'] ?? null) !== null) { $w[] = 'd.value >= CAST(:minv AS numeric)'; $a['minv'] = $f['min_value']; }
    if (($f['max_value'] ?? null) !== null) { $w[] = 'd.value <= CAST(:maxv AS numeric)'; $a['maxv'] = $f['max_value']; }
    if (!empty($f['no_next_step'])) { $w[] = 'd.next_activity_at IS NULL'; }
    if (!empty($f['rotting'])) { $w[] = 'd.is_rotting'; }
    if (($f['close_month'] ?? null) !== null) {                                     // the forecast's cell: closing (or won) that month, lost left out — crm_forecast's rule
        $w[] = "d.status <> 'lost' AND date_trunc('month', COALESCE(d.won_at::date, d.expected_close_date)) = CAST(:cm AS date)";
        $a['cm'] = $f['close_month'] . '-01';
    }
    return $w;
}

/**
 * THE ONE QUERY behind the board, the list and the counts: a pipeline's deals (open by default; the list may ask for won, lost or all — the closed
 * ones of any time), the filters applied, in $sort's order (DEAL_SORTS; the board's is the drag's position). Each row carries the owner's name, the
 * tags, and `missing_next` — the fields the NEXT open stage needs that the deal lacks (the card's flag).
 */
function find_board_deals(PDO $pdo, int $pipeline, array $filters, string $sort = 'position'): array
{
    $a = ['p' => $pipeline];
    $w = array_merge(['d.pipeline_id = :p'], board_where($filters, $a));
    $status = $filters['status'] ?? 'open';
    if ($status !== 'all') { $w[] = 'd.status = :status'; $a['status'] = $status ?? 'open'; }
    $order = DEAL_SORTS[$sort] ?? DEAL_SORTS['position'];
    $st = $pdo->prepare("SELECT d.deal_id, d.number, d.title, d.account_id, d.account_name, d.primary_contact_id, d.primary_contact_name, d.pipeline_id, d.stage_id, d.stage_name, d.position,
                                d.value, d.currency, d.value_from_products, d.probability, d.weighted_value, d.expected_close_date, d.status, d.won_at, d.lost_at, d.owner_member_id,
                                m.display_name AS owner_name, d.stage_entered_at, d.days_in_stage, d.is_rotting, d.next_activity_id, d.next_activity_at, d.next_colour, d.restricted,
                                d.tags::text AS tags, d.source_id, d.campaign_id, d.updated_at,
                                (SELECT kind FROM activities WHERE id = d.next_activity_id) AS next_kind,
                                (SELECT subject FROM activities WHERE id = d.next_activity_id) AS next_subject,
                                CASE WHEN d.status = 'open' THEN (SELECT crm_stage_missing_fields(x, n.id)::text FROM deals x,
                                    LATERAL (SELECT s2.id FROM stages s2 WHERE s2.pipeline_id = x.pipeline_id AND s2.kind = 'open' AND s2.archived_at IS NULL
                                              AND s2.position > (SELECT position FROM stages WHERE id = x.stage_id) ORDER BY s2.position LIMIT 1) n WHERE x.id = d.deal_id) END AS missing_next
                           FROM mcp_deals d LEFT JOIN members m ON m.id = d.owner_member_id
                          WHERE " . implode(' AND ', $w) . " ORDER BY $order");
    $st->execute($a);
    return array_map(static function (array $r): array {
        $r['tags'] = pg_text_array((string) $r['tags']);
        $r['missing_next'] = pg_text_array((string) ($r['missing_next'] ?? ''));
        return $r;
    }, $st->fetchAll());
}

/**
 * The board: each open stage with its deals, count, value and weighted (the business's currency; `other_currency` counts the rest), rotting and
 * no-next-step counts, and the WIP warning; then Won and Lost with the month's count and value (crm_pipeline_summary, the owner and team filters
 * applied). array{stages: [...], closed: {won, lost}}
 */
function board_columns(PDO $pdo, int $pipeline, array $filters): array
{
    $cur = base_currency($pdo);
    $deals = find_board_deals($pdo, $pipeline, ['status' => 'open'] + $filters, 'position');
    $by = [];
    foreach ($deals as $d) { $by[(int) $d['stage_id']][] = $d; }
    $owner = ($filters['owner'] ?? null) === 'me' ? (int) current_member_id() : (($filters['owner'] ?? null) !== null && ctype_digit((string) $filters['owner']) ? (int) $filters['owner'] : null);
    $st = $pdo->prepare('SELECT * FROM crm_pipeline_summary(:p, :o, :t)');
    $st->execute(['p' => $pipeline, 'o' => $owner, 't' => $filters['team'] ?? null]);
    $summary = [];
    foreach ($st->fetchAll() as $s) { $summary[(int) $s['stage_id']] = $s; }
    $stages = [];
    $closed = ['won' => null, 'lost' => null];
    foreach (pipeline_stages($pdo, $pipeline) as $s) {
        $sid = $s['stage_id'];
        if ($s['kind'] !== 'open') {
            $sum = $summary[$sid] ?? null;
            $closed[$s['kind']] = $s + ['count' => (int) ($sum['deals'] ?? 0), 'value' => (float) ($sum['value'] ?? 0)];
            continue;
        }
        $rows = $by[$sid] ?? [];
        $value = 0.0; $weighted = 0.0; $other = 0; $rotting = 0; $none = 0;
        foreach ($rows as $d) {
            if ($d['currency'] === $cur) { $value += (float) $d['value']; $weighted += (float) $d['weighted_value']; } else { $other++; }
            if ($d['is_rotting']) { $rotting++; }
            if ($d['next_activity_at'] === null) { $none++; }
        }
        $stages[] = ['stage' => $s, 'deals' => $rows, 'count' => count($rows), 'value' => round($value, 2), 'weighted' => round($weighted, 2), 'other_currency' => $other,
            'rotting' => $rotting, 'no_next_step' => $none, 'over_wip' => $s['wip_limit'] !== null && count($rows) > $s['wip_limit']];
    }
    return ['stages' => $stages, 'closed' => $closed, 'currency' => $cur];
}

/**
 * The forecast grid (crm_forecast): rows are sellers (or stages), columns the months; each cell open value, weighted, won and the deals counted;
 * a seller's quota per month. Totals per month. array{months[], rows[{id, name, cells{month: {...}}, quota{month: ?amount}}], totals{month: {...}}, by}
 */
function forecast_grid(PDO $pdo, ?int $pipeline, int $months, string $by, ?int $team): array
{
    $st = $pdo->prepare('SELECT * FROM crm_forecast(:p, :m, :b, :t)');
    $st->execute(['p' => $pipeline, 'm' => $months, 'b' => $by, 't' => $team]);
    $grid = ['months' => [], 'rows' => [], 'totals' => [], 'by' => $by];
    foreach ($st->fetchAll() as $r) {
        $m = substr((string) $r['month'], 0, 7);
        $grid['months'][$m] = true;
        $id = (int) $r['row_id'];
        $grid['rows'][$id] ??= ['id' => $id, 'name' => $r['row_name'], 'cells' => [], 'quota' => [], 'sum' => ['open' => 0.0, 'weighted' => 0.0, 'won' => 0.0, 'deals' => 0]];
        $cell = ['open' => (float) $r['open_value'], 'weighted' => (float) $r['weighted'], 'won' => (float) $r['won_value'], 'deals' => (int) $r['deals']];
        $grid['rows'][$id]['cells'][$m] = $cell;
        $grid['rows'][$id]['quota'][$m] = $r['quota'] === null ? null : (float) $r['quota'];
        foreach ($cell as $k => $v) { $grid['rows'][$id]['sum'][$k] += $v; }
        $grid['totals'][$m] ??= ['open' => 0.0, 'weighted' => 0.0, 'won' => 0.0, 'deals' => 0, 'quota' => null];
        foreach ($cell as $k => $v) { $grid['totals'][$m][$k] += $v; }
        if ($r['quota'] !== null) { $grid['totals'][$m]['quota'] = ($grid['totals'][$m]['quota'] ?? 0.0) + (float) $r['quota']; }
    }
    $grid['months'] = array_keys($grid['months']);
    // a seller with no deal in the window and no quota is left out (the agents and the people who do not sell); a stage always stays
    $grid['rows'] = array_values(array_filter($grid['rows'], static fn (array $r): bool => $by === 'stage' || $r['sum']['deals'] > 0 || array_filter($r['quota'], static fn ($q) => $q !== null) !== []));
    return $grid;
}

/** A short amount for a card: 45.7k, 1.2M, 950 — in the deal's currency's symbol. */
function money_short(float|int|string|null $amount, ?string $currency = null): string
{
    $v = (float) ($amount ?? 0);
    $a = abs($v);
    if ($a < 10000) { return money($v, $currency); }
    $text = $a >= 1000000 ? rtrim(rtrim(number_format($a / 1000000, 1, '.', ''), '0'), '.') . 'M' : rtrim(rtrim(number_format($a / 1000, 1, '.', ''), '0'), '.') . 'k';
    $full = money($v >= 0 ? 1 : -1, $currency);
    return str_replace('1', $text, $full);
}

/** A person's initials for the card's avatar. */
function initials(?string $name): string
{
    $parts = preg_split('/\s+/', trim(preg_replace('/^SMOKE\s+/', '', (string) $name))) ?: [];
    $out = '';
    foreach (array_slice($parts, 0, 2) as $p) { $out .= mb_strtoupper(mb_substr($p, 0, 1)); }
    return $out === '' ? '?' : $out;
}

/** The URLs of a pipeline's three views, the filters carried. */
function pipeline_url(array $pipeline, string $page, array $filters = [], array $over = []): string
{
    $q = board_query($filters, $over);
    return '/pipeline/' . rawurlencode((string) $pipeline['key']) . '/' . $page . ($q === '' ? '' : '?' . $q);
}

/** The departments a team filter may name (those with a member here). */
function find_teams(PDO $pdo): array
{
    return $pdo->query('SELECT DISTINCT d.department_id, d.name FROM mcp_departments d JOIN department_members dm ON dm.department_id = d.department_id AND dm.left_at IS NULL
                         WHERE d.archived_at IS NULL ORDER BY d.name')->fetchAll();
}

/** The picker: live pipelines. */
function pipelines_for_pick(PDO $pdo, string $q, array $params, int $page): array
{
    return crm_simple_pick($pdo, "SELECT pipeline_id, name, key, is_default, count(*) OVER () AS total FROM mcp_pipelines WHERE archived_at IS NULL AND (:q = '' OR name ILIKE '%' || :q || '%') ORDER BY is_default DESC, position, name", $q, $page,
        static fn (array $r): array => ['id' => (int) $r['pipeline_id'], 'label' => $r['name'], 'badge' => $r['is_default'] ? 'default' : null]);
}

/** The picker: a pipeline's live stages (`pipeline` required — none named, nothing offered). Won and Lost are not picked here; their forms close a deal. */
function stages_for_pick(PDO $pdo, string $q, array $params, int $page = 1): array
{
    return crm_simple_pick($pdo, "SELECT stage_id, name, probability, count(*) OVER () AS total FROM mcp_stages WHERE pipeline_id = CAST(:p AS bigint) AND kind = 'open' AND archived_at IS NULL AND (:q = '' OR name ILIKE '%' || :q || '%') ORDER BY position", $q, $page,
        static fn (array $r): array => ['id' => (int) $r['stage_id'], 'label' => $r['name'], 'detail' => (int) $r['probability'] . '%'], ['p' => $params['pipeline'] ?? null]);
}
