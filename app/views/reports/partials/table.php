<?php /** A report's table (the accessible version of its chart): the columns say how a cell is printed. Data: report (name, columns, rows, currency, note) */
$cur = $report['currency'];
$cell = static function (string $type, mixed $v) use ($cur): string {
    if ($v === null || $v === '') { return '—'; }
    return match ($type) {
        'int' => number_format((int) $v),
        'money' => money((float) $v, $cur),
        'pct' => rtrim(rtrim(number_format((float) $v, 1), '0'), '.') . '%',
        'dec', 'days' => number_format((float) $v, 1),
        'month' => (new DateTimeImmutable(substr((string) $v, 0, 7) . '-01'))->format('M Y'),
        'json' => is_array($v) ? ($v === [] ? '—' : implode(', ', array_map(static fn ($k, $n): string => $k . ' ' . (int) $n, array_keys($v), $v))) : (string) $v,
        default => (string) $v,
    };
};
$numeric = ['int', 'money', 'pct', 'dec', 'days']; ?>
<div class="card" id="report-table-card"><div class="table-responsive"><table class="table table-hover mb-0 fs-12" id="report-table">
    <caption class="visually-hidden"><?= e($report['title']) ?>, <?= e($report['period']) ?></caption>
    <thead class="thead-light"><tr><?php foreach ($report['columns'] as [$label, $key, $type]): ?><th scope="col" class="<?= in_array($type, $numeric, true) ? 'text-end' : '' ?> text-nowrap"><?= e($label) ?></th><?php endforeach; ?></tr></thead>
    <tbody>
    <?php if ($report['rows'] === []): ?>
        <tr><td colspan="<?= count($report['columns']) ?>" class="text-center text-muted py-3" id="report-table-empty"><?= e($report['note'] !== '' ? $report['note'] : 'Nothing to show for these filters.') ?></td></tr>
    <?php else: foreach ($report['rows'] as $i => $row): ?>
        <tr id="report-row-<?= $i ?>"><?php foreach ($report['columns'] as [$label, $key, $type]): ?><td class="<?= in_array($type, $numeric, true) ? 'text-end' : '' ?>"><?= e($cell($type, $row[$key] ?? null)) ?></td><?php endforeach; ?></tr>
    <?php endforeach; endif; ?>
    </tbody>
</table></div></div>
<?php if ($report['rows'] !== [] && $report['note'] !== ''): ?><div class="fs-11 text-muted mt-1" id="report-table-note"><?= e($report['note']) ?></div><?php endif; ?>
