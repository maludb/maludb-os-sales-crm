<?php
declare(strict_types=1);

/**
 * The reports' screens and their JSON (whitelisted): report_screen() is what each html/reports/<name>.php runs — the filters from the request, the report, the log row, then the page or the data.
 * report() already answers scalars under fixed keys, so the presenter only names what leaves.
 */
require_once __DIR__ . '/queries.php';
require_once __DIR__ . '/charts.php';
require_once dirname(__DIR__) . '/campaigns/queries.php';
require_once dirname(__DIR__) . '/records/queries.php';

function present_report(array $r, array $may): array
{
    $f = $r['filters'];
    return [
        'report' => $r['name'],
        'title' => $r['title'],
        'period' => isset($f['from']) ? ['preset' => $f['preset'] ?? 'custom', 'from' => $f['from'], 'to' => $f['to']] : (isset($f['month']) ? ['month' => $f['month']] : null),
        'filters' => array_filter(array_diff_key($f, ['from' => 1, 'to' => 1, 'preset' => 1]), static fn ($v): bool => $v !== null),
        'currency' => $r['currency'],
        'headline' => $r['headline'],
        'columns' => array_map(static fn (array $c): array => ['label' => $c[0], 'key' => $c[1], 'type' => $c[2]], $r['columns']),
        'rows' => $r['rows'],
        'chart' => $r['chart'],
        'extra' => $r['extra'],
        'note' => $r['note'],
        'may' => $may,
    ];
}

function present_report_cards(array $cards): array
{
    return array_map(static fn (array $c): array => ['report' => $c['name'], 'title' => $c['title'], 'url' => $c['url'], 'headline' => $c['headline'], 'period' => $c['period']], $cards);
}

/** Serve one report's screen: the page, or the JSON. */
function report_screen(string $name): void
{
    require_login();
    require_right('records.read');
    $pdo = db();
    $filters = report_filters_from_request($pdo, $name);
    $r = report($pdo, $name, $filters);
    $screen = 'report-' . str_replace('_', '-', $name);
    log_screen_view($pdo, $screen);
    log_activity($pdo, 'report.view', 'report', null, ['screen' => $screen, 'after' => ['report' => $name, 'filters' => array_filter($filters, static fn ($v): bool => $v !== null)]]);
    $may = ['export' => has_right('export.own'), 'team' => has_right('reports.team')];
    if (wants_json()) {
        respond_screen(present_report($r, $may));
    }
    render_screen($r['title'] . ' report', view('reports/report.php', ['report' => $r, 'options' => ['pipelines' => find_pipelines($pdo), 'owners' => find_owners($pdo), 'teams' => find_teams($pdo)],
        'has_period' => isset($r['filters']['from']), 'may' => $may, 'pipeline_forecast_url' => str_replace('/board', '/forecast', pipeline_board_url())]),
        ['activeNav' => 'reports', 'screen' => $screen, 'entity' => 'report']);
}
