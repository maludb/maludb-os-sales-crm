<?php
/**
 * Proof — the import (leads-and-conversion.md §4, §8): a CSV of 2,000 rows with 30 duplicates dry-runs with the right counts and writes nothing; runs; rolls
 * back leaving tombstones; a template prefills the mapping (in the form and when the start names no mapping); the update policy fills empty fields only; a bad
 * row is refused in words and never sinks its batch; Latin-1 with semicolons is sniffed; a static list collects what is made; owner_email names the owner;
 * the ceiling; the rights (people only, own imports); the status poll; the timing.
 */
require __DIR__ . '/lib.php';
$x = run_tag();
as_member(26);                                                             // the views answer an admitted member only
$leads = static fn (): int => (int) one('SELECT count(*) FROM leads WHERE deleted_at IS NULL');

echo "1. 2,000 rows with 30 duplicates: dry run, run, roll back\n";
$rows = [['First Name', 'Last Name', 'Company', 'E-mail', 'Country', 'Notes']];
for ($i = 1; $i <= 1970; $i++) { $rows[] = ["F$i", "L$i $x", "Co $i $x", "p$i.$x@co$i.example.com", 'US', "row $i"]; }
for ($i = 1; $i <= 30; $i++) { $rows[] = ["Dup$i", "Again $x", "Co $i $x", "p$i.$x@co$i.example.com", 'US', 'again']; }
$file = csv_file($rows);
$n0 = $leads();
$r = import_as(26, 'leads_csv', $file);
$id = (int) ($r['json']['record_id'] ?? 0);
ok($r['code'] === 200 && $id > 0 && ($r['json']['location'] ?? '') === "/import/$id?notice=started", 'import_start answers the import and lands on its page');
$imp = q('SELECT status, dry_run, row_count, mapping, storage_path FROM imports WHERE id = :i', ['i' => $id])[0];
$map = json_decode((string) $imp['mapping'], true);
ok($imp['status'] === 'queued' && $imp['dry_run'] === true && (int) $imp['row_count'] === 2000, 'queued as a dry run of 2,000 rows (the request never runs it)');
ok($map == ['First Name' => 'first_name', 'Last Name' => 'last_name', 'Company' => 'company', 'E-mail' => 'email', 'Country' => 'country', 'Notes' => 'message'], 'with no mapping given, each header is mapped by its own words (E-mail → email, Notes → message)');
ok($imp['storage_path'] === "imports/$id.csv" && is_file(rtrim((string) getenv('CRM_STORAGE_DIR'), '/') . "/imports/$id.csv"), 'the file is kept at storage/imports/<id>.csv');
$s = html_as(26, "/import/$id/status");
ok(str_contains($s['body'], 'hx-trigger="every 3s"') && str_contains($s['body'], 'Waiting for its dry run'), 'while queued the status partial polls every 3 s');
$t = microtime(true);
$w = worker();
$dry = round(microtime(true) - $t, 1);
$d = get_as(26, "/import/$id")['json']['data']['import'];
ok($d['status'] === 'done' && $d['dry_run'] === true && $d['created'] === 1970 && $d['skipped'] === 30 && $d['refused'] === 0, "the dry run counts 1,970 to make and 30 to skip ($dry s)");
ok($leads() === $n0, 'and wrote nothing: the leads are as they were');
ok((int) one("SELECT count(*) FROM import_rows WHERE import_id = :i AND result = 'skipped' AND message LIKE 'The lead % has this email.'", ['i' => $id]) === 30, 'each skipped row says which lead it matched (an earlier row of the same file)');
$s = html_as(26, "/import/$id/status?was=busy");
ok(preg_match('/^HX-Refresh: true/mi', $s['headers']) === 1 && !str_contains($s['body'], 'every 3s'), 'once done, the poll stops and the page reloads with its rows');
ok(str_contains(html_as(26, "/import/$id")['body'], 'id="import-run-btn"'), 'the page offers Run it for real');
ok(post_as(26, '/import/rollback.php', ['import' => $id])['code'] === 422, 'a dry run is not rolled back');
$r = post_as(26, '/import/run.php', ['import' => $id]);
ok($r['code'] === 200 && one('SELECT status || dry_run::text FROM imports WHERE id = :i', ['i' => $id]) === 'queuedfalse' && (int) one('SELECT count(*) FROM import_rows WHERE import_id = :i', ['i' => $id]) === 0, 'import_run queues the real run (the dry run\'s rows cleared)');
$t = microtime(true);
worker();
$real = round(microtime(true) - $t, 1);
$d = get_as(26, "/import/$id")['json']['data']['import'];
ok($d['status'] === 'done' && $d['dry_run'] === false && $d['created'] === 1970 && $d['skipped'] === 30 && $leads() === $n0 + 1970, "the run makes the 1,970 leads ($real s)");
ok($d['rollback_until'] !== null && abs(strtotime($d['rollback_until']) - (time() + 24 * 3600)) < 300, 'and opens a 24-hour window to roll it back');
ok(one("SELECT count(*) FROM leads l JOIN lead_sources s ON s.id = l.source_id WHERE l.email LIKE '%.$x@co%' AND s.name = 'Import' AND l.owner_member_id = 26") === 1970, 'each lead: the Import source and the maker as owner (no owner named)');
ok(post_as(26, '/import/run.php', ['import' => $id])['code'] === 422, 'a run import is not run again');
$r = post_as(30, '/import/rollback.php', ['import' => $id]);
ok($r['code'] === 404, 'another Rep may not roll back Priya\'s import — they do not see it (404)');
$r = post_as(26, '/import/rollback.php', ['import' => $id]);
ok($r['code'] === 200 && $leads() === $n0 && (int) one("SELECT count(*) FROM leads WHERE email LIKE '%.$x@co%' AND deleted_at IS NOT NULL") === 1970, 'import_rollback tombstones the 1,970 leads it made');
ok(one('SELECT status FROM imports WHERE id = :i', ['i' => $id]) === 'rolled_back' && post_as(26, '/import/rollback.php', ['import' => $id])['code'] === 422, 'the import is rolled back, once');
$a = activity('import.rollback');
ok(json_decode((string) end($a)['after'], true)['tombstoned'] === 1970, 'logged import.rollback with the count');

echo "2. Templates, policies, refusals, encodings\n";
$r = post_as(26, '/import/template-save.php', ['name' => "Trade show $x", 'kind' => 'leads_csv', 'mapping' => json_encode(['Who' => 'first_name', 'Org' => 'company', 'Mail' => 'email', 'Seller' => 'owner_email', 'Labels' => 'tags', 'Junk' => 'skip']), 'dedupe_policy' => 'update']);
$tpl = (int) ($r['json']['record_id'] ?? 0);
ok($r['code'] === 200 && $tpl > 0, 'import_template_save keeps a mapping');
$f = html_as(26, "/import/new?template=$tpl")['body'];
ok(str_contains($f, 'name="template" value="' . $tpl . '"') && str_contains($f, '&quot;Who&quot;:&quot;first_name&quot;') && str_contains($f, 'id="import-form-policy-update" checked'), 'the form prefills from the template (its mapping for the browser\'s columns, its policy)');
ok(post_as(26, '/import/template-save.php', ['name' => 'Bad', 'kind' => 'leads_csv', 'mapping' => json_encode(['A' => 'not_a_field'])])['code'] === 422, 'a template naming a field the kind lacks is refused');
as_member(26);
$existing = (int) one("INSERT INTO leads (first_name, email, owner_member_id, created_by) VALUES ('Old', 'known.$x@show.example.com', 26, 26) RETURNING id");
$list = (int) one("INSERT INTO lists (name, kind, record_kind, owner_member_id, created_by) VALUES ('Show $x', 'static', 'lead', 26, 26) RETURNING id");
$file2 = csv_file([['Who', 'Org', 'Mail', 'Seller', 'Labels', 'Junk', 'Country'],
    ['Known', "Filled Co $x", "known.$x@show.example.com", '', '', 'x', ''],
    ['Neu', "New Co $x", "neu.$x@show.example.com", 'dana@example.invalid', "show-$x, hot-$x", 'x', 'DE'],
    ['Bad', "Bad Co $x", "bad.$x@show.example.com", '', '', 'x', 'Germany'],
    ['Ghost', "Ghost Co $x", "ghost.$x@show.example.com", 'nobody@example.invalid', '', 'x', ''],
    ['', '', '', '', '', 'x', '']]);
$r = import_as(26, 'leads_csv', $file2, ['template' => $tpl, 'list' => $list, 'dry_run' => 'no']);
$id2 = (int) ($r['json']['record_id'] ?? 0);
ok($r['code'] === 200 && json_decode((string) one('SELECT mapping FROM imports WHERE id = :i', ['i' => $id2]), true)['Seller'] === 'owner_email' && one('SELECT dedupe_policy FROM imports WHERE id = :i', ['i' => $id2]) === 'update', 'a start that names a template and no mapping uses the template\'s mapping and policy');
worker();
$res = [];
foreach (q('SELECT row_number, result, message FROM import_rows WHERE import_id = :i ORDER BY row_number', ['i' => $id2]) as $row) { $res[(int) $row['row_number']] = [$row['result'], (string) $row['message']]; }
ok(($res[1][0] ?? '') === 'updated' && one('SELECT company FROM leads WHERE id = :i', ['i' => $existing]) === "Filled Co $x" && one('SELECT first_name FROM leads WHERE id = :i', ['i' => $existing]) === 'Old', 'update fills the matched lead\'s empty fields (the company) and keeps its values (the first name)');
ok(($res[2][0] ?? '') === 'created' && one("SELECT owner_member_id FROM leads WHERE email = 'neu.$x@show.example.com'") === 30, 'owner_email names the owner (Dana)');
ok(one("SELECT array_to_string(crm_tags_of('lead', id), ',') FROM leads WHERE email = 'neu.$x@show.example.com'") === "hot-$x,show-$x", '…the tags column tags it');
ok((int) one('SELECT count(*) FROM list_members WHERE list_id = :l', ['l' => $list]) === 1, '…and what is made joins the list (the update does not)');
ok(($res[3][0] ?? '') === 'refused' && str_contains($res[3][1], 'two-letter code'), 'a bad country is refused in words: ' . ($res[3][1] ?? ''));
ok(($res[4][0] ?? '') === 'refused' && str_contains($res[4][1], 'nobody@example.invalid'), 'an owner email nobody has is refused, naming it');
ok(($res[5][0] ?? '') === 'refused' && str_contains($res[5][1], 'names no company'), 'an empty row is refused');
ok(count($res) === 5 && one('SELECT status FROM imports WHERE id = :i', ['i' => $id2]) === 'done', 'refused rows never sink the rest (the run finished)');
$f3 = csv_file([['Name', 'Domain', 'Ville'], ["Café Müller $x", "cafe-$x.example.com", 'Köln'], ["Brasserie $x", "brasserie-$x.example.com", 'Liège']], ';', 'ISO-8859-1');
$r = import_as(26, 'accounts_csv', $f3, ['mapping' => json_encode(['Name' => 'name', 'Domain' => 'domain', 'Ville' => 'city']), 'dry_run' => 'no']);
worker();
ok(one("SELECT city FROM accounts WHERE domain = 'cafe-$x.example.com'") === 'Köln' && one("SELECT name FROM accounts WHERE domain = 'cafe-$x.example.com'") === "Café Müller $x", 'Latin-1 with semicolons is sniffed: Café Müller in Köln, whole');
$f4 = csv_file([['Name', 'Domain'], ["Again $x", "cafe-$x.example.com"]]);
import_as(26, 'accounts_csv', $f4, ['dry_run' => 'no']);
worker();
ok((int) one("SELECT count(*) FROM accounts WHERE domain = 'cafe-$x.example.com'") === 1 && (int) one("SELECT rows_skipped FROM imports ORDER BY id DESC LIMIT 1") === 1, 'an account matched by its domain is skipped (skip, the default)');
$f5 = csv_file([['First Name', 'E-mail'], ['A', 'a@x.example.com']]);
ok(import_as(26, 'leads_csv', $f5, ['mapping' => json_encode(['Nope' => 'email'])])['code'] === 422, 'a mapping naming a column the file lacks is refused');
ok(import_as(26, 'leads_csv', csv_file([['First Name']]))['code'] === 422, 'a file with a header and no rows is refused');
pdo()->exec('UPDATE crm_settings SET import_max_rows = 1000');
ok(str_contains(message_of(import_as(26, 'leads_csv', $file)), 'up to 1,000 rows'), 'more rows than the ceiling is refused in words');
pdo()->exec('UPDATE crm_settings SET import_max_rows = 50000');

echo "3. The rights\n";
ok(import_as(29, 'leads_csv', $f5)['code'] === 403, 'a Viewer may not import');
$tok = run_token(40, 901);
ok(req('POST', '/import/start.php', ['headers' => as_agent($tok), 'form' => ['kind' => 'leads_csv']])['code'] === 403, 'an agent may not import (people only)');
ok(get_as(30, "/import/$id")['code'] === 404 && get_as(27, "/import/$id")['code'] === 200, 'another Rep does not see Priya\'s import; the manager does');
ok(!in_array($id, array_column(get_as(30, '/import')['json']['data']['imports'], 'import_id'), true), 'nor in their list');
finish();
