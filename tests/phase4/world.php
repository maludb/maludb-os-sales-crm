<?php
/** Builds the Phase 4 world once (tests/phase4/lib.php p4_world()) and says how big it is. */
require __DIR__ . '/lib.php';
$w = p4_world();
ok(count($w) >= 40 && $w['hidden'] > 0 && $w['deal_won'] > 0, 'the Phase 4 world is built: ' . count($w) . ' ids');
ok((int) one("SELECT count(*) FROM accounts WHERE restricted_to_member_id IS NOT NULL") >= 2, 'two accounts are restricted (Marco\'s Globex and Dana\'s Hidden Holdings)');
finish();
