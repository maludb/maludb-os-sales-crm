<?php
declare(strict_types=1);
/** /log/meeting (screen `log-meeting`; params contact, deal, account, lead): the quick log of a meeting held — who, when it started, the outcome, notes, the next step. /log/call's controller with the kind. */
$GLOBALS['__log_kind'] = 'meeting';
require __DIR__ . '/call.php';
