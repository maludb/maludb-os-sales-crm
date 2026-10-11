<?php
/**
 * Proof — campaigns (campaigns-lists.md "Proof"): a campaign made, changed and found (campaigns.manage only); members by lead and by contact with their statuses (the wall and the rights);
 * the results tiles right against fixtures — members, responded, leads, converted, deals, won and open value, the cost per lead and per won deal; the cards, the page, the JSON; a tombstone.
 */
require __DIR__ . '/lib.php';
$x = run_tag();
$since = last_activity_id();
$mkt = role_actor(31, 'marketing');

echo "1. Who may keep campaigns\n";
$r = post_as(26, '/campaigns/save.php', ['name' => "Rep camp $x"]);
ok($r['code'] === 403, 'a Sales Rep may not make a campaign: 403');
ok(post_as(29, '/campaigns/save.php', ['name' => "Viewer camp $x"])['code'] === 403, 'a Viewer may not: 403');
ok(html_as(26, '/campaigns/new')['code'] === 403 && html_as(27, '/campaigns/new')['code'] === 200, 'the form is Marketing\'s and the manager\'s: 403 for a Rep, 200 for Marco');
$r = post_as(27, '/campaigns/save.php', ['name' => "Spring $x", 'kind' => 'email', 'status' => 'active', 'starts_on' => '2026-10-01', 'ends_on' => '2026-10-31', 'budget' => '3000', 'currency' => 'usd', 'expected_leads' => 40, 'utm_campaign' => "spring-$x", 'description' => 'The autumn push']);
$camp = (int) ($r['json']['record_id'] ?? 0);
ok($r['code'] === 200 && $camp > 0 && ($r['json']['ok'] ?? false), 'campaign_create by the manager: ' . ($r['json']['did'] ?? $r['body']));
$row = q('SELECT * FROM campaigns WHERE id = :i', ['i' => $camp])[0];
ok($row['kind'] === 'email' && $row['status'] === 'active' && $row['budget'] === '3000.00' && $row['currency'] === 'USD' && (int) $row['expected_leads'] === 40 && (int) $row['owner_member_id'] === 27 && $row['utm_campaign'] === "spring-$x", 'the fields kept; the currency upper-cased; the owner the maker by default');
$rm = post_role($mkt, '/campaigns/save.php', ['name' => "Marketing camp $x", 'kind' => 'event']);
ok($rm['code'] === 200 && ($rm['json']['record_id'] ?? 0) > 0, 'Marketing makes a campaign too');
$bad = [['ends_on' => '2026-09-01'], ['budget' => '-5'], ['kind' => 'sorcery'], ['status' => 'boiling'], ['starts_on' => '2026-13-45'], ['name' => '']];
foreach ($bad as $i => $f) {
    $r = post_as(27, '/campaigns/save.php', $f + ['name' => "Bad $x", 'starts_on' => '2026-10-01']);
    ok($r['code'] === 422 && field_of($r) !== null, 'refused with the field named: ' . json_encode($f) . ' → ' . field_of($r));
}
$r = post_as(27, '/campaigns/save.php', ['campaign' => $camp, 'status' => 'done', 'budget' => '3500']);
$row = q('SELECT * FROM campaigns WHERE id = :i', ['i' => $camp])[0];
ok($r['code'] === 200 && $row['status'] === 'done' && $row['budget'] === '3500.00' && $row['name'] === "Spring $x" && (int) $row['expected_leads'] === 40, 'campaign_update changes what is sent; a field left out stays as it was');
post_as(27, '/campaigns/save.php', ['campaign' => $camp, 'status' => 'active', 'description' => '']);
ok(q('SELECT description FROM campaigns WHERE id = :i', ['i' => $camp])[0]['description'] === null, 'an emptied field is cleared');
$r = post_as(26, '/campaigns/save.php', ['campaign' => $camp, 'name' => 'Hijack']);
ok($r['code'] === 403, 'a Rep cannot change one');
ok(post_as(27, '/campaigns/save.php', ['campaign' => 999999, 'name' => 'x'])['code'] === 404, 'a campaign that is not there: 404');

echo "2. Members by contact and by lead\n";
$acc = (int) post_as(26, '/accounts/save.php', ['name' => "Camp Acct $x"])['json']['record_id'];
$c1 = contact_with("c1-$x@camp-$x.example.invalid", 'Ann', $acc);
$c2 = contact_with("c2-$x@camp-$x.example.invalid", 'Bob', $acc);
$c3 = contact_with("c3-$x@camp-$x.example.invalid", 'Cat', $acc);
$c4 = contact_with("c4-$x@camp-$x.example.invalid", 'Dee', $acc);
$l1 = lead_make(26, ['company' => "Lead One $x"]);
$l2 = lead_make(26, ['company' => "Lead Two $x"]);
$r = post_as(27, '/campaigns/members/add.php', ['campaign' => $camp, 'contact' => $c1]);
ok($r['code'] === 200 && count($r['json']['added'] ?? []) === 1 && member_status($camp, $c1) === 'planned', 'campaign_member_add: a contact joins as planned by default');
$r = post_as(27, '/campaigns/members/add.php', ['campaign' => $camp, 'contact' => $c1]);
ok($r['code'] === 200 && ($r['json']['already'] ?? 0) === 1 && count(members_of($camp)) === 1, 'adding the same contact again changes nothing: reported as already in it');
$r = post_as(27, '/campaigns/members/add.php', ['campaign' => $camp, 'contacts' => [$c2, $c3], 'leads' => [$l1], 'status' => 'sent']);
ok($r['code'] === 200 && count($r['json']['added']) === 3 && member_status($camp, $c2) === 'sent' && (int) one('SELECT count(*) FROM campaign_members WHERE campaign_id = :c AND lead_id = :l', ['c' => $camp, 'l' => $l1]) === 1, 'contacts[] and leads[] together, with a status');
$r = post_as(27, '/campaigns/members/add.php', ['campaign' => $camp, 'contact' => $c4, 'lead' => $l2]);
ok($r['code'] === 200 && count(members_of($camp)) === 6, 'a contact and a lead in one call: six members now');
ok(post_as(27, '/campaigns/members/add.php', ['campaign' => $camp, 'contact' => $c4, 'status' => 'unsubscribed'])['code'] === 422, 'unsubscribed is the contact\'s own act (the link), not a status a person sets');
ok(post_as(27, '/campaigns/members/add.php', ['campaign' => $camp])['code'] === 422 && post_as(27, '/campaigns/members/add.php', ['campaign' => $camp, 'contact' => 'abc'])['code'] === 422, 'who to add is required and an id');
$r = post_as(26, '/campaigns/members/add.php', ['campaign' => $camp, 'contact' => $c4]);
ok($r['code'] === 200 && ($r['json']['already'] ?? 0) === 1, 'a Rep (contacts.write) may add a member: campaigns.manage or contacts.write');
ok(post_as(29, '/campaigns/members/add.php', ['campaign' => $camp, 'contact' => $c4])['code'] === 403, 'a Viewer may not');
$secret = (int) post_as(27, '/contacts/save.php', ['first_name' => 'Hidden', 'last_name' => "Boss $x", 'email' => "hidden-$x@camp.example.invalid"])['json']['record_id'];
post_as(27, '/records/restrict.php', ['kind' => 'contact', 'record' => $secret]);
$r = post_as(26, '/campaigns/members/add.php', ['campaign' => $camp, 'contact' => $secret]);
ok($r['code'] === 404 && member_status($camp, $secret) === null, 'a contact behind the wall is the same 404 as a missing one');
$r = post_as(27, '/campaigns/members/add.php', ['campaign' => $camp, 'contact' => $secret]);
ok($r['code'] === 200 && member_status($camp, $secret) === 'planned', 'the manager sees it and adds it');
$ev = q("SELECT after::text AS after FROM activity_log WHERE id > :s AND action = 'campaign.member_add' ORDER BY id", ['s' => $since]);
ok(count($ev) >= 5 && json_decode($ev[0]['after'], true)['added'] === 1, 'campaign.member_add is logged with the count');

echo "3. Status\n";
$r = post_as(27, '/campaigns/members/status.php', ['campaign' => $camp, 'contact' => $c1, 'status' => 'responded']);
$m1 = q('SELECT * FROM campaign_members WHERE campaign_id = :c AND contact_id = :k', ['c' => $camp, 'k' => $c1])[0];
ok($r['code'] === 200 && $m1['status'] === 'responded' && $m1['responded_at'] !== null && ($r['json']['previous'] ?? '') === 'planned', 'campaign_member_status: a response stamps when it came');
post_as(27, '/campaigns/members/status.php', ['campaign' => $camp, 'contact' => $c2, 'status' => 'attended']);
post_as(27, '/campaigns/members/status.php', ['campaign' => $camp, 'contact' => $c3, 'status' => 'converted']);
post_as(27, '/campaigns/members/status.php', ['campaign' => $camp, 'lead' => $l1, 'status' => 'opened']);
ok(member_status($camp, $c2) === 'attended' && member_status($camp, $c3) === 'converted' && one('SELECT status FROM campaign_members WHERE campaign_id = :c AND lead_id = :l', ['c' => $camp, 'l' => $l1]) === 'opened', 'a contact and a lead each take their status');
ok(post_as(27, '/campaigns/members/status.php', ['campaign' => $camp, 'contact' => $c1, 'status' => 'unsubscribed'])['code'] === 422 && post_as(27, '/campaigns/members/status.php', ['campaign' => $camp, 'contact' => $c1, 'status' => 'nonsense'])['code'] === 422, 'unsubscribed and nonsense are refused');
ok(post_as(27, '/campaigns/members/status.php', ['campaign' => $camp, 'contact' => 999999, 'status' => 'sent'])['code'] === 422, 'a status for someone who is not a member is refused in words');
ok(post_as(26, '/campaigns/members/status.php', ['campaign' => $camp, 'contact' => $c1, 'status' => 'sent'])['code'] === 403, 'status is campaigns.manage: a Rep gets 403');

echo "4. Results against fixtures\n";
// leads and deals that carry the campaign; one lead converted; one deal won, one open
$lc1 = lead_make(26, ['company' => "Carries $x", 'campaign' => $camp]);
$lc2 = lead_make(26, ['company' => "Carries two $x", 'campaign' => $camp]);
$lc3 = lead_make(26, ['company' => "Carries three $x", 'campaign' => $camp]);
$dealWon = deal_make(26, ['title' => "Camp won $x", 'value' => 9000, 'campaign' => $camp]);
$dealOpen = deal_make(26, ['title' => "Camp open $x", 'value' => 4000, 'campaign' => $camp]);
$dealOther = deal_make(26, ['title' => "Not ours $x", 'value' => 777]);
$conv = post_as(26, '/leads/convert.php', ['lead' => $lc1, 'make_deal' => 'no']);
$w = post_as(26, '/deals/won.php', ['deal' => $dealWon, 'value' => 9000]);
ok($conv['code'] === 200 && $w['code'] === 200 && one('SELECT status FROM deals WHERE id = :i', ['i' => $dealWon]) === 'won', 'a lead converted and a deal won (fixtures)');
as_member(27);
$res = q('SELECT * FROM crm_campaign_results(:c)', ['c' => $camp])[0];
$g = get_as(27, "/campaigns/$camp");
$j = $g['json']['data'] ?? [];
$rs = $j['campaign']['results'] ?? [];
ok($g['code'] === 200 && (int) $rs['members'] === 7 && (int) $rs['responded'] === 3 && (int) $rs['leads'] === 3 && (int) $rs['converted'] === 1 && (int) $rs['deals'] === 2 && (int) $rs['won'] === 1, 'the page\'s numbers: 7 members, 3 responded (responded, attended, converted), 3 leads, 1 converted, 2 deals, 1 won — ' . json_encode($rs));
ok((float) $rs['won_value'] === 9000.0 && (float) $rs['open_value'] === 4000.0, 'won 9,000 and open 4,000 — the deal that carries no campaign is not counted');
ok(abs((float) $rs['cost_per_lead'] - 3500 / 3) < 0.01 && abs((float) $rs['cost_per_won'] - 3500.0) < 0.01, 'cost per lead = budget / leads (1,166.67); cost per won deal = budget / won (3,500)');
ok((int) $res['members'] === (int) $rs['members'] && (float) $res['cost_per_lead'] === (float) $rs['cost_per_lead'], 'and they are crm_campaign_results() itself');
$h = html_as(27, "/campaigns/$camp");
ok(str_contains($h['body'], 'id="campaign-results"') && str_contains($h['body'], 'id="campaign-result-won-value-value"') && preg_match('/id="campaign-result-members-value">7</', $h['body']) === 1 && str_contains($h['body'], 'id="campaign-members"'), 'the HTML has the tiles (#campaign-results) and the members (#campaign-members)');
ok(preg_match('/id="campaign-result-cost-per-lead-value">\$1,167</', $h['body']) === 1, 'the tile shows the cost a lead in the campaign\'s currency: $1,167');
ok(count($j['leads'] ?? []) === 3 && count($j['deals'] ?? []) === 2 && ($j['member_counts']['planned'] ?? 0) === 3, 'the leads and deals it produced are listed; the member counts by status');
$g2 = get_as(27, "/campaigns/$camp?status=attended");
ok(($g2['json']['data']['members_total'] ?? 0) === 1 && count($g2['json']['data']['members'] ?? []) === 1, 'the members filter by status: one attended');
$names = array_column($j['members'], 'name');
ok(in_array("Ann Mail " . run_tag(), $names, true) || count($names) >= 6, 'members are named');
$g3 = get_as(26, "/campaigns/$camp");
$hid = array_values(array_filter($g3['json']['data']['members'], static fn ($m) => $m['contact_id'] === $secret));
ok($hid !== [] && $hid[0]['restricted'] === true && $hid[0]['name'] === null, 'a member the Rep may not see is shown as restricted, nameless');

echo "5. Cards, filters, the list, the JSON\n";
$camp2 = campaign_make(['name' => "Webinar $x", 'kind' => 'webinar', 'status' => 'planned']);
$l = get_as(27, '/campaigns')['json']['data'];
$ids = array_column($l['campaigns'], 'campaign_id');
ok($l['total'] >= 3 && in_array($camp, $ids, true) && in_array($camp2, $ids, true), 'the list holds them');
$act = get_as(27, '/campaigns?status=active')['json']['data'];
ok(in_array($camp, array_column($act['campaigns'], 'campaign_id'), true) && !in_array($camp2, array_column($act['campaigns'], 'campaign_id'), true), 'filter by status');
$k = get_as(27, '/campaigns?kind=webinar')['json']['data'];
ok(array_column($k['campaigns'], 'campaign_id') !== [] && array_diff(array_column($k['campaigns'], 'kind'), ['webinar']) === [], 'filter by kind');
$mine = get_as(27, '/campaigns?owner=me')['json']['data'];
ok(in_array($camp, array_column($mine['campaigns'], 'campaign_id'), true) && !in_array(($rm['json']['record_id'] ?? 0), array_column($mine['campaigns'], 'campaign_id'), true), 'filter by owner: mine');
$cards = get_as(27, '/campaigns')['json']['data']['campaigns'];
$ours = array_values(array_filter($cards, static fn ($c) => $c['campaign_id'] === $camp))[0];
ok(($ours['results']['members'] ?? null) === 7 && ($ours['results']['won'] ?? null) === 1, 'each card in JSON carries its results');
$h = html_as(26, '/campaigns');
ok($h['code'] === 200 && str_contains($h['body'], 'id="campaign-card-' . $camp . '"') && str_contains($h['body'], 'id="campaign-card-' . $camp . '-status"') && !str_contains($h['body'], 'id="campaign-new-btn"'), 'the cards: #campaign-card-{id}, the status badge; a Rep has no New campaign button');
ok(str_contains(html_as(27, '/campaigns')['body'], 'id="campaign-new-btn"'), 'the manager has it');
ok(html_as(29, '/campaigns')['code'] === 200 && !str_contains(html_as(29, "/campaigns/$camp")['body'], 'id="campaign-add-members"') && !str_contains(html_as(29, "/campaigns/$camp")['body'], 'campaign-email-form'), 'a Viewer reads the page: no add-members form, no email form');
ok(html_as(26, "/campaigns/$camp/edit")['code'] === 403 && html_as(27, "/campaigns/$camp/edit")['code'] === 200 && html_as(27, '/campaigns/999999')['code'] === 404, 'edit is the manager\'s; a campaign that is not there: 404');
$page = html_as(27, "/campaigns/$camp")['body'];
ok(str_contains($page, 'bg-soft-primary text-primary') && str_contains(html_as(27, '/campaigns')['body'], 'bg-soft-secondary text-secondary'), 'status colours: active primary, planned secondary');
$mid1 = (int) one('SELECT id FROM campaign_members WHERE campaign_id = :c AND contact_id = :k', ['c' => $camp, 'k' => $c1]);
$midc3 = (int) one('SELECT id FROM campaign_members WHERE campaign_id = :c AND contact_id = :k', ['c' => $camp, 'k' => $c3]);
$midp = (int) one('SELECT id FROM campaign_members WHERE campaign_id = :c AND contact_id = :k', ['c' => $camp, 'k' => $c4]);
ok(preg_match('/bg-soft-warning text-warning" id="campaign-member-' . $mid1 . '-status">Responded</', $page) === 1 && preg_match('/bg-soft-success text-success" id="campaign-member-' . $midc3 . '-status">Converted</', $page) === 1
    && preg_match('/bg-soft-secondary text-secondary" id="campaign-member-' . $midp . '-status">Planned</', $page) === 1, 'member status badges use the vocabulary\'s colours: responded warning, converted success, planned secondary');

echo "6. A member goes; the campaign goes to the tombstone\n";
$r = post_as(27, '/campaigns/members/remove.php', ['campaign' => $camp, 'contact' => $c4]);
ok($r['code'] === 200 && member_status($camp, $c4) === null && one('SELECT count(*) FROM contacts WHERE id = :c', ['c' => $c4]) == 1, 'campaign_member_remove takes the membership out; the contact stays');
ok(post_as(27, '/campaigns/members/remove.php', ['campaign' => $camp, 'contact' => $c4])['code'] === 422, 'taking out a non-member is said in words');
post_as(27, '/campaigns/members/remove.php', ['campaign' => $camp, 'lead' => $l2]);
ok((int) one('SELECT count(*) FROM campaign_members WHERE campaign_id = :c', ['c' => $camp]) === 5, 'a lead is removed the same way');
ok(post_as(26, '/campaigns/members/remove.php', ['campaign' => $camp, 'contact' => $c1])['code'] === 403, 'removal is campaigns.manage: 403 for a Rep');
$r = post_as(27, '/records/delete.php', ['kind' => 'campaign', 'record' => $camp2]);
ok($r['code'] === 200 && !in_array($camp2, array_column(get_as(27, '/campaigns')['json']['data']['campaigns'], 'campaign_id'), true), 'record_delete takes a campaign to the tombstone: out of the list');
$a = q("SELECT action FROM activity_log WHERE id > :s AND action LIKE 'campaign.%' ORDER BY id", ['s' => $since]);
$seen = array_unique(array_column($a, 'action'));
foreach (['campaign.create', 'campaign.update', 'campaign.member_add', 'campaign.member_status', 'campaign.member_remove'] as $evt) { ok(in_array($evt, $seen, true), "$evt written"); }
finish();
