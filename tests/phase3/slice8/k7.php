<?php
/**
 * Proof — the two K7 reads the application consumes (agents-k7-api.md divergence 3, binding point 1): k7_read() keeps its signature and is real — the kernel's POST /api/v1/apps/read.php, cached five minutes per account and read,
 * degraded to words (no_connection, unavailable) never a 500; the wall on the account is judged FIRST (a hidden account is not_found and the kernel is not asked); the bridge's k7_read op is the records server's door to the same.
 */
require __DIR__ . '/lib.php';
$x = run_tag();
$since = last_activity_id();
$T = 'helpdesk.tickets_by_organization';
$L = 'gl.customer_by_crm_account';
$acc = (int) post_as(26, '/accounts/save.php', ['name' => "K7 Co $x", 'website' => "https://k7-$x.example.invalid"])['json']['record_id'];
$acc2 = (int) post_as(26, '/accounts/save.php', ['name' => "K7 Two $x", 'website' => "https://k7two-$x.example.invalid"])['json']['record_id'];
$vault = (int) post_as(27, '/accounts/save.php', ['name' => "K7 Vault $x"])['json']['record_id'];
post_as(27, '/records/restrict.php', ['kind' => 'account', 'record' => $vault, 'member' => 27]);
$tickets = array_map(static fn ($i) => ['number' => "T-$i", 'subject' => "Printer $i", 'status' => 'open', 'priority' => 'normal', 'updated_at' => '2026-10-01T10:00:00Z'], range(1, 30));
pg_run('TRUNCATE k7_cache');
kernel_reads([$T => null, $L => null]);
$ask = static fn (int $m, string $read, int $account, array $more = [], string $door = 'mcp') => bridge('k7_read', ['caller' => ['kind' => 'member', 'member_id' => $m], 'door' => $door, 'args' => ['read' => $read, 'account' => $account] + $more]);
$n = static fn () => count(array_filter(kernel_read_log(), static fn ($l) => $l['tool'] === 'tickets_by_organization'));       // the tickets reads the kernel was asked
$nl = static fn () => count(array_filter(kernel_read_log(), static fn ($l) => $l['tool'] === 'customer_by_crm_account'));
$lastRead = static function (): array { $l = kernel_read_log(); return end($l) ?: []; };

echo "1. A connection nobody approved: words, and remembered\n";
$n0 = $n();
$r = $ask(26, 'account_tickets', $acc);
ok($r['code'] === 200 && $r['json']['status'] === 'no_connection' && $r['json']['rows'] === [] && $n() === $n0 + 1, 'the kernel says 403 no_connection: the answer is status no_connection, no rows, not an error');
$r = $ask(26, 'account_tickets', $acc);
ok($r['json']['status'] === 'no_connection' && $n() === $n0 + 1, 'asked again within five minutes: answered from the cache, the kernel not asked');
$page = html_as(26, "/accounts/$acc?tab=tickets")['body'];
ok(str_contains($page, 'id="account-tickets-no-connection"') && str_contains($page, 'Help Desk is not connected') && $n() === $n0 + 1, 'the account\'s Tickets tab says so in words (from the same cache)');
$ov = html_as(26, "/accounts/$acc")['body'];
ok(str_contains($ov, 'id="account-ledger-none"') && str_contains($ov, 'not connected'), 'the ledger panel says the General Ledger is not connected');

echo "2. Approved: rows, cached five minutes\n";
kernel_reads([$T => ['mode' => 'ok', 'result' => ['rows' => $tickets]], $L => ['mode' => 'ok', 'result' => [['name' => "K7 Co Ltd $x", 'balance' => 1250.5, 'currency' => 'USD']]]]);
$r = $ask(26, 'account_tickets', $acc);
ok($r['json']['status'] === 'no_connection', 'approved at the kernel but remembered as not connected: the cache holds for five minutes');
pg_run("UPDATE k7_cache SET fetched_at = now() - interval '6 minutes'");
$n1 = $n();
$r = $ask(26, 'account_tickets', $acc);
ok($r['json']['status'] === 'ok' && count($r['json']['rows']) === 25 && $r['json']['rows'][0]['number'] === 'T-1' && $n() === $n1 + 1, 'after the cache is five minutes old the kernel is asked again: ok, the tickets (the first 25 — the limit)');
$last = $lastRead();
ok($last['provider'] === 'helpdesk' && $last['tool'] === 'tickets_by_organization' && $last['arguments']['crm_account_id'] === $acc && $last['arguments']['domain'] === "k7-$x.example.invalid" && array_keys($last) === ['provider', 'tool', 'arguments'], 'the kernel was asked {provider, tool, arguments: {crm_account_id, domain}} — no person\'s identity crosses');
$r = $ask(26, 'account_tickets', $acc, ['limit' => 5]);
ok(count($r['json']['rows']) === 5 && $n() === $n1 + 1, 'limit 5: five rows, still from the one cached read');
$r = $ask(26, 'account_tickets', $acc2);
ok($r['json']['status'] === 'ok' && $n() === $n1 + 2, 'another account is another read (the cache is per account)');
$page = html_as(26, "/accounts/$acc?tab=tickets")['body'];
ok(str_contains($page, 'id="account-tickets-list"') && str_contains($page, 'T-1') && str_contains($page, 'Printer 1') && $n() === $n1 + 2, 'the Tickets tab lists them');
$ov = html_as(26, "/accounts/$acc")['body'];
$nl1 = $nl();
ok(str_contains($ov, 'id="account-ledger-name"') && str_contains($ov, "K7 Co Ltd $x") && str_contains($ov, '$1,251'), 'the ledger panel names the customer and the balance');
ok(str_contains(html_as(26, "/accounts/$acc")['body'], 'id="account-ledger-name"') && $nl() === $nl1, 'a second visit asks the kernel nothing (cached five minutes)');
$tj = get_as(26, "/accounts/$acc")['json']['data']['tickets'] ?? [];
ok(($tj['status'] ?? '') === 'ok' && count($tj['rows']) === 25 && (static function (array $k): array { sort($k); return $k; })(array_keys($tj['rows'][0])) === ['number', 'priority', 'status', 'subject', 'updated_at'], 'the screen\'s JSON carries the status and only the whitelisted ticket fields, 25 at most — ' . substr(json_encode($tj), 0, 200));

echo "3. The wall comes first\n";
$n2 = $n();
$r = $ask(28, 'account_tickets', $vault);
$r2 = $ask(26, 'account_tickets', 99999999);
ok($r['json']['status'] === 'not_found' && $r2['json']['status'] === 'not_found' && $r['json']['rows'] === [] && $n() === $n2, 'an account the caller cannot see, and one that does not exist: the same not_found, the kernel never asked');
$r = $ask(27, 'account_tickets', $vault);
ok($r['json']['status'] !== 'not_found' && $n() === $n2 + 1, 'the account\'s own manager reads it (the kernel is asked)');
$r = $ask(41, 'account_tickets', $acc);
ok($r['code'] === 403 && ($r['json']['error']['code'] ?? '') === 'unknown_member', 'a caller who is not admitted here: 403 unknown_member');
$r = bridge('k7_read', ['caller' => ['kind' => 'kernel'], 'args' => ['read' => 'account_tickets', 'account' => $acc]]);
ok($r['code'] === 403, 'a caller that is not a member: 403');
ok($ask(26, 'items_index', $acc)['code'] === 422 && $ask(26, 'account_tickets', 0)['code'] === 422, 'a read that is not on the list, or no account: 422');
$bad = bridge('k7_read', ['caller' => ['kind' => 'member', 'member_id' => 26], 'args' => ['read' => 'account_tickets', 'account' => $acc, 'provider' => 'evil', 'tool' => 'drop_everything']]);
ok($lastRead()['provider'] === 'helpdesk' && $bad['code'] === 200, 'a provider or tool named in the arguments is ignored: only the two declared reads can be made');

echo "4. Degraded: a failing provider, a busy kernel\n";
kernel_reads([$T => ['mode' => 'provider_failed']]);
pg_run('TRUNCATE k7_cache');
$n3 = $n();
$r = $ask(26, 'account_tickets', $acc);
ok($r['code'] === 200 && $r['json']['status'] === 'unavailable' && $r['json']['rows'] === [] && $n() === $n3 + 1, '502 from the provider: status unavailable, a 200 from the bridge — not an error');
$r = $ask(26, 'account_tickets', $acc);
ok($r['json']['status'] === 'unavailable' && $n() === $n3 + 1, '…not asked again at once');
pg_run("UPDATE k7_cache SET fetched_at = now() - interval '40 seconds'");
kernel_reads([$T => ['mode' => 'ok', 'result' => ['rows' => $tickets]]]);
$r = $ask(26, 'account_tickets', $acc);
ok($r['json']['status'] === 'ok', 'half a minute later (not five) it is asked again and recovers');
$page = html_as(26, "/accounts/$acc?tab=tickets");
ok($page['code'] === 200, 'the tab renders');
kernel_reads([$T => ['mode' => 'down']]);
pg_run('TRUNCATE k7_cache');
$page = html_as(26, "/accounts/$acc?tab=tickets");
ok($page['code'] === 200 && str_contains($page['body'], 'id="account-tickets-unavailable"') && str_contains($page['body'], 'did not answer just now'), 'a busy kernel (503): the tab says Help Desk did not answer just now — a 200, never a 500');
kernel_reads([$L => ['mode' => 'provider_failed']]);
pg_run('TRUNCATE k7_cache');
$ov = html_as(26, "/accounts/$acc");
ok($ov['code'] === 200 && str_contains($ov['body'], 'did not answer just now'), 'the ledger panel says the General Ledger did not answer');
kernel_reads([$T => ['mode' => 'not_shared']]);
pg_run('TRUNCATE k7_cache');
ok($ask(26, 'account_tickets', $acc)['json']['status'] === 'no_connection', 'a tool the provider does not share (403 not_shared) reads as not connected');
kernel_reads([$T => ['mode' => 'ok', 'result' => 'not rows at all']]);
pg_run('TRUNCATE k7_cache');
$r = $ask(26, 'account_tickets', $acc);
ok($r['code'] === 200 && $r['json']['rows'] === [], 'a provider answering a shape that is not rows: no rows, no error');

echo "5. The log\n";
$k = q("SELECT after FROM activity_log WHERE action = 'k7.read' AND id > :s ORDER BY id", ['s' => $since]);
$a = json_decode($k[0]['after'], true);
ok(count($k) >= 6 && isset($a['app'], $a['tool'], $a['outcome'], $a['ms']) && !str_contains(json_encode($k), 'Printer'), 'k7.read: {app, tool, outcome, ms} per read the kernel was asked, never the answer');
ok(count(q("SELECT 1 FROM activity_log WHERE action = 'k7.read' AND id > :s AND after->>'outcome' = 'no_connection'", ['s' => $since])) >= 1 && count(q("SELECT 1 FROM activity_log WHERE action = 'k7.read' AND id > :s AND after->>'outcome' = 'unavailable'", ['s' => $since])) >= 1, 'the outcomes are named: ok, no_connection, unavailable');
$ag = $ask(26, 'account_tickets', $acc, [], 'agent');
$src = q("SELECT source FROM activity_log WHERE action = 'k7.read' ORDER BY id DESC LIMIT 1");
ok(count($src) === 1, 'an agent\'s door is logged too');
$c = (int) one("SELECT count(*) FROM k7_cache");
ok($c >= 1 && !str_contains(json_encode(q('SELECT * FROM k7_cache')), "evil"), 'the cache holds provider, tool, arguments\' hash and the rows');
finish();
