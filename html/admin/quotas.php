<?php
declare(strict_types=1);
/** /admin/quotas?year= (screen `admin-quotas`; quotas.manage): the quota per seller per month — sellers down, the twelve months of the year across, each cell an input. Save posts the cells that changed (quota_set each). JSON: the grid. */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_once dirname(__DIR__, 2) . '/app/features/quotas/write.php';
require_once dirname(__DIR__, 2) . '/app/features/admin/present.php';
require_login();
require_right('quotas.manage');
$pdo = db();
$thisYear = (int) (new DateTimeImmutable('now', new DateTimeZone(member_timezone())))->format('Y');
$year = ctype_digit($y = request_string('year')) && (int) $y >= 2000 && (int) $y <= 2100 ? (int) $y : $thisYear;
$grid = quota_grid($pdo, $year);
log_screen_view($pdo, 'admin-quotas');
if (wants_json()) {
    respond_screen(present_quota_grid($grid));
}
$notices = ['saved' => ['success', 'Saved the quotas.'], 'nothing' => ['info', 'Nothing had changed.']];
render_screen('Quotas', view('admin/quotas.php', ['grid' => $grid, 'this_year' => $thisYear, 'notice' => crm_notice($_GET['notice'] ?? null, $notices)]), ['activeNav' => 'admin', 'screen' => 'admin-quotas', 'entity' => 'quota']);
