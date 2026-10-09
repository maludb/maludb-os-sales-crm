<?php
declare(strict_types=1);
/** Reports (screen `reports`) — built by slice 7 (NAV_SLICES); until then the shell's placeholder: 200 after the right (records.read), 501 for JSON and a POST. Removing this call is the slice's first step. */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
render_nav_stub('reports', NAV_SLICES['reports'], 'records.read');
