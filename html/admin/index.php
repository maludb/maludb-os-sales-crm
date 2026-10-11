<?php
declare(strict_types=1);
/** /admin (screen `admin`): the admin home — the areas as cards, each with a number; the logging mailbox's health; the tombstones due. A card shows only to a holder of its right; any admin right opens the page. JSON: the same, whitelisted. */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_once dirname(__DIR__, 2) . '/app/features/admin/present.php';
require_login();
require_any_right(ADMIN_RIGHTS);
$pdo = db();
$sum = admin_summary($pdo);
$areas = array_values(array_filter(admin_areas($sum), static fn (array $a): bool => has_right($a[4])));
log_screen_view($pdo, 'admin');
if (wants_json()) {
    respond_screen(['areas' => array_map(static fn (array $a): array => ['area' => $a[0], 'url' => $a[1], 'title' => $a[3], 'right' => $a[4], 'summary' => $a[5]], $areas),
        'mailbox' => has_right('mailbox.manage') ? $sum['mailbox'] : null, 'tombstones' => has_right('records.purge') ? ['due' => $sum['tombstones']['due'], 'waiting' => $sum['tombstones']['waiting'], 'days' => $sum['tombstones']['days']] : null]);
}
render_screen('Admin', view('admin/index.php', ['areas' => $areas, 'sum' => $sum]), ['activeNav' => 'admin', 'screen' => 'admin']);
