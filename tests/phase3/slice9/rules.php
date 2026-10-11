<?php
/**
 * Proof — the assignment rules (admin-settings.md "Proof"): the whole ordered list saved in one transaction (a form's shape and an agent's), the first active rule deciding, a round-robin rule keeping its turn across a save, bad rows refused with their number,
 * and the TEST BOX naming the matching rule WITHOUT assigning anything — the dry function agrees with crm_assign_lead() on every kind, a round-robin turn does not move, no lead is made.
 */
require __DIR__ . '/lib.php';
$x = run_tag();
$since = last_activity_id();
$web = source_id('Website'); $ref = source_id('Referral'); $evt = source_id('Event');
$camp = campaign_make(["name" => "R9 camp $x"]);
$dept = (int) one("SELECT id FROM departments WHERE name = 'Sales'");
$mgr = (int) one('SELECT manager_member_id FROM departments WHERE id = :d', ['d' => $dept]);
adm('/admin/rules/save.php', ['rules' => '[]']);

echo "1. Saving the whole list\n";
$form = [
    '1' => ['name' => "EU web $x", 'source' => [$web], 'country' => 'de, fr', 'assign_kind' => 'member', 'member' => 27, 'active' => 'yes'],
    '2' => ['name' => "Edu $x", 'domain_suffix' => '.edu', 'assign_kind' => 'department', 'department' => $dept, 'active' => 'yes'],
    '3' => ['name' => "Turns $x", 'source' => [$ref], 'campaign' => ['', $camp], 'utm_source' => 'newsletter', 'assign_kind' => 'round_robin', 'members' => ['26', '', '30'], 'active' => 'yes'],
    '4' => ['name' => "Idle $x", 'source' => [$web], 'assign_kind' => 'member', 'member' => 26, 'active' => 'no'],
    'new' => ['name' => '', 'assign_kind' => 'member'],
];
$r = adm('/admin/rules/save.php', ['rules' => $form]);
$rows = q('SELECT * FROM assignment_rules ORDER BY position, id');
ok($r['code'] === 200 && count($rows) === 4 && array_column($rows, 'position') === [10, 20, 30, 40] && array_column($rows, 'name') === ["EU web $x", "Edu $x", "Turns $x", "Idle $x"], 'assignment_rules_save stores the four rules in order (10, 20, 30, 40); the blank "new" row is ignored: ' . ($r['json']['did'] ?? $r['body']));
$m1 = json_decode($rows[0]['match'], true); $m3 = json_decode($rows[2]['match'], true);
ksort($m1); ksort($m3);
ok($m1 === ['country' => ['DE', 'FR'], 'source_id' => [$web]] && $m3 === ['campaign_id' => [$camp], 'source_id' => [$ref], 'utm_source' => ['newsletter']], 'the match is the bounded vocabulary, countries upper-cased, empty picks dropped: ' . $rows[0]['match']);
ok($rows[0]['assign_kind'] === 'member' && (int) $rows[0]['member_id'] === 27 && $rows[1]['assign_kind'] === 'department' && (int) $rows[1]['department_id'] === $dept && texts($rows[2]['members']) === ['26', '30'] && $rows[3]['active'] === false, 'member, department and round-robin rules hold what they point at; an idle rule is stored inactive');
ok($rows[0]['member_id'] !== null && $rows[1]['member_id'] === null && $rows[2]['department_id'] === null, 'a rule keeps only the target of its kind');
$id3 = (int) $rows[2]['id'];

echo "2. The test box — which rule, and nothing assigned\n";
$test = fn (array $q) => get_as(1, '/admin/rules/test?' . http_build_query($q))['json']['data'] ?? [];
$leadsBefore = (int) one('SELECT count(*) FROM leads');
$t = $test(['source' => $web, 'country' => 'DE']);
ok(($t['matched'] ?? false) === true && $t['rule']['name'] === "EU web $x" && $t['rule']['owner_member_id'] === 27 && $t['rule']['position'] === 10, 'a German lead from the website matches the first rule → Marco: ' . json_encode($t['rule'] ?? null));
$t = $test(['source' => $web, 'country' => 'US']);
ok(($t['matched'] ?? true) === false, 'a US lead from the website matches nothing (the idle rule is skipped): it would stay unowned');
$t = $test(['email' => 'pat@mit.edu']);
ok($t['rule']['name'] === "Edu $x" && $t['rule']['assign_kind'] === 'department' && $t['rule']['owner_member_id'] === $mgr, 'an .edu address matches the department rule → that department\'s manager');
$t = $test(['source' => $web, 'country' => 'DE', 'email' => 'pat@mit.edu']);
ok($t['rule']['name'] === "EU web $x", 'order decides: both match, the first wins');
$q3 = ['source' => $ref, 'campaign' => $camp, 'utm_source' => 'newsletter'];
$li0 = (int) one('SELECT last_index FROM assignment_rules WHERE id = :i', ['i' => $id3]);
$a = $test($q3); $b = $test($q3); $c = $test($q3);
ok($a['rule']['name'] === "Turns $x" && $a['rule']['owner_member_id'] === 26 && $b['rule']['owner_member_id'] === 26 && $c['rule']['owner_member_id'] === 26, 'a round-robin rule names the NEXT person (Priya) each time it is asked — the turn does not move');
ok((int) one('SELECT last_index FROM assignment_rules WHERE id = :i', ['i' => $id3]) === $li0 && (int) one('SELECT count(*) FROM leads') === $leadsBefore, 'last_index unchanged, no lead made: nothing was assigned');
ok($test(['source' => $ref, 'campaign' => $camp])['matched'] === false && $test(['source' => $ref, 'utm_source' => 'newsletter'])['matched'] === false, 'every condition of a rule must hold: a missing campaign or utm source is no match');
$h = html_as(1, '/admin/rules/test?source=' . $web . '&country=DE', ['HX-Request: true']);
ok($h['code'] === 200 && str_contains($h['body'], 'id="rule-test"') && str_contains($h['body'], 'nothing was assigned') && !str_contains($h['body'], '<html'), 'over HTMX the answer is the result box alone, saying nothing was assigned');
ok(str_contains(html_as(1, '/admin/rules/test?country=ZZ')['body'], 'id="rule-test"') && get_as(1, '/admin/rules/test?email=notanemail')['code'] === 422, 'the page without scripts answers too; a bad email is 422');
// parity with the real function, in a transaction that is rolled back
$parity = function (array $lead): array {
    $p = pdo();
    $p->beginTransaction();
    $dry = $p->prepare('SELECT owner_member_id FROM crm_assign_lead_dry(jsonb_populate_record(NULL::leads, CAST(:j AS jsonb)))');
    $dry->execute(['j' => json_encode($lead === [] ? new stdClass() : $lead)]);
    $d = $dry->fetchColumn();
    $real = $p->prepare('SELECT crm_assign_lead(jsonb_populate_record(NULL::leads, CAST(:j AS jsonb)))');
    $real->execute(['j' => json_encode($lead === [] ? new stdClass() : $lead)]);
    $rv = $real->fetchColumn();
    $p->rollBack();
    return [$d === false || $d === null ? null : (int) $d, $rv === false || $rv === null ? null : (int) $rv];
};
as_member(1);
$cases = [['source_id' => $web, 'country' => 'DE'], ['email' => 'a@b.edu'], ['source_id' => $ref, 'campaign_id' => $camp, 'utm' => ['utm_source' => 'newsletter']], ['source_id' => $evt], []];
$agree = true; $seen = [];
foreach ($cases as $lead) { [$d, $rv] = $parity($lead); $seen[] = json_encode([$d, $rv]); if ($d !== $rv) { $agree = false; } }
ok($agree, 'crm_assign_lead_dry agrees with crm_assign_lead on a member, a department, a round-robin and a no-match lead: ' . implode(' ', $seen));
ok((int) one('SELECT last_index FROM assignment_rules WHERE id = :i', ['i' => $id3]) === $li0, '(and the rolled-back transaction left the round-robin turn where it was)');
$flag = (string) pg("SELECT provolatile FROM pg_proc WHERE proname = 'crm_assign_lead_dry'");
ok($flag === 's', 'the dry function is declared STABLE: it cannot write (provolatile = ' . $flag . ')');
$written = q("SELECT count(*) AS n FROM activity_log WHERE id > :s AND action LIKE 'lead.%'", ['s' => $since])[0]['n'];
ok((int) $written === 0, 'and the tests logged no lead event');

echo "3. A real lead follows the list, and the round-robin keeps its turn across a save\n";
as_member(1);
$l1 = (int) one("INSERT INTO leads (company, source_id, campaign_id, utm, created_by) VALUES (:c, :s, :k, '{\"utm_source\": \"newsletter\"}', 1) RETURNING id", ['c' => "R9 real $x", 's' => $ref, 'k' => $camp]);
$o1 = (int) one('SELECT owner_member_id FROM leads WHERE id = :i', ['i' => $l1]);
ok($o1 === 26, 'a real lead the rules take is owned by the next in turn (Priya) — owner: ' . $o1);
$dry = $test($q3);
ok(($dry['rule']['owner_member_id'] ?? 0) === 30, 'after one real assignment the dry run names the next turn (Dana)');
// resave the same list with ids: the turn is kept
$cur = screen(1, '/admin/rules')['rules'];
$payload = array_map(static fn (array $r): array => ['id' => $r['rule_id'], 'name' => $r['name'], 'match' => $r['match'], 'assign_kind' => $r['assign_kind'], 'member_id' => $r['member_id'], 'department_id' => $r['department_id'],
    'members' => array_column($r['members'], 'id'), 'active' => $r['active'] ? 'yes' : 'no'], $cur);
$li1 = (int) one('SELECT last_index FROM assignment_rules WHERE id = :i', ['i' => $id3]);
$r = adm('/admin/rules/save.php', ['rules' => json_encode($payload)]);
ok($r['code'] === 200 && (int) one('SELECT last_index FROM assignment_rules WHERE id = :i', ['i' => $id3]) === $li1 && (int) one('SELECT count(*) FROM assignment_rules') === 4, 'saving the list again with the rules\' ids (an agent\'s JSON) keeps the round-robin turn: last_index ' . $li1);
$payload = array_reverse($payload);
$r = adm('/admin/rules/save.php', ['rules' => json_encode($payload)]);
$names = array_column(q('SELECT name FROM assignment_rules ORDER BY position, id'), 'name');
ok($r['code'] === 200 && $names === ["Idle $x", "Turns $x", "Edu $x", "EU web $x"], 'the order of the list is the order: reversed');
$drop = array_slice($payload, 0, 2);
$r = adm('/admin/rules/save.php', ['rules' => json_encode($drop)]);
ok($r['code'] === 200 && (int) one('SELECT count(*) FROM assignment_rules') === 2, 'a rule left out of the list is removed');
$r = adm('/admin/rules/save.php', ['rules' => ['1' => ['id' => $id3, 'remove' => '1', 'name' => 'x']]]);
ok($r['code'] === 200 && (int) one('SELECT count(*) FROM assignment_rules') === 0, 'a ticked Remove drops the rule (here the last: an empty list is allowed)');

echo "4. Refusals\n";
$bad = fn (array $row, string $needle) => (function () use ($row, $needle) { $r = adm('/admin/rules/save.php', ['rules' => json_encode([$row])]); return [$r['code'] === 422 && str_contains(says($r), $needle), says($r)]; })();
[$okk, $msg] = $bad(['name' => 'x', 'assign_kind' => 'member'], 'Rule 1: choose the person');
ok($okk, 'a member rule with no member: ' . $msg);
[$okk, $msg] = $bad(['name' => 'x', 'assign_kind' => 'department'], 'choose the department');
ok($okk, 'a department rule with none: ' . $msg);
[$okk, $msg] = $bad(['name' => 'x', 'assign_kind' => 'round_robin', 'members' => []], 'at least one person');
ok($okk, 'a round-robin with no one: ' . $msg);
[$okk, $msg] = $bad(['name' => 'x', 'assign_kind' => 'sideways'], 'assigns to a person');
ok($okk, 'an unknown kind: ' . $msg);
[$okk, $msg] = $bad(['name' => 'x', 'assign_kind' => 'member', 'member_id' => 27, 'match' => ['country' => ['Germany']]], 'two-letter code');
ok($okk, 'a country that is no code: ' . $msg);
[$okk, $msg] = $bad(['name' => 'x', 'assign_kind' => 'member', 'member_id' => 27, 'match' => ['source_id' => [999999]]], 'no source 999999');
ok($okk, 'a source that is not there: ' . $msg);
[$okk, $msg] = $bad(['name' => 'x', 'assign_kind' => 'member', 'member_id' => 999999], 'does not work here');
ok($okk, 'a person who does not work here: ' . $msg);
[$okk, $msg] = $bad(['id' => 99999, 'name' => 'x', 'assign_kind' => 'member', 'member_id' => 27], 'not there any more');
ok($okk, 'a rule id that is gone: ' . $msg);
[$okk, $msg] = $bad(['name' => '', 'country' => 'US', 'assign_kind' => 'member', 'member_id' => 27], 'give it a name');
ok($okk, 'a rule with conditions but no name: ' . $msg);
$r = adm('/admin/rules/save.php', ['x' => 1]);
ok($r['code'] === 422 && field_of($r) === 'rules', 'no rules[] at all: 422 naming rules');
$r = adm('/admin/rules/save.php', ['rules' => json_encode([['name' => 'good', 'assign_kind' => 'member', 'member_id' => 27], ['name' => 'bad', 'assign_kind' => 'member']])]);
ok($r['code'] === 422 && (int) one('SELECT count(*) FROM assignment_rules') === 0, 'one bad row refuses the whole list — nothing was saved (one transaction)');
$r = adm('/admin/rules/save.php', ['rules' => json_encode([['name' => "All $x", 'assign_kind' => 'member', 'member_id' => 27]])]);
ok($r['code'] === 200 && json_decode((string) one('SELECT match::text FROM assignment_rules'), true) === [], 'a rule with no condition matches every lead (stored {})');
ok($test([])['rule']['name'] === "All $x", '…and the test box says so for an empty lead');
adm('/admin/rules/save.php', ['rules' => '[]']);

echo "5. Rights and the log\n";
ok(get_as(26, '/admin/rules')['code'] === 403 && post_as(26, '/admin/rules/save.php', ['rules' => '[]'])['code'] === 403 && get_as(26, '/admin/rules/test')['code'] === 403, 'a Rep may not see, save or test');
ok(get_as(27, '/admin/rules')['code'] === 200 && get_as(27, '/admin/rules/test?country=DE')['code'] === 200 && post_as(27, '/admin/rules/save.php', ['rules' => '[]'])['code'] === 200, 'the Sales Manager (assignment.rules) may');
$mkt = role_actor(31, 'marketing');
ok(get_role($mkt, '/admin/rules')['code'] === 403, 'Marketing (leads.assign, not assignment.rules) may not keep them');
$ev = q("SELECT after::text AS a FROM activity_log WHERE action = 'rules.save' AND id > :s ORDER BY id", ['s' => $since]);
ok(count($ev) >= 6 && str_contains($ev[0]['a'], 'added') && str_contains($ev[0]['a'], 'removed'), 'rules.save is logged with the names, how many were added and removed');
finish();
