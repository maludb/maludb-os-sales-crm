<?php
/**
 * Proof — the filter vocabulary (campaigns-lists.md divergence 2: app/features/lists/filters.php is the ONE place it is defined): a smart list's filter builder round-trips for each record kind — the form's
 * filter[…] stored as the screen's own keys, the edit form showing them again, the stored object posted back unchanged — and its LIVE COUNT is exactly what the kind's screen shows for the same filters; the saved
 * views' older spellings are read; an unknown key or a value the parser rejects is dropped; a smart list needs a filter; "mine" means whoever opens it; the worker refreshes the stored counts hourly as the owner.
 */
require __DIR__ . '/lib.php';
$x = run_tag();
$since = last_activity_id();
$site = (int) one("SELECT id FROM lead_sources WHERE name = 'Website'");
$event = (int) one("SELECT id FROM lead_sources WHERE name = 'Event'");
$stored = static fn (int $list): array => json_decode((string) one('SELECT filters::text FROM lists WHERE id = :i', ['i' => $list]), true) ?: [];
$smart = static function (int $m, string $name, string $kind, array $filter, array $extra = []) {
    return post_as($m, '/lists/save.php', ['name' => $name, 'kind' => 'smart', 'record_kind' => $kind, 'filter' => $filter] + $extra);
};
$total = static fn (int $m, string $url): int => (int) (get_as($m, $url)['json']['data']['total'] ?? -1);

echo "1. Leads\n";
$camp = campaign_make(['name' => "Vocab camp $x"]);
$p1 = lead_make(26, ['company' => "P1 $x", 'source' => $site]);
$p2 = lead_make(26, ['company' => "P2 $x", 'source' => $event]);
$p3 = lead_make(26, ['company' => "P3 $x", 'campaign' => $camp]);
$d1 = lead_make(30, ['company' => "D1 $x"]);
post_as(26, '/notes/add.php', ['kind' => 'lead', 'record' => $p2, 'body' => 'touched']);
post_as(26, '/notes/add.php', ['kind' => 'lead', 'record' => $p3, 'body' => 'touched']);
post_as(26, '/tags/apply.php', ['kind' => 'lead', 'record' => $p3, 'tag' => "hot-$x"]);
pg_run("UPDATE leads SET created_at = now() - interval '60 days' WHERE id = $p1");
$cases = [
    ['status+owner', ['status' => 'working', 'owner' => 'me'], '/leads?status=working&owner=me', null],
    ['tag', ['tag' => "hot-$x"], "/leads?tag=hot-$x", 1],
    ['source', ['source' => (string) $site], "/leads?source=$site", null],
    ['campaign', ['campaign' => (string) $camp], "/leads?campaign=$camp", 1],
    ['untouched', ['untouched_days' => '30', 'owner' => 'me'], '/leads?untouched_days=30&owner=me', null],
    ['text', ['q' => "P2 $x"], '/leads?q=' . rawurlencode("P2 $x"), null],
];
foreach ($cases as [$label, $f, $screen, $want]) {
    $r = $smart(26, "Leads $label $x", 'lead', $f);
    $id = (int) ($r['json']['record_id'] ?? 0);
    ok($r['code'] === 200 && $id > 0, "lead filter $label: the list is made (" . ($r['json']['did'] ?? $r['body']) . ')');
    $st = $stored($id);
    $same = true;
    foreach ($f as $k => $v) { $same = $same && (string) ($st[$k] ?? '') === (string) $v; }
    ok($same && count($st) === count($f), "…stored as the screen's own keys: " . json_encode($st));
    $live = $total(26, "/lists/$id");
    $scr = $total(26, $screen);
    ok($live === $scr && ($want === null || $live === $want), "…its live count ($live) is what the Leads screen shows for the same filters ($scr)" . ($want === null ? '' : ", and $want"));
    ok((int) one('SELECT member_count FROM lists WHERE id = :i', ['i' => $id]) === $live, '…and the stored count was taken when it was made');
    $re = post_as(26, '/lists/save.php', ['list' => $id, 'kind' => 'smart', 'record_kind' => 'lead', 'filters' => json_encode($st)]);
    ok($re['code'] === 200 && $stored($id) === $st, '…the stored object posted back (filters=) changes nothing');
}
$id = (int) one("SELECT id FROM lists WHERE name = :n", ['n' => "Leads status+owner $x"]);
$edit = html_as(26, "/lists/$id/edit")['body'];
ok(str_contains($edit, 'id="filter-builder"') && preg_match('/<option value="working" selected>/', $edit) === 1 && preg_match('/<option value="me" selected>Mine/', $edit) === 1 && str_contains($edit, 'id="filter-row-0"'), 'the edit form shows them again: status Working selected, owner Mine selected, rows #filter-row-0…');
$idSrc = (int) one("SELECT id FROM lists WHERE name = :n", ['n' => "Leads source $x"]);
$edit = html_as(26, "/lists/$idSrc/edit")['body'];
ok(preg_match('/name="filter\[source\]" id="filter-field-source" value="' . $site . '"/', $edit) === 1 && str_contains($edit, 'Website'), 'a record filter is a picker (not a select of rows) holding the source and its name: Website');
$idTag = (int) one("SELECT id FROM lists WHERE name = :n", ['n' => "Leads tag $x"]);
ok(preg_match('/id="filter-field-tag" name="filter\[tag\]" value="hot-' . $x . '"/', html_as(26, "/lists/$idTag/edit")['body']) === 1, 'a text filter holds its text');
$edit = html_as(26, '/lists/new?record_kind=lead&kind=smart')['body'];
foreach (['status', 'owner', 'source', 'campaign', 'tag', 'untouched_days', 'q'] as $k) { ok(str_contains($edit, 'name="filter[' . $k . ']"'), "the new smart lead list offers $k"); }
ok(!str_contains($edit, 'filter[min_value]') && str_contains($edit, 'id="filter-preview-wrap"'), 'only the lead vocabulary, and a live count that refreshes (#filter-preview-wrap)');
$pv = html_as(26, '/lists/new?preview=1&record_kind=lead&filter[tag]=hot-' . $x . '&filter[owner]=me', ['HX-Request: true'])['body'];
ok(preg_match('/id="filter-preview-count">1</', $pv) === 1 && !str_contains($pv, '<html'), 'the live count as the fields stand (HTMX): 1 lead matches now');
ok($total(27, "/lists/$id") === $total(27, '/leads?status=working&owner=me') && $total(27, "/lists/$id") !== $total(26, "/lists/$id"), '"Mine" means whoever opens it: Marco reads the same list as his own leads (' . $total(27, "/lists/$id") . '), not Priya\'s (' . $total(26, "/lists/$id") . ')');

echo "2. Contacts\n";
$acc = (int) post_as(26, '/accounts/save.php', ['name' => "Vocab Acct $x", 'industry' => 'Software', 'country' => 'DE'])['json']['record_id'];
$k1 = contact_with("k1-$x@vocab.example.invalid", 'Kim', $acc, 26, ['role' => 'champion']);
$k2 = contact_with("k2-$x@vocab.example.invalid", 'Lou', $acc, 26, ['role' => 'finance']);
$k3 = contact_with("k3-$x@vocab.example.invalid", 'Max', null, 30);
post_as(26, '/contacts/opt-out.php', ['contact' => $k2, 'channel' => 'email', 'reason' => 'asked']);
post_as(26, '/tags/apply.php', ['kind' => 'contact', 'record' => $k1, 'tag' => "vip-$x"]);
$cases = [
    ['account', ['account' => (string) $acc], "/contacts?account=$acc", 2],
    ['role', ['role' => 'champion', 'account' => (string) $acc], "/contacts?role=champion&account=$acc", 1],
    ['optout', ['opt_out' => 'email', 'account' => (string) $acc], "/contacts?opt_out=email&account=$acc", 1],
    ['owner', ['owner' => '30'], '/contacts?owner=30', null],
    ['tag', ['tag' => "vip-$x"], "/contacts?tag=vip-$x", 1],
    ['lifecycle', ['lifecycle' => 'prospect', 'account' => (string) $acc], "/contacts?lifecycle=prospect&account=$acc", 2],
];
foreach ($cases as [$label, $f, $screen, $want]) {
    $r = $smart(26, "Contacts $label $x", 'contact', $f);
    $id = (int) ($r['json']['record_id'] ?? 0);
    $st = $stored($id);
    $same = $r['code'] === 200 && count($st) === count($f);
    foreach ($f as $k => $v) { $same = $same && (string) ($st[$k] ?? '') === (string) $v; }
    ok($same, "contact filter $label stored: " . json_encode($st));
    $live = $total(26, "/lists/$id");
    $scr = $total(26, $screen);
    ok($live === $scr && ($want === null || $live === $want), "…live count $live = the Contacts screen's $scr" . ($want === null ? '' : " = $want"));
    $re = post_as(26, '/lists/save.php', ['list' => $id, 'kind' => 'smart', 'record_kind' => 'contact', 'filters' => json_encode($st)]);
    ok($re['code'] === 200 && $stored($id) === $st, '…round trip: unchanged');
}
$idOwner = (int) one("SELECT id FROM lists WHERE name = :n", ['n' => "Contacts owner $x"]);
$edit = html_as(26, "/lists/$idOwner/edit")['body'];
ok(preg_match('/name="filter\[owner_id\]" id="filter-field-owner-person" value="30"/', $edit) === 1 && str_contains($edit, 'SMOKE Dana'), 'a person as the owner: a picker holding Dana');
$idAcc = (int) one("SELECT id FROM lists WHERE name = :n", ['n' => "Contacts account $x"]);
ok(preg_match('/name="filter\[account\]" id="filter-field-account" value="' . $acc . '"/', html_as(26, "/lists/$idAcc/edit")['body']) === 1, 'the account is a picker holding the account');

echo "3. Accounts\n";
$a2 = (int) post_as(26, '/accounts/save.php', ['name' => "Vocab Two $x", 'industry' => 'Retail', 'country' => 'US'])['json']['record_id'];
post_as(26, '/tags/apply.php', ['kind' => 'account', 'record' => $acc, 'tag' => "key-$x"]);
$cases = [
    ['industry', ['industry' => 'Software', 'country' => 'DE'], '/accounts?industry=Software&country=DE', null],
    ['kind', ['kind' => 'prospect', 'tag' => "key-$x"], "/accounts?kind=prospect&tag=key-$x", 1],
    ['owner', ['owner' => 'me', 'q' => "Vocab $x"], '/accounts?owner=me&q=' . rawurlencode("Vocab $x"), null],
    ['text', ['q' => "Two $x"], '/accounts?q=' . rawurlencode("Two $x"), null],
];
foreach ($cases as [$label, $f, $screen, $want]) {
    $r = $smart(26, "Accounts $label $x", 'account', $f);
    $id = (int) ($r['json']['record_id'] ?? 0);
    $st = $stored($id);
    $same = $r['code'] === 200 && count($st) === count($f);
    foreach ($f as $k => $v) { $same = $same && (string) ($st[$k] ?? '') === (string) $v; }
    ok($same, "account filter $label stored: " . json_encode($st));
    $live = $total(26, "/lists/$id");
    $scr = $total(26, $screen);
    ok($live === $scr && ($want === null || $live === $want), "…live count $live = the Accounts screen's $scr" . ($want === null ? '' : " = $want"));
    ok($re = post_as(26, '/lists/save.php', ['list' => $id, 'kind' => 'smart', 'record_kind' => 'account', 'filters' => json_encode($st)])['code'] === 200 && $stored($id) === $st, '…round trip: unchanged');
}

echo "4. Deals\n";
$pipe = (int) one("SELECT id FROM pipelines WHERE key = 'new_business'");
$dA = deal_make(26, ['title' => "VD A $x", 'value' => 1000, 'campaign' => $camp]);
$dB = deal_make(26, ['title' => "VD B $x", 'value' => 50000]);
$dC = deal_make(26, ['title' => "VD C $x", 'value' => 8000]);
post_as(26, '/deals/won.php', ['deal' => $dC, 'value' => 8000]);
post_as(26, '/tags/apply.php', ['kind' => 'deal', 'record' => $dB, 'tag' => "big-$x"]);
$cases = [
    ['value', ['min_value' => '20000', 'owner' => 'me'], '/deals?min_value=20000&owner=me', null],
    ['value both', ['min_value' => '500', 'max_value' => '2000', 'owner' => 'me'], '/deals?min_value=500&max_value=2000&owner=me', null],
    ['campaign', ['campaign' => (string) $camp], "/deals?campaign=$camp", 1],
    ['won', ['status' => 'won', 'owner' => 'me'], '/deals?status=won&owner=me', null],
    ['tag', ['tag' => "big-$x", 'pipeline' => (string) $pipe], "/deals?tag=big-$x&pipeline=$pipe", 1],
    ['no step', ['no_next_step' => '1', 'owner' => 'me'], '/deals?no_next_step=1&owner=me', null],
    ['text', ['q' => "VD B $x", 'status' => 'all'], '/deals?q=' . rawurlencode("VD B $x") . '&status=all', null],
];
foreach ($cases as [$label, $f, $screen, $want]) {
    $r = $smart(26, "Deals $label $x", 'deal', $f);
    $id = (int) ($r['json']['record_id'] ?? 0);
    $st = $stored($id);
    $same = $r['code'] === 200 && count($st) === count($f);
    foreach ($f as $k => $v) { $same = $same && (string) ($st[$k] ?? '') === (string) $v; }
    ok($same, "deal filter $label stored: " . json_encode($st));
    $live = $total(26, "/lists/$id");
    $scr = $total(26, $screen);
    ok($live === $scr && ($want === null || $live === $want), "…live count $live = the Deals screen's $scr" . ($want === null ? '' : " = $want"));
    ok(post_as(26, '/lists/save.php', ['list' => $id, 'kind' => 'smart', 'record_kind' => 'deal', 'filters' => json_encode($st)])['code'] === 200 && $stored($id) === $st, '…round trip: unchanged');
}
$idNs = (int) one("SELECT id FROM lists WHERE name = :n", ['n' => "Deals no step $x"]);
ok(preg_match('/id="filter-field-no_next_step" name="filter\[no_next_step\]" value="1" checked/', html_as(26, "/lists/$idNs/edit")['body']) === 1, 'a yes/no filter is a switch, checked');
$idV = (int) one("SELECT id FROM lists WHERE name = :n", ['n' => "Deals value $x"]);
ok(preg_match('/id="filter-field-min_value" name="filter\[min_value\]" value="20000"/', html_as(26, "/lists/$idV/edit")['body']) === 1, 'a number filter holds its number');
$idPipe = (int) one("SELECT id FROM lists WHERE name = :n", ['n' => "Deals tag $x"]);
ok(preg_match('/name="filter\[pipeline\]" id="filter-field-pipeline" value="' . $pipe . '"/', html_as(26, "/lists/$idPipe/edit")['body']) === 1, 'the pipeline is a picker');

echo "5. The older spellings, the strangers, the rules\n";
$r = post_as(26, '/lists/save.php', ['name' => "Old $x", 'kind' => 'smart', 'record_kind' => 'lead', 'filters' => json_encode(['status' => ['working'], 'source_id' => [$event], 'owner' => 'me', 'bogus' => 'x', 'tags' => []])]);
$id = (int) ($r['json']['record_id'] ?? 0);
ok($r['code'] === 200 && $stored($id) == ['owner' => 'me', 'source' => $event, 'status' => 'working'], 'the saved views\' spellings (status: [..], source_id) are read, an unknown key is dropped: ' . json_encode($stored($id)));
$r = post_as(26, '/lists/save.php', ['name' => "Junk $x", 'kind' => 'smart', 'record_kind' => 'contact', 'filter' => ['lifecycle' => 'astronaut', 'role' => 'decision_maker', 'untouched_days' => 'lots']]);
ok($r['code'] === 200 && $stored((int) $r['json']['record_id']) === ['role' => 'decision_maker'], 'a value the parser rejects (lifecycle astronaut, untouched "lots") is dropped, the rest kept');
$r = post_as(26, '/lists/save.php', ['name' => "Nothing $x", 'kind' => 'smart', 'record_kind' => 'contact', 'filter' => ['lifecycle' => 'astronaut']]);
ok($r['code'] === 422 && field_of($r) === 'filters', 'a smart list with no filter left is refused: it would be every contact');
$r = post_as(26, '/lists/save.php', ['name' => "Empty $x", 'kind' => 'smart', 'record_kind' => 'contact']);
ok($r['code'] === 422 && field_of($r) === 'filters', 'and one with none given');
$r = post_as(26, '/lists/save.php', ['name' => "Static filters $x", 'kind' => 'static', 'record_kind' => 'contact', 'filter' => ['role' => 'finance']]);
ok($r['code'] === 422 && field_of($r) === 'filters', 'a static list takes no filters');
$r = post_as(26, '/lists/save.php', ['name' => "Bad json $x", 'kind' => 'smart', 'record_kind' => 'contact', 'filters' => '{not json']);
ok($r['code'] === 422 && field_of($r) === 'filters', 'filters that are not an object: refused');
$g = html_as(26, '/lists/new?record_kind=contact&filters=' . rawurlencode(json_encode(['role' => 'finance'])));
ok($g['code'] === 200 && preg_match('/<option value="finance" selected>/', $g['body']) === 1 && str_contains($g['body'], 'A smart list of contacts'), 'list-add prefills from ?filters= (a JSON object): a screen\'s filters become a list');
$g = html_as(26, '/lists/new?record_kind=contact&filters=' . rawurlencode('role=finance&lifecycle=prospect'));
ok(preg_match('/<option value="finance" selected>/', $g['body']) === 1 && preg_match('/<option value="prospect" selected>/', $g['body']) === 1, '…or from a query string');
$jv = get_as(26, '/lists/new?record_kind=deal')['json']['data'];
ok(array_key_exists('min_value', $jv['vocabulary']) && $jv['vocabulary']['min_value']['type'] === 'number' && $jv['vocabulary']['status']['options']['all'] === 'Any', 'JSON: the vocabulary of the kind with its types and options (what segment_preview takes)');

echo "6. The worker keeps the counts\n";
$tl = $smart(26, "Counted hourly $x", 'lead', ['owner' => 'me', 'status' => 'new']);
$tid = (int) $tl['json']['record_id'];
$before = (int) one('SELECT member_count FROM lists WHERE id = :i', ['i' => $tid]);
lead_make(26, ['company' => "Counted $x"]);
$rep = pass('smart_lists');
ok((int) one('SELECT member_count FROM lists WHERE id = :i', ['i' => $tid]) === $before && (int) one('SELECT count(*) FROM lists WHERE id = :i AND counted_at > now() - interval \'5 minutes\'', ['i' => $tid]) === 1, 'within the hour the stored count is left alone (' . $before . ')');
pg_run("UPDATE lists SET counted_at = now() - interval '2 hours' WHERE id = $tid");
$rep = pass('smart_lists');
ok(($rep['counted'] ?? 0) >= 1 && (int) one('SELECT member_count FROM lists WHERE id = :i', ['i' => $tid]) === $before + 1 && empty($rep['stub']), 'an hour on it is counted again, as its owner: ' . $before . ' → ' . ($before + 1));
ok((int) one('SELECT member_count FROM lists WHERE id = :i', ['i' => $tid]) === $total(26, "/lists/$tid"), 'and agrees with the live count');
$rep = pass('smart_lists', date(DATE_ATOM, time() + 7200));
ok(($rep['counted'] ?? 0) >= 1, 'the pass runs by the clock it is given (CRM_WORKER_NOW): two hours later it counts again');
ok(str_contains(html_as(26, '/lists')['body'], 'id="list-card-' . $tid . '-count">' . ($before + 1) . '<'), 'the card shows the stored count');
$a = q("SELECT action FROM activity_log WHERE id > :s AND action LIKE 'list.%'", ['s' => $since]);
ok(in_array('list.create', array_column($a, 'action'), true) && in_array('list.update', array_column($a, 'action'), true), 'list.create and list.update are logged');
finish();
