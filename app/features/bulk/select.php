<?php
declare(strict_types=1);

/**
 * What a bulk action acts on (campaigns-lists.md divergence 1): `records[]` (ticked ids), `list` (a list — a static one's members or a smart one's filter run now) or `filters` (the find_* vocabulary,
 * as JSON or a query string) — exactly one of them; the bulk bar adds `scope` (selected | all) because its form carries both the ticks and the screen's filters: `selected` reads only the ticks,
 * `all` only the list or the filters. The wall is the views': a record the caller may not see is not in a filter's or a list's set, and a tick that names one is the same 404 as a missing record.
 * At most `bulk_sync_rows` (the setting, 1,000 by default) are acted on; MORE IS REFUSED with "Narrow the selection or use a list" — the design's "the worker beyond" needs a jobs table the schema
 * does not have (recorded for the owner; Extended).
 */
require_once dirname(__DIR__) . '/lists/queries.php';
require_once dirname(__DIR__) . '/records/queries.php';
require_once dirname(__DIR__) . '/custom_fields/queries.php';
require_once dirname(__DIR__) . '/accounts/queries.php';

const BULK_REFUSED_OVER = 'Narrow the selection or use a list.';

function bulk_limit(PDO $pdo): int
{
    return max(10, (int) (one_value($pdo, "SELECT crm_setting_int('bulk_sync_rows')") ?: 1000));
}

/** The ids a request names, as integers (a form's `records[]`, or a comma-separated string from an agent). */
function bulk_ids_from(mixed $v): array
{
    $v = is_array($v) ? $v : explode(',', (string) $v);
    return array_values(array_unique(array_map('intval', array_filter(array_map(static fn ($x): string => trim((string) $x), $v), static fn (string $x): bool => $x !== '' && ctype_digit($x)))));
}

/**
 * The records an action will act on: ['ids', 'total', 'over_limit', 'source' => records|list|filters, 'list' => ?id, 'filters' => ?compact]. $input: records, list, filters, scope. A DomainException
 * says what is wrong in words ('Not found.' for a list or a record the caller cannot see).
 */
function bulk_select(PDO $pdo, string $kind, array $input): array
{
    if (!isset(FILTER_KINDS[$kind])) {
        throw new DomainException('Say which kind of record: ' . implode(', ', array_keys(FILTER_KINDS)) . '.');
    }
    $scope = (string) ($input['scope'] ?? '');
    $records = $scope === 'all' ? [] : bulk_ids_from($input['records'] ?? []);
    $list = $scope === 'selected' || !isset($input['list']) || $input['list'] === '' ? null : (int) $input['list'];
    $rawFilters = $scope === 'selected' ? null : ($input['filters'] ?? null);
    $filters = $rawFilters === null || $rawFilters === '' || $rawFilters === [] ? null : $rawFilters;
    $named = ($records !== [] ? 1 : 0) + ($list !== null ? 1 : 0) + ($filters !== null ? 1 : 0);
    if ($named === 0) {
        throw new DomainException($scope === 'selected' ? 'Tick the ' . RECORD_KINDS[$kind]['noun'] . 's to act on.' : 'Say what to act on: records, a list or filters.');
    }
    if ($named > 1) {
        throw new DomainException('Say what to act on with one of records, a list or filters — not several.');
    }
    $limit = bulk_limit($pdo);
    $over = static fn (int $total): never => throw new DomainException('That is ' . number_format($total) . ' ' . RECORD_KINDS[$kind]['noun'] . 's and a bulk action takes up to ' . number_format($limit) . '. ' . BULK_REFUSED_OVER);
    if ($records !== []) {
        if (count($records) > $limit) { $over(count($records)); }
        $k = RECORD_KINDS[$kind];
        $st = $pdo->prepare("SELECT {$k['id']} FROM {$k['view']} WHERE {$k['id']} = ANY (CAST(:ids AS bigint[]))");
        $st->execute(['ids' => pg_array_literal($records)]);
        $seen = array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
        if (array_diff($records, $seen) !== []) {
            throw new DomainException('Not found.');
        }
        return ['ids' => $records, 'total' => count($records), 'over_limit' => false, 'source' => 'records', 'list' => null, 'filters' => null];
    }
    if ($list !== null) {
        $l = find_list($pdo, $list) ?? throw new DomainException('Not found.');
        if ($l['record_kind'] !== $kind) {
            throw new DomainException('That list holds ' . LIST_RECORD_KINDS[$l['record_kind']] . ', not ' . FILTER_KINDS[$kind] . '.');
        }
        $r = $l['kind'] === 'static' ? filters_ids($pdo, $kind, [], $limit, ['in_list' => $list]) : filters_ids($pdo, $kind, $l['filters'], $limit);
        if ($r['over_limit']) { $over($r['total']); }
        if ($r['ids'] === []) { throw new DomainException('Nothing is in that list for you to act on.'); }
        return $r + ['source' => 'list', 'list' => $list, 'filters' => $l['kind'] === 'smart' ? $l['filters'] : null];
    }
    $compact = filters_parse($kind, filters_decode($filters));
    $r = filters_ids($pdo, $kind, $compact, $limit);
    if ($r['over_limit']) { $over($r['total']); }
    if ($r['ids'] === []) { throw new DomainException('Nothing matches those filters, so there is nothing to change.'); }
    return $r + ['source' => 'filters', 'list' => null, 'filters' => $compact];
}

/**
 * The records the caller may not CHANGE among $ids (the edit wall — crm_edit_wall: the owner, an unowned record with the kind's write right, a manager): one is enough to refuse the whole
 * action, naming it (403, like the edit rule on one record). Returns when all may be changed.
 */
function bulk_require_editable(PDO $pdo, string $kind, array $ids): void
{
    $k = RECORD_KINDS[$kind];
    $st = $pdo->prepare("SELECT t.id, {$k['name']} AS name, t.owner_member_id FROM {$k['table']} t WHERE t.id = ANY (CAST(:ids AS bigint[])) AND NOT crm_edit_wall(t.owner_member_id, :right) ORDER BY t.id LIMIT 2");
    $st->execute(['ids' => pg_array_literal($ids), 'right' => $k['right']]);
    $bad = $st->fetchAll();
    if ($bad === []) { return; }
    $owner = $bad[0]['owner_member_id'] !== null ? (string) member_name($pdo, (int) $bad[0]['owner_member_id']) : '';
    refuse(403, 'Only ' . ($owner !== '' ? $owner . ', the owner,' : 'the owner') . ' or a manager may change the ' . $k['noun'] . ' ' . trim((string) $bad[0]['name']) . ($bad[1] ?? null ? ' (and more)' : '') . ' — nothing was changed.');
}

/**
 * The columns a bulk update may set, per kind: field => [column, how the value is read, its argument, may it be cleared]. Reads: ref (an id in a view, "view:column"), country, enum (a|b|c), text (max
 * characters), date, band. Not here, by design: an owner (bulk_assign), the stage and status of a deal (deal_move keeps its required fields), a lead's status (conversion and qualifying have their
 * reasons), the opt-outs, the restriction, anything a trigger derives.
 */
const BULK_FIELDS = [
    'lead' => ['source' => ['source_id', 'ref', 'mcp_lead_sources:source_id', true], 'campaign' => ['campaign_id', 'ref', 'mcp_campaigns:campaign_id', true], 'country' => ['country', 'country', '', true],
               'title' => ['title', 'text', '120', true]],
    'contact' => ['role' => ['role', 'enum', 'decision_maker|champion|influencer|user|finance|procurement|other', true], 'preferred_channel' => ['preferred_channel', 'enum', 'email|phone|sms', true],
                  'source' => ['source_id', 'ref', 'mcp_lead_sources:source_id', true], 'country' => ['country', 'country', '', true], 'title' => ['title', 'text', '120', true]],
    'account' => ['kind' => ['kind', 'enum', 'prospect|partner|other|former', false], 'industry' => ['industry', 'text', '120', true], 'employees' => ['employees', 'band', '', true],
                  'country' => ['country', 'country', '', true], 'source' => ['source_id', 'ref', 'mcp_lead_sources:source_id', true]],
    'deal' => ['expected_close_date' => ['expected_close_date', 'date', '', true], 'source' => ['source_id', 'ref', 'mcp_lead_sources:source_id', true], 'campaign' => ['campaign_id', 'ref', 'mcp_campaigns:campaign_id', true]],
];

/** The fields a bulk update offers for a kind, in words: field => label. Custom fields are named custom:<key>. */
function bulk_field_choices(PDO $pdo, string $kind): array
{
    $words = ['source' => 'Source', 'campaign' => 'Campaign', 'country' => 'Country', 'title' => 'Job title', 'role' => 'Role', 'preferred_channel' => 'Preferred channel', 'kind' => 'Kind', 'industry' => 'Industry',
              'employees' => 'Size', 'expected_close_date' => 'Expected close date'];
    $out = [];
    foreach (array_keys(BULK_FIELDS[$kind] ?? []) as $f) { $out[$f] = $words[$f] ?? $f; }
    foreach (custom_field_defs($pdo, $kind) as $d) { $out['custom:' . $d['key']] = $d['label'] . ' (custom)'; }
    return $out;
}

/** Is there any bulk right the bar could offer this caller? (A viewer sees no ticks and no bar.) */
function bulk_enabled(): bool
{
    foreach (['records.bulk', 'tags.apply', 'campaigns.manage', 'lists.own', 'export.own'] as $r) { if (has_right($r)) { return true; } }
    return false;
}

/** The words of a finished bulk action for the banner a list screen shows (?notice=bulk_<action>&n=<count>&k=<kind>): built from whitelisted parts only, null when the request names none. */
function bulk_notice(): ?array
{
    $key = (string) ($_GET['notice'] ?? '');
    if (!str_starts_with($key, 'bulk_')) { return null; }
    $act = substr($key, 5);
    $n = max(0, (int) ($_GET['n'] ?? 0));
    $kind = isset(FILTER_KINDS[$k = (string) ($_GET['k'] ?? '')]) ? $k : 'record';
    $noun = ($kind === 'record' ? 'record' : RECORD_KINDS[$kind]['noun']) . ($n === 1 ? '' : 's');
    $text = match ($act) {
        'assign' => "Gave $n $noun a new owner.", 'tag' => "Changed the tags of $n $noun.", 'update' => "Updated $n $noun.", 'campaign' => "Added $n $noun to the campaign.",
        'list' => "Added $n $noun to the list.", 'delete' => "Deleted $n $noun. They can be restored for a while.", default => null,
    };
    return $text === null ? null : ['success', $text];
}

/**
 * The bulk bar for a list screen (shared/bulk-bar.php): ticks in the cards (form="bulk-bar") and, for the whole result, the screen's filters or the list it shows. '' when the caller holds no right a bulk
 * action needs. $filters are the screen's parsed filters; $list the list being shown (its members are the "all"); $total how many the screen has.
 */
function bulk_bar(PDO $pdo, string $kind, array $filters, int $total, ?int $list = null, string $back = ''): string
{
    $may = ['assign' => has_right('records.bulk'), 'tag' => has_right('records.bulk') || has_right('tags.apply'), 'update' => has_right('records.bulk'),
            'campaign' => has_right('campaigns.manage') && in_array($kind, ['lead', 'contact'], true), 'list' => has_right('lists.own'), 'delete' => has_right('records.bulk'), 'export' => has_right('export.own')];
    if (!in_array(true, $may, true)) { return ''; }
    return view('shared/bulk-bar.php', ['kind' => $kind, 'filters_json' => $list === null ? filters_json(filters_parse($kind, $filters)) : null, 'list' => $list, 'total' => $total, 'may' => $may,
        'fields' => $may['update'] ? bulk_field_choices($pdo, $kind) : [], 'back' => $back !== '' ? $back : here_url(), 'limit' => bulk_limit($pdo)]);
}

/** One tick for a card or a row (shared/bulk-check.php): a checkbox of the bar's form. '' when the screen has no bar. */
function bulk_check(string $kind, int $id, bool $on, string $label = ''): string
{
    return $on ? view('shared/bulk-check.php', ['kind' => $kind, 'id' => $id, 'label' => $label]) : '';
}

/** The end of a bulk handler: the sentence, the count as the record id, the landing (back where the bar was, with the notice) and the extra the JSON learns. */
function bulk_done(string $action, string $kind, array $sel, int $count, string $did, array $extra = []): never
{
    $default = $sel['list'] !== null ? '/lists/' . $sel['list'] : ['lead' => '/leads', 'contact' => '/contacts', 'account' => '/accounts', 'deal' => pipeline_board_url()][$kind];
    [$path, $query] = array_pad(explode('?', return_path($default), 2), 2, '');
    parse_str($query, $q);
    unset($q['notice'], $q['n'], $q['k']);                                              // the page the bar was on may already carry an earlier notice
    $land = $path . '?' . http_build_query($q + ['notice' => 'bulk_' . $action, 'n' => $count, 'k' => $kind]);
    crm_done($did, $count, $land, $kind . 'Changed', ['count' => $count, 'selected' => count($sel['ids']), 'source' => $sel['source']] + $extra);
}
