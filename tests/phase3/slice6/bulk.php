<?php
/**
 * Proof — bulk actions (campaigns-lists.md divergence 1): each of the six over `records[]`, over a list and over `filters`, in one transaction; exactly one of them is the source (the bar's `scope` picks between
 * its ticks and the whole result); one record the caller may not change refuses the WHOLE action by name and nothing changes; a record behind the wall is the same 404; over `bulk_sync_rows` the action is refused with
 * the spec's words; the rights; the log's rows hold kind, count and filters, never a name; an agent's assign, update and delete pause (the registry). The bar on the four list screens and the list's page.
 */
require __DIR__ . '/lib.php';
$x = run_tag();
$since = last_activity_id();
$mkt = role_actor(31, 'marketing');
pg_run("UPDATE crm_settings SET bulk_sync_rows = 1000");
$acc = (int) post_as(26, '/accounts/save.php', ['name' => "Bulk Acct $x"])['json']['record_id'];
$P = [];
foreach (['One', 'Two', 'Three', 'Four', 'Five'] as $i => $n) { $P[$i + 1] = contact_with("p" . ($i + 1) . "-$x@bulk-$x.example.invalid", $n, $acc, 26); }
$Dn = contact_with("d1-$x@bulk-$x.example.invalid", 'Dana-owned', $acc, 30);
$secret = (int) post_as(27, '/contacts/save.php', ['first_name' => 'Sealed', 'last_name' => "Away $x", 'email' => "sealed-$x@bulk.example.invalid"])['json']['record_id'];
post_as(27, '/records/restrict.php', ['kind' => 'contact', 'record' => $secret]);
$owner = static fn (int $c): ?int => ($v = one('SELECT owner_member_id FROM contacts WHERE id = :c', ['c' => $c])) === null || $v === false ? null : (int) $v;
$tagged = static fn (string $label, string $kind = 'contact'): array => array_map('intval', array_column(q("SELECT rt.record_id FROM record_tags rt JOIN tags t ON t.id = rt.tag_id WHERE rt.record_type = :k AND t.label = :l ORDER BY 1", ['k' => $kind, 'l' => $label]), 'record_id'));
$msg = static fn (array $r): string => message_of($r);
$last = static fn (string $action): array => json_decode((string) (q("SELECT after::text AS after FROM activity_log WHERE id > :s AND action = :a ORDER BY id DESC LIMIT 1", ['s' => $since, 'a' => $action])[0]['after'] ?? '{}'), true);

echo "1. Assign\n";
ok(post_as(26, '/bulk/assign.php', ['kind' => 'contact', 'owner' => 30, 'records' => [$P[1]]])['code'] === 403, 'a Rep has no records.bulk: bulk_assign is 403');
ok(post_as(29, '/bulk/assign.php', ['kind' => 'contact', 'owner' => 30, 'records' => [$P[1]]])['code'] === 403, '…and a Viewer');
$r = post_as(27, '/bulk/assign.php', ['kind' => 'contact', 'owner' => 30, 'records' => [$P[1], $P[2]]]);
ok($r['code'] === 200 && ($r['json']['count'] ?? 0) === 2 && ($r['json']['record_id'] ?? 0) === 2 && $owner($P[1]) === 30 && $owner($P[2]) === 30 && $owner($P[3]) === 26, 'over records[]: two contacts to Dana, the others untouched — ' . ($r['json']['did'] ?? $r['body']));
$la = $last('bulk.assign');
ok($la['kind'] === 'contact' && $la['count'] === 2 && $la['source'] === 'records' && $la['owner_member_id'] === 30 && !isset($la['filters']), 'bulk.assign logs {kind, count, source, owner} once: ' . json_encode($la));
$r = post_as(27, '/bulk/assign.php', ['kind' => 'contact', 'owner' => 26, 'records' => [$P[1], $P[2]]]);
ok($r['code'] === 200 && $owner($P[1]) === 26, 'and back again (a record already hers is not counted twice)');
post_as(27, '/bulk/assign.php', ['kind' => 'contact', 'owner' => 30, 'records' => [$P[1]]]);
$r = post_as(27, '/bulk/assign.php', ['kind' => 'contact', 'owner' => 26, 'records' => [$P[1], $P[3]]]);
ok(($r['json']['count'] ?? 0) === 1, 'only the one that changed owner is counted: 1');
post_as(27, '/tags/apply.php', ['kind' => 'contact', 'records' => [$P[1], $P[2], $P[3], $P[4]], 'tag' => "wave-$x"]);
$r = post_as(27, '/bulk/assign.php', ['kind' => 'contact', 'owner' => 30, 'filters' => json_encode(['tag' => "wave-$x"])]);
ok($r['code'] === 200 && ($r['json']['count'] ?? 0) === 4 && ($r['json']['source'] ?? '') === 'filters' && $owner($P[4]) === 30, 'over filters (the tag wave): the four');
$la = $last('bulk.assign');
ok($la['filters'] == ['tag' => "wave-$x"] && $la['source'] === 'filters', 'the log keeps the filters: ' . json_encode($la['filters']));
$l1 = (int) post_as(27, '/lists/save.php', ['name' => "Bulk static $x", 'kind' => 'static', 'record_kind' => 'contact'])['json']['record_id'];
post_as(27, '/lists/add.php', ['list' => $l1, 'records' => [$P[1], $P[2]]]);
$r = post_as(27, '/bulk/assign.php', ['kind' => 'contact', 'owner' => 26, 'list' => $l1]);
ok($r['code'] === 200 && ($r['json']['count'] ?? 0) === 2 && ($r['json']['source'] ?? '') === 'list' && $owner($P[1]) === 26 && $owner($P[2]) === 26 && $owner($P[4]) === 30, 'over a static list: its two members');
$lsm = (int) post_as(27, '/lists/save.php', ['name' => "Bulk smart $x", 'kind' => 'smart', 'record_kind' => 'contact', 'filter' => ['tag' => "wave-$x"]])['json']['record_id'];
$r = post_as(27, '/bulk/assign.php', ['kind' => 'contact', 'owner' => 26, 'list' => $lsm]);
ok($r['code'] === 200 && ($r['json']['count'] ?? 0) >= 2 && $owner($P[4]) === 26 && $owner($P[3]) === 26, 'over a smart list: the filter run now (the wave)');
foreach ([['kind' => 'lead'], ['kind' => 'account'], ['kind' => 'deal']] as $f) {
    $ids = match ($f['kind']) { 'lead' => [lead_make(26), lead_make(26)], 'account' => [(int) post_as(26, '/accounts/save.php', ['name' => "BA1 $x"])['json']['record_id'], (int) post_as(26, '/accounts/save.php', ['name' => "BA2 $x"])['json']['record_id']], 'deal' => [deal_make(26), deal_make(26)] };
    $r = post_as(27, '/bulk/assign.php', ['kind' => $f['kind'], 'owner' => 30, 'records' => $ids]);
    $tbl = ['lead' => 'leads', 'account' => 'accounts', 'deal' => 'deals'][$f['kind']];
    ok($r['code'] === 200 && ($r['json']['count'] ?? 0) === 2 && (int) one("SELECT count(*) FROM $tbl WHERE id = ANY (CAST(:i AS bigint[])) AND owner_member_id = 30", ['i' => lit($ids)]) === 2, 'a ' . $f['kind'] . ' pair goes to Dana: ' . ($r['json']['did'] ?? $r['body']));
}
$r = post_as(27, '/bulk/assign.php', ['kind' => 'contact', 'records' => [$P[1]]]);
ok($r['code'] === 422 && field_of($r) === 'owner', 'no owner: the field is named');
ok(post_as(27, '/bulk/assign.php', ['kind' => 'contact', 'owner' => 999999, 'records' => [$P[1]]])['code'] === 422, 'an owner who is not here: refused');
ok(post_as(27, '/bulk/assign.php', ['kind' => 'sorcery', 'owner' => 30, 'records' => [$P[1]]])['code'] === 422, 'an unknown kind: refused in words');

echo "2. What to act on: exactly one\n";
$r = post_as(27, '/bulk/assign.php', ['kind' => 'contact', 'owner' => 26]);
ok($r['code'] === 422 && str_contains($msg($r), 'Say what to act on'), 'nothing named: ' . $msg($r));
$r = post_as(27, '/bulk/assign.php', ['kind' => 'contact', 'owner' => 26, 'records' => [$P[1]], 'filters' => json_encode(['tag' => "wave-$x"])]);
ok($r['code'] === 422 && str_contains($msg($r), 'one of'), 'records and filters together: refused — one of them');
$r = post_as(27, '/bulk/assign.php', ['kind' => 'contact', 'owner' => 26, 'scope' => 'selected', 'records' => [$P[2]], 'filters' => json_encode(['tag' => "wave-$x"])]);
ok($r['code'] === 200 && ($r['json']['source'] ?? '') === 'records', 'the bar\'s scope=selected reads only the ticks');
$r = post_as(27, '/bulk/assign.php', ['kind' => 'contact', 'owner' => 30, 'scope' => 'all', 'records' => [$P[2]], 'filters' => json_encode(['tag' => "wave-$x"])]);
ok($r['code'] === 200 && ($r['json']['source'] ?? '') === 'filters' && ($r['json']['count'] ?? 0) === 4, 'scope=all reads only the filters (here the wave of four)');
$r = post_as(27, '/bulk/assign.php', ['kind' => 'contact', 'owner' => 30, 'scope' => 'selected', 'filters' => json_encode(['tag' => "wave-$x"])]);
ok($r['code'] === 422 && str_contains($msg($r), 'Tick'), 'scope=selected with no tick: "Tick the contacts to act on"');
$r = post_as(27, '/bulk/assign.php', ['kind' => 'contact', 'owner' => 30, 'list' => 999999]);
ok($r['code'] === 404, 'a list that is not there: 404');
$lLeads = (int) post_as(27, '/lists/save.php', ['name' => "Bulk leads $x", 'kind' => 'static', 'record_kind' => 'lead'])['json']['record_id'];
$r = post_as(27, '/bulk/assign.php', ['kind' => 'contact', 'owner' => 30, 'list' => $lLeads]);
ok($r['code'] === 422 && str_contains($msg($r), 'holds'), 'a list of leads for contacts: "That list holds Leads, not Contacts"');
$r = post_as(27, '/bulk/assign.php', ['kind' => 'contact', 'owner' => 30, 'records' => [$secret, $P[1]]]);
ok($r['code'] === 200, 'the manager sees the sealed contact: it is assigned too');
$r = post_as(26, '/bulk/tag.php', ['kind' => 'contact', 'tag' => "x-$x", 'records' => [$secret, $P[1]]]);
ok($r['code'] === 404 && $tagged("x-$x") === [], 'a record behind the wall in the ticks: the same 404 as a missing one, and nothing was tagged');
$r = post_as(26, '/bulk/tag.php', ['kind' => 'contact', 'tag' => "x-$x", 'filters' => json_encode(['tag' => "no-such-$x"])]);
ok($r['code'] === 422 && str_contains($msg($r), 'Nothing matches') && $tagged("x-$x") === [], 'filters that match nothing change nothing and say so: ' . $msg($r));
$r = post_as(26, '/bulk/tag.php', ['kind' => 'contact', 'tag' => "x-$x", 'filters' => json_encode(['q' => "Sealed Away $x", 'tag' => "sealed-$x"])]);
ok($r['code'] === 422 && $tagged("x-$x") === [], 'and what only the manager can see is not in her filters\' result');

echo "3. Tag\n";
ok(post_as(29, '/bulk/tag.php', ['kind' => 'contact', 'tag' => 'x', 'records' => [$P[1]]])['code'] === 403, 'a Viewer may not tag');
post_as(27, '/bulk/assign.php', ['kind' => 'contact', 'owner' => 26, 'records' => [$P[1], $P[2], $P[3]]]);
$r = post_as(26, '/bulk/tag.php', ['kind' => 'contact', 'tag' => "mine-$x", 'records' => [$P[1], $P[2], $P[3]]]);
ok($r['code'] === 200 && ($r['json']['count'] ?? 0) === 3 && $tagged("mine-$x") === [$P[1], $P[2], $P[3]], 'a Rep (tags.apply) tags her own three over records[] — a new tag is made');
$r = post_as(26, '/bulk/tag.php', ['kind' => 'contact', 'tag' => "mine-$x", 'records' => [$P[1], $P[2], $P[3]]]);
ok($r['code'] === 200 && ($r['json']['count'] ?? 1) === 0, 'again: none changed (they carry it)');
$r = post_as(26, '/bulk/tag.php', ['kind' => 'contact', 'tag' => "mixed-$x", 'records' => [$P[1], $Dn]]);
ok($r['code'] === 403 && str_contains($msg($r), 'Dana') && str_contains($msg($r), 'Dana-owned') && str_contains($msg($r), 'nothing was changed') && $tagged("mixed-$x") === [], 'one record she may not change refuses the WHOLE action, naming it: ' . $msg($r));
$r = post_as(26, '/bulk/tag.php', ['kind' => 'contact', 'tag' => "mixed-$x", 'filters' => json_encode(['account' => $acc])]);
ok($r['code'] === 403 && $tagged("mixed-$x") === [], '…the same over filters that take in Dana\'s contact: refused whole');
$r = post_as(26, '/bulk/tag.php', ['kind' => 'contact', 'tag' => "mine-$x", 'remove' => 'yes', 'records' => [$P[1], $P[2]]]);
ok($r['code'] === 200 && ($r['json']['count'] ?? 0) === 2 && $tagged("mine-$x") === [$P[3]], 'remove=yes takes the tag off two');
ok(post_as(26, '/bulk/tag.php', ['kind' => 'contact', 'tag' => "never-made-$x", 'remove' => 'yes', 'records' => [$P[1]]])['code'] === 422, 'taking off a tag nobody made is said in words');
ok(post_as(26, '/bulk/tag.php', ['kind' => 'contact', 'records' => [$P[1]]])['code'] === 422 && field_of(post_as(26, '/bulk/tag.php', ['kind' => 'contact', 'records' => [$P[1]]])) === 'tag', 'no tag: the field is named');
$r = post_as(27, '/bulk/tag.php', ['kind' => 'contact', 'tag' => "sealed-$x", 'records' => [$secret]]);
ok($r['code'] === 200, 'the manager tags anything he sees');
$la = $last('bulk.tag');
ok($la['kind'] === 'contact' && $la['count'] === 1 && $la['tag'] === "sealed-$x" && !str_contains(json_encode($la), 'Sealed'), 'bulk.tag logs kind, count and the tag, no name: ' . json_encode($la));

echo "4. Update a field\n";
pg_run("INSERT INTO custom_field_defs (key, label, field_type, options, applies_to) VALUES ('tier_$x', 'Tier $x', 'select', '{gold,silver}', 'contact')");
$r = post_as(27, '/bulk/update.php', ['kind' => 'contact', 'field' => 'role', 'value' => 'finance', 'records' => [$P[1], $P[2]]]);
ok($r['code'] === 200 && ($r['json']['count'] ?? 0) === 2 && one("SELECT role FROM contacts WHERE id = $P[1]") === 'finance', 'a column over records[]: role = finance on two');
$r = post_as(27, '/bulk/update.php', ['kind' => 'contact', 'field' => 'role', 'value' => 'finance', 'records' => [$P[1], $P[2]]]);
ok(($r['json']['count'] ?? 1) === 0, 'the same value again changes nobody');
$r = post_as(27, '/bulk/update.php', ['kind' => 'contact', 'field' => 'role', 'value' => '', 'records' => [$P[1]]]);
ok($r['code'] === 200 && one("SELECT role FROM contacts WHERE id = $P[1]") === null, 'an empty value clears it (where it may be cleared)');
$r = post_as(27, '/bulk/update.php', ['kind' => 'contact', 'field' => 'role', 'value' => 'wizard', 'records' => [$P[1]]]);
ok($r['code'] === 422 && field_of($r) === 'value' && str_contains($msg($r), 'one of'), 'a value outside the choices: the field named, the choices listed');
foreach (['owner_member_id', 'owner', 'email_opt_out', 'status', 'lifecycle', 'restricted_to_member_id', 'id'] as $bad) {
    $r = post_as(27, '/bulk/update.php', ['kind' => 'contact', 'field' => $bad, 'value' => '1', 'records' => [$P[1]]]);
    ok($r['code'] === 422 && field_of($r) === 'field', "field $bad cannot be set in bulk: refused, the field named");
}
$r = post_as(27, '/bulk/update.php', ['kind' => 'contact', 'field' => 'custom:tier_' . $x, 'value' => 'gold', 'records' => [$P[1], $P[2], $P[3]]]);
ok($r['code'] === 200 && ($r['json']['count'] ?? 0) === 3 && (int) one("SELECT count(*) FROM contacts WHERE id IN ($P[1], $P[2], $P[3]) AND custom_values->>'tier_$x' = 'gold'") === 3, 'a custom field: tier = gold on three');
$r = post_as(27, '/bulk/update.php', ['kind' => 'contact', 'field' => 'custom:tier_' . $x, 'value' => 'bronze', 'records' => [$P[1]]]);
ok($r['code'] === 422 && one("SELECT custom_values->>'tier_$x' FROM contacts WHERE id = $P[1]") === 'gold', 'a custom value the database refuses (not an option): 422, nothing changed');
$r = post_as(27, '/bulk/update.php', ['kind' => 'contact', 'field' => 'custom:tier_' . $x, 'value' => '', 'records' => [$P[1]]]);
ok($r['code'] === 200 && one("SELECT jsonb_exists(custom_values, 'tier_$x') FROM contacts WHERE id = $P[1]") === false, 'cleared');
ok(post_as(27, '/bulk/update.php', ['kind' => 'contact', 'field' => 'custom:nothing', 'value' => 'a', 'records' => [$P[1]]])['code'] === 422, 'a custom field that does not exist: refused');
$r = post_as(27, '/bulk/update.php', ['kind' => 'deal', 'field' => 'expected_close_date', 'value' => '2027-03-04', 'records' => [deal_make(26), deal_make(26)]]);
ok($r['code'] === 200 && ($r['json']['count'] ?? 0) === 2, 'deals: the close date');
ok(post_as(27, '/bulk/update.php', ['kind' => 'deal', 'field' => 'expected_close_date', 'value' => 'soon', 'records' => [deal_make(26)]])['code'] === 422, 'a date that is not one: refused');
ok(post_as(27, '/bulk/update.php', ['kind' => 'account', 'field' => 'kind', 'value' => '', 'records' => [$acc]])['code'] === 422, 'an account\'s kind cannot be cleared');
ok(post_as(27, '/bulk/update.php', ['kind' => 'account', 'field' => 'industry', 'value' => 'Mining', 'records' => [$acc]])['code'] === 200 && one('SELECT industry FROM accounts WHERE id = :a', ['a' => $acc]) === 'Mining', 'accounts: the industry');
$camp = campaign_make(['name' => "Bulk camp $x"]);
$r = post_as(27, '/bulk/update.php', ['kind' => 'lead', 'field' => 'campaign', 'value' => (string) $camp, 'records' => [lead_make(26), lead_make(26)]]);
ok($r['code'] === 200 && ($r['json']['count'] ?? 0) === 2, 'leads: the campaign (an id the caller sees)');
ok(post_as(27, '/bulk/update.php', ['kind' => 'lead', 'field' => 'campaign', 'value' => '999999', 'records' => [lead_make(26)]])['code'] === 422, 'a campaign that is not there: refused');
$la = $last('bulk.update');
ok($la['field'] === 'campaign' && $la['count'] === 0 || $la['field'] !== null, 'bulk.update logs the field, never the value: ' . json_encode(array_diff_key($la, ['record_ids' => 1])));
ok(!array_key_exists('value', $la), 'no value key in the log');
ok(post_as(26, '/bulk/update.php', ['kind' => 'contact', 'field' => 'role', 'value' => 'finance', 'records' => [$P[3]]])['code'] === 403, 'a Rep may not: bulk_update needs records.bulk');

echo "5. Add to a campaign; add to a list\n";
$r = post_as(27, '/bulk/campaign.php', ['kind' => 'contact', 'campaign' => $camp, 'records' => [$P[1], $P[2], $P[3]]]);
ok($r['code'] === 200 && ($r['json']['count'] ?? 0) === 3 && member_status($camp, $P[1]) === 'planned', 'bulk_campaign_add over records[]: three planned members');
$r = post_as(27, '/bulk/campaign.php', ['kind' => 'contact', 'campaign' => $camp, 'filters' => json_encode(['tag' => "wave-$x"])]);
ok($r['code'] === 200 && ($r['json']['count'] ?? 0) === 1 && ($r['json']['already'] ?? 0) === 3, 'over filters: the wave of four — three were in, one new');
$r = post_as(27, '/bulk/campaign.php', ['kind' => 'contact', 'campaign' => $camp, 'list' => $l1]);
ok($r['code'] === 200 && ($r['json']['already'] ?? 0) === 2, 'over a list: both were in already');
$r = post_as(27, '/bulk/campaign.php', ['kind' => 'lead', 'campaign' => $camp, 'records' => [lead_make(26)]]);
ok($r['code'] === 200 && ($r['json']['count'] ?? 0) === 1, 'leads join too');
ok(post_as(27, '/bulk/campaign.php', ['kind' => 'account', 'campaign' => $camp, 'records' => [$acc]])['code'] === 422, 'only leads and contacts belong to a campaign');
ok(post_as(27, '/bulk/campaign.php', ['kind' => 'contact', 'campaign' => 999999, 'records' => [$P[1]]])['code'] === 404 && post_as(27, '/bulk/campaign.php', ['kind' => 'contact', 'records' => [$P[1]]])['code'] === 422, 'no such campaign: 404; none named: 422');
ok(post_as(26, '/bulk/campaign.php', ['kind' => 'contact', 'campaign' => $camp, 'records' => [$P[3]]])['code'] === 403, 'a Rep (no campaigns.manage): 403');
ok(post_role($mkt, '/bulk/campaign.php', ['kind' => 'contact', 'campaign' => $camp, 'records' => [$Dn]])['code'] === 200, 'Marketing (campaigns.manage) adds a contact it does not own — adding to a campaign changes nothing of the record');
$myList = (int) post_as(26, '/lists/save.php', ['name' => "Priya bulk $x", 'kind' => 'static', 'record_kind' => 'contact'])['json']['record_id'];
$r = post_as(26, '/bulk/list.php', ['kind' => 'contact', 'target_list' => $myList, 'records' => [$P[1], $P[2], $P[3]]]);
ok($r['code'] === 200 && ($r['json']['count'] ?? 0) === 3 && (int) one('SELECT member_count FROM lists WHERE id = :l', ['l' => $myList]) === 3, 'bulk_list_add by a Rep (lists.own) into her own list: three in, the count is the trigger\'s');
$r = post_as(26, '/bulk/list.php', ['kind' => 'contact', 'list' => $myList, 'records' => [$P[3], $P[2]]]);
ok($r['code'] === 200 && ($r['json']['count'] ?? 1) === 0 && ($r['json']['already'] ?? 0) === 2, '`list` is the destination for an agent: both already there');
$r = post_as(26, '/bulk/list.php', ['kind' => 'contact', 'target_list' => $myList, 'filters' => json_encode(['tag' => "mine-$x"])]);
ok($r['code'] === 200 && ($r['json']['already'] ?? 0) === 1, 'over filters');
$r = post_as(26, '/bulk/list.php', ['kind' => 'lead', 'target_list' => $myList, 'records' => [lead_make(26)]]);
ok($r['code'] === 422 && str_contains($msg($r), 'holds contacts'), 'a list of contacts will not take leads: ' . $msg($r));
ok(post_as(26, '/bulk/list.php', ['kind' => 'contact', 'target_list' => $lsm, 'records' => [$P[1]]])['code'] === 404 || post_as(26, '/bulk/list.php', ['kind' => 'contact', 'target_list' => $lsm, 'records' => [$P[1]]])['code'] === 422, 'a smart list (not hers, and with no members of its own): refused');
$sm = (int) post_as(26, '/lists/save.php', ['name' => "Priya smart $x", 'kind' => 'smart', 'record_kind' => 'contact', 'filter' => ['owner' => 'me']])['json']['record_id'];
$r = post_as(26, '/bulk/list.php', ['kind' => 'contact', 'target_list' => $sm, 'records' => [$P[1]]]);
ok($r['code'] === 422 && str_contains($msg($r), 'smart'), 'her own smart list: "a smart list has no members of its own"');
ok(post_as(30, '/bulk/list.php', ['kind' => 'contact', 'target_list' => $myList, 'records' => [$Dn]])['code'] === 404, 'Dana cannot see Priya\'s private list: 404');
$shared = (int) post_role($mkt, '/lists/save.php', ['name' => "Shared bulk $x", 'kind' => 'static', 'record_kind' => 'contact', 'is_shared' => 'yes'])['json']['record_id'];
ok(post_as(30, '/bulk/list.php', ['kind' => 'contact', 'target_list' => $shared, 'records' => [$Dn]])['code'] === 403, 'a shared list she sees but does not keep: 403');
$r = post_as(26, '/bulk/list.php', ['kind' => 'contact', 'target_list' => $l1, 'list' => $myList, 'scope' => 'all']);
ok($r['code'] === 404, 'the whole of one list into another that is not hers (Marco\'s): his list is a 404 to her');
$myList2 = (int) post_as(26, '/lists/save.php', ['name' => "Priya second $x", 'kind' => 'static', 'record_kind' => 'contact'])['json']['record_id'];
$r = post_as(26, '/bulk/list.php', ['kind' => 'contact', 'target_list' => $myList2, 'list' => $myList, 'scope' => 'all']);
ok($r['code'] === 200 && ($r['json']['count'] ?? 0) === 3 && ($r['json']['source'] ?? '') === 'list', 'the bar on a list\'s page copies the whole list into another');

echo "6. Delete\n";
$d = [contact_with("del1-$x@bulk-$x.example.invalid", 'Del', $acc, 26), contact_with("del2-$x@bulk-$x.example.invalid", 'Del2', $acc, 26)];
ok(post_as(26, '/bulk/delete.php', ['kind' => 'contact', 'records' => $d])['code'] === 403, 'a Rep has no records.bulk: bulk_delete 403');
$r = post_as(27, '/bulk/delete.php', ['kind' => 'contact', 'records' => $d]);
ok($r['code'] === 200 && ($r['json']['count'] ?? 0) === 2 && (int) one('SELECT count(*) FROM contacts WHERE id = ANY (CAST(:i AS bigint[])) AND deleted_at IS NOT NULL', ['i' => lit($d)]) === 2, 'over records[]: both go to the tombstone');
ok(!in_array($d[0], array_map(static fn ($c) => $c['contact_id'], get_as(27, '/contacts?q=Del')['json']['data']['contacts']), true), 'and are out of the list');
ok(post_as(27, '/records/restore.php', ['kind' => 'contact', 'record' => $d[0]])['code'] === 200 && ($r['json']['undo'] ?? '') === 'record_restore', 'restorable one by one (record_restore)');
post_as(27, '/tags/apply.php', ['kind' => 'contact', 'records' => [$d[0]], 'tag' => "doomed-$x"]);
$r = post_as(27, '/bulk/delete.php', ['kind' => 'contact', 'filters' => json_encode(['tag' => "doomed-$x"])]);
ok($r['code'] === 200 && ($r['json']['count'] ?? 0) === 1, 'over filters');
$la = $last('bulk.delete');
ok($la['kind'] === 'contact' && $la['count'] === 1 && $la['tombstone'] === true && $la['filters'] == ['tag' => "doomed-$x"], 'bulk.delete logs the kind, the count, the filters: ' . json_encode(array_diff_key($la, ['record_ids' => 1])));

echo "7. Over the limit\n";
pg_run("UPDATE crm_settings SET bulk_sync_rows = 10");
pg_run("INSERT INTO contacts (first_name, last_name, email, owner_member_id, created_by) SELECT 'Mass', 'Row ' || g || ' $x', 'mass' || g || '-$x@mass.example.invalid', 26, 26 FROM generate_series(1, 11) g");
pg_run("INSERT INTO tags (slug, label) VALUES ('mass-$x', 'mass-$x') ON CONFLICT DO NOTHING");
pg_run("INSERT INTO record_tags (record_type, record_id, tag_id) SELECT 'contact', c.id, (SELECT id FROM tags WHERE slug = 'mass-$x') FROM contacts c WHERE c.last_name LIKE 'Row % $x'");
$mass = array_map('intval', array_column(q("SELECT id FROM contacts WHERE last_name LIKE 'Row % $x' ORDER BY id"), 'id'));
$r = post_as(27, '/bulk/assign.php', ['kind' => 'contact', 'owner' => 30, 'filters' => json_encode(['tag' => "mass-$x"])]);
ok($r['code'] === 422 && str_contains($msg($r), 'Narrow the selection or use a list') && str_contains($msg($r), '11') && (int) one("SELECT count(*) FROM contacts WHERE id = ANY (CAST(:i AS bigint[])) AND owner_member_id = 30", ['i' => lit($mass)]) === 0, 'over filters: eleven against a limit of ten is refused with the spec\'s words, nothing changed: ' . $msg($r));
$r = post_as(27, '/bulk/tag.php', ['kind' => 'contact', 'tag' => "over-$x", 'records' => $mass]);
ok($r['code'] === 422 && str_contains($msg($r), 'Narrow the selection or use a list') && $tagged("over-$x") === [], 'over records[]: refused');
pg_run("INSERT INTO list_members (list_id, record_id) SELECT $l1, id FROM unnest(ARRAY[" . implode(',', $mass) . "]) id ON CONFLICT DO NOTHING");
$r = post_as(27, '/bulk/delete.php', ['kind' => 'contact', 'list' => $l1]);
ok($r['code'] === 422 && str_contains($msg($r), 'Narrow the selection or use a list'), 'over a list: refused — a bulk delete included');
$r = post_as(27, '/bulk/tag.php', ['kind' => 'contact', 'tag' => "ten-$x", 'records' => array_slice($mass, 0, 10)]);
ok($r['code'] === 200 && ($r['json']['count'] ?? 0) === 10, 'exactly the limit is allowed: ten');
pg_run("UPDATE crm_settings SET bulk_sync_rows = 1000");
$r = post_as(27, '/bulk/tag.php', ['kind' => 'contact', 'tag' => "all-$x", 'filters' => json_encode(['tag' => "mass-$x"])]);
ok($r['code'] === 200 && ($r['json']['count'] ?? 0) === 11 && count($tagged("all-$x")) === 11, 'with the setting back at 1,000 the eleven go in one transaction');

echo "8. The agent; the log\n";
$tok = run_token(40, 901);
$r = req('POST', '/bulk/delete.php', ['headers' => as_agent($tok), 'form' => ['kind' => 'contact', 'records' => [$P[1]]]]);
ok($r['code'] === 403, 'the Expert (a Rep) under a run token has no records.bulk: bulk_delete is 403');
$reg = json_decode((string) file_get_contents(dirname(__DIR__, 3) . '/mcp/action_registry.json'), true);
$os = json_decode((string) file_get_contents(dirname(__DIR__, 3) . '/maludb-os.json'), true);
$cats = [];
foreach ($os['approvals'] ?? [] as $a) { $cats[$a['action'] ?? $a['log_event'] ?? ''] = $a['category'] ?? null; }
$want = ['bulk_assign' => 'other', 'bulk_update' => 'other', 'bulk_delete' => 'deletion', 'list_delete' => 'deletion', 'campaign_email_send' => 'external_send'];
$okc = true;
foreach ($want as $act => $cat) { $okc = $okc && ($reg['actions'][$act]['approval'] ?? '') === $cat && ($reg['actions'][$act]['confirm'] ?? false) === true && ($cats[$act] ?? '') === $cat; }
ok($okc, 'an agent\'s bulk_assign, bulk_update, bulk_delete, list_delete and campaign_email_send PAUSE: the registry and maludb-os.json agree (other, other, deletion, deletion, external_send)');
ok(empty($reg['actions']['bulk_tag']['approval']) && empty($reg['actions']['bulk_campaign_add']['approval']) && empty($reg['actions']['bulk_list_add']['approval']) && !isset($cats['bulk_tag']) && !isset($cats['bulk_list_add']), 'bulk_tag, bulk_campaign_add and bulk_list_add are free inside the business');
$a = q("SELECT action, after::text AS after FROM activity_log WHERE id > :s AND action LIKE 'bulk.%'", ['s' => $since]);
$seen = array_unique(array_column($a, 'action'));
foreach (['bulk.assign', 'bulk.tag', 'bulk.update', 'bulk.campaign_add', 'bulk.list_add', 'bulk.delete'] as $evt) { ok(in_array($evt, $seen, true), "$evt written"); }
ok(!str_contains(json_encode($a), 'Dana-owned') && !str_contains(json_encode($a), "p1-$x@"), 'no name and no address in any bulk log row');
$rows = q("SELECT entity_type, entity_id FROM activity_log WHERE id > :s AND action LIKE 'bulk.%'", ['s' => $since]);
ok(array_unique(array_column($rows, 'entity_type')) === [null], 'one row per action, about no one record (the ids are in the payload)');

echo "9. The bar\n";
$h = html_as(27, '/contacts?tag=wave-' . $x)['body'];
ok(str_contains($h, 'id="bulk-bar"') && str_contains($h, 'id="bulk-count"') && str_contains($h, 'id="bulk-select-all"') && str_contains($h, 'id="bulk-tick-contact-' . $P[1] . '"') && str_contains($h, 'form="bulk-bar"'), 'the contacts screen has the bar and a tick on each card (form="bulk-bar")');
foreach (['assign', 'tag', 'update', 'campaign', 'list', 'delete', 'export'] as $b) { ok(str_contains($h, 'id="bulk-' . $b . '-btn"'), "the manager's bar has #bulk-$b-btn"); }
ok(preg_match('/id="bulk-scope-all-label">All 4 matching</', $h) === 1 && preg_match('/name="filters" value="[^"]*wave-' . $x . '/', $h) === 1, 'the whole result is offered with its count and the screen\'s filters');
$hr = html_as(26, '/contacts?tag=mine-' . $x)['body'];
ok(str_contains($hr, 'id="bulk-bar"') && str_contains($hr, 'id="bulk-tag-btn"') && str_contains($hr, 'id="bulk-list-btn"') && str_contains($hr, 'id="bulk-export-btn"') && !str_contains($hr, 'id="bulk-assign-btn"') && !str_contains($hr, 'id="bulk-delete-btn"') && !str_contains($hr, 'id="bulk-update-btn"') && !str_contains($hr, 'id="bulk-campaign-btn"'), 'a Rep\'s bar: tag, list, export — no assign, update, delete or campaign');
$hv = html_as(29, '/contacts')['body'];
ok(!str_contains($hv, 'id="bulk-bar"') && !str_contains($hv, 'bulk-tick'), 'a Viewer sees no bar and no ticks');
foreach (['/leads' => 'lead', '/accounts' => 'account'] as $u => $k) {
    $hh = html_as(27, $u)['body'];
    ok(str_contains($hh, 'id="bulk-bar"') && str_contains($hh, 'data-kind="' . $k . '"') && str_contains($hh, 'class="form-check-input bulk-tick'), "the $k list screen has the bar and the ticks");
}
$dh = html_as(27, '/pipeline/new_business/list?status=all')['body'];
ok(str_contains($dh, 'id="bulk-bar"') && str_contains($dh, 'data-kind="deal"') && preg_match('/id="bulk-tick-deal-\d+"/', $dh) === 1, 'the deal list has the bar and a tick in each row');
$hx = html_as(27, '/contacts?tag=wave-' . $x, ['HX-Request: true'])['body'];
ok(str_contains($hx, 'id="bulk-bar"') && !str_contains($hx, '<html'), 'and over HTMX the partial carries it');
ok(!str_contains(html_as(27, '/contacts?tag=nonexistent-' . $x)['body'], 'id="bulk-bar"'), 'no bar on an empty result');
$n = html_as(27, '/contacts?notice=bulk_tag&n=3&k=contact')['body'];
ok(str_contains($n, 'Changed the tags of 3 contacts.'), 'the notice a finished action lands with, built from whitelisted parts: "Changed the tags of 3 contacts."');
$n = html_as(27, '/contacts?notice=bulk_tag&n=1&k=%3Cb%3E')['body'];
ok(str_contains($n, 'Changed the tags of 1 record.') && !str_contains($n, '<b>'), 'a kind that is not one falls back to "record" and nothing is echoed');
ok(!str_contains(html_as(27, '/contacts?notice=bulk_nonsense&n=3')['body'], 'id="notice-banner"'), 'an unknown action shows no banner');
$lp = html_as(27, "/lists/$l1")['body'];
ok(str_contains($lp, 'id="bulk-bar"') && str_contains($lp, 'name="list" value="' . $l1 . '"') && str_contains($lp, 'The whole list'), 'a list\'s page: the bar acts on the list (name="list"), "The whole list"');
$post = req('POST', '/bulk/tag.php', ['jar' => actor(27)[0], 'headers' => ['X-CSRF-Token: ' . actor(27)[1]], 'form' => ['kind' => 'contact', 'tag' => "viaform-$x", 'records' => [$P[1]], 'return_to' => '/contacts?tag=wave-' . $x . '&notice=old&n=9&k=lead']]);
ok($post['code'] === 302 && str_contains($post['location'], '/contacts?tag=wave-' . $x) && str_contains($post['location'], 'notice=bulk_tag') && str_contains($post['location'], 'n=1') && !str_contains($post['location'], 'notice=old'), 'a browser post lands back where the bar was, with the new notice in place of the old: ' . $post['location']);
$bad = req('POST', '/bulk/tag.php', ['jar' => actor(27)[0], 'headers' => ['X-CSRF-Token: ' . actor(27)[1]], 'form' => ['kind' => 'contact', 'tag' => 'x', 'records' => [$P[1]], 'return_to' => 'https://evil.example/']]);
ok($bad['code'] === 302 && !str_contains($bad['location'], 'evil.example'), 'a return_to that leaves the application is not followed');
finish();
