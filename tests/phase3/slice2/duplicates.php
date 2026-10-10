<?php
/**
 * Proof — duplicates and the merge (leads-and-conversion.md §5, §8): the duplicates page shows the pairs by what matched (only pairs the caller sees both of);
 * the merge page puts the two side by side with the older chosen; a merge keeps the survivor's values and takes the loser's where empty, moves the children
 * (notes, tags, the deal's contact, the activities) and leaves the loser a tombstone pointing at it; "Not a duplicate" hides the pair; a Rep may not merge;
 * an agent's records_merge pauses (deletion).
 */
require __DIR__ . '/lib.php';
$x = run_tag();
as_member(26);                                                             // the views answer an admitted member only
setting('duplicates_policy', "'warn'");
$acc = (int) post_as(26, '/accounts/save.php', ['name' => "Dupe Co $x"])['json']['record_id'];
$older = (int) post_as(26, '/contacts/save.php', ['first_name' => 'Pat', 'last_name' => "Twin $x", 'email' => "pat.$x@dupe.example.com", 'account' => $acc])['json']['record_id'];
$newer = (int) post_as(26, '/contacts/save.php', ['first_name' => 'Patricia', 'last_name' => "Twin $x", 'email' => "pat.$x@dupe.example.com", 'title' => 'CFO', 'phone' => '+1 555 0101'])['json']['record_id'];
post_as(26, '/notes/add.php', ['kind' => 'contact', 'record' => $newer, 'body' => 'Note on the newer one']);
post_as(26, '/tags/apply.php', ['kind' => 'contact', 'record' => $newer, 'tag' => "twin-$x"]);
as_member(26);
$deal = (int) one("INSERT INTO deals (title, account_id, primary_contact_id, pipeline_id, stage_id, owner_member_id, created_by) SELECT 'Twin deal $x', $acc, $newer, p.id, s.id, 26, 26 FROM pipelines p JOIN stages s ON s.pipeline_id = p.id AND s.kind = 'open' WHERE p.key = 'new_business' ORDER BY s.position LIMIT 1 RETURNING id");
$hidden = (int) post_as(27, '/contacts/save.php', ['first_name' => 'Secret', 'last_name' => "Twin $x", 'email' => "secret.$x@dupe.example.com"])['json']['record_id'];
post_as(27, '/records/restrict.php', ['kind' => 'contact', 'record' => $hidden]);
$hidden2 = (int) post_as(27, '/contacts/save.php', ['first_name' => 'Secret', 'last_name' => "Twin $x", 'email' => "secret.$x@dupe.example.com"])['json']['record_id'];

echo "1. The pairs\n";
$pairs = get_as(26, '/duplicates?kind=contact')['json']['data']['pairs'];
$pair = array_values(array_filter($pairs, static fn ($p) => $p['a']['id'] === $older && $p['b']['id'] === $newer));
ok(count($pair) === 1 && $pair[0]['matched_on'] === 'email', 'the duplicates page lists the pair, matched on the email');
ok(array_filter($pairs, static fn ($p) => in_array($hidden, [$p['a']['id'], $p['b']['id']], true)) === [], 'a pair the caller cannot see both of is not listed');
ok(array_filter(get_as(27, '/duplicates?kind=contact')['json']['data']['pairs'], static fn ($p) => in_array($hidden, [$p['a']['id'], $p['b']['id']], true)) !== [], '…the manager sees it');
$h = html_as(26, '/duplicates?kind=contact')['body'];
ok(str_contains($h, 'id="duplicates-pair-' . $older . '-' . $newer . '"') && str_contains($h, '<mark>pat.' . $x . '@dupe.example.com</mark>'), 'the matching field is highlighted on both sides');
ok(!str_contains($h, 'id="duplicates-pair-' . $older . '-' . $newer . '-merge"'), 'a Rep sees no Merge button (records.merge is a manager\'s)');

echo "2. The merge\n";
$m = get_as(27, "/duplicates/merge?kind=contact&a=$older&b=$newer")['json']['data'];
ok($m['survivor'] === $older && $m['differs']['name'] === true && $m['differs']['email'] === false, 'the merge page: the older is kept by default; the differing fields are marked');
ok(str_contains(html_as(27, "/duplicates/merge?kind=contact&a=$older&b=$newer")['body'], 'id="merge-survivor-a" checked'), '…its radio checked');
ok(post_as(26, '/duplicates/merge.php', ['kind' => 'contact', 'survivor' => $older, 'loser' => $newer])['code'] === 403, 'a Rep may not merge: 403');
$r = post_as(27, '/duplicates/merge.php', ['kind' => 'contact', 'a' => $older, 'b' => $newer, 'survivor' => $older]);
ok($r['code'] === 200 && (int) $r['json']['record_id'] === $older && $r['json']['loser'] === $newer, 'records_merge with the pair and the survivor: the loser is the other');
$s = q('SELECT first_name, title, phone FROM contacts WHERE id = :i', ['i' => $older])[0];
ok($s['first_name'] === 'Pat' && $s['title'] === 'CFO' && $s['phone'] === '+1 555 0101', 'the survivor keeps its values (Pat) and takes the loser\'s where empty (CFO, the phone)');
ok((int) one("SELECT count(*) FROM notes WHERE record_type = 'contact' AND record_id = :i", ['i' => $older]) === 1 && one("SELECT array_to_string(crm_tags_of('contact', :i), ',')", ['i' => $older]) === "twin-$x", 'the note and the tag moved to the survivor');
ok((int) one('SELECT primary_contact_id FROM deals WHERE id = :d', ['d' => $deal]) === $older, 'the deal\'s contact moved to the survivor');
ok(one('SELECT merged_into_id FROM contacts WHERE id = :i', ['i' => $newer]) === $older && one('SELECT deleted_at IS NOT NULL FROM contacts WHERE id = :i', ['i' => $newer]), 'the loser is a tombstone pointing at the survivor');
$a = activity('records.merge');
ok(json_decode((string) end($a)['after'], true)['loser'] === $newer && (int) end($a)['contact_id'] === $older, 'logged records.merge with the survivor as the audit key');
ok(post_as(27, '/duplicates/merge.php', ['kind' => 'contact', 'survivor' => $older, 'loser' => $older])['code'] === 422, 'a record is not merged into itself');
$l1 = (int) post_as(26, '/leads/save.php', ['first_name' => 'Lee', 'email' => "lee.$x@leads.example.com", 'owner' => 26])['json']['record_id'];
$l2 = (int) post_as(26, '/leads/save.php', ['first_name' => 'Lee', 'last_name' => 'Two', 'email' => "lee.$x@leads.example.com", 'owner' => 26])['json']['record_id'];
ok(array_filter(get_as(27, '/duplicates?kind=lead')['json']['data']['pairs'], static fn ($p) => $p['a']['id'] === $l1 && $p['b']['id'] === $l2) !== [], 'leads pair by email too');
ok(post_as(27, '/duplicates/merge.php', ['kind' => 'lead', 'survivor' => $l1, 'loser' => $l2])['code'] === 200 && one('SELECT last_name FROM leads WHERE id = :i', ['i' => $l1]) === 'Two', 'and merge');

echo "3. Not a duplicate\n";
$a1 = (int) post_as(26, '/accounts/save.php', ['name' => "Same Name $x Inc"])['json']['record_id'];
$a2 = (int) post_as(26, '/accounts/save.php', ['name' => "Same Name $x Ltd"])['json']['record_id'];
$has = static fn (int $m) => array_filter(get_as($m, '/duplicates?kind=account')['json']['data']['pairs'], static fn ($p) => $p['a']['id'] === $a1 && $p['b']['id'] === $a2) !== [];
ok($has(26), 'two accounts with a name alike are a pair');
ok(post_as(30, '/duplicates/dismiss.php', ['kind' => 'account', 'a' => $a1, 'b' => $a2])['code'] === 403, 'another Rep (who may change neither) may not dismiss it');
$r = post_as(26, '/duplicates/dismiss.php', ['kind' => 'account', 'a' => $a2, 'b' => $a1]);
ok($r['code'] === 200 && !$has(26) && !$has(27), 'Priya (who may change both) says not a duplicate: the pair is hidden for everyone');
ok(one('SELECT a_id || \'-\' || b_id FROM duplicate_dismissals WHERE record_kind = \'account\' AND a_id = :a', ['a' => $a1]) === "$a1-$a2", 'kept with the smaller id first');
ok(post_as(26, '/duplicates/dismiss.php', ['kind' => 'account', 'a' => $a1, 'b' => $a2])['json']['did'] === 'That pair was dismissed before', 'dismissing it again says so');

echo "4. An agent's merge pauses\n";
$manifest = json_decode((string) file_get_contents(dirname(__DIR__, 3) . '/maludb-os.json'), true);
$cats = [];
foreach ($manifest['approvals'] ?? [] as $ap) { $cats[$ap['action'] ?? ''] = $ap['category'] ?? null; }
ok(($cats['records_merge'] ?? null) === 'deletion' && ($cats['lead_dismiss_proposed'] ?? null) === 'deletion' && ($cats['import_rollback'] ?? null) === 'deletion', 'maludb-os.json pauses records_merge, lead_dismiss_proposed and import_rollback for agents');
ok(!isset($cats['duplicate_dismiss']) && !isset($cats['lead_convert']) && !isset($cats['import_start']), '…and not dismissing a pair, converting or starting an import');
finish();
