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

## Built and proven (2026-10-11)
The reports area: `/reports` as nine cards (the number large, the period small, a tone for attainment: 100 and up success, 70–99 warning, under danger) and the nine reports as full pages — a filter bar (the
period presets as links, two dates, the report's own controls), the headline, ONE inline-SVG chart drawn by the server (`charts.php`: horizontal bars grouped or stacked, the funnel as stacked bars, lines per month; no library, no
JavaScript; the theme's primary first, a neutral for "the rest", the data-viz palette's validated hues after; a `<title>`, a `<desc>` that reads the numbers, a legend from two series; `viewBox`-scaled so 375 px has no sideways
scroll), the table beside it, Export CSV. Every read is a db/015 or db/017 function: `report()` answers rows exactly as the function does, `headline`, `chart` as data. The forecast report is slice 3's `forecast_grid()` across pipelines
(`pipeline = NULL`), drawn as lines (open, weighted, won, quota). `report_export` (`html/reports/export.php`): a small report (under 2,000 rows) downloads at once — a POST with the page's CSRF token, an attachment with nosniff, a sandbox
CSP and no-store, formula cells written as text with slice 6's `export_csv_cell()` — and is recorded as a finished, downloaded `exports` row of kind `report`; `later=1`, a tool's call (JSON) or 2,000 rows and more queue an export that the
worker's `exports` pass builds AS THE MAKER (`build.php` gained the `report` kind), downloaded once from /exports. The home's tiles were filled by Phase 2 and are only completed here (the quota percent toned, the links to the forecast and
the leaderboard now that they exist). The registry reads 67 screens and 90 actions built (this slice's 10 and 1; `report_export` free for an agent, nothing paused).

**Proven** by `tests/phase3/slice7/run.sh` on a fresh `crm_dev7` seeded with `seed.sql` (200 synthetic deals — 80 won, 40 lost, 80 open — with their stage history, 70 leads, 150 done activities, three campaigns, quotas for 26/27/30, six
won deals restricted to Priya; deterministic arithmetic on the index, dates counted back from today), under php -S and a real Apache — periods 36, reports 102, wall 41, export 45, home 29, json 46, browser 62 (361 checks under php -S and 361 under Apache) and the log and the registry: periods across a year boundary, a leap February, a quarter's edge and the week, in the member's own zone (Auckland, Los Angeles, Kolkata, Kiritimati, Pago Pago); every report equal to
its function AS THE MEMBER asking (manager, two Reps, a Viewer, the admin), cross-checked against raw counts (80 won / 40 lost, 70 leads, 150 touches, the Spring webinar's 6,000 / won deals); the wall through the screens and the JSON
(a Rep's activity and forecast hold only their own line, the leaderboard is a manager's, six restricted deals are in no one else's numbers); the CSV of each of the nine reports equal to its table; formula cells; the queued export built
as a Rep holding only her rows; once-only download; the home's tiles for a manager, a Rep and a Viewer against the database's own numbers; JSON mode and the HTMX stamps; the log; the registry (10 screens, 1 action); a real browser at
375 and 1280 (no sideways scroll, charts inside the viewport, the filter bar stacking, a preset by a click, Export as a real download, JavaScript off, dark mode swapping the chart's colours).

**Found and fixed:** the cards' call of the leaderboard report named no month (a PHP warning rendered into the page by the dev build) — every report now fills its own defaults.

**Decisions taken here (within the spec):**
- No migration: `exports.kind` already admits `report` (db/012), every read is a db/015/db/017 function. `export_create()` still refuses kind `report` ("a report is exported from its own page"); `report_export()` queues it.
- `report_csv(array $report)` takes the report array (rows and columns) rather than bare rows, so the header's labels are the table's.
- Who may export: `records.read` and `export.own` (a Viewer holds neither export right and is refused 403); a Rep exports what the report shows them, which on the team reports is their own rows, because the file is built as the caller.
  The manifest's "reports.team (a seller their own rows)" is read that way.
- The leaderboard for a Rep: the function answers nothing without `reports.team`, so the screen says "The leaderboard is the team managers'." and the card shows "—". Win rate, sources, funnel, lost reasons and cycle time are
  open to everyone who reads records (open visibility; the wall hides only restricted records), as the functions answer them. The one rule PHP adds is the forecast by seller: a Rep sees their own line and quota, by stage no quota.
- Period presets are whole calendar spans (the month, the quarter, the year — not "to date"), resolved to dates in the member's time zone; dates that are a preset's are that preset; both dates or neither; a start after the end is 422.
  Defaults: this quarter (funnel, win rate, cycle time, sources, lost reasons), this month (activity). The leaderboard takes `month`; the Activity card is the Monday-to-Sunday week.
- Headlines: funnel — the stage where, of the deals that LEFT it, the fewest moved on (those still there have not decided); win rate — won of closed; cycle time — the AVERAGE days creation-to-won from `crm_touches_to_win()`
  (no function answers a median across all sellers, and every read is a function); sources — the most won value; campaigns — the lowest cost per won deal; activity — touches done; leaderboard — the leader, toned by attainment;
  lost reasons — the top reason; forecast — this month's weighted, toned by won against the quota.
- Sources and reasons with nothing in the period are left out of the table and said so in a line; the chart draws the first twelve categories (the table has all).
- The filter bar uses `<select>`s for pipeline, owner, team, status and "by" (small closed sets, as slice 3's forecast and slice 6's campaign list do), and `<input type=date|month>`.
- The MCP tools this slice lists (`funnel`, `win_rate`, …) are Phase 4's: the records server does not exist yet; the screens' JSON is the same data under whitelisted keys.
- Added beyond the list: `html/reports/export.php`'s inline path writes an `exports` row without a file (storage NULL, downloaded now); `app/features/activity/queries.php` says `report.view` and `report.export` in words;
  `tests/phase3/slice7/seed.sql`; the leaders tile gained a link to the leaderboard.
