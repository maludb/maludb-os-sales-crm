<?php
declare(strict_types=1);

/**
 * The reports' charts (reports-home.md "Files"; the dataviz skill's rules): inline SVG drawn by the server from a report's `chart` data — no library, no JavaScript. This file is the GEOMETRY;
 * app/views/reports/partials/chart-{bars,lines,funnel}.php are the markup. One system: thin bars with a 2px gap, a zero baseline, direct value labels, a legend for two series or more,
 * recessive gridlines; the colours are CSS custom properties (app-overrides.css `.rpt-chart`) so the theme and dark mode swap them in one place — the theme's primary first, a neutral for "the rest".
 * Every chart carries a <title> and a <desc> that read its numbers aloud; the table beside it is the other accessible version. The viewBox scales it, so 375 px has no sideways scroll.
 */
const CHART_WIDTH = 400;
const CHART_MAX_CATEGORIES = 12;

/** A number as the chart's unit prints it: money (short), pct, days, count. */
function chart_format(string $unit, ?float $v, ?string $currency = null): string
{
    if ($v === null) {
        return '';
    }
    return match ($unit) {
        'money' => money_short($v, $currency),
        'pct' => rtrim(rtrim(number_format($v, 1), '0'), '.') . '%',
        'days' => rtrim(rtrim(number_format($v, 1), '0'), '.') . ' d',
        default => number_format($v, 0),
    };
}

/** The class a series is drawn in: the report's own `tone` (neutral, danger), else the nth slot of the palette (1 is the theme's primary). */
function chart_series_class(array $series, int $i): string
{
    $tone = $series['tone'] ?? null;
    return $tone !== null ? 'rpt-' . $tone : 'rpt-s' . (($i % 8) + 1);
}

/** A "nice" top for an axis: 1, 2, 2.5, 5 times a power of ten at or above the value. */
function chart_nice(float $max): float
{
    if ($max <= 0) {
        return 1.0;
    }
    $p = 10 ** floor(log10($max));
    foreach ([1, 2, 2.5, 5, 10] as $m) {
        if ($max <= $m * $p) {
            return (float) ($m * $p);
        }
    }
    return (float) (10 * $p);
}

/** The sentence a screen reader gets: "Win rate by seller: Priya 50%; Marco 25%." (the first twelve). */
function chart_desc(array $spec, ?string $currency = null): string
{
    $unit = $spec['unit'] ?? 'count';
    if (($spec['type'] ?? '') === 'lines') {
        $parts = [];
        foreach ($spec['series'] as $s) {
            $parts[] = $s['label'] . ': ' . implode(', ', array_map(static fn ($x, $v): string => $x . ' ' . ($v === null ? 'none' : chart_format($unit, (float) $v, $currency)), $spec['x'], $s['values']));
        }
        return $spec['title'] . '. ' . implode('. ', $parts) . '.';
    }
    $parts = [];
    foreach (array_slice($spec['categories'], 0, CHART_MAX_CATEGORIES, true) as $i => $c) {
        $vals = [];
        foreach ($spec['series'] as $s) {
            $vals[] = (count($spec['series']) > 1 ? $s['label'] . ' ' : '') . chart_format($unit, (float) ($s['values'][$i] ?? 0), $currency);
        }
        $parts[] = $c . ' ' . implode(', ', $vals);
    }
    return $spec['title'] . '. ' . implode('; ', $parts) . '.';
}

/** The funnel as stacked bars: each stage's width is the deals that entered it, cut into those that moved on, those still there and those lost from it. */
function chart_funnel_spec(array $spec): array
{
    $stages = $spec['stages'];
    return ['type' => 'bars', 'stacked' => true, 'title' => $spec['title'], 'unit' => 'count', 'categories' => array_column($stages, 'label'), 'series' => [
        ['key' => 'moved_on', 'label' => 'Moved on', 'values' => array_column($stages, 'moved_on')],
        ['key' => 'still_here', 'label' => 'Still here', 'tone' => 'neutral', 'values' => array_column($stages, 'still_here')],
        ['key' => 'lost_from', 'label' => 'Lost from here', 'tone' => 'danger', 'values' => array_column($stages, 'lost_from')]]];
}

/**
 * Horizontal bars (grouped, or stacked): each category a label line over its bar(s); a value at the end of each; marks (a quota) as a tick across the bar.
 * Answers array{w, h, rows[{label, y, bars[{x, y, w, h, class, text, label, value}], total_x, total_text, mark{x, text}|null}], cut, baseline}
 */
function chart_bars_layout(array $spec, ?string $currency = null): array
{
    $unit = $spec['unit'] ?? 'count';
    $stacked = !empty($spec['stacked']);
    $cats = array_slice($spec['categories'], 0, CHART_MAX_CATEGORIES, true);
    $series = $spec['series'];
    $marks = $spec['marks']['values'] ?? [];
    $plot = CHART_WIDTH - 70;
    $max = 0.0;
    foreach (array_keys($cats) as $i) {
        $tot = 0.0;
        foreach ($series as $s) {
            $v = max(0.0, (float) ($s['values'][$i] ?? 0));
            $stacked ? $tot += $v : $max = max($max, $v);
        }
        $max = max($max, $tot, (float) ($marks[$i] ?? 0));
    }
    $max = $max > 0 ? $max : 1.0;
    $barH = 11;
    $gap = 3;
    $y = 4;
    $rows = [];
    foreach ($cats as $i => $label) {
        $row = ['label' => (string) $label, 'y' => $y + 11, 'bars' => [], 'total_x' => 0.0, 'total_text' => '', 'mark' => null];
        $y += 16;
        $x = 0.0;
        $n = count($series);
        foreach ($series as $si => $s) {
            $v = max(0.0, (float) ($s['values'][$i] ?? 0));
            $w = $v / $max * $plot;
            if ($stacked) {
                $bar = ['x' => round($x, 1), 'y' => $y, 'w' => round(max(0.0, $w - ($v > 0 && $x > 0 ? 2 : 0)), 1), 'h' => $barH];
                $bar['x'] = round($x + ($v > 0 && $x > 0 ? 2 : 0), 1);
                $x += $w;
            } else {
                $bar = ['x' => 0.0, 'y' => $y + $si * ($barH + $gap), 'w' => round($w, 1), 'h' => $barH];
                $row['total_x'] = max($row['total_x'], $w);
            }
            $bar += ['class' => chart_series_class($s, $si), 'text' => $s['label'] . ' ' . chart_format($unit, $v, $currency), 'label' => $s['label'], 'value' => $v];
            $row['bars'][] = $bar;
        }
        if ($stacked) {
            $row['total_x'] = $x;
            $row['total_text'] = chart_format($unit, array_sum(array_map(static fn (array $s): float => max(0.0, (float) ($s['values'][$i] ?? 0)), $series)), $currency);
            $y += $barH;
        } else {
            foreach ($row['bars'] as &$b) {
                $b['value_text'] = chart_format($unit, $b['value'], $currency);
            }
            unset($b);
            $y += $n * $barH + ($n - 1) * $gap;
        }
        if (isset($marks[$i]) && $marks[$i] !== null) {
            $row['mark'] = ['x' => round((float) $marks[$i] / $max * $plot, 1), 'text' => ($spec['marks']['label'] ?? 'Mark') . ' ' . chart_format($unit, (float) $marks[$i], $currency), 'y1' => $row['bars'][0]['y'] - 2, 'y2' => $y + 2];
        }
        $row['total_x'] = round((float) $row['total_x'], 1);
        $rows[] = $row;
        $y += 10;
    }
    return ['w' => CHART_WIDTH, 'h' => max(30, $y), 'rows' => $rows, 'stacked' => $stacked, 'cut' => max(0, count($spec['categories']) - CHART_MAX_CATEGORIES), 'baseline' => [0, 0, $y]];
}

/** Lines over a category axis (the months): array{w, h, ticks[{y, text}], xs[{x, text}], lines[{class, dashed, d, points[{x, y, text}], label}]}. A null value breaks the line. */
function chart_lines_layout(array $spec, ?string $currency = null): array
{
    $unit = $spec['unit'] ?? 'count';
    $w = CHART_WIDTH + 40;
    $h = 240;
    $left = 54;
    $right = 14;
    $top = 14;
    $bottom = 30;
    $n = count($spec['x']);
    $max = 0.0;
    foreach ($spec['series'] as $s) {
        foreach ($s['values'] as $v) {
            $max = max($max, (float) ($v ?? 0));
        }
    }
    $top_v = chart_nice($max);
    $X = static fn (int $i): float => $n <= 1 ? $left + ($w - $left - $right) / 2 : $left + $i * ($w - $left - $right) / ($n - 1);
    $Y = static fn (float $v): float => $top + ($h - $top - $bottom) * (1 - $v / $top_v);
    $ticks = [];
    for ($k = 0; $k <= 4; $k++) {
        $v = $top_v * $k / 4;
        $ticks[] = ['y' => round($Y($v), 1), 'text' => chart_format($unit, $v, $currency)];
    }
    $xs = [];
    foreach ($spec['x'] as $i => $label) {
        if ($n <= 7 || $i % 2 === 0) {
            $xs[] = ['x' => round($X($i), 1), 'text' => (string) $label];
        }
    }
    $tones = ['open_value' => 'neutral', 'weighted' => 's1', 'won_value' => 's3', 'quota' => 'ink'];
    $lines = [];
    foreach ($spec['series'] as $si => $s) {
        $d = '';
        $pts = [];
        $pen = false;
        foreach ($s['values'] as $i => $v) {
            if ($v === null) {
                $pen = false;
                continue;
            }
            $px = round($X($i), 1);
            $py = round($Y((float) $v), 1);
            $d .= ($pen ? ' L ' : ($d === '' ? 'M ' : ' M ')) . $px . ' ' . $py;
            $pen = true;
            $pts[] = ['x' => $px, 'y' => $py, 'text' => $s['label'] . ', ' . $spec['x'][$i] . ': ' . chart_format($unit, (float) $v, $currency)];
        }
        $lines[] = ['class' => 'rpt-' . ($tones[$s['key']] ?? 's' . (($si % 8) + 1)), 'dashed' => !empty($s['dashed']), 'd' => $d, 'points' => $pts, 'label' => $s['label']];
    }
    return ['w' => $w, 'h' => $h, 'left' => $left, 'right' => $w - $right, 'bottom' => $h - $bottom, 'ticks' => $ticks, 'xs' => $xs, 'lines' => $lines];
}
