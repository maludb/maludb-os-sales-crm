<?php
/**
 * Proof — notes, attachments and tags (accounts-contacts.md "Proof" 5 and 6): notes touch the record; only the author or a manager deletes one; an
 * attachment of 25 MB is stored under the canonical path and streamed as a download; an HTML file is never shown inline (nor with ?inline=1), an image
 * is when asked; a 26 MB file is refused, a program and an empty file too; an agent may attach in JSON (base64); tags apply, remove, count; a new label
 * makes a tag; bulk apply to three; a record the caller may not edit refuses the whole call.
 */
require __DIR__ . '/lib.php';
$x = run_tag();
$acc = (int) post_as(26, '/accounts/save.php', ['name' => "Umbrella $x"])['json']['record_id'];
$con = (int) post_as(26, '/contacts/save.php', ['first_name' => 'Alice', 'last_name' => "Abernathy $x", 'account' => $acc])['json']['record_id'];
$store = rtrim((string) getenv('CRM_STORAGE_DIR'), '/');

echo "1. Notes\n";
ok(one('SELECT last_touched_at FROM contacts WHERE id = :i', ['i' => $con]) === null, 'a fresh contact has never been touched');
$r = post_as(26, '/notes/add.php', ['kind' => 'contact', 'record' => $con, 'body' => "Prefers mornings.\nAsk about the merger."]);
$nid = (int) ($r['json']['record_id'] ?? 0);
ok($r['code'] === 200 && $nid > 0 && one('SELECT last_touched_at IS NOT NULL FROM contacts WHERE id = :i', ['i' => $con]) && one('SELECT last_touched_at IS NOT NULL FROM accounts WHERE id = :i', ['i' => $acc]), 'a note touches the contact and its account');
$a = activity('note.add');
ok(json_decode((string) end($a)['after'], true) == ['kind' => 'contact', 'record_id' => $con, 'length' => 39] && (int) end($a)['contact_id'] === $con && !str_contains((string) end($a)['after'], 'merger'), 'note.add logs the length with the contact key — never the words');
ok(post_as(26, '/notes/add.php', ['kind' => 'contact', 'record' => $con, 'body' => ''])['code'] === 422 && post_as(26, '/notes/add.php', ['kind' => 'contact', 'record' => $con, 'body' => str_repeat('x', 10001)])['code'] === 422, 'an empty note and one over 10000 characters are refused');
ok(post_as(29, '/notes/add.php', ['kind' => 'contact', 'record' => $con, 'body' => 'A viewer\'s note'])['code'] === 200, 'anyone who sees the record may add a note (the Viewer too — records.read)');
$v = html_as(26, "/contacts/$con?tab=notes")['body'];
ok(str_contains($v, 'id="note-' . $nid . '"') && str_contains($v, 'Ask about the merger.') && str_contains($v, 'id="note-' . $nid . '-delete-btn"'), 'the Notes & files tab shows it with its delete button for the author');
ok(!str_contains(html_as(30, "/contacts/$con?tab=notes")['body'], 'id="note-' . $nid . '-delete-btn"'), 'another Rep sees no delete button');
ok(post_as(30, '/notes/delete.php', ['note' => $nid])['code'] === 403, 'another Rep may not delete the note');
$r = post_as(26, '/notes/delete.php', ['note' => $nid]);
ok($r['code'] === 200 && (int) one('SELECT count(*) FROM notes WHERE id = :i', ['i' => $nid]) === 0, 'the author may');
$m = (int) post_as(30, '/notes/add.php', ['kind' => 'contact', 'record' => $con, 'body' => 'Dana was here'])['json']['record_id'];
ok(post_as(27, '/notes/delete.php', ['note' => $m])['code'] === 200, 'a manager may delete anyone\'s');

echo "2. Attachments\n";
$dir = sys_get_temp_dir() . '/crm-s1-' . $x;
@mkdir($dir);
$big = "$dir/plan.bin"; $fh = fopen($big, 'wb'); ftruncate($fh, 25 * 1048576); fclose($fh);
$r = upload_as(26, '/attachments/add.php', ['kind' => 'account', 'record' => (string) $acc], [$big, 'application/octet-stream', 'Quarterly plan (final).bin']);
$aid = (int) ($r['json']['record_id'] ?? 0);
$row = q('SELECT storage_path, byte_size, sha256, filename FROM attachments WHERE id = :i', ['i' => $aid])[0] ?? [];
ok($r['code'] === 200 && (int) ($row['byte_size'] ?? 0) === 25 * 1048576, 'a 25 MB file is accepted');
ok(($row['storage_path'] ?? '') === "attachments/account/$acc/" . substr((string) $row['sha256'], 0, 12) . '-Quarterly-plan-final-.bin' && is_file($store . '/' . $row['storage_path']), 'stored under the canonical path attachments/<kind>/<id>/<sha12>-<safe name>: ' . ($row['storage_path'] ?? ''));
ok(!str_contains((string) ($row['storage_path'] ?? ''), '..') && !is_file(dirname(__DIR__, 3) . '/html/' . ($row['storage_path'] ?? 'x')), 'never under html/');
[$j, ] = actor(26);
$d = req('GET', "/attachments/$aid", ['jar' => $j]);
ok($d['code'] === 200 && strlen($d['body']) === 25 * 1048576 && preg_match('/^Content-Disposition: attachment; filename="Quarterly-plan-final-.bin"/mi', $d['headers']) === 1 && preg_match('/^X-Content-Type-Options: nosniff/mi', $d['headers']) === 1, 'streamed whole as a download, nosniff');
$a = activity('attachment.add');
ok(!str_contains((string) end($a)['after'], 'attachments/') && json_decode((string) end($a)['after'], true)['byte_size'] === 25 * 1048576, 'attachment.add logs the name, size and type — never the path');
$huge = "$dir/huge.bin"; $fh = fopen($huge, 'wb'); ftruncate($fh, 26 * 1048576); fclose($fh);
$r = upload_as(26, '/attachments/add.php', ['kind' => 'account', 'record' => (string) $acc], [$huge, 'application/octet-stream', 'huge.bin']);
ok($r['code'] === 422 && str_contains(message_of($r), 'up to 25 MB'), 'a 26 MB file is refused in words: ' . message_of($r));
file_put_contents("$dir/page.html", '<!doctype html><script>alert(document.cookie)</script>');
$hid = (int) upload_as(26, '/attachments/add.php', ['kind' => 'account', 'record' => (string) $acc], ["$dir/page.html", 'text/html', 'page.html'])['json']['record_id'];
foreach (['', '?inline=1'] as $q) {
    $d = req('GET', "/attachments/$hid$q", ['jar' => $j]);
    ok($d['code'] === 200 && preg_match('/^Content-Disposition: attachment/mi', $d['headers']) === 1 && preg_match('/^Content-Type: application\/octet-stream/mi', $d['headers']) === 1, "an HTML file is a download, never inline$q");
}
ok(one('SELECT mime_type FROM attachments WHERE id = :i', ['i' => $hid]) === 'text/html', '…its type sniffed, not taken from the browser');
$png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==');
file_put_contents("$dir/dot.png", $png);
$pid = (int) upload_as(26, '/attachments/add.php', ['kind' => 'contact', 'record' => (string) $con], ["$dir/dot.png", 'application/octet-stream', 'dot.png'])['json']['record_id'];
$d = req('GET', "/attachments/$pid?inline=1", ['jar' => $j]);
ok(preg_match('/^Content-Disposition: inline/mi', $d['headers']) === 1 && preg_match('/^Content-Type: image\/png/mi', $d['headers']) === 1, 'an image is shown inline when asked (its sniffed type)');
file_put_contents("$dir/run.php", '<?php echo 1;');
$r = upload_as(26, '/attachments/add.php', ['kind' => 'account', 'record' => (string) $acc], ["$dir/run.php", 'text/plain', 'run.php']);
ok($r['code'] === 422 && str_contains(message_of($r), 'program'), 'a program (.php) is refused: ' . message_of($r));
file_put_contents("$dir/empty.txt", '');
ok(upload_as(26, '/attachments/add.php', ['kind' => 'account', 'record' => (string) $acc], ["$dir/empty.txt", 'text/plain', 'empty.txt'])['code'] === 422, 'an empty file is refused');
ok(upload_as(30, '/attachments/add.php', ['kind' => 'account', 'record' => (string) $acc], ["$dir/dot.png", 'image/png', 'dot.png'])['code'] === 403, 'another Rep may not attach to Priya\'s account (the edit rule)');
ok(req('GET', "/attachments/$aid")['code'] === 302 && html_as(28, "/attachments/$pid")['code'] === 200, 'no session: the launcher; anyone who sees the record may download');
$tok = run_token(40, 802);
$r = req('POST', '/attachments/add.php', ['headers' => as_agent($tok), 'form' => ['kind' => 'account', 'record' => (string) $acc, 'filename' => 'agent.txt', 'file_base64' => base64_encode('from the agent')]]);
ok($r['code'] === 403, 'an agent is held to the edit rule (not its account)');
as_member(26);
pdo()->exec("UPDATE accounts SET owner_member_id = NULL WHERE id = $acc");
$r = req('POST', '/attachments/add.php', ['headers' => as_agent($tok), 'form' => ['kind' => 'account', 'record' => (string) $acc, 'filename' => 'agent.txt', 'file_base64' => base64_encode('from the agent')]]);
ok($r['code'] === 200 && one("SELECT filename FROM attachments WHERE record_type = 'account' AND record_id = :a ORDER BY id DESC LIMIT 1", ['a' => $acc]) === 'agent.txt', 'on an unowned account the agent attaches in JSON (file_base64 + filename)');
pdo()->exec("UPDATE accounts SET owner_member_id = 26 WHERE id = $acc");
ok(post_as(30, '/attachments/delete.php', ['attachment' => $hid])['code'] === 403, 'another Rep may not delete Priya\'s file');
$path = $store . '/' . one('SELECT storage_path FROM attachments WHERE id = :i', ['i' => $hid]);
$r = post_as(26, '/attachments/delete.php', ['attachment' => $hid]);
ok($r['code'] === 200 && !is_file($path) && (int) one('SELECT count(*) FROM attachments WHERE id = :i', ['i' => $hid]) === 0, 'the uploader deletes it: the row and the bytes go');
$v = html_as(26, "/accounts/$acc?tab=notes")['body'];
ok(str_contains($v, 'id="attachment-' . $aid . '"') && str_contains($v, '25 MB') && str_contains($v, 'href="/attachments/' . $aid . '"'), 'the Files panel lists the file with its size and the gate\'s link');

echo "3. Tags\n";
$r = post_as(26, '/tags/apply.php', ['kind' => 'account', 'record' => $acc, 'tag' => "Strategic $x"]);
$tid = (int) ($r['json']['tag_id'] ?? 0);
ok($r['code'] === 200 && ($r['json']['tag_made'] ?? null) === true && one('SELECT slug FROM tags WHERE id = :i', ['i' => $tid]) === 'strategic-' . $x, 'a new label makes a tag (slugged)');
ok((int) one('SELECT usage_count FROM tags WHERE id = :i', ['i' => $tid]) === 1, 'its count is 1');
$r = post_as(26, '/tags/apply.php', ['kind' => 'account', 'record' => $acc, 'tag' => "strategic $x"]);
ok(($r['json']['did'] ?? '') === "It already carries Strategic $x" && (int) one('SELECT usage_count FROM tags WHERE id = :i', ['i' => $tid]) === 1, 'applying it again (any case) changes nothing and says so');
$ids = [];
foreach (['A', 'B', 'C'] as $n) { $ids[] = (int) post_as(26, '/accounts/save.php', ['name' => "Bulk $n $x"])['json']['record_id']; }
$r = post_as(26, '/tags/apply.php', ['kind' => 'account', 'records' => $ids, 'tag' => "Strategic $x"]);
ok($r['code'] === 200 && count($r['json']['applied'] ?? []) === 3 && (int) one('SELECT usage_count FROM tags WHERE id = :i', ['i' => $tid]) === 4, 'bulk apply to three: applied 3, the count 4');
ok(count(activity('tag.apply')) >= 4, 'one tag.apply row per record');
$r = post_as(26, '/tags/remove.php', ['kind' => 'account', 'record' => $ids[0], 'tag' => "Strategic $x"]);
ok($r['code'] === 200 && (int) one('SELECT usage_count FROM tags WHERE id = :i', ['i' => $tid]) === 3, 'tag_remove takes it off; the count follows');
ok(post_as(26, '/tags/remove.php', ['kind' => 'account', 'record' => $ids[0], 'tag' => 'no-such-tag-' . $x])['code'] === 422, 'removing a tag that does not exist is refused');
$r = post_as(30, '/tags/apply.php', ['kind' => 'account', 'records' => [$ids[1], $acc], 'tag' => "Hostile $x"]);
ok($r['code'] === 403 && (int) one('SELECT count(*) FROM tags WHERE label = :l', ['l' => "Hostile $x"]) === 0, 'a record the caller may not edit refuses the whole call — and no tag is made');
ok(post_as(29, '/tags/apply.php', ['kind' => 'account', 'record' => $acc, 'tag' => 'x'])['code'] === 403, 'a Viewer may not tag');
$v = html_as(26, "/accounts/$acc")['body'];
ok(str_contains($v, 'id="tag-chip-' . $tid . '"') && str_contains($v, 'id="tag-editor-field-tag"'), 'the page shows the chip and the add field');
$p = html_as(26, '/pick/?source=tag&q=' . rawurlencode("strategic $x"))['body'];
ok(str_contains($p, 'data-label="Strategic ' . $x . '"') && str_contains($p, '3 uses'), 'the tag picker (the editor\'s suggestions) offers it with its count');
finish();
