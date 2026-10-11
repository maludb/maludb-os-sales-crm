<?php
/** Proof — report_export (reports-home.md): a small report downloads at once by POST with the page's CSRF token (an attachment, nosniff, a sandbox CSP, formula cells made plain, recorded as a finished downloaded
 * export); `later=1`, a tool's call (JSON) and anything over 2,000 rows are queued as an export of kind `report` that the worker builds AS THE MAKER (a Rep's team report holds their rows) and its maker downloads once;
 * every report's CSV is its table; the rights. */
require __DIR__ . '/lib.php';
ensure_book();
$w = win();
$x = run_tag();
$since = last_activity_id();
/** A browser-style POST (HTML) as a member: the raw response. */
function post_html(int $m, string $path, array $form): array
{
    [$j, $t] = actor($m);
    return req('POST', $path, ['jar' => $j, 'headers' => ['X-CSRF-Token: ' . $t], 'form' => $form]);
}
function hdr(array $r, string $name): string { return preg_match('/^' . preg_quote($name, '/') . ': (.*?)\r?$/mi', $r['headers'], $m) ? trim($m[1]) : ''; }

echo "1. Who may export\n";
$r = post_as(29, '/reports/export.php', ['report' => 'funnel'] + $w);
ok($r['code'] === 403, 'a Viewer has no export.own: 403 — ' . message_of($r));
$g = html_as(29, '/reports/funnel?from=' . $w['from'] . '&to=' . $w['to'])['body'];
ok(!str_contains($g, 'id="report-export-btn"') && !str_contains($g, 'report-export-later-btn'), '…and the page does not offer the button');
$r = post_as(26, '/reports/export.php', ['report' => 'nonsense']);
ok($r['code'] === 422 && isset($r['json']['error']['fields']['report']), 'an unknown report: 422 naming the field');
$r = req('GET', '/reports/export.php', ['jar' => actor(26)[0]]);
ok($r['code'] === 405, 'a GET is not an export: 405');
$before = (int) one("SELECT count(*) FROM exports WHERE kind = 'report'");
[$j, ] = actor(26);
$r = req('POST', '/reports/export.php', ['jar' => $j, 'form' => ['report' => 'funnel'] + $w]);
ok($r['code'] >= 400 && (int) one("SELECT count(*) FROM exports WHERE kind = 'report'") === $before, 'a POST without the page\'s CSRF token is refused and makes nothing: ' . $r['code']);
$r = req('POST', '/reports/export.php', ['form' => ['report' => 'funnel'] + $w]);
ok(in_array($r['code'], [302, 401, 403], true) && !str_contains($r['body'], 'Seller'), 'no session at all: ' . $r['code'] . ' and no file');

echo "2. A small report downloads at once\n";
$r = post_html(26, '/reports/export.php', ['report' => 'activity'] + $w);
ok($r['code'] === 200 && str_starts_with(hdr($r, 'Content-Type'), 'text/csv') && hdr($r, 'X-Content-Type-Options') === 'nosniff' && hdr($r, 'Content-Security-Policy') === 'sandbox'
    && str_starts_with(hdr($r, 'Content-Disposition'), 'attachment; filename="sales-crm-report-activity-' . $w['from'] . '.csv"') && hdr($r, 'Cache-Control') === 'no-store' && (int) hdr($r, 'Content-Length') === strlen($r['body']),
    'a Rep\'s activity report: 200 text/csv, an attachment, nosniff, a sandbox CSP, no-store, the length — ' . hdr($r, 'Content-Disposition'));
$csv = csv_rows_of($r['body']);
$screen = rep(26, 'activity', $w);
ok($csv[0] === ['Seller', 'Kind', 'Done', 'Still scheduled', 'Outcomes'] && count($csv) === 1 + count($screen['rows']), 'the header is the table\'s and there is a line per row: ' . (count($csv) - 1));
ok(array_unique(array_column(array_slice($csv, 1), 0)) === ['SMOKE Priya'] && count($csv) > 1, 'the Rep\'s file holds her own rows only (the schema\'s rule for the activity summary)');
$tot = 0; foreach (array_slice($csv, 1) as $line) { $tot += (int) $line[2]; }
ok($tot === array_sum(array_column($screen['rows'], 'done')), 'the Done column adds up to the screen\'s');
$row = q("SELECT * FROM exports WHERE kind = 'report' AND created_by = 26 ORDER BY id DESC LIMIT 1")[0];
$params = json_decode($row['params'], true);
ok($row['status'] === 'done' && $row['downloaded_at'] !== null && (int) $row['item_count'] === count($csv) - 1 && (int) $row['byte_size'] === strlen($r['body']) && $row['storage_path'] === null && $params['report'] === 'activity' && $params['filters']['from'] === $w['from'],
    'recorded as a finished export, already downloaded, kind report, with the report and its period in params');
$mgr = post_html(27, '/reports/export.php', ['report' => 'activity'] + $w);
$mcsv = csv_rows_of($mgr['body']);
$sellers = array_unique(array_column(array_slice($mcsv, 1), 0));
sort($sellers);
ok($sellers === ['SMOKE Dana', 'SMOKE Marco', 'SMOKE Priya'], 'the manager\'s file holds every seller: ' . implode(', ', $sellers));

echo "3. Every report's CSV is its table\n";
$reports = ['funnel' => $w, 'win_rate' => $w + ['by' => 'source'], 'cycle_time' => $w, 'sources' => $w, 'campaigns' => [], 'activity' => $w, 'leaderboard' => ['month' => month_start()], 'lost_reasons' => $w + ['by' => 'seller'], 'forecast' => ['by' => 'seller', 'months' => 3]];
foreach ($reports as $name => $q) {
    $d = rep(27, $name, $q);
    $r = post_html(27, '/reports/export.php', ['report' => $name] + $q);
    $c = csv_rows_of($r['body']);
    $labels = array_column($d['columns'], 'label');
    $okRows = $r['code'] === 200 && $c[0] === $labels && count($c) === 1 + count($d['rows']);
    $okCells = true;
    foreach ($d['rows'] as $i => $row) {
        foreach ($d['columns'] as $k => $col) {
            $v = $row[$col['key']] ?? null;
            $got = $c[$i + 1][$k] ?? null;
            if ($col['type'] === 'json') { continue; }
            if ($v === null || $v === '') { $okCells = $okCells && $got === ''; }
            elseif (in_array($col['type'], ['int', 'money', 'pct', 'dec', 'days'], true)) { $okCells = $okCells && abs((float) $got - (float) $v) < 0.006; }
            elseif ($col['type'] === 'month') { $okCells = $okCells && $got === substr($v, 0, 7); }
            else { $okCells = $okCells && ($got === (string) $v || $got === "'" . $v); }          // a campaign named by a formula is written as text
        }
    }
    ok($okRows && $okCells, "$name: the CSV's header, " . count($d['rows']) . ' rows and every cell equal the table' . ($okRows ? '' : ' — code ' . $r['code']));
}

echo "4. A cell that would run as a formula is written as text\n";
foreach (['=HYPERLINK("http://x.invalid","y")', '+SUM(A1)', '-2+3', '@cmd', "\tTAB"] as $i => $name) {
    pg("INSERT INTO campaigns (name, kind, status, owner_member_id, created_by) VALUES (" . pg_quote("$name $x") . ", 'other', 'planned', 27, 27)");
}
pg("INSERT INTO campaigns (name, kind, status, owner_member_id, created_by) VALUES (" . pg_quote("Quote, \"comma\" $x") . ", 'other', 'planned', 27, 27)");
function pg_quote(string $s): string { return "'" . str_replace("'", "''", $s) . "'"; }
$r = post_html(27, '/reports/export.php', ['report' => 'campaigns']);
$c = csv_rows_of($r['body']);
$names = array_column(array_slice($c, 1), 0);
foreach (['=HYPERLINK("http://x.invalid","y")', '+SUM(A1)', '-2+3', '@cmd', "\tTAB"] as $name) {
    ok(in_array("'$name $x", $names, true) && !in_array("$name $x", $names, true), 'a campaign named ' . json_encode($name) . ' is written with a leading apostrophe');
}
ok(in_array("Quote, \"comma\" $x", $names, true), 'a comma and quotes survive the CSV round trip');
ok(!str_contains($r['body'], "\n=") && !str_contains($r['body'], ",=") , 'no cell in the file begins with an equals sign');

echo "5. Queued: later=1, a tool, and the worker as the maker\n";
$r = post_html(26, '/reports/export.php', ['report' => 'activity', 'later' => '1'] + $w);
ok(in_array($r['code'], [302, 303], true) && str_contains($r['location'], '/exports') && str_contains($r['location'], 'notice=queued'), 'later=1: a redirect to /exports with the notice — ' . $r['location']);
$q1 = (int) one("SELECT max(id) FROM exports WHERE kind = 'report' AND created_by = 26");
$row = q('SELECT * FROM exports WHERE id = :i', ['i' => $q1])[0];
ok($row['status'] === 'queued' && $row['storage_path'] === null && $row['downloaded_at'] === null && json_decode($row['params'], true)['report'] === 'activity', 'queued as an export of kind report, not yet built');
$j = post_as(27, '/reports/export.php', ['report' => 'activity'] + $w);
ok($j['code'] === 200 && ($j['json']['ok'] ?? false) === true && ($j['json']['record_id'] ?? 0) > 0 && ($j['json']['rows'] ?? 0) === count($mcsv) - 1 && str_contains($j['json']['did'] ?? '', 'Queued'), 'a tool\'s call (JSON) is queued, never downloaded: ' . ($j['json']['did'] ?? $j['body']));
$q2 = (int) $j['json']['record_id'];
$rep = pass('exports');
ok(($rep['built'] ?? 0) >= 2 && ($rep['failed'] ?? 1) === 0, 'the worker builds both: ' . json_encode($rep));
$t1 = (string) export_text($q1);
$t2 = (string) export_text($q2);
ok($t1 === $r['body'] || csv_rows_of($t1) === csv_rows_of(post_html(26, '/reports/export.php', ['report' => 'activity'] + $w)['body']), 'the Rep\'s queued file equals the file she downloads at once: built as her, her rows only');
ok(csv_rows_of($t2) === $mcsv, 'the manager\'s queued file equals his download');
ok(array_unique(array_column(array_slice(csv_rows_of($t1), 1), 0)) === ['SMOKE Priya'], 'built by the worker with no session, the Rep\'s file still holds only Priya\'s rows');
$page = get_as(26, '/exports')['json']['data']['exports'] ?? [];
$mine = row_by($page, 'export_id', $q1);
ok($mine !== null && $mine['kind'] === 'report' && $mine['state'] === 'ready' && $mine['can_download'] === true, 'her Exports page lists it ready');
$h = html_as(26, '/exports')['body'];
preg_match('/id="export-' . $q1 . '".*?<span class="fw-semibold">([^<]+)</s', $h, $mt);
ok(isset($mt[1]) && str_starts_with($mt[1], 'The activity report — ') && str_contains($mt[1], $w['from'] === '' ? '' : (new DateTimeImmutable($w['from']))->format('M j')), 'titled in words: "' . ($mt[1] ?? '?') . '"');
$d1 = post_html(26, '/exports/download.php', ['export' => $q1]);
ok($d1['code'] === 200 && $d1['body'] === $t1 && hdr($d1, 'X-Content-Type-Options') === 'nosniff' && str_contains(hdr($d1, 'Content-Disposition'), 'attachment; filename="sales-crm-report-' . $q1 . '.csv"'), 'its maker downloads it once: the same bytes, an attachment');
$d2 = post_html(26, '/exports/download.php', ['export' => $q1]);
ok(in_array($d2['code'], [302, 303], true) && str_contains($d2['location'], 'notice=gone'), 'the second time it is gone');
ok(post_html(27, '/exports/download.php', ['export' => $q2])['code'] === 200 && post_html(26, '/exports/download.php', ['export' => $q2])['code'] === 404, 'only the maker downloads (Priya may not take Marco\'s)');

echo "6. The agent, the log, the threshold\n";
$tok = run_token(40, 951);
$r = req('GET', '/reports/funnel?from=' . $w['from'] . '&to=' . $w['to'], ['headers' => as_agent($tok)]);
ok($r['code'] === 200 && isset(json_decode($r['body'], true)['data']['rows']), 'the Expert under a run token reads a report as JSON');
$r = req('POST', '/reports/export.php', ['headers' => as_agent($tok), 'form' => ['report' => 'funnel'] + $w]);
$aj = json_decode($r['body'], true) ?: [];
ok($r['code'] === 200 && ($aj['ok'] ?? false) === true && ($aj['record_id'] ?? 0) > 0, 'the Expert may export (queued): ' . ($aj['did'] ?? $r['body']));
$ev = q("SELECT action, source, entity_type, after::text AS after FROM activity_log WHERE id > :s AND action = 'report.export' ORDER BY id", ['s' => $since]);
ok(count($ev) >= 14 && count(array_filter($ev, static fn ($l) => json_decode($l['after'], true)['mode'] === 'download')) >= 11 && count(array_filter($ev, static fn ($l) => json_decode($l['after'], true)['mode'] === 'queued')) >= 3 && in_array('agent', array_column($ev, 'source'), true), 'report.export is logged — downloads and queued ones, the agent\'s as source agent: ' . count($ev) . ' rows');
$l = json_decode($ev[0]['after'], true);
ok(isset($l['report'], $l['filters'], $l['rows']) && $ev[0]['entity_type'] === 'export' && !str_contains(json_encode($ev), 'HYPERLINK') && !str_contains(json_encode($ev), 'SMOKE Priya'), 'its payload names the report, its filters and the row count — no cell, no name');
$src = (string) file_get_contents(dirname(__DIR__, 3) . '/app/features/reports/queries.php');
ok(str_contains($src, "const REPORT_INLINE_ROWS = 2000;"), 'the immediate-download threshold is 2,000 rows (report_export queues anything larger)');
finish();
