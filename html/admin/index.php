<?php
declare(strict_types=1);
/** Admin (screen `admin`) — built by slice 9 (NAV_SLICES); until then the shell's placeholder: 200 after the right (any admin area's right), 501 for JSON and a POST. Removing this call is the slice's first step. */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
render_nav_stub('admin', NAV_SLICES['admin'], ADMIN_RIGHTS);
