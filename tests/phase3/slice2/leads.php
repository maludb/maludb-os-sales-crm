<?php
/**
 * Proof — leads (leads-and-conversion.md §1, §2, §8): a lead from the website form (under a person's action token, as the capture route will) and from a
 * seller; the assignment rules route three leads three ways (a member, a department's manager, round-robin in turn); none matching leaves it unowned; a
 * note moves new → working; the inbox lists a proposed and an unowned lead, accept and assign empty it, dismiss tombstones; qualify sets the score; unqualify
 * needs a reason, requalify restores; the edit rule; a converted lead is read-only.
 */
require __DIR__ . '/lib.php';
$x = run_tag();
as_member(26);                                                             // the views answer an admitted member only
pdo()->exec('DELETE FROM assignment_rules');
pg_run("UPDATE departments SET manager_member_id = 27 WHERE id = 7");
$event = (int) one("SELECT source_id FROM mcp_lead_sources WHERE name = 'Event'");
$website = (int) one("SELECT source_id FROM mcp_lead_sources WHERE name = 'Website'");
pdo()->exec("INSERT INTO assignment_rules (name, position, match, assign_kind, member_id) VALUES ('Germany to Dana', 10, '{\"country\": [\"DE\"]}', 'member', 30)");
pdo()->exec("INSERT INTO assignment_rules (name, position, match, assign_kind, department_id) VALUES ('Events to Sales', 20, '{\"source_id\": [$event]}', 'department', 7)");
pdo()->exec("INSERT INTO assignment_rules (name, position, match, assign_kind, members) VALUES ('Schools in turn', 30, '{\"domain_suffix\": [\".edu\"]}', 'round_robin', '{26,30}')");

echo "1. Making leads; the rules\n";
$r = req('POST', '/leads/save.php', ['headers' => ['Accept: application/json', 'X-Action-Token: ' . person_token(26)],
    'form' => ['first_name' => 'Web', 'last_name' => "Visitor $x", 'email' => "web.$x@prospect.example.com", 'company' => "Prospect $x", 'source' => $website, 'message' => 'Please call me about pricing']]);
$web = (int) (json_decode($r['body'], true)['record_id'] ?? 0);
ok($r['code'] === 200 && $web > 0 && one('SELECT source_id FROM leads WHERE id = :i', ['i' => $web]) === $website && one('SELECT status FROM leads WHERE id = :i', ['i' => $web]) === 'new', 'a lead from the website form (lead_create, source Website) is new');
$a = activity('lead.create');
ok(!str_contains((string) end($a)['after'], 'pricing'), 'its message is never in the log');
$de = (int) post_as(26, '/leads/save.php', ['first_name' => 'Klaus', 'last_name' => "Berlin $x", 'country' => 'de'])['json']['record_id'];
$ev = (int) post_as(26, '/leads/save.php', ['company' => "Expo Co $x", 'source' => $event])['json']['record_id'];
$e1 = (int) post_as(26, '/leads/save.php', ['first_name' => 'Ada', 'email' => "ada.$x@uni.edu"])['json']['record_id'];
$e2 = (int) post_as(26, '/leads/save.php', ['first_name' => 'Bo', 'email' => "bo.$x@college.edu"])['json']['record_id'];
$e3 = (int) post_as(26, '/leads/save.php', ['first_name' => 'Cy', 'email' => "cy.$x@school.edu"])['json']['record_id'];
$own = static fn (int $id) => one('SELECT owner_member_id FROM leads WHERE id = :i', ['i' => $id]);
ok($own($de) === 30, 'a member rule: the German lead goes to Dana');
ok($own($ev) === 27, 'a department rule: the event lead goes to the Sales department\'s manager (Marco)');
ok([$own($e1), $own($e2), $own($e3)] === [26, 30, 26], 'round-robin in turn: Priya, Dana, Priya');
ok($own($web) === null, 'no rule matches the website lead: unowned (the inbox\'s)');
$mine = (int) post_as(26, '/leads/save.php', ['first_name' => 'Mine', 'last_name' => $x, 'owner' => 26, 'country' => 'DE'])['json']['record_id'];
ok($own($mine) === 26, 'an owner given wins over the rules');
$r = post_as(26, '/leads/save.php', ['title' => 'nobody']);
ok($r['code'] === 422 && field_of($r) === 'company', 'a company, a name or an email is required');
ok(one("SELECT s.name FROM leads l JOIN lead_sources s ON s.id = l.source_id WHERE l.id = :i", ['i' => $de]) === 'Outbound', 'a seller\'s lead with no source is Outbound');
ok(post_as(29, '/leads/save.php', ['company' => "Viewer $x"])['code'] === 403, 'a Viewer may not add a lead');

echo "2. The lead's life\n";
post_as(26, '/notes/add.php', ['kind' => 'lead', 'record' => $e1, 'body' => 'Left a voicemail']);
ok(one('SELECT status FROM leads WHERE id = :i', ['i' => $e1]) === 'working' && one('SELECT last_touched_at IS NOT NULL FROM leads WHERE id = :i', ['i' => $e1]), 'a note moves new → working and touches it');
$r = post_as(26, '/leads/qualify.php', ['lead' => $e1, 'score' => 80]);
ok($r['code'] === 200 && (int) one('SELECT score FROM leads WHERE id = :i', ['i' => $e1]) === 80, 'lead_qualify sets the score');
ok(post_as(26, '/leads/qualify.php', ['lead' => $e1, 'score' => 101])['code'] === 422 && post_as(26, '/leads/qualify.php', ['lead' => $e1, 'status' => 'qualified'])['code'] === 422, 'a score over 100 or a status other than new or working is refused');
$r = post_as(26, '/leads/unqualify.php', ['lead' => $e1]);
ok($r['code'] === 422 && field_of($r) === 'reason', 'unqualify needs a reason');
$reason = (int) one("SELECT lost_reason_id FROM mcp_lost_reasons WHERE kind = 'lead' AND label = 'No budget'");
$dealReason = (int) one("SELECT lost_reason_id FROM mcp_lost_reasons WHERE kind = 'deal' LIMIT 1");
ok(post_as(26, '/leads/unqualify.php', ['lead' => $e1, 'reason' => $dealReason])['code'] === 422, 'a deal\'s lost reason is not a lead\'s');
$r = post_as(26, '/leads/unqualify.php', ['lead' => $e1, 'reason' => $reason, 'note' => 'Next year maybe']);
ok($r['code'] === 200 && one('SELECT status FROM leads WHERE id = :i', ['i' => $e1]) === 'unqualified', 'unqualify with a reason');
ok(!in_array($e1, array_column(get_as(26, '/leads?owner=26')['json']['data']['leads'] ?? [], 'lead_id'), true), 'the list of leads in work no longer shows it');
ok(in_array($e1, array_column(get_as(26, '/leads?status=unqualified')['json']['data']['leads'], 'lead_id'), true), '…and the Unqualified chip finds it');
ok(post_as(26, "/leads/$e1/convert", ['lead' => $e1])['code'] === 422, 'an unqualified lead is not converted');
$r = post_as(26, '/leads/requalify.php', ['lead' => $e1]);
ok($r['code'] === 200 && one('SELECT status || coalesce(unqualified_reason_id::text, \'-\') FROM leads WHERE id = :i', ['i' => $e1]) === 'working-', 'requalify restores it to working, the reason cleared');
ok(post_as(26, '/leads/requalify.php', ['lead' => $e1])['code'] === 422, 'requalifying a lead in work is refused');
ok(post_as(26, '/leads/save.php', ['lead' => $de, 'title' => 'x'])['code'] === 403, 'the edit rule: Dana\'s lead is not Priya\'s to change');
ok(post_as(27, '/leads/save.php', ['lead' => $de, 'title' => 'CTO'])['code'] === 200, '…the manager may');

echo "3. The inbox\n";
as_member(26);
$prop = (int) one("INSERT INTO leads (first_name, last_name, email, message, is_proposed, source_id, created_by) VALUES ('Stranger', '$x', 'stranger.$x@new.example.com', 'Saw your talk — can we chat?', true, (SELECT id FROM lead_sources WHERE name = 'Mailbox'), NULL) RETURNING id");
$prop2 = (int) one("INSERT INTO leads (company, is_proposed, created_by) VALUES ('Spam Co $x', true, NULL) RETURNING id");
ok($own($prop) === null, 'a proposed lead is not routed by the rules on insert');
$box = get_as(26, '/leads/inbox')['json']['data'];
ok(in_array($prop, array_column($box['proposed'], 'lead_id'), true) && in_array($web, array_column($box['unowned'], 'lead_id'), true), 'the inbox lists the proposed lead and the unowned one');
ok(!in_array($prop, array_column(get_as(26, '/leads?q=' . rawurlencode("Stranger"))['json']['data']['leads'], 'lead_id'), true), 'a proposed lead is not in the list in work');
ok((int) get_as(26, '/leads')['json']['data']['inbox'] >= 2 && str_contains(html_as(26, '/')['body'], 'id="nav-leads-inbox"'), 'the sidebar\'s Leads badge counts the inbox');
$r = post_as(26, '/leads/accept.php', ['lead' => $prop]);
ok($r['code'] === 200 && one('SELECT is_proposed FROM leads WHERE id = :i', ['i' => $prop]) === false && $own($prop) === null, 'accept: into the book; no rule matches, so it is unowned');
ok(post_as(26, '/leads/accept.php', ['lead' => $prop])['code'] === 422, 'accepting it again is refused');
$r = post_as(30, '/leads/assign.php', ['lead' => $web, 'owner' => 26]);
ok($r['code'] === 403, 'a Rep may not give a lead to someone else');
$r = post_as(30, '/leads/assign.php', ['lead' => $web, 'owner' => 30]);
ok($r['code'] === 200 && $own($web) === 30, 'a Rep takes an unowned lead for themselves');
$r = post_as(27, '/leads/assign.php', ['leads' => [$prop, $web], 'owner' => 26]);
ok($r['code'] === 200 && $own($prop) === 26 && $own($web) === 26 && count($r['json']['changed']) === 2, 'the manager assigns several (leads[]) at once');
ok(count(array_filter(activity('lead.assign'), static fn ($a) => in_array((int) $a['entity_id'], [$prop, $web], true))) >= 3, 'one lead.assign row per lead');
$r = post_as(26, '/leads/dismiss.php', ['lead' => $prop2]);
ok($r['code'] === 200 && one('SELECT deleted_at IS NOT NULL FROM leads WHERE id = :i', ['i' => $prop2]), 'dismiss sends the proposed lead to the tombstone');
ok(post_as(26, '/leads/dismiss.php', ['lead' => $e2])['code'] === 422, 'only a proposed lead is dismissed');
$box = get_as(26, '/leads/inbox')['json']['data'];
ok(!in_array($prop, array_column($box['proposed'], 'lead_id'), true) && !in_array($web, array_column($box['unowned'], 'lead_id'), true), 'accept and assign empty the inbox of them');

echo "4. A converted lead is read-only\n";
$c = (int) post_as(26, '/leads/save.php', ['first_name' => 'Conv', 'last_name' => $x, 'company' => "Conv Co $x", 'owner' => 26])['json']['record_id'];
post_as(26, "/leads/$c/convert", ['lead' => $c, 'make_deal' => 'no']);
ok(one('SELECT status FROM leads WHERE id = :i', ['i' => $c]) === 'qualified', 'converted (status qualified)');
$r = post_as(26, '/leads/save.php', ['lead' => $c, 'title' => 'x']);
ok($r['code'] === 422 && str_contains(message_of($r), 'A converted lead is read-only'), 'lead_update on it: 422 "A converted lead is read-only"');
ok(post_as(26, '/leads/qualify.php', ['lead' => $c, 'score' => 5])['code'] === 422 && post_as(26, '/leads/unqualify.php', ['lead' => $c, 'reason' => $reason])['code'] === 422 && post_as(27, '/leads/assign.php', ['lead' => $c, 'owner' => 30])['code'] === 422, 'nor qualified, unqualified or assigned');
ok(html_as(26, "/leads/$c/edit")['code'] === 422 && !str_contains(html_as(26, "/leads/$c")['body'], 'id="lead-edit-btn"'), 'it has no edit form and no Edit button');
ok(str_contains(html_as(26, "/leads/$c")['body'], 'id="lead-converted-account"') && str_contains(html_as(26, "/leads/$c")['body'], 'id="lead-converted-contact"'), 'its page shows the records it became as cards');
finish();
