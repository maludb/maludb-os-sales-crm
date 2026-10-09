# Build spec: reports, the forecast report and the home's tiles (slice 7)

What exists at the end: the reports area — nine reports as cards with a headline number each, every report a full page with its filters, a table and the dataviz
skill's chart (a bar, a funnel, a line), CSV export; the home's manager tiles filled (the forecast against quota, the leaderboard's top three) and the seller's
closing tile. Every read is a db/015 or db/017 function; the slice draws.

Schema (db/015, 017, never modified): `crm_funnel()`, `crm_win_rate()`, `crm_touches_to_win()`, `crm_source_performance()`, `crm_campaign_results()`, `crm_activity_summary()`,
`crm_leaderboard()`, `crm_forecast()` (db/015); `crm_lost_reasons_summary()` (db/017); `exports` (db/012).

## Divergences from the design
1. **One handler, `report_export`**, serves every report's CSV through slice 6's `exports` (kind `report`, `params` the report and its filters) — the file is built by the worker and the
   page polls; a small report (< 2,000 rows) downloads at once. 2. **The forecast report is the pipeline's forecast view across pipelines** (`pipeline = NULL`).

## Who may do what
Every report: `records.read`; the team's rows (`leaderboard`, `activity_summary` for others, `forecast` by seller for others): `reports.team` — a Rep sees their own rows only (the
functions' rule). `report_export`: `reports.team`, or a Rep for their own rows.

## Screens
`reports` `/reports` (cards: Forecast — this month's weighted; Funnel — the weakest stage; Win rate — this quarter; Cycle time — median days; Sources — the best source; Campaigns — cost per
won; Activity — touches this week; Leaderboard — the leader; Lost reasons — the top reason) · `report-funnel` `/reports/funnel?pipeline=&from=&to=&owner=` (a funnel chart + the table) ·
`report-win-rate` `/reports/win-rate?from=&to=&by=` (bars) · `report-cycle-time` `/reports/cycle-time?from=&to=` (bars + the touches tile) · `report-sources` `/reports/sources?from=&to=` ·
`report-campaigns` `/reports/campaigns?status=` · `report-activity` `/reports/activity?from=&to=&team=` (stacked bars per seller) · `report-leaderboard` `/reports/leaderboard?month=&team=`
(bars against the quota line) · `report-lost-reasons` `/reports/lost-reasons?from=&to=` (bars) · `report-forecast` `/reports/forecast?by=&months=&team=` (lines per month). Every page: the
filter bar (period presets: this month, last month, this quarter, last quarter, this year, custom), the chart (dataviz: one system, the neutral palette swapped for the theme's), the table,
Export CSV.

## Files (exactly these)
`html/reports/{index,funnel,win-rate,cycle-time,sources,campaigns,activity,leaderboard,lost-reasons,forecast,export}.php`; `app/features/reports/{queries,present,periods,charts}.php`
(`charts.php`: the SVG/Chart.js-free inline charts per the dataviz skill — bars and lines as inline SVG, the funnel as stacked bars); `app/views/reports/{index,report}.php`,
`app/views/reports/partials/{card,filters,chart-bars,chart-lines,chart-funnel,table}.php`; the home's `partials/{manager-forecast,leaders}.php` filled (the shell's placeholders); `tests/phase3/slice7/`.

## Query functions (signatures fixed)
`report(PDO, string $name, array $filters): array{rows, headline, chart}` (one entry, switching on the name) · `periods(string $preset): array{from, to}` · `report_csv(array $rows): string`
· writes: `report_export(PDO, string $name, array $filters, int $by): int` (slice 6's `export_create` with kind `report`)

## MCP tools this slice makes real
`funnel`, `win_rate`, `cycle_time`, `deals_closed`, `lost_reasons_summary`, `leaderboard`, `recent_activity` (the home's replies strip reads the activity server's shape), `activity_timeline`.

## Manifest rows claimed
Screens (10): `reports`, `report-funnel`, `report-win-rate`, `report-cycle-time`, `report-sources`, `report-campaigns`, `report-activity`, `report-leaderboard`, `report-lost-reasons`, `report-forecast`
Actions (1): `report_export`

## Activity log events
`report.view` (the report and its filters, per screen view), `report.export`.

## Status vocabulary
Headline tiles `card` with the number large and the period small; attainment ≥ 100 `success`, 70–99 `warning`, under `danger`. Ids: `reports-cards`, `report-card-{name}`, `report-filters`,
`report-chart`, `report-table`, `report-export-btn`.

## Out of scope for this slice
Evals of the reports' numbers (Extended), scheduled report mail (never — the morning note carries the numbers).

## Proof (`tests/phase3/slice7/run.sh`: scratch `crm_dev7` seeded with the Phase 0 proof's fixtures plus 200 synthetic deals)
Every report renders with the fixtures' numbers equal to the function's; a Rep sees only their rows on the team reports; the period presets compute right across a year boundary; the
export builds the CSV; the home's tiles for a manager and a Rep; 375 (the charts stack, no horizontal scroll) and 1280; JSON mode; the registry `--check` (10 screens, 1 action).

## Open questions
None.
