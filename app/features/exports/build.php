<?php
declare(strict_types=1);

/**
 * Building an export (the worker's `exports` pass): the file is made AS ITS MAKER — app.member_id is theirs while the find_* of the kind run, so a record they cannot see is never in it — paged
 * through the same queries as the screens (filters.php), written under storage/exports/<id>.csv|json (never under html/), then the row says done with the byte size and the count. A CSV cell that
 * begins with = + - @ (or a tab or a return) is written with a leading apostrophe so a spreadsheet reads it as text and never runs it. The download is export/download.php's (once, an attachment).
 */
require_once __DIR__ . '/queries.php';
require_once dirname(__DIR__) . '/attachments/store.php';
require_once dirname(__DIR__) . '/reports/queries.php';

/** The columns of a kind's export: [header, row key | callable(row): scalar]. The row keys are the find_* rows' (the screens' own queries). */
function export_columns(string $recordKind): array
{
    $tags = static fn (array $r): string => implode('; ', pg_text_array((string) ($r['tags'] ?? '{}')));
    return match ($recordKind) {
        'lead' => [['Id', 'lead_id'], ['Name', 'label'], ['First name', 'first_name'], ['Last name', 'last_name'], ['Company', 'company'], ['Title', 'title'], ['Email', 'email'], ['Status', 'status'], ['Score', 'score'],
                   ['Source', 'source'], ['Owner', 'owner_name'], ['Last touched', 'last_touched_at'], ['Created', 'created_at'], ['Tags', $tags]],
        'contact' => [['Id', 'contact_id'], ['Name', 'name'], ['Title', 'title'], ['Email', 'email'], ['Phone', 'phone'], ['Mobile', 'mobile'], ['Account', 'account_name'], ['Lifecycle', 'lifecycle'], ['Role', 'role'],
                      ['Owner', 'owner_name'], ['Last touched', 'last_touched_at'], ['No email', static fn (array $r): string => $r['email_opt_out'] ? 'yes' : 'no'],
                      ['No texts', static fn (array $r): string => $r['sms_opt_out'] ? 'yes' : 'no'], ['Tags', $tags]],
        'account' => [['Id', 'account_id'], ['Name', 'name'], ['Domain', 'domain'], ['Kind', 'kind'], ['Industry', 'industry'], ['City', 'city'], ['Country', 'country'], ['Owner', 'owner_name'],
                      ['Last touched', 'last_touched_at'], ['Open deals', 'open_deals_count'], ['Open value', 'open_deals_value'], ['Contacts', 'contacts_count'], ['Tags', $tags]],
        'deal' => [['Id', 'deal_id'], ['Number', 'number'], ['Title', 'title'], ['Account', 'account_name'], ['Stage', 'stage_name'], ['Value', 'value'], ['Currency', 'currency'], ['Weighted', 'weighted_value'],
                   ['Probability', 'probability'], ['Expected close', 'expected_close_date'], ['Status', 'status'], ['Owner', 'owner_name'], ['Created', 'created_at']],
        'activity' => [['Id', 'activity_id'], ['Kind', 'kind'], ['Subject', 'subject'], ['When', 'at'], ['Done at', 'done_at'], ['State', 'state'], ['Outcome', 'outcome'], ['Priority', 'priority'], ['Owner', 'owner_name'],
                       ['Deal', 'deal_title'], ['Contact', 'contact_name'], ['Account', 'account_name'], ['Lead', 'lead_name']],
    };
}

/** A cell for a CSV: text that a spreadsheet would run as a formula is made plain (a leading apostrophe); plain numbers are left alone. */
function export_csv_cell(mixed $v): string|int|float
{
    if ($v === null || $v === false) { return ''; }
    if ($v === true) { return 'yes'; }
    if (is_int($v) || is_float($v)) { return $v; }
    $s = (string) $v;
    if ($s !== '' && strpbrk($s[0], "=+-@\t\r") !== false && !preg_match('/^-?\d+(\.\d+)?$/', $s)) {
        return "'" . $s;
    }
    return $s;
}

/** The value of a column for a row. */
function export_value(array $row, string|callable $key): mixed
{
    return is_callable($key) ? $key($row) : ($row[$key] ?? null);
}

/**
 * Build one export (queued or running): acts as its maker, writes the file, marks it done. Answers ['rows', 'bytes']. A DomainException (or anything else) is the caller's to turn into a failed row.
 * The file is written to a temporary name and renamed when whole, so a half-made file is never a download.
 */
function export_build(PDO $pdo, int $id): array
{
    $e = one_row($pdo, 'SELECT id, kind, format, params, created_by, list_id FROM exports WHERE id = :id', ['id' => $id]) ?? throw new DomainException('No such export.');
    $by = (int) $e['created_by'];
    $pdo->prepare("SELECT set_config('app.member_id', :m, false)")->execute(['m' => (string) $by]);
    $_SESSION['member_id'] = $by;                                                     // the find_* parsers read "me" from the session as well
    try {
        if (!db_bool($pdo, 'SELECT crm_is_member_here()')) { throw new DomainException('Its maker no longer has access, so nothing was exported.'); }
        $params = json_decode((string) $e['params'], true) ?: [];
        if ($e['kind'] === 'report') {                                                     // a report: built as its maker, so a seller's team report holds their rows only
            $name = report_name((string) ($params['report'] ?? '')) ?? throw new DomainException('That report is not built here.');
            $rep = report($pdo, $name, is_array($params['filters'] ?? null) ? $params['filters'] : []);
            $rk = 'report';
            $page = static fn (int $p): array => ['rows' => $p === 1 ? $rep['rows'] : [], 'total' => count($rep['rows'])];
        } elseif ($e['kind'] === 'list') {
            $l = find_list($pdo, (int) $e['list_id']) ?? throw new DomainException('The list is gone or no longer yours to see.');
            $rk = $l['record_kind'];
            $page = static fn (int $p): array => list_rows($pdo, (int) $l['list_id'], $p);
        } else {
            $rk = EXPORT_RECORD_KIND[$e['kind']] ?? throw new DomainException('That kind of export is not built here.');
            $compact = is_array($params['filters'] ?? null) ? $params['filters'] : [];
            if ($rk === 'activity') {                                                      // whose activities: judged here, as the maker, because a refusal inside the parser would end the worker
                $who = (string) ($compact['who'] ?? 'me');
                if (preg_match('/^team:\d+$/', $who) && !db_bool($pdo, "SELECT crm_has_right('calendar.team')")) { throw new DomainException('Its maker no longer holds the team calendar right.'); }
                if (ctype_digit($who) && (int) $who !== $by && !db_bool($pdo, "SELECT crm_has_right('calendar.read')")) { throw new DomainException('Its maker no longer holds the calendar right.'); }
            }
            $page = static fn (int $p): array => filters_find($pdo, $rk, $compact, $p);
        }
        $cols = $rk === 'report' ? array_map(static fn (array $c): array => [$c[0], static fn (array $row): mixed => report_cell($c[2], $row[$c[1]] ?? null)], $rep['columns']) : export_columns($rk);
        $rel = 'exports/' . $id . '.' . $e['format'];
        $abs = attachment_storage_dir() . '/' . $rel;
        if (!is_dir(dirname($abs)) && !mkdir(dirname($abs), 0750, true) && !is_dir(dirname($abs))) { throw new RuntimeException('export store: cannot make exports/'); }
        $tmp = $abs . '.part';
        $fh = fopen($tmp, 'wb');
        if ($fh === false) { throw new RuntimeException('export store: cannot write ' . $rel); }
        $count = 0;
        $first = true;
        if ($e['format'] === 'csv') {
            fputcsv($fh, array_map(static fn (array $c): string => $c[0], $cols), ',', '"', '');
        } else {
            fwrite($fh, '{"kind":' . json_encode($e['kind']) . ',"exported_at":' . json_encode(date(DATE_ATOM)) . ',"columns":' . json_encode(array_map(static fn (array $c): string => $c[0], $cols)) . ',"rows":[');
        }
        for ($p = 1; ; $p++) {
            $r = $page($p);
            if ($r['rows'] === []) { break; }
            foreach ($r['rows'] as $row) {
                if (++$count > EXPORT_MAX_ROWS) { throw new DomainException('That is more than ' . number_format(EXPORT_MAX_ROWS) . ' rows; narrow the filters.'); }
                $line = array_map(static fn (array $c): mixed => export_value($row, $c[1]), $cols);
                if ($e['format'] === 'csv') {
                    fputcsv($fh, array_map('export_csv_cell', $line), ',', '"', '');
                } else {
                    fwrite($fh, ($first ? '' : ',') . json_encode(array_combine(array_map(static fn (array $c): string => $c[0], $cols), $line), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
                    $first = false;
                }
            }
            if ($p * ($rk === 'report' ? max(1, $r['total']) : filters_page($rk)) >= $r['total']) { break; }
        }
        if ($e['format'] === 'json') { fwrite($fh, '],"count":' . $count . '}'); }
        fclose($fh);
        @chmod($tmp, 0640);
        rename($tmp, $abs);
        $bytes = (int) filesize($abs);
        $pdo->prepare("UPDATE exports SET status = 'done', storage_path = :p, byte_size = :b, item_count = :n, finished_at = now(), log = NULL WHERE id = :id")->execute(['p' => $rel, 'b' => $bytes, 'n' => $count, 'id' => $id]);
        return ['rows' => $count, 'bytes' => $bytes];
    } finally {
        $pdo->exec("SELECT set_config('app.member_id', '', false)");
        unset($_SESSION['member_id']);
    }
}
