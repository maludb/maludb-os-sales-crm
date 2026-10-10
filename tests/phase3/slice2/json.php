<?php
/** Proof — JSON mode on every screen and handler of slice 2, the HTMX stamps, and the registry: 11 screens built, 15 actions built, 3 approvals in step. */
require __DIR__ . '/lib.php';
$x = run_tag();
as_member(26);                                                             // the views answer an admitted member only
$l = (int) post_as(26, '/leads/save.php', ['first_name' => 'Json', 'last_name' => $x, 'company' => "Json Co $x", 'email' => "json.$x@json.example.com", 'owner' => 26])['json']['record_id'];
$imp = (int) import_as(26, 'leads_csv', csv_file([['First Name', 'E-mail'], ["J$x", "j.$x@json.example.com"]]))['json']['record_id'];
worker();
$c1 = (int) post_as(26, '/contacts/save.php', ['first_name' => 'Jay', 'last_name' => "Son $x", 'email' => "jay.$x@json.example.com"])['json']['record_id'];
setting('duplicates_policy', "'warn'");
$c2 = (int) post_as(26, '/contacts/save.php', ['first_name' => 'Jay', 'last_name' => "Son $x", 'email' => "jay.$x@json.example.com"])['json']['record_id'];

echo "1. Every screen answers JSON\n";
$screens = ['/leads' => 'leads', '/leads/inbox' => 'proposed', "/leads/$l" => 'lead', '/leads/new' => 'values', "/leads/$l/edit" => 'values', "/leads/$l/convert" => 'preview',
    '/import' => 'imports', '/import/new' => 'fields', "/import/$imp" => 'import', '/duplicates?kind=contact' => 'pairs', "/duplicates/merge?kind=contact&a=$c1&b=$c2" => 'records'];
foreach ($screens as $url => $key) {
    $r = get_as(27, $url);
    ok($r['code'] === 200 && array_key_exists($key, $r['json']['data'] ?? []), "GET $url as JSON: 200 with `$key`");
}
$d = get_as(26, "/leads/$l")['json']['data']['lead'];
ok($d['label'] === "Json $x · Json Co $x" && $d['status'] === 'new' && $d['converted'] === null && array_key_exists('duplicates', $d), 'get_lead\'s shape: the label, the status, converted (none), the duplicates');
$r = html_as(26, "/leads/$l", ['HX-Request: true']);
ok(!str_contains($r['body'], '<html') && preg_match('/^X-Screen: lead-view\r?$/mi', $r['headers']) === 1 && preg_match('/^X-Record-Id: ' . $l . '\r?$/mi', $r['headers']) === 1, 'over HTMX the lead page is the partial stamped lead-view and its id');

echo "2. Every handler answers JSON\n";
$reason = (int) one("SELECT lost_reason_id FROM mcp_lost_reasons WHERE kind = 'lead' LIMIT 1");
as_member(26);
$p = (int) one("INSERT INTO leads (company, is_proposed) VALUES ('Prop $x', true) RETURNING id");
$p2 = (int) one("INSERT INTO leads (company, is_proposed) VALUES ('Prop2 $x', true) RETURNING id");
$checks = [
    ['/leads/save.php', ['lead' => $l, 'title' => 'CEO'], 'lead_update', 26], ['/leads/qualify.php', ['lead' => $l, 'score' => 50], 'lead_qualify', 26],
    ['/leads/unqualify.php', ['lead' => $l, 'reason' => $reason], 'lead_unqualify', 26], ['/leads/requalify.php', ['lead' => $l], 'lead_requalify', 26],
    ['/leads/assign.php', ['lead' => $l, 'owner' => 30], 'lead_assign', 27], ['/leads/accept.php', ['lead' => $p], 'lead_accept_proposed', 26],
    ['/leads/dismiss.php', ['lead' => $p2], 'lead_dismiss_proposed', 26], ["/leads/$l/convert", ['lead' => $l, 'make_deal' => 'no'], 'lead_convert', 27],
    ['/import/run.php', ['import' => $imp], 'import_run', 26],
    ['/import/template-save.php', ['name' => "Json $x", 'kind' => 'leads_csv', 'mapping' => json_encode(['First Name' => 'first_name'])], 'import_template_save', 26],
    ['/duplicates/dismiss.php', ['kind' => 'contact', 'a' => $c1, 'b' => $c2], 'duplicate_dismiss', 26],
];
foreach ($checks as [$path, $form, $name, $m]) {
    $r = post_as($m, $path, $form);
    ok($r['code'] === 200 && ($r['json']['ok'] ?? false) === true && ($r['json']['did'] ?? '') !== '' && array_key_exists('record_id', $r['json']), "$name: {ok, did, record_id} — " . ($r['json']['did'] ?? $r['body']));
}
worker();
$r = post_as(26, '/import/rollback.php', ['import' => $imp]);
ok($r['code'] === 200 && ($r['json']['record_id'] ?? 0) === $imp, 'import_rollback: {ok, did, record_id}');
$r = post_as(26, '/leads/save.php', ['company' => "Created $x"]);
ok($r['code'] === 200 && ($r['json']['record_id'] ?? 0) > 0, 'lead_create: the record_id');
$r = import_as(26, 'contacts_csv', csv_file([['Name'], ['x']]), ['mapping' => json_encode(['Name' => 'first_name'])]);
ok($r['code'] === 200 && ($r['json']['record_id'] ?? 0) > 0, 'import_start: the record_id');
setting('duplicates_policy', "'warn'");
$c3 = (int) post_as(26, '/contacts/save.php', ['first_name' => 'Jay', 'last_name' => "Son $x", 'email' => "jay.$x@json.example.com"])['json']['record_id'];
$r = post_as(27, '/duplicates/merge.php', ['kind' => 'contact', 'survivor' => $c1, 'loser' => $c3]);
ok($r['code'] === 200 && ($r['json']['record_id'] ?? 0) === $c1, 'records_merge: the survivor\'s record_id');
$r = post_as(26, '/leads/save.php', ['title' => 'only']);
ok($r['code'] === 422 && ($r['json']['error']['code'] ?? '') === 'invalid' && is_array($r['json']['error']['fields'] ?? null), 'a refusal: 422 invalid with the fields');

echo "3. The registry\n";
exec('php ' . escapeshellarg(dirname(__DIR__, 3) . '/bin/build_action_registry.php') . ' --check 2>&1', $out, $rc);
ok($rc === 0, 'bin/build_action_registry.php --check: the registry and approvals[] match the manifest');
$reg = json_decode((string) file_get_contents(dirname(__DIR__, 3) . '/mcp/action_registry.json'), true);
$mine = ['lead-list', 'lead-inbox', 'lead-view', 'lead-add', 'lead-edit', 'lead-convert', 'import-list', 'import-new', 'import-view', 'duplicates', 'merge'];
ok(array_filter($mine, static fn ($s) => empty($reg['screens'][$s]['built'])) === [], 'the 11 screens read as built');
$acts = ['lead_create', 'lead_update', 'lead_qualify', 'lead_unqualify', 'lead_requalify', 'lead_convert', 'lead_assign', 'lead_accept_proposed', 'lead_dismiss_proposed', 'import_start', 'import_run', 'import_rollback', 'import_template_save', 'records_merge', 'duplicate_dismiss'];
ok(array_filter($acts, static fn ($a) => empty($reg['actions'][$a]['built'])) === [], 'the 15 actions read as built');
$paused = array_values(array_filter($acts, static fn ($a) => !empty($reg['actions'][$a]['approval'] ?? $reg['actions'][$a]['agent_approval'] ?? null)));
sort($paused);
ok($paused === ['import_rollback', 'lead_dismiss_proposed', 'records_merge'], '3 approvals in step: ' . implode(', ', $paused));
finish();
