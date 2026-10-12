<?php
/**
 * Proof — the five K7 shares and the two K7 reads this application consumes, through the bridge (tool surface "The shares", "The bridge"): the kernel's token and X-OS-Consumer reach a share; this server asks the
 * application's loopback bridge (the PHP that logs `share.read` and calls the db/017 SECURITY DEFINER functions as the application) and passes PHP's document on UNCHANGED — the proof sets the two answers side by side;
 * a restricted record is never shared, no body and no note crosses, a missing consumer reads nothing and logs nothing, a bridge that cannot be reached or whose signature the application refuses is an answer
 * (`bridge_unavailable`, retry), and an account's tickets and ledger customer come from the kernel's approved connections — degraded to words, the wall judged first.
 */
require __DIR__ . '/lib.php';
$w = p4_world();
$KT = static fn (string $tool, array $args = [], string $consumer = 'gl', array $extra = []) => mcp_tool(REC, kernel_token(), $tool, $args, true, array_merge($consumer === '' ? [] : ['X-OS-Consumer: ' . $consumer], $extra));
$php = static function (string $op, array $args, string $consumer = 'gl') { $r = bridge($op, ['consumer' => $consumer, 'args' => $args]); return $r['json']['result'] ?? null; };
$T = 'helpdesk.tickets_by_organization';
$L = 'gl.customer_by_crm_account';
$FORBIDDEN = ['HIDDEN', 'PRIVATE-BODY', 'PUBLIC-BODY', 'DRAFT-BODY', 'NOTE-LONG', 'p4 hello from the form', 'p4 stranger text'];
$clean = static function (string $text) use ($FORBIDDEN): array { return array_values(array_filter($FORBIDDEN, static fn ($n) => stripos($text, $n) !== false)); };

echo "1. The shares are PHP's documents, passed on unchanged\n";
$cases = [
    ['accounts_index', 'share_accounts_index', []], ['accounts_index', 'share_accounts_index', ['limit' => 1, 'offset' => 1]], ['accounts_index', 'share_accounts_index', ['q' => 'Umbrella']],
    ['contacts_index', 'share_contacts_index', []], ['contacts_index', 'share_contacts_index', ['account' => $w['acme']]],
    ['deals_won', 'share_deals_won', ['from' => date('Y-m-01'), 'to' => date('Y-m-d')]], ['deals_open_by_account', 'share_deals_open_by_account', ['account' => $w['acme']]],
    ['account_timeline_summary', 'share_account_timeline_summary', ['account' => $w['acme'], 'limit' => 5]],
];
foreach ($cases as [$tool, $op, $args]) {
    $got = $KT($tool, $args);
    $want = $php($op, $args);
    ok(!$got['error'] && $got['data'] === json_decode(json_encode($want), true), "$tool " . json_encode($args) . ': the tool\'s document equals the bridge\'s' . ($got['error'] ? ' — ' . substr($got['text'], 0, 120) : ''));
}
$acc = $KT('accounts_index', ['limit' => 100])['data'];
$names = array_column($acc['accounts'], 'name');
ok($acc['schema'] === 'os.sales-crm-accounts/1' && $acc['application'] === 'sales_crm' && in_array('SMOKE Acme Industries', $names, true) && in_array('p4 Umbrella Retail', $names, true), 'accounts_index: os.sales-crm-accounts/1 with Acme and Umbrella');
ok(!in_array('SMOKE Globex', $names, true) && !in_array('p4 Hidden Holdings', $names, true), 'a restricted account is never shared — Marco\'s Globex and Dana\'s Hidden Holdings are not in it');
$a1 = find_row($acc['accounts'], 'name', 'SMOKE Acme Industries');
ok($a1['owner_name'] === 'SMOKE Priya' && !array_key_exists('owner_email', $a1) && $a1['open_deals_count'] === 3 && $a1['gl_customer_id'] === null, 'an owner crosses as a display name only; Acme\'s three open deals are counted');
$ct = $KT('contacts_index', ['limit' => 100])['data'];
ok(find_row($ct['contacts'], 'name', 'Pat Umbra')['email_opt_out'] === true && count(find_row($ct['contacts'], 'name', 'Pat Umbra')['emails']) >= 2 && find_row($ct['contacts'], 'name', 'Hid Den') === null, 'contacts_index: Pat with every address and the opt-out; the restricted contact is not shared');
$byEmail = $KT('contacts_index', ['email' => 'dana.reyes@acme.example.invalid'])['data'];
ok($byEmail['total'] === 1 && $byEmail['contacts'][0]['name'] === 'Dana Reyes', 'contacts_index by email — Help Desk\'s requester match');
$dw = $KT('deals_won', ['from' => date('Y-m-01'), 'to' => date('Y-m-d')])['data'];
ok($dw['schema'] === 'os.sales-crm-deals-won/1' && find_row($dw['deals'], 'title', 'p4 Won — Priya') !== null && find_row($dw['deals'], 'title', 'p4 Lost — Priya') === null, 'deals_won: the won deal, not the lost one');
$won = find_row($dw['deals'], 'title', 'p4 Won — Priya');
ok(is_array($won['account']) && $won['account']['name'] === 'SMOKE Acme Industries' && isset($won['pipeline_key']) && is_array($won['products']), 'a won deal carries its account, pipeline and product lines');
$ob = $KT('deals_open_by_account', ['account' => $w['acme']])['data'];
ok($ob['schema'] === 'os.sales-crm-deals-open/1' && count($ob['deals']) === 3, 'deals_open_by_account: Acme\'s three open deals');
$hid = $KT('deals_open_by_account', ['account' => $w['hidden']])['data'];
$gone = $KT('deals_open_by_account', ['account' => 99999999])['data'];
ok($hid['deals'] === [] && $gone['deals'] === [] && $hid['account_id'] === $w['hidden'] && $gone['account_id'] === 99999999, 'a restricted account and a missing one answer the same empty list — nothing tells them apart');
$tl = $KT('account_timeline_summary', ['account' => $w['acme']])['data'];
ok($tl['schema'] === 'os.sales-crm-timeline/1' && count($tl['touches']) >= 1 && isset($tl['next_step']['subject']), 'account_timeline_summary: touches and the next step');
$all = json_encode([$acc, $ct, $dw, $ob, $tl]);
ok($clean($all) === [], 'no email body, note, draft, form message or restricted text anywhere in the five documents' . ($clean($all) ? ' — ' . implode(',', $clean($all)) : ''));

echo "2. Who asked, and the trail\n";
$since = last_activity_id();
$KT('accounts_index', ['limit' => 2], 'gl', ['X-OS-Consumer-Agent: 77']);
$KT('deals_won', ['from' => date('Y-m-01'), 'to' => date('Y-m-d')], 'inventory');
$ev = activity('share.read', $since);
ok(count($ev) === 2, 'every call writes one share.read row');
$after = json_decode($ev[0]['after'], true);
ok($ev[0]['source'] === 'application' && $after['application'] === 'gl' && $after['tool'] === 'accounts_index' && $after['rows'] === 2 && is_int($after['ms']) && ($after['consumer_agent'] ?? null) === '77', 'source application; {application: the consumer, tool, rows, ms} and the consumer\'s agent from X-OS-Consumer-Agent: ' . $ev[0]['after']);
$after2 = json_decode($ev[1]['after'], true);
ok($after2['application'] === 'inventory' && $after2['tool'] === 'deals_won' && isset($after2['from']), 'another consumer is another application in the row; the window asked about is kept');
ok($clean($ev[0]['after'] . $ev[1]['after']) === [] && !str_contains($ev[0]['after'] . $ev[1]['after'], 'Acme'), 'and never an answer');
$since = last_activity_id();
$r1 = $KT('accounts_index', [], '');
$r2 = $KT('accounts_index', ['consumer' => 'gl'], '');
ok($r1['code'] === 'consumer_unknown' && $r2['code'] === 'consumer_unknown' && activity('share.read', $since) === [], 'no consumer header (an argument does not make one): consumer_unknown, nothing read, nothing logged');

echo "3. Arguments\n";
foreach ([['deals_won', ['from' => 'x', 'to' => date('Y-m-d')], 'invalid_argument'], ['deals_won', ['from' => date('Y-m-d'), 'to' => date('Y-m-d', time() - 86400 * 3)], 'invalid_argument'], ['deals_won', ['to' => date('Y-m-d')], 'invalid_argument'],
          ['accounts_index', ['limit' => 501], 'invalid_argument'], ['accounts_index', ['since' => 'next tuesday-ish'], 'invalid_argument'], ['deals_open_by_account', ['account' => 0], 'invalid_argument'],
          ['deals_open_by_account', [], 'invalid_argument'], ['account_timeline_summary', ['account' => $w['acme'], 'limit' => 51], 'invalid_argument']] as [$t, $a, $code]) {
    $r = $KT($t, $a);
    ok($r['code'] === $code, "$t " . json_encode($a) . " → $code" . ($r['code'] === $code ? '' : ' (got ' . ($r['code'] ?? 'an answer') . ')'));
}
$small = $KT('accounts_index', ['limit' => 1])['data'];
ok(count($small['accounts']) === 1 && $small['total'] >= 2, 'paging: one account of the total');

echo "4. The bridge cannot be reached, or does not accept the signature\n";
mcp_ctl('variant nobridge');
$r = mcp_tool(VARIANT, kernel_token(), 'accounts_index', [], true, ['X-OS-Consumer: gl']);
ok($r['code'] === 'bridge_unavailable' && ($r['data']['error']['retry'] ?? null) === true, 'the application\'s port closed: bridge_unavailable, retry true — an answer, not an exception');
$r = mcp_tool(VARIANT, ptoken(PRIYA), 'account_tickets', ['account' => $w['acme']]);
ok($r['code'] === 'bridge_unavailable', 'and for a person\'s account_tickets');
mcp_ctl('variant badkey');
$since = last_activity_id();
$r = mcp_tool(VARIANT, kernel_token(), 'accounts_index', [], true, ['X-OS-Consumer: gl']);
ok($r['code'] === 'bridge_unavailable' && activity('share.read', $since) === [], 'a bridge signed with a key the application does not hold is refused by PHP (401): nothing shared, nothing logged');
ok(mcp_tool(VARIANT, ptoken(PRIYA), 'find_deals', ['status' => ['open']])['code'] === null, 'tools that never use the bridge are unaffected');
mcp_ctl('variant stop');

echo "5. What this application consumes: an account's tickets and its ledger customer\n";
pg_run('TRUNCATE k7_cache');
kernel_reads([$T => null, $L => null]);
$n = static fn (string $tool) => count(array_filter(kernel_read_log(), static fn ($l) => $l['tool'] === $tool));
$n0 = $n('tickets_by_organization');
$r = tool(PRIYA, 'account_tickets', ['account' => $w['acme']]);
ok($r['code'] === 'no_connection' && $r['data']['error']['retry'] === false && $n('tickets_by_organization') === $n0 + 1, 'no approved connection: no_connection (once, remembered five minutes)');
$ga = tdata(PRIYA, 'get_account', ['account' => $w['acme'], 'with_ledger' => true]);
ok($ga['account']['name'] === 'SMOKE Acme Industries' && $ga['ledger']['error']['code'] === 'no_connection', 'get_account with_ledger and no connection: the account is answered, the ledger says so');
$tickets = array_map(static fn ($i) => ['number' => "T-$i", 'subject' => "Printer $i", 'status' => 'open', 'priority' => 'normal', 'updated_at' => '2026-10-01T10:00:00Z', 'url' => "https://help.example.invalid/t/$i", 'internal_note' => 'p4 SECRET'], range(1, 30));
kernel_reads([$T => ['mode' => 'ok', 'result' => ['rows' => $tickets]], $L => ['mode' => 'ok', 'result' => [['customer_id' => 5, 'name' => 'Acme Ltd', 'balance' => 1250.5, 'currency' => 'USD']]]]);
pg_run("UPDATE k7_cache SET fetched_at = now() - interval '6 minutes'");
$r = tool(PRIYA, 'account_tickets', ['account' => $w['acme'], 'limit' => 5]);
ok(!$r['error'] && count($r['data']['tickets']) === 5 && $r['data']['tickets'][0]['number'] === 'T-1' && !str_contains($r['text'], 'SECRET') && array_keys($r['data']['tickets'][0]) === ['number', 'subject', 'status', 'priority', 'updated_at', 'url'],
   'approved: the first five tickets, only the whitelisted fields (an internal note does not cross)');
$last = kernel_read_log(); $last = end($last);
ok($last['provider'] === 'helpdesk' && $last['arguments']['crm_account_id'] === $w['acme'] && $last['arguments']['domain'] === 'acme.example.invalid' && !array_key_exists('member_id', $last['arguments']), 'the kernel was asked the account and its domain — no person\'s identity crosses');
$ga = tdata(PRIYA, 'get_account', ['account' => $w['acme'], 'with_ledger' => true]);
ok(($ga['ledger']['name'] ?? '') === 'Acme Ltd' && (float) ($ga['ledger']['balance'] ?? 0) === 1250.5, 'get_account with_ledger: the ledger\'s customer and balance');
$before = $n('tickets_by_organization');
tool(PRIYA, 'account_tickets', ['account' => $w['acme']]);
ok($n('tickets_by_organization') === $before, 'asked again within five minutes: the cache answers, the kernel is not asked');
$before = $n('tickets_by_organization');
$r = tool(PRIYA, 'account_tickets', ['account' => $w['hidden']]);
$r2 = tool(PRIYA, 'account_tickets', ['account' => 99999999]);
ok($r['code'] === 'not_found' && $r2['code'] === 'not_found' && $n('tickets_by_organization') === $before, 'a hidden account and a missing one: not_found, and the kernel is not asked about either');
pg_run('TRUNCATE k7_cache');
kernel_reads([$T => ['mode' => 'provider_failed', 'result' => []]]);
$r = tool(PRIYA, 'account_tickets', ['account' => $w['acme']]);
ok($r['code'] === 'bridge_unavailable' && $r['data']['error']['retry'] === true, 'the provider failing: bridge_unavailable, retry true');
pg_run('TRUNCATE k7_cache');
kernel_reads([$T => ['mode' => 'ok', 'result' => ['rows' => array_slice($tickets, 0, 3)]]]);
$rid = runid(41);
facts($rid, EXPERT, ['Records MCP' => ['account_tickets' => [], 'get_account' => []]]);
$r = mcp_tool(REC, rtoken(EXPERT, $rid), 'account_tickets', ['account' => $w['acme']]);
ok(!$r['error'] && count($r['data']['tickets']) === 3, 'an agent granted account_tickets reads them too (the bridge takes the run, the door agent)');
$rid = runid(42);
facts($rid, EXPERT, ['Records MCP' => ['account_tickets' => []]], ['trigger' => 'eval']);
ok(!mcp_tool(REC, rtoken(EXPERT, $rid), 'account_tickets', ['account' => $w['acme']])['error'], 'an evaluation run reads (it writes nothing)');
finish();
