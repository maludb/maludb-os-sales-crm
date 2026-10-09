#!/usr/bin/env php
<?php
declare(strict_types=1);
/**
 * Rewrite maludb-os.json's approvals[] from docs/sales-crm-action-manifest.md, so the categories the kernel pauses on are exactly the
 * manifest's "Agent approval" column — one source. `php bin/sync_approvals.php` writes; `--check` verifies only (non-zero when stale).
 */
$manifest = file(__DIR__ . '/../docs/sales-crm-action-manifest.md', FILE_IGNORE_NEW_LINES) ?: [];
$mode = '';
$approvals = [];
foreach ($manifest as $line) {
    if (str_starts_with($line, '## ')) { $mode = ''; continue; }
    if (preg_match('/^Actions\b/', $line)) { $mode = 'actions'; continue; }
    if (preg_match('/^Screens:/', $line)) { $mode = 'screens'; continue; }
    if ($mode !== 'actions' || $line === '' || $line[0] !== '|') { continue; }
    $cells = array_map('trim', explode('|', trim($line, "| \t")));
    if (count($cells) < 8 || str_starts_with($cells[0], '---') || $cells[0] === 'Action') { continue; }
    $name = trim($cells[0], '`');
    if (!preg_match('/^[a-z0-9_]+$/', $name)) { continue; }
    $cat = trim($cells[5]);
    if ($cat !== '' && in_array($cat, ['money_out', 'external_send', 'deletion', 'other'], true)) {
        $approvals[$name] = $cat;
    } elseif ($cat !== '') {
        fwrite(STDERR, "{$name}: unknown category '{$cat}'\n");
        exit(1);
    }
}
ksort($approvals);
$list = [];
foreach ($approvals as $action => $category) { $list[] = ['action' => $action, 'category' => $category]; }
$path = __DIR__ . '/../maludb-os.json';
$json = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
$current = $json['approvals'] ?? [];
if (in_array('--check', $argv, true)) {
    if ($current === $list) { echo count($list) . " approvals in sync\n"; exit(0); }
    echo "maludb-os.json approvals[] is stale (" . count($current) . " vs " . count($list) . " from the manifest)\n"; exit(1);
}
$json['approvals'] = $list;
file_put_contents($path, json_encode($json, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n");
echo "wrote " . count($list) . " approvals\n";
