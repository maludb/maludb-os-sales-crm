<?php
declare(strict_types=1);

/**
 * The period presets of the reports (reports-home.md "Screens"): this month, last month, this quarter, last quarter, this year, or a custom span. A preset is a
 * calendar span counted from TODAY IN THE MEMBER'S TIME ZONE (member_timezone(), slice 4) — at the turn of a year a person in Auckland is already in the
 * new year while the server's UTC clock is not — and it is resolved to two dates here, once; the functions of db/015 and db/017 only ever see dates.
 */
const REPORT_PRESETS = ['this_month' => 'This month', 'last_month' => 'Last month', 'this_quarter' => 'This quarter', 'last_quarter' => 'Last quarter', 'this_year' => 'This year'];

/** Today's date in a time zone (an unknown zone is UTC). $now is an instant, for the proofs; null is the clock. */
function periods_today(?string $tz = null, ?DateTimeImmutable $now = null): DateTimeImmutable
{
    try {
        $zone = new DateTimeZone($tz ?? member_timezone());
    } catch (Throwable) {
        $zone = new DateTimeZone('UTC');
    }
    return ($now ?? new DateTimeImmutable('now', new DateTimeZone('UTC')))->setTimezone($zone)->setTime(0, 0);
}

/**
 * A preset as its two dates (YYYY-MM-DD, both inclusive): array{from, to}. An unknown preset is this quarter. $tz and $now are the proofs' (the member's zone and the clock are the defaults).
 */
function periods(string $preset, ?string $tz = null, ?DateTimeImmutable $now = null): array
{
    $t = periods_today($tz, $now);
    $y = (int) $t->format('Y');
    $m = (int) $t->format('n');
    $first = static fn (int $year, int $month): DateTimeImmutable => (new DateTimeImmutable('now', new DateTimeZone('UTC')))->setDate($year, $month, 1)->setTime(0, 0);
    $span = static fn (DateTimeImmutable $from, int $months): array => ['from' => $from->format('Y-m-d'), 'to' => $from->modify('+' . $months . ' months')->modify('-1 day')->format('Y-m-d')];
    switch ($preset) {
        case 'this_month':
            return $span($first($y, $m), 1);
        case 'last_month':
            return $span($first($y, $m)->modify('-1 month'), 1);
        case 'last_quarter':
            return $span($first($y, intdiv($m - 1, 3) * 3 + 1)->modify('-3 months'), 3);
        case 'this_year':
            return $span($first($y, 1), 12);
        default:                                                                          // this_quarter
            return $span($first($y, intdiv($m - 1, 3) * 3 + 1), 3);
    }
}

/** The Monday-to-Sunday week that holds today (the Activity card's "this week"): array{from, to}. */
function periods_week(?string $tz = null, ?DateTimeImmutable $now = null): array
{
    $t = periods_today($tz, $now);
    $monday = $t->modify('-' . ((int) $t->format('N') - 1) . ' days');
    return ['from' => $monday->format('Y-m-d'), 'to' => $monday->modify('+6 days')->format('Y-m-d')];
}

/** The first of a month ('YYYY-MM' or 'YYYY-MM-DD') as a date, or the current month's first when it is not one. */
function periods_month(string $text, ?string $tz = null, ?DateTimeImmutable $now = null): string
{
    if (preg_match('/^(\d{4})-(\d{2})(?:-\d{2})?$/', $text, $m) && (int) $m[2] >= 1 && (int) $m[2] <= 12) {
        return $m[1] . '-' . $m[2] . '-01';
    }
    return periods_today($tz, $now)->format('Y-m-01');
}

/** A date input that is a real calendar date, else null. */
function periods_date(string $text): ?string
{
    if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $text, $m) || !checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
        return null;
    }
    return $text;
}

/**
 * The period a request asks for: preset (default per report) unless both from and to are given (then custom). Answers array{preset, from, to}; a malformed date or a start after the end is
 * refused in words (422).
 */
function periods_from_request(string $default = 'this_quarter'): array
{
    $from = request_string('from');
    $to = request_string('to');
    if ($from !== '' || $to !== '') {
        $f = periods_date($from);
        $t = periods_date($to);
        if ($f === null || $t === null) {
            refuse(422, 'Give both dates of the period as year-month-day.');
        }
        if ($f > $t) {
            refuse(422, 'The period starts after it ends.');
        }
        foreach (array_keys(REPORT_PRESETS) as $k) {                                       // dates that are a preset's are that preset (the filter form always sends both)
            if (periods($k) === ['from' => $f, 'to' => $t]) {
                return ['preset' => $k, 'from' => $f, 'to' => $t];
            }
        }
        return ['preset' => 'custom', 'from' => $f, 'to' => $t];
    }
    $preset = request_string('preset', $default);
    $preset = isset(REPORT_PRESETS[$preset]) ? $preset : $default;
    return ['preset' => $preset] + periods($preset);
}
