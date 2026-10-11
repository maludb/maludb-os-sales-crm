<?php
/**
 * Proof — the bridge and the five K7 shares (agents-k7-api.md "The bridge", binding points 3 and 4): loopback + HMAC, a whitelist of ops (any other 404), the shares read only with a consumer (K26's X-OS-Consumer) and refuse
 * without one, never include a restricted account, contact or deal (nor the totals of one), never an email body or a note, each logged share.read {application, tool, rows, ms}; the public name answers 404 under /internal/ (Apache).
 */
require __DIR__ . '/lib.php';
require_once dirname(__DIR__, 3) . '/app/bootstrap.php';
require_once dirname(__DIR__, 3) . '/app/features/shares/queries.php';
$x = run_tag();
$since = last_activity_id();
setting('allow_restricted_records', 'true');
$SECRET = "SECRET-BODY-$x";

// the book: Orion (ordinary, Priya's) with two contacts, an open deal, a RESTRICTED open deal, a won deal with products, activities incl. a private one and an email with a body;
// Vault (Marco's, restricted) with a contact, a deal and an activity; a contact of Priya's restricted to herself on Orion; a deleted account.
$orion = (int) post_as(26, '/accounts/save.php', ['name' => "Orion $x", 'website' => "https://orion-$x.example.invalid", 'city' => 'Chicago', 'country' => 'US'])['json']['record_id'];
$ann = (int) post_as(26, '/contacts/save.php', ['first_name' => 'Ann', 'last_name' => "Orion $x", 'email' => "Ann-$x@orion.example.invalid", 'account' => $orion, 'title' => 'CTO'])['json']['record_id'];
$bob = (int) post_as(26, '/contacts/save.php', ['first_name' => 'Bob', 'last_name' => "Orion $x", 'email' => "bob-$x@orion.example.invalid", 'account' => $orion])['json']['record_id'];
post_as(26, '/contacts/opt-out.php', ['contact' => $bob, 'channel' => 'email', 'reason' => 'asked']);
$hid = (int) post_as(26, '/contacts/save.php', ['first_name' => 'Hidden', 'last_name' => "Orion $x", 'email' => "hidden-$x@orion.example.invalid", 'account' => $orion])['json']['record_id'];
post_as(27, '/records/restrict.php', ['kind' => 'contact', 'record' => $hid, 'member' => 26]);
$open = deal_make(26, ['account' => $orion, 'primary_contact' => $ann, 'title' => "Orion open $x", 'value' => 1000]);
$hiddenDeal = deal_make(26, ['account' => $orion, 'primary_contact' => $ann, 'title' => "Orion SECRET deal $x", 'value' => 7000]);
post_as(27, '/records/restrict.php', ['kind' => 'deal', 'record' => $hiddenDeal, 'member' => 26]);
$won = deal_make(26, ['account' => $orion, 'primary_contact' => $ann, 'title' => "Orion won $x", 'value' => 4000]);
post_as(26, '/deals/products/add.php', ['deal' => $won, 'name' => 'Widget', 'unit_price' => 100, 'quantity' => 2, 'sku' => 'W-1']);
post_as(26, '/deals/won.php', ['deal' => $won, 'close_date' => date('Y-m-d', strtotime('-1 day'))]);
$hiddenWon = deal_make(26, ['account' => $orion, 'primary_contact' => $ann, 'title' => "Orion SECRET won $x", 'value' => 9000]);
post_as(26, '/deals/won.php', ['deal' => $hiddenWon, 'close_date' => date('Y-m-d', strtotime('-1 day'))]);
post_as(27, '/records/restrict.php', ['kind' => 'deal', 'record' => $hiddenWon, 'member' => 26]);
post_as(26, '/notes/add.php', ['kind' => 'account', 'record' => $orion, 'body' => "A note: $SECRET"]);
$call = act_id(26, ['kind' => 'call', 'subject' => "Done call $x", 'account' => $orion, 'contact' => $ann, 'starts_at' => at('-3 days 10:00')]);
post_as(26, '/activities/complete.php', ['activity' => $call, 'outcome' => 'connected', 'outcome_note' => "Note $SECRET"]);
$priv = act_id(26, ['kind' => 'call', 'subject' => "Private call $x", 'account' => $orion, 'starts_at' => at('-2 days 10:00')]);
pg_run("UPDATE activities SET done_at = now() - interval '2 days', is_private = true WHERE id = $priv");
$later = act_id(26, ['kind' => 'task', 'subject' => "Far task $x", 'account' => $orion, 'due_at' => at('+20 days 10:00')]);
$soon = act_id(26, ['kind' => 'meeting', 'subject' => "Near meeting $x", 'account' => $orion, 'starts_at' => at('+2 days 10:00')]);
pg_run("INSERT INTO outbound_email (to_address, subject, body_text, contact_id, sender_member_id, status, sent_at) VALUES ('ann-$x@orion.example.invalid', 'An email $x', '$SECRET email body', $ann, 26, 'sent', now())");
$vault = (int) post_as(27, '/accounts/save.php', ['name' => "Vault $x", 'website' => "https://vault-$x.example.invalid"])['json']['record_id'];
$vc = (int) post_as(27, '/contacts/save.php', ['first_name' => 'Vic', 'last_name' => "Vault $x", 'email' => "vic-$x@vault.example.invalid", 'account' => $vault])['json']['record_id'];
$vd = deal_make(27, ['account' => $vault, 'primary_contact' => $vc, 'title' => "Vault deal $x", 'value' => 50000]);
post_as(27, '/deals/won.php', ['deal' => $vd, 'close_date' => date('Y-m-d', strtotime('-1 day'))]);
act_id(27, ['kind' => 'task', 'subject' => "Vault task $x", 'account' => $vault, 'due_at' => at('+1 day 10:00')]);
post_as(27, '/records/restrict.php', ['kind' => 'account', 'record' => $vault, 'member' => 27]);
$dead = (int) post_as(26, '/accounts/save.php', ['name' => "Deadco $x"])['json']['record_id'];
post_as(26, '/records/delete.php', ['kind' => 'account', 'record' => $dead]);

$since = last_activity_id();          // what follows is the shares' own log
echo "1. The door: loopback, signed, fresh, whitelisted\n";
$args = ['q' => "Orion $x"];
$ok = bridge('share_accounts_index', ['consumer' => 'gl', 'args' => $args]);
ok($ok['code'] === 200 && ($ok['json']['ok'] ?? false) === true, 'a signed call is answered');
ok(bridge('share_accounts_index', ['consumer' => 'gl', 'args' => $args], ['unsigned' => true])['code'] === 401, 'no X-CRM-Bridge: 401');
ok(bridge('share_accounts_index', ['consumer' => 'gl', 'args' => $args], ['sig' => str_repeat('0', 64)])['code'] === 401, 'a wrong signature: 401');
ok(bridge('share_accounts_index', ['consumer' => 'gl', 'args' => $args], ['ts' => time() - 60])['code'] === 401 && bridge('share_accounts_index', ['consumer' => 'gl', 'args' => $args], ['ts' => time() + 60])['code'] === 401, 'a timestamp 60 s old or 60 s ahead: 401');
$raw = json_encode(['op' => 'share_accounts_index', 'consumer' => 'gl', 'args' => $args]);
$ts = (string) time();
$sig = hash_hmac('sha256', 'crm-bridge:' . $ts . '.' . hash('sha256', $raw), need('ACTIONS_RELAY_KEY'));
$r = req('POST', bridge_base() . '/internal/bridge.php', ['headers' => ['Content-Type: application/json', 'X-CRM-Bridge: ' . $ts . '.' . $sig], 'raw' => str_replace('"gl"', '"hd"', $raw)]);
ok($r['code'] === 401, 'a body changed after it was signed: 401');
$r = req('POST', bridge_base() . '/internal/bridge.php', ['headers' => ['Content-Type: application/json', 'X-CRM-Bridge: ' . $ts . '.' . hash_hmac('sha256', $ts . '.' . hash('sha256', $raw), need('ACTIONS_RELAY_KEY'))], 'raw' => $raw]);
ok($r['code'] === 401, 'a signature without the crm-bridge prefix (Inventory\'s recipe or none): 401');
$r = bridge('share_accounts_index', ['consumer' => 'gl', 'args' => $args], ['headers' => ['X-Forwarded-For: 203.0.113.9', 'X-Real-IP: 203.0.113.9']]);
ok($r['code'] === 200, 'forwarding headers are not read: loopback is the connection\'s own address');
$r = bridge('share_accounts_index', ['consumer' => 'gl'], ['method' => 'GET']);
ok($r['code'] === 405 && header_of($r, 'Allow') === 'POST', 'GET: 405');
ok(verify_ok(), 'bridge_verify(): a signed body passes from 127.0.0.1 and ::1');
function verify_ok(): bool
{
    $body = '{"op":"x"}';
    $h = bridge_sign($body);
    return bridge_verify($body, $h, '127.0.0.1', time()) === null && bridge_verify($body, $h, '::1', time()) === null
        && bridge_verify($body, $h, '10.0.0.5', time()) === 'not_loopback' && bridge_verify($body, $h, '203.0.113.9', time()) === 'not_loopback' && bridge_verify($body, $h, '::ffff:10.0.0.5', time()) === 'not_loopback'
        && bridge_verify($body, '', '127.0.0.1', time()) === 'bad_signature' && bridge_verify($body, 'x.y', '127.0.0.1', time()) === 'bad_signature' && bridge_verify('{"op":"y"}', $h, '127.0.0.1', time()) === 'bad_signature';
}
foreach (['delete_everything', 'sql', 'share_nope', 'notify2', '', 'SHARE_ACCOUNTS_INDEX'] as $op) {
    $r = bridge($op, ['consumer' => 'gl']);
    ok($r['code'] === 404 && ($r['json']['error']['code'] ?? '') === 'no_such_op', 'op "' . $op . '" is not on the list: 404');
}
ok(bridge('share_accounts_index', ['consumer' => 'gl'], ['headers' => []])['code'] === 200, 'the five share ops, k7_read and notify are the whole list: ' . implode(', ', BRIDGE_OPS));
if (getenv('CRM_APP') === 'apache') {
    $r = bridge('share_accounts_index', ['consumer' => 'gl'], ['base' => BASE]);
    ok($r['code'] === 404, 'under Apache the PUBLIC name answers 404 for /internal/bridge.php, signed or not (the internal port is the only door)');
    ok(req('GET', '/internal/bridge.php')['code'] === 404 && req('GET', '/internal/')['code'] === 404, 'and for any path under /internal/');
}

echo "2. A share needs a consumer\n";
foreach (['share_accounts_index', 'share_contacts_index', 'share_deals_won', 'share_deals_open_by_account', 'share_account_timeline_summary'] as $op) {
    $nl = bridge($op, ['args' => ['account' => $orion, 'from' => '2020-01-01', 'to' => '2030-01-01']]);
    $bad = bridge($op, ['consumer' => 'Not A Key!', 'args' => ['account' => $orion, 'from' => '2020-01-01', 'to' => '2030-01-01']]);
    $smug = bridge($op, ['args' => ['consumer' => 'gl', 'as_agent' => 'x', 'account' => $orion, 'from' => '2020-01-01', 'to' => '2030-01-01']]);
    ok($nl['code'] === 403 && ($nl['json']['error']['code'] ?? '') === 'consumer_required' && !isset($nl['json']['result']) && $bad['code'] === 403 && $smug['code'] === 403, "$op: no consumer, a bad one, or one smuggled in the arguments: 403 consumer_required, no data");
}
ok(count(activity('share.read', $since)) > 0 && (int) one("SELECT count(*) FROM activity_log WHERE action = 'share.read' AND id > :s AND after->>'application' IN ('Not A Key!', '')", ['s' => $since]) === 0, 'a refused call reads nothing and logs no share.read');

echo "3. accounts_index\n";
$d = bridge('share_accounts_index', ['consumer' => 'gl', 'args' => ['q' => $x]])['json']['result'];
$names = array_column($d['accounts'], 'name');
ok($d['schema'] === 'os.sales-crm-accounts/1' && $d['application'] === 'sales_crm' && isset($d['total']), 'the document: schema os.sales-crm-accounts/1, the application, a total');
ok(in_array("Orion $x", $names, true) && !in_array("Vault $x", $names, true) && !in_array("Deadco $x", $names, true), 'Orion is shared; the restricted Vault and the deleted Deadco are not');
$o = array_values(array_filter($d['accounts'], static fn ($a) => $a['name'] === "Orion $x"))[0];
ok($o['account_id'] === $orion && $o['domain'] === "orion-$x.example.invalid" && $o['kind'] === 'customer' && $o['owner_name'] === 'SMOKE Priya' && $o['city'] === 'Chicago' && $o['country'] === 'US' && $o['currency'] === 'USD', 'its facts: id, domain, kind, owner\'s name, city, country, currency — ' . json_encode(array_intersect_key($o, array_flip(['kind', 'owner_name']))));
ok($o['open_deals_count'] === 1 && (float) $o['open_deals_value'] === 1000.0 && (float) $o['won_deals_value'] === 4000.0, 'its totals count only the deals a share may show: one open deal (1,000) and one won (4,000) — the restricted 7,000 open and 9,000 won are left out (db/023): ' . json_encode([$o['open_deals_count'], $o['open_deals_value'], $o['won_deals_value']]));
ok(array_keys($o) === ['account_id', 'name', 'legal_name', 'domain', 'kind', 'owner_member_id', 'owner_name', 'gl_customer_id', 'helpdesk_organization_id', 'country', 'city', 'open_deals_count', 'open_deals_value', 'won_deals_value', 'currency', 'updated_at'], 'exactly the documented fields');
$p = bridge('share_accounts_index', ['consumer' => 'hd', 'args' => ['limit' => 1, 'offset' => 0]])['json']['result'];
ok(count($p['accounts']) === 1 && $p['total'] >= 2, 'limit 1 pages: one row and the total');
ok(bridge('share_accounts_index', ['consumer' => 'hd', 'args' => ['limit' => 1000]])['code'] === 422 && bridge('share_accounts_index', ['consumer' => 'hd', 'args' => ['since' => 'yesterday-ish']])['code'] === 422, 'a limit over 500 or an unreadable since: 422');
$sn = bridge('share_accounts_index', ['consumer' => 'hd', 'args' => ['since' => gmdate(DATE_ATOM, time() + 3600)]])['json']['result'];
ok($sn['accounts'] === [] && $sn['total'] === 0, '`since` an hour ahead: nothing');
$inj = bridge('share_accounts_index', ['consumer' => 'gl', 'args' => ['q' => "x%' OR 1=1 --"]]);
ok($inj['code'] === 200 && $inj['json']['result']['accounts'] === [], 'a hostile q is only text');
ok(!str_contains(json_encode($d), "Vault $x") && !str_contains(json_encode($d), 'SECRET'), 'no word of the restricted account or the restricted deals');

echo "4. contacts_index\n";
$d = bridge('share_contacts_index', ['consumer' => 'hd', 'args' => ['account' => $orion]])['json']['result'];
$cn = array_column($d['contacts'], 'contact_id');
ok($d['schema'] === 'os.sales-crm-contacts/1' && in_array($ann, $cn, true) && in_array($bob, $cn, true) && !in_array($hid, $cn, true), 'Orion\'s contacts: Ann and Bob; the restricted Hidden is not shared');
$a = array_values(array_filter($d['contacts'], static fn ($c) => $c['contact_id'] === $ann))[0];
$b = array_values(array_filter($d['contacts'], static fn ($c) => $c['contact_id'] === $bob))[0];
ok($a['emails'] === ["ann-$x@orion.example.invalid"] && $a['title'] === 'CTO' && $a['account_id'] === $orion && $a['account_name'] === "Orion $x" && $b['email_opt_out'] === true && $a['email_opt_out'] === false, 'emails (lower case) as a list, title, account, the opt-out flag');
ok(array_keys($a) === ['contact_id', 'account_id', 'account_name', 'name', 'first_name', 'last_name', 'title', 'role', 'emails', 'phone', 'mobile', 'lifecycle', 'email_opt_out', 'sms_opt_out', 'owner_member_id', 'updated_at'], 'exactly the documented fields');
$by = bridge('share_contacts_index', ['consumer' => 'hd', 'args' => ['email' => "ANN-$x@Orion.example.invalid"]])['json']['result'];
ok(count($by['contacts']) === 1 && $by['contacts'][0]['contact_id'] === $ann, 'by email, case-insensitive: Ann (the Help Desk\'s requester match)');
$byHidden = bridge('share_contacts_index', ['consumer' => 'hd', 'args' => ['email' => "hidden-$x@orion.example.invalid"]])['json']['result'];
$byVault = bridge('share_contacts_index', ['consumer' => 'hd', 'args' => ['account' => $vault]])['json']['result'];
ok($byHidden['contacts'] === [] && $byVault['contacts'] === [], 'a restricted contact, and a contact of a restricted account, are not found even by exact email or account');
ok(!str_contains(json_encode($d), $SECRET), 'no note');

echo "5. deals_won\n";
$w = ['from' => date('Y-m-d', strtotime('-7 days')), 'to' => date('Y-m-d')];
$d = bridge('share_deals_won', ['consumer' => 'inventory', 'args' => $w])['json']['result'];
$ids = array_column($d['deals'], 'deal_id');
ok($d['schema'] === 'os.sales-crm-deals-won/1' && in_array($won, $ids, true) && !in_array($hiddenWon, $ids, true) && !in_array($vd, $ids, true), 'the won deal is shared; the restricted deal and the restricted account\'s deal are not');
$wd = array_values(array_filter($d['deals'], static fn ($r) => $r['deal_id'] === $won))[0];
ok($wd['account']['account_id'] === $orion && $wd['account']['name'] === "Orion $x" && $wd['primary_contact']['contact_id'] === $ann && $wd['primary_contact']['email'] === "ann-$x@orion.example.invalid" && (float) $wd['value'] === 4000.0 && $wd['currency'] === 'USD' && $wd['pipeline_key'] === 'new_business',
    'account, primary contact, value, currency, pipeline');
ok(count($wd['products']) === 1 && $wd['products'][0]['name'] === 'Widget' && $wd['products'][0]['sku'] === 'W-1' && (float) $wd['products'][0]['quantity'] === 2.0, 'its products: name, sku, quantity');
ok(array_keys($wd) === ['deal_id', 'number', 'title', 'account', 'primary_contact', 'value', 'currency', 'won_at', 'owner_member_id', 'owner_name', 'pipeline_key', 'products'], 'exactly the documented fields');
$none = bridge('share_deals_won', ['consumer' => 'inventory', 'args' => ['from' => '2001-01-01', 'to' => '2001-12-31']])['json']['result'];
ok($none['deals'] === [] && $none['total'] === 0, 'a window with nothing: an empty list');
ok(bridge('share_deals_won', ['consumer' => 'inventory', 'args' => ['to' => '2030-01-01']])['code'] === 422 && bridge('share_deals_won', ['consumer' => 'inventory', 'args' => ['from' => '2030-01-02', 'to' => '2030-01-01']])['code'] === 422
    && bridge('share_deals_won', ['consumer' => 'inventory', 'args' => ['from' => 'last week', 'to' => '2030-01-01']])['code'] === 422, 'a missing, reversed or unreadable window: 422');
ok(!str_contains(json_encode($d), 'SECRET') && !str_contains(json_encode($d), "Vault $x"), 'nothing of the restricted ones');

echo "6. deals_open_by_account\n";
$d = bridge('share_deals_open_by_account', ['consumer' => 'hd', 'args' => ['account' => $orion]])['json']['result'];
ok($d['schema'] === 'os.sales-crm-deals-open/1' && $d['account_id'] === $orion && array_column($d['deals'], 'deal_id') === [$open], 'Orion\'s open deals: just the one a share may show');
ok(array_keys($d['deals'][0]) === ['deal_id', 'number', 'title', 'stage_name', 'probability', 'value', 'weighted_value', 'currency', 'expected_close_date', 'owner_member_id', 'owner_name', 'next_activity_at', 'next_colour', 'stage_entered_at'], 'exactly the documented fields');
$v = bridge('share_deals_open_by_account', ['consumer' => 'hd', 'args' => ['account' => $vault]]);
$missing = bridge('share_deals_open_by_account', ['consumer' => 'hd', 'args' => ['account' => 99999999]]);
ok($v['code'] === 200 && $v['json']['result']['deals'] === [] && $missing['json']['result']['deals'] === [] && json_encode($v['json']['result']) === str_replace('99999999', (string) $vault, json_encode($missing['json']['result'])), 'a restricted account and a missing one answer the same empty list: nothing tells them apart');
ok(bridge('share_deals_open_by_account', ['consumer' => 'hd', 'args' => []])['code'] === 422, 'no account: 422');

echo "7. account_timeline_summary\n";
$d = bridge('share_account_timeline_summary', ['consumer' => 'hd', 'args' => ['account' => $orion, 'limit' => 10]])['json']['result'];
$subjects = array_column($d['touches'], 'subject');
ok($d['schema'] === 'os.sales-crm-timeline/1' && in_array("Done call $x", $subjects, true) && !in_array("Private call $x", $subjects, true), 'the done call is a touch; the private one is not');
ok(($d['next_step']['subject'] ?? '') === "Near meeting $x" && !in_array("Far task $x", $subjects, true), 'the next step is the NEAREST open activity (db/023), not the farthest — ' . ($d['next_step']['subject'] ?? 'none'));
ok(array_keys($d['touches'][0]) === ['at', 'kind', 'subject', 'who', 'direction', 'deal_number'] && array_keys($d['next_step']) === ['at', 'kind', 'subject', 'who', 'deal_number'], 'dates, kinds, subjects, who — the documented fields, and no body');
$j = json_encode($d);
ok(!str_contains($j, $SECRET) && !str_contains($j, 'outcome') && !str_contains($j, 'An email'), 'no note, no outcome note, no email subject or body');
$limited = bridge('share_account_timeline_summary', ['consumer' => 'hd', 'args' => ['account' => $orion, 'limit' => 1]])['json']['result'];
ok(count($limited['touches']) === 1, 'limit 1: one touch');
$v = bridge('share_account_timeline_summary', ['consumer' => 'hd', 'args' => ['account' => $vault]])['json']['result'];
ok($v['touches'] === [] && !isset($v['next_step']), 'a restricted account: no touches, no next step');

echo "8. The log\n";
$sr = q("SELECT * FROM activity_log WHERE action = 'share.read' AND id > :s ORDER BY id", ['s' => $since]);
$a0 = json_decode($sr[0]['after'], true);
ok(count($sr) >= 15 && $sr[0]['source'] === 'application' && $sr[0]['actor_member_id'] === null && isset($a0['application'], $a0['tool'], $a0['rows'], $a0['ms']), 'every successful share is logged share.read with source application and {application, tool, rows, ms}');
$tools = array_unique(array_map(static fn ($r) => json_decode($r['after'], true)['tool'], $sr));
sort($tools);
ok($tools === ['account_timeline_summary', 'accounts_index', 'contacts_index', 'deals_open_by_account', 'deals_won'], 'one tool name each of the five');
$ow = array_values(array_filter($sr, static fn ($r) => json_decode($r['after'], true)['tool'] === 'deals_won'))[0];
ok(json_decode($ow['after'], true)['application'] === 'inventory' && json_decode($ow['after'], true)['from'] === $w['from'], 'the consumer is named, with the window asked about');
$r = bridge('share_accounts_index', ['consumer' => 'spaces', 'consumer_agent' => 'Spaces Librarian', 'args' => ['limit' => 1]]);
$last = json_decode(q("SELECT after FROM activity_log WHERE action = 'share.read' ORDER BY id DESC LIMIT 1")[0]['after'], true);
ok($r['code'] === 200 && ($last['consumer_agent'] ?? '') === 'Spaces Librarian' && $last['application'] === 'spaces', 'X-OS-Consumer-Agent (K26) is kept in the row when the kernel names one');
$all = log_text_since($since);
ok(!str_contains($all, $SECRET) && !str_contains($all, "ann-$x@orion") && !str_contains($all, 'Widget'), 'no row, name, email or product of the answer in the log');
finish();
