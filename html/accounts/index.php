<?php
declare(strict_types=1);
/** Accounts (screen `account-list`) — built by slice 1 (NAV_SLICES); until then the shell's placeholder: 200 after the right (records.read), 501 for JSON and a POST. Removing this call is the slice's first step. */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
render_nav_stub('accounts', NAV_SLICES['accounts'], 'records.read');
