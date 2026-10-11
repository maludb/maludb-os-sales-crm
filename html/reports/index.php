<?php
declare(strict_types=1);
/** /reports (screen `reports`): the nine reports as cards, each with one number. JSON: the cards. */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_once dirname(__DIR__, 2) . '/app/features/reports/present.php';
require_login();
require_right('records.read');
$pdo = db();
$cards = report_cards($pdo);
log_screen_view($pdo, 'reports');
if (wants_json()) {
    respond_screen(['currency' => base_currency($pdo), 'cards' => present_report_cards($cards)]);
}
render_screen('Reports', view('reports/index.php', ['cards' => $cards, 'currency' => base_currency($pdo)]), ['activeNav' => 'reports', 'screen' => 'reports', 'entity' => 'report']);
