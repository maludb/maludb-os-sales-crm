<?php
/**
 * Proof — custom fields (admin-settings.md "Proof"): a field added on a record kind and OFFERED by that kind's form on the next render, its values checked by the database (a wrong type refused with the field named), its key and record
 * fixed, its type fixed once values exist, choices required, a deal field for chosen pipelines only and required to win (slice 3's won handler reads it), archive hiding it from the forms while the values stay valid, the rights.
 */
require __DIR__ . '/lib.php';
$x = run_tag();
$since = last_activity_id();

echo "1. A field is on the next form\n";
ok(!str_contains(html_as(26, '/leads/new')['body'], "Tier $x"), 'before: the lead form does not have it');
$r = adm('/admin/fields/save.php', ['applies_to' => 'lead', 'label' => "Tier $x", 'key' => "tier_$x", 'field_type' => 'select', 'options' => "Gold\nSilver\nBronze", 'sort_order' => 5]);
$tier = (int) ($r['json']['record_id'] ?? 0);
$row = q('SELECT * FROM custom_field_defs WHERE id = :i', ['i' => $tier])[0] ?? [];
ok($r['code'] === 200 && $row['field_type'] === 'select' && texts($row['options']) === ['Gold', 'Silver', 'Bronze'] && $row['applies_to'] === 'lead' && $row['archived_at'] === null, 'custom_field_save adds a choice field: ' . ($r['json']['did'] ?? $r['body']));
$form = html_as(26, '/leads/new')['body'];
ok(str_contains($form, "Tier $x") && str_contains($form, 'custom[tier_' . $x . ']') && str_contains($form, '>Silver<'), 'the lead form (slice 2, reading mcp_custom_fields) offers the field with its choices on the very next render');
ok(!str_contains(html_as(26, '/accounts/new')['body'], "Tier $x") && !str_contains(html_as(26, '/contacts/new')['body'], "Tier $x"), 'and no other kind\'s form has it');
$lead = (int) post_as(26, '/leads/save.php', ['last_name' => "Fieldy $x", 'company' => "F9 $x", 'custom' => ["tier_$x" => 'Gold']])['json']['record_id'];
ok($lead > 0 && json_decode((string) one('SELECT custom_values::text FROM leads WHERE id = :i', ['i' => $lead]), true) === ["tier_$x" => 'Gold'], 'a lead saved with a valid choice holds it');
$r = post_as(26, '/leads/save.php', ['lead' => $lead, 'custom' => ["tier_$x" => 'Platinum']]);
ok($r['code'] === 422 && str_contains(says($r), "tier_$x") && str_contains(says($r), 'one of its options'), 'a value that is not a choice is refused, the field named: ' . message_of($r));
$num = (int) adm('/admin/fields/save.php', ['applies_to' => 'lead', 'label' => "Seats $x", 'field_type' => 'number'])['json']['record_id'];
$numKey = (string) one('SELECT key FROM custom_field_defs WHERE id = :i', ['i' => $num]);
ok(str_starts_with($numKey, 'seats_') && preg_match('/^[a-z][a-z0-9_]{0,39}$/', $numKey) === 1, 'a key omitted is made from the label: ' . $numKey);
$r = post_as(26, '/leads/save.php', ['lead' => $lead, 'custom' => [$numKey => 'many']]);
ok($r['code'] === 422 && str_contains(says($r), 'takes a number'), 'a number field refuses a word on a record, naming the field: ' . message_of($r));
$r = post_as(26, '/leads/save.php', ['lead' => $lead, 'custom' => [$numKey => '12']]);
ok($r['code'] === 200 && json_decode((string) one('SELECT custom_values::text FROM leads WHERE id = :i', ['i' => $lead]), true)[$numKey] === 12, 'and takes a number');

echo "2. What a definition cannot do\n";
$r = adm('/admin/fields/save.php', ['field' => $num, 'field_type' => 'text']);
ok($r['code'] === 422 && str_contains(says($r), 'stays number'), 'a field with values keeps its type: ' . message_of($r));
$r = adm('/admin/fields/save.php', ['field' => $num, 'key' => 'other_key']);
ok($r['code'] === 422 && str_contains(says($r), 'keeps its key'), 'a key never changes: ' . message_of($r));
$r = adm('/admin/fields/save.php', ['field' => $num, 'applies_to' => 'deal']);
ok($r['code'] === 422 && str_contains(says($r), 'keeps its key and its record'), 'nor its record kind');
$r = adm('/admin/fields/save.php', ['applies_to' => 'lead', 'label' => 'Dup', 'key' => $numKey, 'field_type' => 'text']);
ok($r['code'] === 422 && str_contains(says($r), 'exists already'), 'a key a kind has already (even archived) is refused: ' . message_of($r));
$r = adm('/admin/fields/save.php', ['applies_to' => 'lead', 'label' => 'No choices', 'key' => "nc_$x", 'field_type' => 'select']);
ok($r['code'] === 422 && str_contains(says($r), 'needs its choices'), 'a choice field needs its choices: ' . message_of($r));
$r = adm('/admin/fields/save.php', ['applies_to' => 'lead', 'label' => 'Comma', 'key' => "cm_$x", 'field_type' => 'select', 'options' => "a,b\nc"]);
ok($r['code'] === 422 && str_contains(says($r), 'without commas'), 'a choice with a comma is refused: ' . message_of($r));
$r = adm('/admin/fields/save.php', ['applies_to' => 'lead', 'label' => 'Bad', 'key' => 'Bad Key', 'field_type' => 'text']);
ok($r['code'] === 422 && field_of($r) === 'key', 'a key that is no slug names its field');
$r = adm('/admin/fields/save.php', ['applies_to' => 'thing', 'label' => 'Bad', 'field_type' => 'blob']);
ok($r['code'] === 422 && isset($r['json']['error']['fields']['applies_to']) && isset($r['json']['error']['fields']['field_type']), 'an unknown record kind and type: both fields named');
$r = adm('/admin/fields/save.php', ['applies_to' => 'deal', 'label' => 'Value', 'key' => 'value', 'field_type' => 'text']);
ok($r['code'] === 422 && str_contains(says($r), 'column of a deal'), 'a deal field cannot take the name of a deal column (a stage\'s required fields use both): ' . message_of($r));
$r = adm('/admin/fields/save.php', ['field' => $tier, 'options' => "Gold\nSilver\nBronze\nPlatinum", 'label' => "Tier $x!"]);
ok($r['code'] === 200 && texts(one('SELECT options::text FROM custom_field_defs WHERE id = :i', ['i' => $tier])) === ['Gold', 'Silver', 'Bronze', 'Platinum'], 'choices can be added to a field in use');
$r = post_as(26, '/leads/save.php', ['lead' => $lead, 'custom' => ["tier_$x" => 'Platinum']]);
ok($r['code'] === 200, '…and the new choice is taken at once');

echo "3. Archive hides a field, the values stay\n";
$r = adm('/admin/fields/save.php', ['field' => $tier, 'archived' => 'yes']);
ok($r['code'] === 200 && one('SELECT archived_at FROM custom_field_defs WHERE id = :i', ['i' => $tier]) !== null, 'archived');
ok(!str_contains(html_as(26, '/leads/new')['body'], "Tier $x!") && !str_contains(html_as(26, "/leads/$lead/edit")['body'], "Tier $x!"), 'the forms no longer offer it');
$r = post_as(26, '/leads/save.php', ['lead' => $lead, 'company' => "F9 renamed $x"]);
ok($r['code'] === 200 && json_decode((string) one('SELECT custom_values::text FROM leads WHERE id = :i', ['i' => $lead]), true)["tier_$x"] === 'Platinum', 'a lead that holds a value for it still saves (db/024: an archived field\'s values stay valid), and keeps it');
$list = screen(1, '/admin/fields?kind=lead')['fields'];
$mine = array_values(array_filter($list, static fn (array $f): bool => $f['field_id'] === $tier))[0];
ok($mine['archived'] === true && $mine['in_use'] === 1 && array_search($tier, array_column($list, 'field_id'), true) > array_search($num, array_column($list, 'field_id'), true), 'the screen lists it last, archived, with its use (1 record)');
ok(adm('/admin/fields/save.php', ['field' => $tier, 'archived' => 'no'])['code'] === 200 && str_contains(html_as(26, '/leads/new')['body'], "Tier $x!"), 'restored: on the forms again');

echo "4. A deal field for chosen pipelines, required to win\n";
[$pp] = pipeline_made("F9 pipe $x");
$r = adm('/admin/fields/save.php', ['applies_to' => 'deal', 'label' => "Contract $x", 'key' => "contract_$x", 'field_type' => 'text', 'pipelines' => [$pp], 'required_to_win' => 'yes']);
$df = (int) ($r['json']['record_id'] ?? 0);
$drow = q('SELECT * FROM custom_field_defs WHERE id = :i', ['i' => $df])[0] ?? [];
ok($r['code'] === 200 && texts($drow['type_ids']) === [(string) $pp] && $drow['required_on_solve'] === true, 'pipelines[] and required_to_win are kept for a deal field');
$r = adm('/admin/fields/save.php', ['applies_to' => 'deal', 'label' => "Nope $x", 'key' => "nope_$x", 'field_type' => 'text', 'pipelines' => [999999]]);
ok($r['code'] === 422 && str_contains(says($r), 'no pipeline'), 'a pipeline that is not there is refused');
$r = adm('/admin/fields/save.php', ['applies_to' => 'lead', 'label' => "LeadWin $x", 'key' => "lw_$x", 'field_type' => 'text', 'pipelines' => [$pp], 'required_to_win' => 'yes']);
$lw = q('SELECT type_ids::text AS t, required_on_solve FROM custom_field_defs WHERE key = :k', ['k' => "lw_$x"])[0];
ok($r['code'] === 200 && texts($lw['t']) === [] && $lw['required_on_solve'] === false, 'pipelines and required_to_win mean nothing outside deals: stored empty and off');
$won = (int) one("SELECT id FROM stages WHERE pipeline_id = :p AND kind = 'won'", ['p' => $pp]);
$open = stage_made($pp, "Go $x");
$d = deal_in($pp, $open, "F9 deal $x", ['value' => 100]);
$m = post_as(26, "/deals/$d/won", ['value' => 100]);
ok($m['code'] === 422 && str_contains(says($m), "Contract $x"), 'slice 3\'s won handler refuses a deal that lacks the field required to win, naming it: ' . message_of($m));
$u = post_as(26, '/deals/save.php', ['deal' => $d, 'custom' => ["contract_$x" => 'C-1']]);
$m = post_as(26, "/deals/$d/won", ['value' => 100]);
ok($u['code'] === 200 && $m['code'] === 200 && one('SELECT status FROM deals WHERE id = :i', ['i' => $d]) === 'won', 'filled in, the deal is won');
$other = deal_in(1, stage('Qualification'), "F9 other pipeline $x");
ok(!str_contains(html_as(26, "/deals/$other/edit")['body'], "Contract $x") && str_contains(html_as(26, "/deals/$d/edit")['body'], "Contract $x"), 'the field is on the deal forms of its pipeline only');
$pk = screen(1, '/admin/fields?kind=deal');
ok(isset($pk['pipelines'][0]['pipeline_id']) && isset($pk['types']['money']) && count($pk['fields']) >= 1, 'JSON: the kind\'s fields, the types and (for deals) the live pipelines');

echo "5. Rights, JSON, the log\n";
ok(get_as(26, '/admin/fields')['code'] === 403 && post_as(26, '/admin/fields/save.php', ['label' => 'x'])['code'] === 403 && get_as(27, '/admin/fields')['code'] === 403, 'a Rep and the Sales Manager may not (fields.manage is the CRM admin\'s)');
$mkt = role_actor(31, 'marketing');
ok(get_role($mkt, '/admin/fields')['code'] === 403, 'nor Marketing');
$r = req('POST', '/admin/fields/save.php', ['headers' => as_agent(run_token(1, 9301)), 'form' => ['applies_to' => 'lead', 'label' => "ByAgent $x", 'key' => "ba_$x", 'field_type' => 'text']]);
$ev = array_unique(array_column(q('SELECT action FROM activity_log WHERE id > :s', ['s' => $since]), 'action'));
ok(in_array('field.save', $ev, true), 'field.save written');
$la = q("SELECT after::text AS a FROM activity_log WHERE action = 'field.save' AND id > :s ORDER BY id LIMIT 1", ['s' => $since])[0]['a'];
ok(str_contains($la, 'applies_to') && str_contains($la, 'label') && !str_contains($la, 'Gold,'), 'the log names the field and what it is for: ' . $la);
finish();
