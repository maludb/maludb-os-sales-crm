<?php
declare(strict_types=1);

/**
 * The reports (slice 7; reports-home.md): nine reads of db/015 and db/017, each answered by ONE function — report(pdo, name, filters) — as
 *   array{name, title, period, rows, columns, headline, chart, ...}
 * rows are exactly what the database function answered (the wall is its own: a Rep's activity summary holds their rows, the leaderboard is a manager's, a forecast by seller
 * shows a Rep their own line), columns say how the table and the CSV label and format them, headline is the card's one number, chart is DATA (charts.php draws it).
 * Nothing here judges who may see what that the functions do not; the single rule added in PHP is the forecast by seller, which crm_forecast() answers for every seller.
 * Run as the caller (a screen) or as an export's maker (the worker sets app.member_id), so nothing here reads the session but through the database.
 */
require_once dirname(__DIR__) . '/pipeline/queries.php';
require_once __DIR__ . '/periods.php';

/** The reports in menu order: name => [title, URL slug, what the card says it shows, the period it opens on]. */
const REPORTS = [
    'forecast'    => ['Forecast',     'forecast',     'weighted this month', 'this_month'],
    'funnel'      => ['Funnel',       'funnel',       'the weakest stage',   'this_quarter'],
    'win_rate'    => ['Win rate',     'win-rate',     'won of closed',       'this_quarter'],
    'cycle_time'  => ['Cycle time',   'cycle-time',   'days to win',         'this_quarter'],
    'sources'     => ['Sources',      'sources',      'the best source',     'this_quarter'],
    'campaigns'   => ['Campaigns',    'campaigns',    'cost per won deal',   'this_quarter'],
    'activity'    => ['Activity',     'activity',     'touches this week',   'this_month'],
    'leaderboard' => ['Leaderboard',  'leaderboard',  'the leader',          'this_month'],
    'lost_reasons' => ['Lost reasons', 'lost-reasons', 'the top reason',      'this_quarter'],
];
/** A small report downloads at once (a POST, the same screen's rows); more, or on request, is a queued export built by the worker. */
const REPORT_INLINE_ROWS = 2000;

/** The report a URL slug or a name means (win-rate or win_rate), or null. */
function report_name(string $text): ?string
{
    $n = str_replace('-', '_', strtolower(trim($text)));
    return isset(REPORTS[$n]) ? $n : null;
}

function report_url(string $name, array $query = []): string
{
    $q = array_filter($query, static fn ($v): bool => $v !== null && $v !== '');
    return '/reports/' . REPORTS[$name][1] . ($q === [] ? '' : '?' . http_build_query($q));
}

/** The filters a request names, resolved to values report() can run (and an export can keep): the period as two dates, the rest as ids and words. */
function report_filters_from_request(PDO $pdo, string $name): array
{
    $f = [];
    if (!in_array($name, ['leaderboard', 'campaigns', 'forecast'], true)) {
        $f = periods_from_request(REPORTS[$name][3]);
    }
    $team = request_integer('team');
    switch ($name) {
        case 'funnel':
            $f['pipeline'] = pipeline_from_request($pdo)['pipeline_id'];
            $owner = request_string('owner');
            $f['owner'] = $owner === 'me' ? (int) current_member_id() : (ctype_digit($owner) && $owner !== '' ? (int) $owner : null);
            break;
        case 'win_rate':
            $by = request_string('by');
            $f['by'] = in_array($by, ['seller', 'source', 'pipeline'], true) ? $by : 'seller';
            break;
        case 'campaigns':
            $status = request_string('status');
            $f['status'] = isset(CAMPAIGN_STATUSES[$status]) ? $status : null;
            break;
        case 'activity':
            $f['team'] = $team;
            break;
        case 'leaderboard':
            $f['month'] = periods_month(request_string('month'));
            $f['team'] = $team;
            break;
        case 'lost_reasons':
            $f['by'] = request_string('by') === 'seller' ? 'seller' : 'reason';
            break;
        case 'forecast':
            $by = request_string('by');
            $months = request_integer('months');
            $f['by'] = $by === 'stage' ? 'stage' : 'seller';
            $f['months'] = $months === null ? (int) one_value($pdo, "SELECT crm_setting_int('forecast_months')") : max(1, min(12, $months));
            $f['team'] = $team;
            break;
        default:
            break;
    }
    return $f;
}

/** How much of a quota a number is: 'success' from 100 up, 'warning' from 70, else 'danger'; null when there is no quota to measure against. */
function report_tone(?float $attainment): ?string
{
    if ($attainment === null) {
        return null;
    }
    return $attainment >= 100 ? 'success' : ($attainment >= 70 ? 'warning' : 'danger');
}

function report_pct(?float $part, ?float $whole): ?float
{
    return $whole === null || $whole <= 0 || $part === null ? null : round(100 * $part / $whole, 1);
}

/** The period of a report's filters as the screens print it: "Oct 1 – Dec 31, 2026". */
function report_period_words(array $f): string
{
    if (!isset($f['from'], $f['to'])) {
        return isset($f['month']) ? (new DateTimeImmutable($f['month']))->format('F Y') : '';
    }
    $a = new DateTimeImmutable($f['from']);
    $b = new DateTimeImmutable($f['to']);
    return $a->format('Y') === $b->format('Y') ? $a->format('M j') . ' – ' . $b->format('M j, Y') : $a->format('M j, Y') . ' – ' . $b->format('M j, Y');
}

/**
 * One report: array{name, title, filters, period, rows, columns, headline{text, label, detail, tone}, chart, note}. The columns are [label, key, type] with type text|int|money|pct|dec|days|date|json.
 * $filters are report_filters_from_request()'s (or an export's kept ones); anything missing takes the report's default.
 */
function report(PDO $pdo, string $name, array $filters): array
{
    if (!isset(REPORTS[$name])) {
        throw new DomainException('There is no report called ' . $name . '.');
    }
    $f = $filters;
    if (!in_array($name, ['leaderboard', 'campaigns', 'forecast'], true) && !isset($f['from'], $f['to'])) {
        $f += periods(REPORTS[$name][3]);
    }
    if ($name === 'leaderboard') {
        $f['month'] = periods_month((string) ($f['month'] ?? ''));
    }
    $cur = base_currency($pdo);
    $r = ['name' => $name, 'title' => REPORTS[$name][0], 'filters' => $f, 'period' => report_period_words($f), 'rows' => [], 'columns' => [], 'currency' => $cur,
          'headline' => ['text' => '—', 'label' => REPORTS[$name][2], 'detail' => '', 'tone' => null], 'chart' => ['type' => 'none'], 'note' => '', 'extra' => []];
    $int = static fn ($v): ?int => $v === null ? null : (int) $v;
    $num = static fn ($v): ?float => $v === null ? null : (float) $v;
    switch ($name) {
        case 'funnel':
            $st = $pdo->prepare('SELECT * FROM crm_funnel(:p, :a, :b, :o)');
            $st->execute(['p' => $f['pipeline'] ?? pipeline_from_request($pdo, '')['pipeline_id'], 'a' => $f['from'], 'b' => $f['to'], 'o' => $f['owner'] ?? null]);
            $weak = null;
            foreach ($st->fetchAll() as $x) {
                $entered = (int) $x['entered'];
                $moved = (int) $x['moved_on'];
                $row = ['stage_id' => (int) $x['stage_id'], 'stage_name' => $x['stage_name'], 'position' => (int) $x['stage_position'], 'entered' => $entered, 'moved_on' => $moved, 'lost_from' => (int) $x['lost_from'],
                        'still_here' => (int) $x['still_here'], 'median_days' => $num($x['median_days']), 'conversion' => report_pct((float) $moved, (float) $entered)];
                $r['rows'][] = $row;
                $left = $row['moved_on'] + $row['lost_from'];                              // the stage leaks where, of the deals that LEFT it, the fewest moved on (those still there have not decided)
                $row['kept'] = $left > 0 ? report_pct((float) $row['moved_on'], (float) $left) : null;
                if ($row['kept'] !== null && ($weak === null || $row['kept'] < $weak['kept'])) {
                    $weak = $row;
                }
            }
            $r['columns'] = [['Stage', 'stage_name', 'text'], ['Entered', 'entered', 'int'], ['Moved on', 'moved_on', 'int'], ['Lost from here', 'lost_from', 'int'], ['Still here', 'still_here', 'int'],
                             ['Moved on %', 'conversion', 'pct'], ['Median days', 'median_days', 'dec']];
            if ($weak !== null && $weak['kept'] >= 100.0) {                                   // nothing leaked: no stage is the weakest
                $r['headline'] = ['text' => 'None', 'label' => 'the weakest stage', 'detail' => 'every deal that left a stage moved on', 'tone' => null];
            } elseif ($weak !== null) {
                $r['headline'] = ['text' => $weak['stage_name'], 'label' => 'the weakest stage', 'detail' => number_format($weak['kept'], 1) . '% of those that left it moved on', 'tone' => null];
            }
            $r['chart'] = ['type' => 'funnel', 'title' => 'Deals by the stage they entered in ' . $r['period'], 'stages' => array_map(static fn (array $x): array => ['label' => $x['stage_name'], 'entered' => $x['entered'],
                'moved_on' => $x['moved_on'], 'still_here' => $x['still_here'], 'lost_from' => $x['lost_from']], $r['rows'])];
            break;

        case 'win_rate':
            $st = $pdo->prepare('SELECT * FROM crm_win_rate(:a, :b, :by)');
            $st->execute(['a' => $f['from'], 'b' => $f['to'], 'by' => $f['by'] ?? 'seller']);
            $won = $lost = 0;
            foreach ($st->fetchAll() as $x) {
                $r['rows'][] = ['row_id' => $int($x['row_id']), 'row_name' => $x['row_name'] ?? '(none)', 'won' => (int) $x['won'], 'lost' => (int) $x['lost'], 'win_rate' => $num($x['win_rate']),
                                'won_value' => (float) $x['won_value'], 'avg_cycle_days' => $num($x['avg_cycle_days']), 'median_cycle_days' => $num($x['median_cycle_days'])];
                $won += (int) $x['won'];
                $lost += (int) $x['lost'];
            }
            $label = ['seller' => 'Seller', 'source' => 'Source', 'pipeline' => 'Pipeline'][$f['by'] ?? 'seller'];
            $r['columns'] = [[$label, 'row_name', 'text'], ['Won', 'won', 'int'], ['Lost', 'lost', 'int'], ['Win rate', 'win_rate', 'pct'], ['Won value', 'won_value', 'money'], ['Average days', 'avg_cycle_days', 'dec'], ['Median days', 'median_cycle_days', 'dec']];
            if ($won + $lost > 0) {
                $r['headline'] = ['text' => number_format(100 * $won / ($won + $lost), 1) . '%', 'label' => 'won of closed', 'detail' => $won . ' won, ' . $lost . ' lost', 'tone' => null];
            }
            $r['chart'] = ['type' => 'bars', 'title' => 'Win rate by ' . strtolower($label), 'unit' => 'pct', 'categories' => array_column($r['rows'], 'row_name'), 'series' => [['key' => 'win_rate', 'label' => 'Win rate', 'values' => array_map(static fn ($x) => $x['win_rate'] ?? 0.0, $r['rows'])]]];
            break;

        case 'cycle_time':
            $st = $pdo->prepare("SELECT * FROM crm_win_rate(:a, :b, 'seller')");
            $st->execute(['a' => $f['from'], 'b' => $f['to']]);
            foreach ($st->fetchAll() as $x) {
                if ((int) $x['won'] === 0) {
                    continue;
                }
                $r['rows'][] = ['row_id' => $int($x['row_id']), 'row_name' => $x['row_name'] ?? '(none)', 'won' => (int) $x['won'], 'avg_cycle_days' => $num($x['avg_cycle_days']), 'median_cycle_days' => $num($x['median_cycle_days'])];
            }
            $t = one_row($pdo, 'SELECT * FROM crm_touches_to_win(:a, :b)', ['a' => $f['from'], 'b' => $f['to']]) ?? [];
            $r['extra'] = ['touches' => ['deals' => (int) ($t['deals'] ?? 0), 'avg_touches' => $num($t['avg_touches'] ?? null), 'median_touches' => $num($t['median_touches'] ?? null), 'avg_days' => $num($t['avg_days'] ?? null)]];
            $r['columns'] = [['Seller', 'row_name', 'text'], ['Won', 'won', 'int'], ['Average days', 'avg_cycle_days', 'dec'], ['Median days', 'median_cycle_days', 'dec']];
            if ($r['extra']['touches']['deals'] > 0 && $r['extra']['touches']['avg_days'] !== null) {
                $r['headline'] = ['text' => number_format($r['extra']['touches']['avg_days'], 1) . ' days', 'label' => 'on average from creation to won', 'detail' => $r['extra']['touches']['deals'] . ' won deal' . ($r['extra']['touches']['deals'] === 1 ? '' : 's'), 'tone' => null];
            }
            $r['chart'] = ['type' => 'bars', 'title' => 'Days from creation to won, by seller', 'unit' => 'days', 'categories' => array_column($r['rows'], 'row_name'), 'series' => [
                ['key' => 'median', 'label' => 'Median', 'values' => array_map(static fn ($x) => $x['median_cycle_days'] ?? 0.0, $r['rows'])],
                ['key' => 'average', 'label' => 'Average', 'values' => array_map(static fn ($x) => $x['avg_cycle_days'] ?? 0.0, $r['rows'])]]];
            break;

        case 'sources':
            $st = $pdo->prepare('SELECT * FROM crm_source_performance(:a, :b)');
            $st->execute(['a' => $f['from'], 'b' => $f['to']]);
            $idle = 0;
            $best = null;
            foreach ($st->fetchAll() as $x) {
                $row = ['source_id' => (int) $x['source_id'], 'source_name' => $x['source_name'], 'leads' => (int) $x['leads'], 'converted' => (int) $x['converted'], 'unqualified' => (int) $x['unqualified'], 'deals' => (int) $x['deals'],
                        'won' => (int) $x['won'], 'won_value' => (float) $x['won_value'], 'conversion_rate' => $num($x['conversion_rate'])];
                if ($row['leads'] + $row['deals'] + $row['won'] === 0) {
                    $idle++;
                    continue;
                }
                $r['rows'][] = $row;
                if ($row['won_value'] > 0 && ($best === null || $row['won_value'] > $best['won_value'])) {
                    $best = $row;
                }
            }
            $r['note'] = $idle > 0 ? $idle . ' source' . ($idle === 1 ? '' : 's') . ' with nothing in this period ' . ($idle === 1 ? 'is' : 'are') . ' left out.' : '';
            $r['columns'] = [['Source', 'source_name', 'text'], ['Leads', 'leads', 'int'], ['Converted', 'converted', 'int'], ['Unqualified', 'unqualified', 'int'], ['Deals', 'deals', 'int'], ['Won', 'won', 'int'],
                             ['Won value', 'won_value', 'money'], ['Conversion', 'conversion_rate', 'pct']];
            if ($best !== null) {
                $r['headline'] = ['text' => $best['source_name'], 'label' => 'the best source', 'detail' => money($best['won_value'], $cur) . ' won', 'tone' => null];
            }
            $r['chart'] = ['type' => 'bars', 'title' => 'Leads and won deals by source', 'unit' => 'count', 'categories' => array_column($r['rows'], 'source_name'), 'series' => [
                ['key' => 'leads', 'label' => 'Leads', 'values' => array_column($r['rows'], 'leads')], ['key' => 'won', 'label' => 'Won deals', 'values' => array_column($r['rows'], 'won')]]];
            break;

        case 'campaigns':
            $w = ($f['status'] ?? null) !== null ? 'WHERE c.status = :s' : '';
            $st = $pdo->prepare('SELECT c.campaign_id, c.name, c.kind, c.status, c.budget, c.currency, r.members, r.leads, r.converted, r.deals, r.won, r.won_value, r.open_value, r.cost_per_lead, r.cost_per_won
                                   FROM mcp_campaigns c LEFT JOIN LATERAL crm_campaign_results(c.campaign_id) r ON true ' . $w . ' ORDER BY c.starts_on DESC NULLS LAST, c.campaign_id DESC LIMIT 200');
            $st->execute($w === '' ? [] : ['s' => $f['status']]);
            $best = null;
            foreach ($st->fetchAll() as $x) {
                $row = ['campaign_id' => (int) $x['campaign_id'], 'name' => $x['name'], 'kind' => $x['kind'], 'status' => $x['status'], 'budget' => $num($x['budget']), 'members' => (int) $x['members'], 'leads' => (int) $x['leads'],
                        'converted' => (int) $x['converted'], 'deals' => (int) $x['deals'], 'won' => (int) $x['won'], 'won_value' => (float) $x['won_value'], 'cost_per_lead' => $num($x['cost_per_lead']), 'cost_per_won' => $num($x['cost_per_won'])];
                $r['rows'][] = $row;
                if ($row['cost_per_won'] !== null && ($best === null || $row['cost_per_won'] < $best['cost_per_won'])) {
                    $best = $row;
                }
            }
            $r['columns'] = [['Campaign', 'name', 'text'], ['Status', 'status', 'text'], ['Budget', 'budget', 'money'], ['Members', 'members', 'int'], ['Leads', 'leads', 'int'], ['Deals', 'deals', 'int'], ['Won', 'won', 'int'],
                             ['Won value', 'won_value', 'money'], ['Cost per lead', 'cost_per_lead', 'money'], ['Cost per won', 'cost_per_won', 'money']];
            if ($best !== null) {
                $r['headline'] = ['text' => money($best['cost_per_won'], $cur), 'label' => 'cost per won deal, ' . $best['name'], 'detail' => 'the lowest of ' . count($r['rows']) . ' campaign' . (count($r['rows']) === 1 ? '' : 's'), 'tone' => null];
            }
            $with = array_values(array_filter($r['rows'], static fn (array $x): bool => $x['cost_per_won'] !== null));
            $r['chart'] = ['type' => 'bars', 'title' => 'Cost per won deal, by campaign', 'unit' => 'money', 'categories' => array_column($with, 'name'), 'series' => [['key' => 'cost_per_won', 'label' => 'Cost per won deal', 'values' => array_column($with, 'cost_per_won')]]];
            break;

        case 'activity':
            $st = $pdo->prepare('SELECT * FROM crm_activity_summary(:a, :b, :t)');
            $st->execute(['a' => $f['from'], 'b' => $f['to'], 't' => $f['team'] ?? null]);
            $done = 0;
            $kinds = [];
            $people = [];
            foreach ($st->fetchAll() as $x) {
                $outcomes = json_decode((string) $x['outcomes'], true) ?: [];
                $r['rows'][] = ['member_id' => (int) $x['member_id'], 'display_name' => $x['display_name'], 'kind' => $x['kind'], 'done' => (int) $x['done'], 'scheduled' => (int) $x['scheduled'], 'outcomes' => $outcomes];
                $done += (int) $x['done'];
                $kinds[$x['kind']] = true;
                $people[(int) $x['member_id']] = $x['display_name'];
            }
            $r['columns'] = [['Seller', 'display_name', 'text'], ['Kind', 'kind', 'text'], ['Done', 'done', 'int'], ['Still scheduled', 'scheduled', 'int'], ['Outcomes', 'outcomes', 'json']];
            $r['headline'] = ['text' => (string) $done, 'label' => 'touches done', 'detail' => count($people) . ' seller' . (count($people) === 1 ? '' : 's'), 'tone' => null];
            $series = [];
            foreach (array_keys($kinds) as $k) {
                $vals = [];
                foreach (array_keys($people) as $pid) {
                    $vals[] = array_sum(array_map(static fn (array $x): int => $x['member_id'] === $pid && $x['kind'] === $k ? $x['done'] : 0, $r['rows']));
                }
                $series[] = ['key' => $k, 'label' => ucfirst($k), 'values' => $vals];
            }
            $r['chart'] = ['type' => 'bars', 'stacked' => true, 'title' => 'Touches done, by seller and kind', 'unit' => 'count', 'categories' => array_values($people), 'series' => $series];
            break;

        case 'leaderboard':
            $st = $pdo->prepare('SELECT * FROM crm_leaderboard(:m, :t)');
            $st->execute(['m' => $f['month'], 't' => $f['team'] ?? null]);
            foreach ($st->fetchAll() as $x) {
                $r['rows'][] = ['member_id' => (int) $x['member_id'], 'display_name' => $x['display_name'], 'won_value' => (float) $x['won_value'], 'won_count' => (int) $x['won_count'], 'quota' => $num($x['quota']),
                                'attainment' => $num($x['attainment']), 'open_weighted' => (float) $x['open_weighted'], 'activities_done' => (int) $x['activities_done']];
            }
            $r['columns'] = [['Seller', 'display_name', 'text'], ['Won', 'won_value', 'money'], ['Deals won', 'won_count', 'int'], ['Quota', 'quota', 'money'], ['Attainment', 'attainment', 'pct'], ['Open weighted', 'open_weighted', 'money'],
                             ['Activities done', 'activities_done', 'int']];
            if ($r['rows'] === []) {
                $r['note'] = db_bool($pdo, "SELECT crm_has_right('reports.team')") ? 'No one sells here yet.' : 'The leaderboard is the team managers\'.';
            } else {
                $lead = $r['rows'][0];
                $r['headline'] = ['text' => $lead['display_name'], 'label' => 'the leader', 'detail' => money($lead['won_value'], $cur) . ' won' . ($lead['attainment'] !== null ? ', ' . number_format($lead['attainment'], 1) . '% of quota' : ''), 'tone' => report_tone($lead['attainment'])];
            }
            $r['chart'] = ['type' => 'bars', 'title' => 'Won against quota', 'unit' => 'money', 'categories' => array_column($r['rows'], 'display_name'), 'series' => [['key' => 'won_value', 'label' => 'Won', 'values' => array_column($r['rows'], 'won_value')]],
                           'marks' => ['label' => 'Quota', 'values' => array_column($r['rows'], 'quota')]];
            break;

        case 'lost_reasons':
            $bySeller = ($f['by'] ?? 'reason') === 'seller';
            $st = $pdo->prepare('SELECT * FROM crm_lost_reasons_summary(:a, :b, :s)');
            $st->bindValue('a', $f['from']);
            $st->bindValue('b', $f['to']);
            $st->bindValue('s', $bySeller, PDO::PARAM_BOOL);
            $st->execute();
            $unused = 0;
            $byReason = [];
            foreach ($st->fetchAll() as $x) {
                if ((int) $x['deals'] === 0) {
                    $unused++;
                    continue;
                }
                $r['rows'][] = ['lost_reason_id' => (int) $x['lost_reason_id'], 'label' => $x['label'], 'member_id' => $int($x['member_id']), 'display_name' => $x['display_name'], 'deals' => (int) $x['deals'], 'lost_value' => (float) $x['lost_value']];
                $byReason[$x['label']] = ($byReason[$x['label']] ?? 0) + (int) $x['deals'];
            }
            arsort($byReason);
            $r['note'] = $unused > 0 ? $unused . ' reason' . ($unused === 1 ? '' : 's') . ' nobody gave in this period ' . ($unused === 1 ? 'is' : 'are') . ' left out.' : '';
            $r['columns'] = array_merge([['Reason', 'label', 'text']], $bySeller ? [['Seller', 'display_name', 'text']] : [], [['Deals lost', 'deals', 'int'], ['Value lost', 'lost_value', 'money']]);
            if ($byReason !== []) {
                $top = array_key_first($byReason);
                $r['headline'] = ['text' => (string) $top, 'label' => 'the top reason', 'detail' => $byReason[$top] . ' deal' . ($byReason[$top] === 1 ? '' : 's') . ' lost', 'tone' => null];
            }
            $r['chart'] = ['type' => 'bars', 'title' => 'Deals lost, by reason', 'unit' => 'count', 'categories' => array_keys($byReason), 'series' => [['key' => 'deals', 'label' => 'Deals lost', 'values' => array_values($byReason)]]];
            break;

        case 'forecast':
            $by = ($f['by'] ?? 'seller') === 'stage' ? 'stage' : 'seller';
            $grid = forecast_grid($pdo, null, (int) ($f['months'] ?? 6), $by, $f['team'] ?? null);
            $team = db_bool($pdo, "SELECT crm_has_right('reports.team')");
            if (!$team) {                                                                  // the one rule PHP adds: another seller's line is a manager's
                $me = (int) one_value($pdo, 'SELECT app_current_member_id()');
                if ($by === 'seller') {
                    $grid['rows'] = array_values(array_filter($grid['rows'], static fn (array $x): bool => $x['id'] === $me));
                    $grid['totals'] = [];
                    foreach ($grid['rows'] as $x) {
                        foreach ($x['cells'] as $m => $c) {
                            $grid['totals'][$m] ??= ['open' => 0.0, 'weighted' => 0.0, 'won' => 0.0, 'deals' => 0, 'quota' => null];
                            foreach (['open', 'weighted', 'won', 'deals'] as $k) { $grid['totals'][$m][$k] += $c[$k]; }
                            if (($x['quota'][$m] ?? null) !== null) { $grid['totals'][$m]['quota'] = ($grid['totals'][$m]['quota'] ?? 0.0) + $x['quota'][$m]; }
                        }
                    }
                } else {
                    foreach ($grid['totals'] as &$t) { $t['quota'] = null; }
                    unset($t);
                }
            }
            foreach ($grid['rows'] as $x) {
                foreach ($grid['months'] as $m) {
                    $c = $x['cells'][$m] ?? ['open' => 0.0, 'weighted' => 0.0, 'won' => 0.0, 'deals' => 0];
                    $q = $team || $by === 'seller' ? ($x['quota'][$m] ?? null) : null;
                    if ($c['deals'] === 0 && $q === null) {
                        continue;
                    }
                    $r['rows'][] = ['month' => $m . '-01', 'row_id' => $x['id'], 'row_name' => $x['name'], 'open_value' => $c['open'], 'weighted' => $c['weighted'], 'won_value' => $c['won'], 'deals' => $c['deals'], 'quota' => $q];
                }
            }
            $r['columns'] = [['Month', 'month', 'month'], [$by === 'seller' ? 'Seller' : 'Stage', 'row_name', 'text'], ['Open value', 'open_value', 'money'], ['Weighted', 'weighted', 'money'], ['Won', 'won_value', 'money'],
                             ['Deals', 'deals', 'int'], ['Quota', 'quota', 'money']];
            $months = $grid['months'];
            $tot = static fn (string $m, string $k): float => (float) ($grid['totals'][$m][$k] ?? 0);
            $r['extra'] = ['months' => array_map(static fn (string $m): array => ['month' => $m . '-01', 'open_value' => $tot($m, 'open'), 'weighted' => $tot($m, 'weighted'), 'won_value' => $tot($m, 'won'), 'deals' => (int) ($grid['totals'][$m]['deals'] ?? 0),
                'quota' => $grid['totals'][$m]['quota'] ?? null], $months)];
            if ($months !== [] && $r['rows'] !== []) {
                $m0 = $r['extra']['months'][0];
                $att = report_pct($m0['won_value'], $m0['quota']);
                $r['headline'] = ['text' => money($m0['weighted'], $cur), 'label' => 'weighted this month', 'detail' => money($m0['won_value'], $cur) . ' won' . ($m0['quota'] !== null ? ' of ' . money($m0['quota'], $cur) . ' quota' : ''), 'tone' => report_tone($att)];
            }
            $series = [['key' => 'open_value', 'label' => 'Open', 'values' => array_column($r['extra']['months'], 'open_value')],
                       ['key' => 'weighted', 'label' => 'Weighted', 'values' => array_column($r['extra']['months'], 'weighted')],
                       ['key' => 'won_value', 'label' => 'Won', 'values' => array_column($r['extra']['months'], 'won_value')]];
            if (array_filter(array_column($r['extra']['months'], 'quota'), static fn ($q): bool => $q !== null) !== []) {
                $series[] = ['key' => 'quota', 'label' => 'Quota', 'dashed' => true, 'values' => array_map(static fn ($q) => $q === null ? null : (float) $q, array_column($r['extra']['months'], 'quota'))];
            }
            if ($months !== []) {
                $r['period'] = (new DateTimeImmutable($months[0] . '-01'))->format('M Y') . (count($months) > 1 ? ' – ' . (new DateTimeImmutable(end($months) . '-01'))->format('M Y') : '');
            }
            $r['chart'] = ['type' => 'lines', 'title' => 'The forecast by month, ' . ($by === 'seller' ? 'every pipeline' : 'by stage'), 'unit' => 'money',
                           'x' => array_map(static fn (string $m): string => (new DateTimeImmutable($m . '-01'))->format('M Y'), $months), 'series' => $series];
            break;
    }
    return $r;
}

/** A cell as the CSV and the table print it (text): money in whole units with the currency apart, never a symbol, so a spreadsheet adds it up. */
function report_cell(string $type, mixed $v): string|int|float|null
{
    if ($v === null || $v === '') {
        return null;
    }
    return match ($type) {
        'json' => is_array($v) ? implode('; ', array_map(static fn ($k, $n): string => $k . ' ' . $n, array_keys($v), $v)) : (string) $v,
        'month' => substr((string) $v, 0, 7),
        'int' => (int) $v,
        'money', 'pct', 'dec', 'days' => round((float) $v, 2),
        default => (string) $v,
    };
}

/** The CSV of a report (array from report()): a header row of the column labels, then each row; a cell a spreadsheet would run as a formula is made plain. UTF-8, no BOM. */
function report_csv(array $report): string
{
    require_once dirname(__DIR__) . '/exports/build.php';
    $fh = fopen('php://memory', 'w+');
    fputcsv($fh, array_map(static fn (array $c): string => $c[0], $report['columns']), ',', '"', '');
    foreach ($report['rows'] as $row) {
        fputcsv($fh, array_map(static fn (array $c): mixed => export_csv_cell(report_cell($c[2], $row[$c[1]] ?? null)), $report['columns']), ',', '"', '');
    }
    rewind($fh);
    return (string) stream_get_contents($fh);
}

/** What a report's filters keep in an export row's params and the log: only the keys the report understands. */
function report_export_params(string $name, array $filters): array
{
    $keep = ['from', 'to', 'pipeline', 'owner', 'by', 'status', 'team', 'month', 'months'];
    $p = [];
    foreach ($keep as $k) {
        if (isset($filters[$k]) && $filters[$k] !== '') {
            $p[$k] = $filters[$k];
        }
    }
    return ['report' => $name, 'filters' => (object) $p];
}

/**
 * Queue a report as an export (slice 6's table, kind `report`): the worker's exports pass builds the file AS ITS MAKER, so a seller's team report holds their rows only. Answers the export's id.
 */
function report_export(PDO $pdo, string $name, array $filters, int $by): int
{
    if (!isset(REPORTS[$name])) {
        throw new DomainException('There is no report called ' . $name . '.');
    }
    $st = $pdo->prepare("INSERT INTO exports (kind, format, status, created_by, params) VALUES ('report', 'csv', 'queued', :by, CAST(:p AS jsonb)) RETURNING id");
    $st->execute(['by' => $by, 'p' => json_encode(report_export_params($name, $filters), JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)]);
    return (int) $st->fetchColumn();
}

/** The cards of /reports: each report's headline on its own default filters. [{name, title, url, headline, period}] */
function report_cards(PDO $pdo): array
{
    $cards = [];
    foreach (array_keys(REPORTS) as $name) {
        $f = $name === 'activity' ? periods_week() : [];
        if ($name === 'forecast') {
            $f = ['by' => 'seller', 'months' => 1];
        }
        $rep = report($pdo, $name, $f);
        $url = report_url($name, $name === 'activity' ? ['from' => $f['from'], 'to' => $f['to']] : []);
        $cards[] = ['name' => $name, 'title' => REPORTS[$name][0], 'url' => $url, 'headline' => $rep['headline'], 'period' => $name === 'activity' ? 'This week' : REPORT_PRESETS[REPORTS[$name][3]], 'note' => $rep['note']];
    }
    return $cards;
}
