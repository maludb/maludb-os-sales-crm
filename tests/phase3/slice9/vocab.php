<?php
/**
 * Proof — sources, lost reasons and tags (admin-settings.md "Proof"): sources and reasons added, edited, deactivated (the picker stops offering them), delete refused when something names them — by name, counting what — and allowed when nothing does;
 * a tag renamed (the slug follows, the search finds it by the new name, a saved view's filter follows), merged (rows moved, counts right, a record with both keeps one), deleted (taken off every record); the rights.
 */
require __DIR__ . '/lib.php';
$x = run_tag();
$since = last_activity_id();

echo "1. Lead sources\n";
$r = adm('/admin/sources/save.php', ['name' => "Trade show $x", 'kind' => 'outbound', 'sort_order' => 55]);
$s = (int) ($r['json']['record_id'] ?? 0);
$row = q('SELECT * FROM lead_sources WHERE id = :i', ['i' => $s])[0] ?? [];
ok($r['code'] === 200 && $row['name'] === "Trade show $x" && $row['kind'] === 'outbound' && (int) $row['sort_order'] === 55 && $row['active'] === true, 'source_save adds a source: ' . ($r['json']['did'] ?? $r['body']));
$r = adm('/admin/sources/save.php', ['name' => strtolower("trade show $x")]);
ok($r['code'] === 422 && str_contains(says($r), 'already'), 'the same name in any case is refused: ' . message_of($r));
$r = adm('/admin/sources/save.php', ['name' => '', 'kind' => 'sideways']);
ok($r['code'] === 422 && isset($r['json']['error']['fields']['name']) && isset($r['json']['error']['fields']['kind']), 'an empty name and a bad kind: both fields named');
$r = adm('/admin/sources/save.php', ['source' => $s, 'name' => "Expo $x", 'sort_order' => 56]);
ok($r['code'] === 200 && one('SELECT name FROM lead_sources WHERE id = :i', ['i' => $s]) === "Expo $x" && (string) one('SELECT kind FROM lead_sources WHERE id = :i', ['i' => $s]) === 'outbound', 'a rename; the kind left out stays as it was');
$pickBody = html_as(26, '/pick/?source=source&q=' . urlencode($x))['body'];
ok(str_contains($pickBody, "Expo $x"), 'the record picker offers it');
$r = adm('/admin/sources/save.php', ['source' => $s, 'active' => 'no']);
ok($r['code'] === 200 && one('SELECT active FROM lead_sources WHERE id = :i', ['i' => $s]) === false && !str_contains(html_as(26, '/pick/?source=source&q=' . urlencode($x))['body'], "Expo $x"), 'deactivated: the picker stops offering it');
adm('/admin/sources/save.php', ['source' => $s, 'active' => 'yes']);
// named by a lead, a deal, a capture key and a rule
$lead = lead_make(26, ['source' => $s]);
$deal = deal_in(1, stage('Qualification'), "S9 deal $x", ['source_id' => $s]);
[$kid] = capture_key(1, $s, "S9 key $x");
$rules = json_encode([['name' => "S9 rule $x", 'match' => ['source_id' => [$s]], 'assign_kind' => 'member', 'member_id' => 27]]);
$rs = adm('/admin/rules/save.php', ['rules' => $rules]);
ok($rs['code'] === 200, 'a lead, a deal, a lead-capture key and a rule name the source');
$r = adm('/admin/sources/delete.php', ['source' => $s]);
ok($r['code'] === 422 && str_contains(says($r), "Expo $x is named by 1 lead, 1 deal, 1 lead-capture key and 1 assignment rule — deactivate it instead"), 'delete is refused by name, counting what names it: ' . message_of($r));
ok(exists_row('lead_sources', $s), '…the source is still there');
adm('/admin/rules/save.php', ['rules' => '[]']);
pg_run("UPDATE mcp_access_tokens SET revoked_at = now() WHERE id = $kid");
pg_run("UPDATE leads SET source_id = NULL WHERE id = $lead; UPDATE deals SET source_id = NULL WHERE id = $deal");
$r = adm('/admin/sources/delete.php', ['source' => $s]);
ok($r['code'] === 200 && !exists_row('lead_sources', $s), 'with nothing naming it the source is deleted');

echo "2. Lost reasons\n";
$r = adm('/admin/reasons/save.php', ['kind' => 'deal', 'label' => "Budget frozen $x", 'sort_order' => 15]);
$lr = (int) ($r['json']['record_id'] ?? 0);
ok($r['code'] === 200 && one('SELECT kind FROM lost_reasons WHERE id = :i', ['i' => $lr]) === 'deal', 'lost_reason_save adds a deal reason');
$r = adm('/admin/reasons/save.php', ['kind' => 'deal', 'label' => "budget frozen $x"]);
ok($r['code'] === 422 && str_contains(says($r), 'already'), 'a duplicate (any case) is refused');
$r = adm('/admin/reasons/save.php', ['kind' => 'lead', 'label' => "Budget frozen $x"]);
ok($r['code'] === 200, 'the same words as a lead reason are another reason');
$r = adm('/admin/reasons/save.php', ['lost_reason' => $lr, 'kind' => 'lead']);
ok($r['code'] === 422 && str_contains(says($r), 'stays a deal reason'), 'a reason keeps its kind: ' . message_of($r));
$r = adm('/admin/reasons/save.php', ['label' => 'No kind']);
ok($r['code'] === 422 && isset($r['json']['error']['fields']['kind']), 'a new reason needs its kind');
$r = adm('/admin/reasons/save.php', ['lost_reason' => $lr, 'active' => 'no', 'label' => "Funds frozen $x"]);
ok($r['code'] === 200 && one('SELECT active FROM lost_reasons WHERE id = :i', ['i' => $lr]) === false && one('SELECT label FROM lost_reasons WHERE id = :i', ['i' => $lr]) === "Funds frozen $x", 'renamed and deactivated');
adm('/admin/reasons/save.php', ['lost_reason' => $lr, 'active' => 'yes']);
$d = deal_in(1, stage('Qualification'), "R9 lost $x");
pg_run("UPDATE deals SET lost_reason_id = $lr WHERE id = $d");
$r = adm('/admin/reasons/delete.php', ['lost_reason' => $lr]);
ok($r['code'] === 422 && str_contains(says($r), "is named by 1 deal — deactivate it instead"), 'delete refused by name when a deal names it: ' . message_of($r));
pg_run("UPDATE deals SET lost_reason_id = NULL WHERE id = $d");
$r = adm('/admin/reasons/delete.php', ['lost_reason' => $lr]);
ok($r['code'] === 200 && !exists_row('lost_reasons', $lr), 'then it is deleted');
$page = screen(1, '/admin/sources');
ok(isset($page['sources'][0]['leads'], $page['reasons'][0]['deals']) && count($page['reasons']) >= 13, 'the screen shows each with what names it (JSON: sources, reasons)');
$mkt = role_actor(31, 'marketing');
ok(post_as(26, '/admin/sources/save.php', ['name' => 'x'])['code'] === 403 && post_role($mkt, '/admin/sources/save.php', ['name' => "Mkt $x"])['code'] === 200 && get_as(26, '/admin/sources')['code'] === 403 && get_role($mkt, '/admin/sources')['code'] === 200, 'a Rep may not; Marketing (sources.manage) may');

echo "3. Tags — rename\n";
$acc = [];
foreach (range(1, 4) as $i) { $acc[$i] = (int) post_as(26, '/accounts/save.php', ['name' => "Tagged$i $x"])['json']['record_id']; }
function tag_make(string $label): int { return (int) one('INSERT INTO tags (slug, label) VALUES (:s, :l) RETURNING id', ['s' => strtolower(preg_replace('/\W+/', '-', $label)), 'l' => $label]); }
function tag_on(int $tag, array $accounts): void { foreach ($accounts as $a) { pdo()->prepare("INSERT INTO record_tags (record_type, record_id, tag_id) VALUES ('account', :a, :t) ON CONFLICT DO NOTHING")->execute(['a' => $a, 't' => $tag]); } }
$ta = tag_make("alpha$x"); $tb = tag_make("beta$x"); $tc = tag_make("gamma$x");
tag_on($ta, [$acc[1], $acc[2], $acc[3]]); tag_on($tb, [$acc[3], $acc[4]]); tag_on($tc, [$acc[1]]);
pg_run("INSERT INTO saved_views (name, scope, owner_member_id, record_kind, filters) VALUES ('S9 view $x', 'personal', 26, 'account', '{\"tag\": \"alpha$x\"}')");
$r = adm('/admin/tags/save.php', ['tag' => $ta, 'label' => "Alpha Prime $x"]);
$tr = q('SELECT slug, label, usage_count FROM tags WHERE id = :i', ['i' => $ta])[0];
ok($r['code'] === 200 && $tr['label'] === "Alpha Prime $x" && $tr['slug'] === strtolower("alpha-prime-$x"), 'tag_save renames: the label changes and the slug follows: ' . $tr['slug']);
$found = get_as(26, '/search?q=' . urlencode("Prime $x"));
ok(str_contains($found['body'], "Tagged1 $x"), 'the search finds the tagged records by the new name');
ok(one("SELECT filters ->> 'tag' FROM saved_views WHERE name = :n", ['n' => "S9 view $x"]) === "Alpha Prime $x", 'a saved view filtering by the old name follows it');
$r = adm('/admin/tags/save.php', ['tag' => $ta, 'label' => "BETA$x"]);
ok($r['code'] === 422 && str_contains(says($r), 'merge them instead'), 'a name another tag has is a merge, not a rename: ' . message_of($r));
$r = adm('/admin/tags/save.php', ['tag' => $ta, 'label' => '']);
ok($r['code'] === 422 && field_of($r) === 'label', 'an empty name names its field');
$r = adm('/admin/tags/save.php', ['tag' => $ta, 'label' => "Alpha Prime $x"]);
ok($r['code'] === 200 && ($r['json']['did'] ?? '') === 'Nothing to change', 'the same name changes nothing');

echo "4. Tags — merge, delete\n";
$r = adm('/admin/tags/merge.php', ['tag' => $ta, 'into' => $ta]);
ok($r['code'] === 422 && str_contains(says($r), 'another tag'), 'a tag is not merged into itself');
$r = adm('/admin/tags/merge.php', ['tag' => $ta]);
ok($r['code'] === 422 && field_of($r) === 'into', 'no target names the field');
$r = adm('/admin/tags/merge.php', ['tag' => $ta, 'into' => $tb]);
$onB = array_map('intval', array_column(q("SELECT record_id FROM record_tags WHERE tag_id = :t AND record_type = 'account' ORDER BY record_id", ['t' => $tb]), 'record_id'));
ok($r['code'] === 200 && $onB === [$acc[1], $acc[2], $acc[3], $acc[4]] && !exists_row('tags', $ta) && (int) q('SELECT count(*) AS n FROM record_tags WHERE tag_id = :t', ['t' => $ta])[0]['n'] === 0, 'tag_merge moves the rows (the one that carried both keeps one) and the merged tag is gone: ' . ($r['json']['did'] ?? $r['body']));
ok((int) one('SELECT usage_count FROM tags WHERE id = :i', ['i' => $tb]) === 4 && (int) one('SELECT count(*) FROM record_tags WHERE tag_id = :t', ['t' => $tb]) === 4, 'the survivor\'s count is the rows: 4');
ok(one("SELECT filters ->> 'tag' FROM saved_views WHERE name = :n", ['n' => "S9 view $x"]) === "BETA$x" || one("SELECT filters ->> 'tag' FROM saved_views WHERE name = :n", ['n' => "S9 view $x"]) === "beta$x", 'a saved view follows the merge to the survivor\'s name');
ok(str_contains(get_as(26, '/search?q=' . urlencode("beta$x"))['body'], "Tagged2 $x"), 'a record that carried the merged tag is found under the survivor');
$r = adm('/admin/tags/delete.php', ['tag' => $tc]);
ok($r['code'] === 200 && !exists_row('tags', $tc) && (int) one("SELECT count(*) FROM record_tags WHERE tag_id = :t", ['t' => $tc]) === 0 && str_contains($r['json']['did'], '1 record'), 'tag_delete takes the tag off every record and deletes it: ' . $r['json']['did']);
$r = adm('/admin/tags/delete.php', ['tag' => $tc]);
ok($r['code'] === 404, 'a tag already gone is 404');
$s = screen(1, '/admin/tags?q=' . urlencode($x));
ok(isset($s['tags'], $s['total']) && array_column($s['tags'], 'label') === ["beta$x"] && $s['tags'][0]['usage_count'] === 4, 'the screen lists the vocabulary with its counts (narrowed by ?q=)');
ok(get_as(26, '/admin/tags')['code'] === 403 && post_as(26, '/admin/tags/delete.php', ['tag' => $tb])['code'] === 403 && get_role($mkt, '/admin/tags')['code'] === 200, 'a Rep may not; Marketing (tags.manage) may');
$pick = req('GET', '/pick/?source=tag&q=beta', ['jar' => $mkt[0]]);
ok($pick['code'] === 200 && str_contains($pick['body'], "beta$x"), 'the tag picker admits a holder of tags.manage and offers the merge target');
$ev = array_unique(array_column(q('SELECT action FROM activity_log WHERE id > :s', ['s' => $since]), 'action'));
foreach (['source.save', 'source.delete', 'reason.save', 'reason.delete', 'tag.save', 'tag.merge', 'tag.delete'] as $e) { ok(in_array($e, $ev, true), "$e written"); }
finish();
