<?php
declare(strict_types=1);
/** /duplicates?kind= (screen `duplicates`): the open pairs of contacts, accounts or leads the caller may see both sides of, by what matched — Merge · Not a duplicate. */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_once dirname(__DIR__, 2) . '/app/features/records/queries.php';
require_once dirname(__DIR__, 2) . '/app/features/duplicates/queries.php';
require_once dirname(__DIR__, 2) . '/app/features/duplicates/present.php';
require_once dirname(__DIR__, 2) . '/app/features/duplicates/write.php';
require_login();
require_right('records.read');
$pdo = db();
$kind = isset(DUPLICATE_KINDS[$k = request_string('kind', 'contact')]) ? $k : 'contact';
$pairs = duplicates($pdo, $kind);
log_screen_view($pdo, 'duplicates');
if (wants_json()) {
    respond_screen(['kind' => $kind, 'pairs' => array_map(static fn (array $p): array => present_duplicate_pair($kind, $p), $pairs)]);
}
$notices = ['merged' => ['success', 'Merged. The other record is a tombstone pointing at the one kept.'], 'dismissed' => ['success', 'That pair will not be shown again.']];
render_screen('Duplicates', view('duplicates/index.php', ['kind' => $kind, 'pairs' => $pairs, 'may' => ['merge' => has_right('records.merge')], 'notice' => $notices[$_GET['notice'] ?? ''] ?? null]),
    ['activeNav' => 'leads', 'screen' => 'duplicates', 'entity' => $kind]);
