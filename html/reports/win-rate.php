<?php
declare(strict_types=1);
/** /reports/win-rate (screen `report-win-rate`): the report's filters, headline, chart and table (app/features/reports). JSON: the report's data. */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_once dirname(__DIR__, 2) . '/app/features/reports/present.php';
report_screen('win_rate');
