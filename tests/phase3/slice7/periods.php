<?php
/** Proof — the period presets (reports-home.md): calendar spans counted from today IN THE MEMBER'S TIME ZONE, right across a year boundary, a leap February and a quarter's edge; the week; the month filter; the dates a
 * request may name (a malformed or backwards span refused in words); the same through the screens for members in other zones. */
require __DIR__ . '/lib.php';
$at = static fn (string $iso): DateTimeImmutable => new DateTimeImmutable($iso, new DateTimeZone('UTC'));
$p = static fn (string $preset, string $iso, string $tz = 'UTC'): string => implode('..', periods($preset, $tz, $at($iso)));

echo "1. The presets\n";
ok($p('this_month', '2026-12-31T12:00:00Z') === '2026-12-01..2026-12-31', 'this month on 31 December: 2026-12-01..2026-12-31');
ok($p('this_month', '2026-12-31T23:30:00Z', 'Pacific/Auckland') === '2027-01-01..2027-01-31', 'the same instant in Auckland is already January 2027: 2027-01-01..2027-01-31');
ok($p('this_month', '2027-01-01T05:00:00Z', 'Pacific/Honolulu') === '2026-12-01..2026-12-31', 'a few hours into 2027 in Honolulu it is still December 2026');
ok($p('last_month', '2027-01-15T12:00:00Z') === '2026-12-01..2026-12-31', 'last month in January 2027 is December 2026 (the year boundary)');
ok($p('last_month', '2026-03-15T12:00:00Z') === '2026-02-01..2026-02-28', 'last month in March 2026: February has 28 days');
ok($p('last_month', '2028-03-10T12:00:00Z') === '2028-02-01..2028-02-29', 'last month in March 2028: a leap February has 29');
ok($p('this_quarter', '2026-11-03T12:00:00Z') === '2026-10-01..2026-12-31', 'this quarter in November: 2026-10-01..2026-12-31');
ok($p('this_quarter', '2026-03-31T23:59:00Z') === '2026-01-01..2026-03-31' && $p('this_quarter', '2026-04-01T00:00:00Z') === '2026-04-01..2026-06-30', 'the quarter turns on 1 April, not before');
ok($p('last_quarter', '2027-02-10T12:00:00Z') === '2026-10-01..2026-12-31', 'last quarter in February 2027 is the fourth quarter of 2026 (the year boundary)');
ok($p('last_quarter', '2026-05-05T12:00:00Z') === '2026-01-01..2026-03-31', 'last quarter in May is January to March');
ok($p('this_year', '2027-01-01T00:30:00Z') === '2027-01-01..2027-12-31' && $p('this_year', '2026-12-31T23:59:00Z') === '2026-01-01..2026-12-31', 'this year: the calendar year, turning at midnight in the zone');
ok($p('nonsense', '2026-11-03T12:00:00Z') === '2026-10-01..2026-12-31', 'an unknown preset is this quarter');
ok($p('this_quarter', '2026-12-31T23:30:00Z', 'Pacific/Auckland') === '2027-01-01..2027-03-31' && $p('last_quarter', '2026-12-31T23:30:00Z', 'Pacific/Auckland') === '2026-10-01..2026-12-31', 'in Auckland at the turn of the year: this quarter 2027-01-01..2027-03-31, last quarter 2026-10-01..2026-12-31');
$w = periods_week('UTC', $at('2026-12-31T12:00:00Z'));
ok($w === ['from' => '2026-12-28', 'to' => '2027-01-03'], 'this week on Thursday 31 December runs Monday 28 December to Sunday 3 January: ' . json_encode($w));
ok(periods_week('UTC', $at('2027-01-03T12:00:00Z')) === $w && periods_week('UTC', $at('2026-12-28T00:00:00Z')) === $w, '…and a Sunday and a Monday belong to the same week');
ok(periods_month('2026-02-17') === '2026-02-01' && periods_month('2026-11') === '2026-11-01' && periods_month('2026-13') === periods_today()->format('Y-m-01') && periods_month('nonsense') === periods_today()->format('Y-m-01'), 'the month filter: the first of the month named, else this month');
ok(periods_date('2028-02-29') === '2028-02-29' && periods_date('2026-02-30') === null && periods_date('26-1-1') === null && periods_date('') === null, 'a date is a real calendar date or nothing');

echo "2. A request's period\n";
$g = get_as(27, '/reports/win-rate?from=2026-02-30&to=2026-03-31');
ok($g['code'] === 422 && str_contains(message_of($g), 'Give both dates'), 'a malformed date: 422 in words — ' . message_of($g));
$g = get_as(27, '/reports/win-rate?from=2026-05-01&to=2026-04-01');
ok($g['code'] === 422 && str_contains(message_of($g), 'starts after it ends'), 'a start after the end: 422 in words — ' . message_of($g));
$g = get_as(27, '/reports/win-rate?from=2026-05-01');
ok($g['code'] === 422, 'one date alone: 422');
$d = rep(27, 'win_rate', ['from' => '2026-05-01', 'to' => '2026-05-31']);
ok($d['period'] === ['preset' => 'custom', 'from' => '2026-05-01', 'to' => '2026-05-31'], 'two dates make a custom span');
$q = periods('this_quarter');
$d = rep(27, 'win_rate', $q);
ok(($d['period']['preset'] ?? '') === 'this_quarter', 'dates that are a preset\'s are that preset (the filter form always sends both): ' . json_encode($d['period'] ?? null));
foreach (REPORT_PRESETS as $k => $label) {
    $d = rep(27, 'funnel', ['preset' => $k]);
    ok(($d['period'] ?? null) === ['preset' => $k] + periods($k), "?preset=$k: " . $d['period']['from'] . ' to ' . $d['period']['to']);
}
$d = rep(27, 'funnel');
ok($d['period'] === ['preset' => 'this_quarter'] + periods('this_quarter'), 'the funnel opens on this quarter');
$d = rep(27, 'activity');
ok($d['period'] === ['preset' => 'this_month'] + periods('this_month'), 'the activity report opens on this month');
$d = rep(27, 'leaderboard', ['month' => '2026-09']);
ok(($d['period']['month'] ?? '') === '2026-09-01', 'the leaderboard takes a month: ' . json_encode($d['period'] ?? null));

echo "3. In the member's time zone, through the screens\n";
foreach ([26 => 'Pacific/Auckland', 30 => 'America/Los_Angeles', 29 => 'Asia/Kolkata'] as $m => $zone) {
    pg("UPDATE members SET timezone = '$zone' WHERE id = $m");
}
foreach ([26 => 'Pacific/Auckland', 30 => 'America/Los_Angeles'] as $m => $zone) {
    $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
    $d = rep($m, 'win_rate', ['preset' => 'this_month']);
    $want = periods('this_month', $zone, $now);
    ok(($d['period']['from'] ?? '') === $want['from'] && ($d['period']['to'] ?? '') === $want['to'], "$zone: this month is " . $want['from'] . '..' . $want['to'] . ' (the screen says ' . ($d['period']['from'] ?? '?') . '..' . ($d['period']['to'] ?? '?') . ')');
}
foreach ([26 => 'Pacific/Auckland', 30 => 'America/Los_Angeles', 29 => 'Asia/Kolkata'] as $m => $zone) {
    $cards = get_as($m, '/reports')['json']['data']['cards'] ?? [];
    $act = row_by($cards, 'report', 'activity');
    $wk = periods_week($zone, new DateTimeImmutable('now', new DateTimeZone('UTC')));
    ok($act !== null && str_contains($act['url'], 'from=' . $wk['from']) && str_contains($act['url'], 'to=' . $wk['to']), "$zone: the Activity card's week is " . $wk['from'] . '..' . $wk['to'] . ' — ' . ($act['url'] ?? '?'));
}
$a = periods('this_year', 'Pacific/Kiritimati', new DateTimeImmutable('2026-12-31T12:00:00Z'));
$b = periods('this_year', 'Pacific/Pago_Pago', new DateTimeImmutable('2026-12-31T12:00:00Z'));
ok($a['from'] === '2027-01-01' && $b['from'] === '2026-01-01', 'the same instant is 2027 on Kiritimati (UTC+14) and 2026 on Pago Pago (UTC−11)');
pg('UPDATE members SET timezone = \'UTC\' WHERE id IN (26, 30, 29)');
finish();
