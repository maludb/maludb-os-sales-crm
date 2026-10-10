<?php
declare(strict_types=1);

/**
 * Saved views (saved_views, Help Desk's table reused; db/012): a name over a screen's filters, sort, columns and layout. Mine (personal) and the shared
 * ones (global; a manager's to make — reports.team). Run = the screen with ?view=<id>; the screen merges the request's own filters over it.
 */
const SAVED_VIEW_KINDS = ['lead' => 'Leads', 'account' => 'Accounts', 'contact' => 'Contacts', 'deal' => 'Deals', 'activity' => 'Activities'];
const SAVED_VIEW_LAYOUTS = ['list' => 'List', 'board' => 'Board', 'calendar' => 'Calendar', 'forecast' => 'Forecast'];

/** The views the caller sees of a kind (all kinds when null): mine and the shared ones, in order. */
function my_saved_views(PDO $pdo, ?string $recordKind = null): array
{
    $st = $pdo->prepare("SELECT v.saved_view_id, v.name, v.scope, v.owner_member_id, m.display_name AS owner_name, v.pipeline_id, v.record_kind, v.filters, v.sort_by, v.sort_dir, v.columns::text AS columns,
                                v.layout, v.is_system, v.sort_order FROM mcp_saved_views v LEFT JOIN members m ON m.id = v.owner_member_id
                          WHERE CAST(:k AS text) IS NULL OR v.record_kind = CAST(:k AS text) ORDER BY v.record_kind, (v.scope = 'personal') DESC, v.sort_order, v.name");
    $st->execute(['k' => $recordKind]);
    return array_map('saved_view_decode', $st->fetchAll());
}

/** One view the caller may see, or null. */
function find_saved_view(PDO $pdo, int $id): ?array
{
    $r = one_row($pdo, 'SELECT v.saved_view_id, v.name, v.scope, v.owner_member_id, v.pipeline_id, v.record_kind, v.filters, v.sort_by, v.sort_dir, v.columns::text AS columns, v.layout, v.is_system, v.sort_order
                          FROM mcp_saved_views v WHERE v.saved_view_id = :id', ['id' => $id]);
    return $r === null ? null : saved_view_decode($r);
}

function saved_view_decode(array $r): array
{
    $r['saved_view_id'] = (int) $r['saved_view_id'];
    $r['filters'] = json_decode((string) $r['filters'], true) ?: [];
    $r['columns'] = pg_text_array((string) $r['columns']);
    $r['pipeline_id'] = $r['pipeline_id'] === null ? null : (int) $r['pipeline_id'];
    if (isset($r['filters']['pipeline']) && $r['pipeline_id'] === null) { $r['pipeline_id'] = (int) $r['filters']['pipeline']; }
    return $r;
}

/** May the caller change or delete this view? Their own; a shared one with reports.team; never a system view. */
function saved_view_mine(array $v): bool
{
    if ($v['is_system']) {
        return false;
    }
    return $v['scope'] === 'personal' ? (int) $v['owner_member_id'] === (int) current_member_id() : has_right('reports.team');
}

/** Where a view runs: its screen with ?view=. */
function saved_view_url(PDO $pdo, array $v): string
{
    $q = '?view=' . $v['saved_view_id'];
    if ($v['record_kind'] === 'deal') {
        $p = $v['pipeline_id'] ?? null;
        $key = $p === null ? 'default' : (string) (one_value($pdo, 'SELECT key FROM mcp_pipelines WHERE pipeline_id = :p', ['p' => $p]) ?? 'default');
        $layout = in_array($v['layout'], ['board', 'list', 'forecast'], true) ? $v['layout'] : 'list';
        return '/pipeline/' . rawurlencode($key) . '/' . $layout . $q;
    }
    return match ($v['record_kind']) { 'lead' => '/leads', 'account' => '/accounts', 'contact' => '/contacts', default => '/activities' } . $q;
}

/** The filters as chips a person reads: "Owner: Priya", "Closing this month", "No next step". */
function saved_view_chips(PDO $pdo, array $filters): array
{
    $out = [];
    foreach ($filters as $k => $v) {
        if ($v === null || $v === '' || $v === false || $k === 'pipeline') { continue; }
        $out[] = match ($k) {
            'owner' => 'Owner: ' . ($v === 'me' ? 'mine' : ($v === 'none' ? 'unowned' : (string) (member_name($pdo, (int) $v) ?? '#' . $v))),
            'team' => 'Team: ' . (string) (one_value($pdo, 'SELECT name FROM mcp_departments WHERE department_id = :d', ['d' => (int) $v]) ?? '#' . $v),
            'close' => BOARD_CLOSE[$v] ?? (string) $v,
            'no_next_step' => 'No next step',
            'rotting' => 'Rotting',
            'min_value' => 'From ' . money($v),
            'max_value' => 'Up to ' . money($v),
            'source' => 'Source: ' . (string) (one_value($pdo, 'SELECT name FROM mcp_lead_sources WHERE source_id = :s', ['s' => (int) $v]) ?? '#' . $v),
            'q' => '“' . (is_array($v) ? implode(' ', $v) : (string) $v) . '”',
            default => ucfirst(str_replace('_', ' ', (string) $k)) . ': ' . (is_array($v) ? implode(', ', $v) : (string) $v),
        };
    }
    return $out;
}
