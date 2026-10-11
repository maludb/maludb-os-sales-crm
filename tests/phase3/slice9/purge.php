<?php
/**
 * Proof — the tombstones and the purge (admin-settings.md "Proof"): the screen counting what is due and what waits, records_purge destroying only what has waited PAST the window (a tombstone inside it survives every call, a `before` can only narrow),
 * the children going with a record (notes, tags, the stored file), an account that still has people or deals kept, the count by kind answered and logged without a name — and the worker's passes `tombstones_purge` and `key_usage_prune`, real now.
 */
require __DIR__ . '/lib.php';
$x = run_tag();
$since = last_activity_id();
pg_run("UPDATE crm_settings SET tombstone_days = 30");
$gone = fn (string $table, int $id): bool => !exists_row($table, $id);

echo "1. The screen\n";
$late = ['lead' => tombstone('lead', 40, "PurgeLateLead$x"), 'contact' => tombstone('contact', 40, "PurgeLate$x"), 'deal' => tombstone('deal', 40, "Purge late deal $x"), 'account' => tombstone('account', 40, "Purge late acct $x"), 'activity' => tombstone('activity', 40, "Purge late act $x")];
$early = ['lead' => tombstone('lead', 10, "PurgeEarlyLead$x"), 'contact' => tombstone('contact', 10, "PurgeEarly$x"), 'deal' => tombstone('deal', 10, "Purge early deal $x"), 'account' => tombstone('account', 10, "Purge early acct $x"), 'activity' => tombstone('activity', 10, "Purge early act $x")];
$old2 = tombstone('lead', 100, "PurgeOldLead$x");
$s = screen(1, '/admin/purge');
ok($s['days'] === 30 && $s['kinds']['lead']['due'] >= 2 && $s['kinds']['lead']['waiting'] >= 1 && $s['due'] >= 6 && $s['waiting'] >= 5 && $s['next_purge_at'] !== null, 'JSON: the window (30 days), the counts due and waiting by kind, the date the next one is purged');
$names = array_column($s['tombstones'], 'name');
ok(in_array("Purge late deal $x", $names, true) && in_array("Purge early act $x", $names, true), 'the tombstones are listed with their names (the five kinds, activities too)');
$row = array_values(array_filter($s['tombstones'], static fn ($t) => $t['name'] === "Purge early deal $x"))[0];
ok($row['due'] === false && $row['purges_at'] !== null && substr($row['purges_at'], 0, 10) === gmdate('Y-m-d', strtotime('+20 days')), 'a record deleted 10 days ago is purged in 20: ' . $row['purges_at']);
$h = html_as(1, '/admin/purge')['body'];
ok(str_contains($h, 'id="purge-list"') && str_contains($h, 'id="purge-lead-due"') && str_contains($h, 'id="purge-now-btn"') && !str_contains($h, 'id="purge-now-btn" disabled'), 'the page: the counts by kind, the list, Purge now (enabled: something is due)');
ok(count(screen(1, '/admin/purge?kind=lead')['tombstones']) === count(array_filter($s['tombstones'], static fn ($t) => $t['record_kind'] === 'lead')), '?kind= narrows the list');
ok(str_contains(html_as(1, '/admin')['body'], 'id="admin-tombstones-due"'), 'the admin home says records are due');

echo "2. Children go with a record\n";
$deal = $late['deal'];
pg_run("INSERT INTO notes (record_type, record_id, member_id, body) VALUES ('deal', $deal, 26, 'purge note $x')");
pg_run("INSERT INTO record_tags (record_type, record_id, tag_id) SELECT 'deal', $deal, id FROM tags LIMIT 1");
$store = need('CRM_STORAGE_DIR');
$rel = "attachments/deal/$deal/abc123abc123-doc.txt";
@mkdir("$store/attachments/deal/$deal", 0750, true);
file_put_contents("$store/$rel", 'purge me');
pg_run("INSERT INTO attachments (record_type, record_id, filename, mime_type, byte_size, sha256, storage_path, uploaded_by) VALUES ('deal', $deal, 'doc.txt', 'text/plain', 8, 'abc123abc123abc123abc123abc123abc123abc123abc123abc123abc123abcd', '$rel', 26)");
$keepRel = "attachments/deal/{$early['deal']}/abc123abc123-keep.txt";
@mkdir("$store/attachments/deal/{$early['deal']}", 0750, true);
file_put_contents("$store/$keepRel", 'keep me');
pg_run("INSERT INTO attachments (record_type, record_id, filename, mime_type, byte_size, sha256, storage_path, uploaded_by) VALUES ('deal', {$early['deal']}, 'keep.txt', 'text/plain', 7, 'def123abc123abc123abc123abc123abc123abc123abc123abc123abc123abcd', '$keepRel', 26)");
pg_run("INSERT INTO activities (kind, subject, deal_id, owner_member_id, created_by, due_at) VALUES ('task', 'live activity of a purged deal $x', $deal, 26, 26, now())");
$acct2 = tombstone('account', 40, "Purge kept acct $x");
pg_run("INSERT INTO contacts (first_name, last_name, account_id, owner_member_id, created_by) VALUES ('Live', 'Contact $x', $acct2, 26, 26)");

echo "3. Purge now — one kind, the window, a date that only narrows\n";
$r = adm('/admin/purge.php', ['kind' => 'lead']);
ok($r['code'] === 200 && ($r['json']['purged']['lead'] ?? -1) >= 2 && $gone('leads', $late['lead']) && $gone('leads', $old2) && exists_row('leads', $early['lead']), 'records_purge kind=lead takes the leads past the window (40 and 100 days) and leaves the one inside it (10 days): ' . ($r['json']['did'] ?? $r['body']));
ok(exists_row('contacts', $late['contact']) && exists_row('deals', $late['deal']), 'and nothing of another kind');
$r = adm('/admin/purge.php', ['kind' => 'contact', 'before' => gmdate('Y-m-d', strtotime('-60 days'))]);
ok($r['code'] === 200 && ($r['json']['purged']['contact'] ?? -1) === 0 && exists_row('contacts', $late['contact']), 'before = 60 days ago: the contact deleted 40 days ago is not old enough — it stays');
$r = adm('/admin/purge.php', ['kind' => 'contact', 'before' => gmdate('Y-m-d', strtotime('+365 days'))]);
ok($r['code'] === 200 && $gone('contacts', $late['contact']) && exists_row('contacts', $early['contact']), 'before = next year: the window\'s edge still holds — the past-window contact goes, the one 10 days old survives');
$r = adm('/admin/purge.php', ['kind' => 'sideways']);
ok($r['code'] === 422 && field_of($r) === 'kind', 'a kind that is none names its field');
$r = adm('/admin/purge.php', ['before' => '30/11/2026']);
ok($r['code'] === 422 && field_of($r) === 'before', 'a date that is no date names its field');

echo "4. Purge now — every kind\n";
$since2 = last_activity_id();
$r = adm('/admin/purge.php', []);
$p = $r['json']['purged'] ?? [];
ok($r['code'] === 200 && $p['deal'] >= 1 && $p['account'] >= 1 && $p['activity'] >= 1, 'with no kind every kind is taken: ' . json_encode($p));
ok($gone('deals', $late['deal']) && $gone('activities', $late['activity']) && $gone('accounts', $late['account']), 'the past-window deal, activity and account are gone');
ok(exists_row('deals', $early['deal']) && exists_row('activities', $early['activity']) && exists_row('accounts', $early['account']) && exists_row('leads', $early['lead']) && exists_row('contacts', $early['contact']), 'and EVERY tombstone inside the window survived (a lead, contact, deal, account and activity, 10 days old)');
ok(exists_row('accounts', $acct2), 'an account that still has a contact is kept even past the window');
ok((int) one("SELECT count(*) FROM notes WHERE record_type = 'deal' AND record_id = $deal") === 0 && (int) one("SELECT count(*) FROM record_tags WHERE record_type = 'deal' AND record_id = $deal") === 0 && (int) one("SELECT count(*) FROM attachments WHERE record_type = 'deal' AND record_id = $deal") === 0, 'the deal\'s notes, tags and attachment rows went with it');
clearstatcache();
ok(!is_file("$store/$rel") && !is_dir("$store/attachments/deal/$deal") && is_file("$store/$keepRel"), 'and its stored file with them (the bytes), while the file of a record inside the window stays');
ok((int) one("SELECT count(*) FROM activities WHERE subject = :s", ['s' => "live activity of a purged deal $x"]) === 1, 'a live activity that pointed at the purged deal stays (it just points at nothing)');
$lg = q("SELECT after::text AS a, source FROM activity_log WHERE action = 'records.purge' AND id > :s ORDER BY id", ['s' => $since2]);
$la = json_decode($lg[0]['a'] ?? '{}', true);
ok(count($lg) === 1 && $la['purged'] === $p && $la['total'] === array_sum($p) && $la['kind'] === 'all' && $la['files'] >= 1 && !str_contains($lg[0]['a'], $x), 'records.purge logs the count by kind and the files — and no name: ' . $lg[0]['a']);
$again = adm('/admin/purge.php', []);
ok($again['code'] === 200 && array_sum($again['json']['purged']) === 0 && str_contains($again['json']['did'], 'Nothing was due'), 'purging again: "Nothing was due"');
$s = screen(1, '/admin/purge');
ok($s['kinds']['deal']['waiting'] >= 1 && $s['kinds']['lead']['waiting'] >= 1, 'the screen still counts the ones that wait');
adm('/admin/purge.php', ['kind' => 'account']);
ok(exists_row('accounts', $acct2), '(and the account with a contact is still there after an account purge)');

echo "5. The old function, and the setting\n";
$t = tombstone('lead', 40, "OldFn$x");
$n = (int) one('SELECT crm_tombstones_purge(200)');
ok($n >= 1 && $gone('leads', $t), 'crm_tombstones_purge(200) (db/013\'s signature) still answers the total and takes what is past the window');
pg_run("UPDATE crm_settings SET tombstone_days = 7");
$t8 = tombstone('lead', 8, "Shorter$x");
$r = adm('/admin/purge.php', ['kind' => 'lead']);
ok($gone('leads', $t8) && $gone('leads', $early['lead']), 'tombstone_days is the window: at 7 days the lead deleted 8 days ago and the one deleted 10 days ago are both due and go');
pg_run("UPDATE crm_settings SET tombstone_days = 30");

echo "6. The worker's passes\n";
$wl = tombstone('lead', 45, "WorkerLate$x"); $wd = tombstone('deal', 45, "WorkerLateDeal$x"); $we = tombstone('lead', 3, "WorkerEarly$x");
$extra = [tombstone('lead', 50, "WLimit1$x"), tombstone('lead', 50, "WLimit2$x"), tombstone('lead', 50, "WLimit3$x")];
$since3 = last_activity_id();
$w = json_decode((string) shell_exec('php ' . escapeshellarg(dirname(__DIR__, 3) . '/bin/worker.php') . ' --passes=tombstones_purge --limit=2 2>&1'), true) ?: [];
$pass = $w['ran']['tombstones_purge'] ?? [];
ok(empty($pass['stub']) && isset($pass['purged'], $pass['by_kind']) && ($w['errors'] ?? ['x']) === [] && $pass['by_kind']['lead'] === 2, 'the pass is real (no stub flag) and --limit=2 takes at most two of a kind: ' . json_encode($pass));
ok(exists_row('leads', $we) && exists_row('accounts', $acct2), 'a tombstone inside the window survives the worker');
$w = json_decode((string) shell_exec('php ' . escapeshellarg(dirname(__DIR__, 3) . '/bin/worker.php') . ' --passes=tombstones_purge 2>&1'), true) ?: [];
ok($gone('leads', $wl) && $gone('deals', $wd) && !array_filter($extra, fn ($id) => exists_row('leads', $id)), 'the next pass takes the rest');
$lg = q("SELECT after::text AS a, source, actor_member_id FROM activity_log WHERE action = 'records.purge' AND id > :s ORDER BY id", ['s' => $since3]);
ok(count($lg) === 2 && $lg[0]['source'] === 'cron' && $lg[0]['actor_member_id'] === null && !str_contains(json_encode($lg), $x) && json_decode($lg[0]['a'], true)['purged']['lead'] === 2, 'each pass that took something logged records.purge from cron with the counts by kind, no names');
$w = json_decode((string) shell_exec('php ' . escapeshellarg(dirname(__DIR__, 3) . '/bin/worker.php') . ' --passes=tombstones_purge 2>&1'), true) ?: [];
ok($w['ran']['tombstones_purge']['purged'] === 0 && (int) one("SELECT count(*) FROM activity_log WHERE action = 'records.purge' AND id > :s", ['s' => $since3]) === 2, 'a pass with nothing to take logs nothing');

echo "7. Key usage pruning\n";
[$kid] = capture_key(1, source_id('Website'), "Prune $x");
pg_run("INSERT INTO key_usage (token_id, bucket_kind, bucket_start, calls) VALUES ($kid, 'minute', now() - interval '3 days', 4), ($kid, 'minute', date_trunc('minute', now()) - interval '1 hour', 2), ($kid, 'day', date_trunc('day', now()) - interval '40 days', 9), ($kid, 'day', date_trunc('day', now()) - interval '10 days', 7)");
$w = json_decode((string) shell_exec('php ' . escapeshellarg(dirname(__DIR__, 3) . '/bin/worker.php') . ' --passes=key_usage_prune 2>&1'), true) ?: [];
$left = q('SELECT bucket_kind, calls FROM key_usage WHERE token_id = :k ORDER BY bucket_kind, calls', ['k' => $kid]);
ok(empty($w['ran']['key_usage_prune']['stub']) && $w['ran']['key_usage_prune']['pruned'] >= 2 && array_column($left, 'calls') === ['7', '2'] || array_map('intval', array_column($left, 'calls')) === [7, 2], 'the pass is real: the minute bucket older than a day and the day bucket older than 35 days went (' . json_encode($w['ran']['key_usage_prune'] ?? $w) . '), the recent ones stayed: ' . json_encode($left));
$stubs = shell_exec('grep -n "\'stub\' => true" ' . escapeshellarg(dirname(__DIR__, 3) . '/bin/worker.php'));
ok(trim((string) $stubs) === '', 'bin/worker.php has no stub pass left');
$all = json_decode((string) shell_exec('php ' . escapeshellarg(dirname(__DIR__, 3) . '/bin/worker.php') . ' --passes=tombstones_purge,key_usage_prune 2>&1'), true) ?: [];
ok(array_filter($all['ran'] ?? [], static fn ($r) => !empty($r['stub'])) === [] && ($all['errors'] ?? ['x']) === [], 'and no pass reports a stub');

echo "8. Rights\n";
foreach ([26 => 'a Rep', 27 => 'the Sales Manager', 29 => 'a Viewer'] as $m => $who) {
    ok(get_as($m, '/admin/purge')['code'] === 403 && post_as($m, '/admin/purge.php', [])['code'] === 403, "$who: the screen and the purge are 403");
}
ok(get_role(role_actor(31, 'marketing'), '/admin/purge')['code'] === 403, 'Marketing too');
ok(agent_post(40, 9801, '/admin/purge.php', [])['code'] === 403, 'an agent without the right is refused');
finish();
