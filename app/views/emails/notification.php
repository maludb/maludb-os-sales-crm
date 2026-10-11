<?php /** The application's own mail body — the outbox's (slice 8 sends what render_mail() makes of it); the words are the notification row's (crm_notify wrote them), this only dresses them.
 * Data: format (html|text), title, body, url (the record, absolute) or null, app; optional lead (a small line above the title), facts (label => value), sections (heading => [line, …]), cta (the button's words) */
$lead = $lead ?? null;
$facts = $facts ?? [];
$sections = $sections ?? [];
$cta = $cta ?? 'Open it in ' . $app;
if ($format === 'text') {
    echo ($lead !== null ? strtoupper($lead) . "\n" : '') . $title . "\n";
    if (trim((string) $body) !== '') { echo "\n" . trim((string) $body) . "\n"; }
    foreach ($facts as $label => $value) { echo $label . ': ' . $value . "\n"; }
    foreach ($sections as $heading => $lines) {
        echo "\n" . $heading . "\n";
        foreach ($lines as $line) { echo '- ' . (is_array($line) ? $line[0] . ($line[1] ? ' (' . $line[1] . ')' : '') : $line) . "\n"; }
    }
    if ($url !== null) { echo "\n" . $cta . ': ' . $url . "\n"; }
    return;
} ?>
<div style="font-family:Arial,Helvetica,sans-serif;font-size:14px;line-height:1.5;color:#1f2937;max-width:560px">
    <?php if ($lead !== null): ?><div style="font-size:12px;letter-spacing:.04em;text-transform:uppercase;color:#6b7280"><?= e($lead) ?></div><?php endif; ?>
    <h2 style="font-size:18px;margin:4px 0 12px"><?= e($title) ?></h2>
    <?php if (trim((string) $body) !== ''): ?><p style="margin:0 0 12px"><?= nl2br(e(trim((string) $body))) ?></p><?php endif; ?>
    <?php if ($facts !== []): ?><table role="presentation" style="border-collapse:collapse;margin:0 0 12px"><?php foreach ($facts as $label => $value): ?>
        <tr><td style="padding:2px 12px 2px 0;color:#6b7280"><?= e((string) $label) ?></td><td style="padding:2px 0"><?= e((string) $value) ?></td></tr><?php endforeach; ?></table><?php endif; ?>
    <?php foreach ($sections as $heading => $lines): ?>
        <h3 style="font-size:14px;margin:16px 0 4px"><?= e((string) $heading) ?></h3>
        <ul style="margin:0 0 8px;padding-left:18px"><?php foreach ($lines as $line): ?><li><?php if (is_array($line) && $line[1]): ?><a href="<?= e($line[1]) ?>"><?= e($line[0]) ?></a><?php else: ?><?= e(is_array($line) ? $line[0] : $line) ?><?php endif; ?></li><?php endforeach; ?></ul>
    <?php endforeach; ?>
    <?php if ($url !== null): ?><p style="margin:16px 0"><a href="<?= e($url) ?>" style="background:#3454d1;color:#fff;text-decoration:none;padding:9px 16px;border-radius:6px;display:inline-block"><?= e($cta) ?></a></p><?php endif; ?>
    <p style="font-size:12px;color:#6b7280;margin-top:20px">Sent by <?= e($app) ?>. You can change what you are told about in My settings.</p>
</div>
