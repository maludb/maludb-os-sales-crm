<?php
/**
 * Proof — JSON mode on every screen and handler of slice 9, the HTMX stamps, the rights matrix of the admin area, the methods and the CSRF gate, the log's vocabulary (never a key, a secret or a body), the people-only and the paused actions through the
 * registry, and the registry's end state: 84 of 84 screens and 121 of 121 actions built, no placeholder and no stub pass left.
 */
require __DIR__ . '/lib.php';
$x = run_tag();
$since = last_activity_id();
$root = dirname(__DIR__, 3);
$web = source_id('Website');

echo "1. Every screen answers JSON, and HTMX gets the partial stamped with its screen\n";
[$pp] = pipeline_made("J9 pipe $x");
$stg = stage_made($pp, "J9 stage $x");
$fld = (int) adm('/admin/fields/save.php', ['applies_to' => 'account', 'label' => "J9 field $x", 'field_type' => 'text'])['json']['record_id'];
$screens = ['admin' => ['/admin', 'areas'], 'admin-pipelines' => ['/admin/pipelines', 'pipelines'], 'admin-pipeline-add' => ['/admin/pipelines/new', 'pipeline'], 'admin-pipeline-edit' => ["/admin/pipelines/$pp/edit", 'pipeline'],
    'admin-stage-add' => ["/admin/pipelines/$pp/stages/new", 'required_field_choices'], 'admin-stage-edit' => ["/admin/stages/$stg/edit", 'stage'], 'admin-sources' => ['/admin/sources', 'sources'], 'admin-rules' => ['/admin/rules', 'rules'],
    'admin-fields' => ['/admin/fields?kind=account', 'fields'], 'admin-tags' => ['/admin/tags', 'tags'], 'admin-mailbox' => ['/admin/mailbox', 'health'], 'admin-quotas' => ['/admin/quotas', 'sellers'], 'admin-settings' => ['/admin/settings', 'settings'],
    'admin-tokens' => ['/admin/tokens', 'tokens'], 'admin-purge' => ['/admin/purge', 'tombstones']];
foreach ($screens as $id => [$url, $key]) {
    $g = get_as(1, $url);
    ok($g['code'] === 200 && array_key_exists($key, $g['json']['data'] ?? []), "GET $url as JSON: 200 with `$key`");
    $h = html_as(1, $url, ['HX-Request: true']);
    ok(preg_match('/^X-Screen: ' . preg_quote($id, '/') . '\r?$/mi', $h['headers']) === 1 && !str_contains($h['body'], '<html'), "over HTMX $url is the partial stamped $id");
}
$full = html_as(1, '/admin/pipelines')['body'];
ok(str_contains($full, 'id="page-content"') && str_contains($full, 'data-screen="admin-pipelines"') && str_contains($full, 'href="/admin"'), 'the full page stamps data-screen on #page-content, and the menu has Admin');
$d = screen(1, '/admin');
ok(count($d['areas']) === 11 && array_column($d['areas'], 'area') === ['pipelines', 'sources', 'rules', 'fields', 'tags', 'mailbox', 'quotas', 'settings', 'agents', 'tokens', 'purge'] && isset($d['mailbox']['state'], $d['tombstones']['due']), 'the admin home: eleven areas for the admin (one is slice 8\'s agents screen), the mailbox\'s health, the tombstones due: ' . implode(',', array_column($d['areas'], 'area')));
$h = html_as(1, '/admin')['body'];
ok(str_contains($h, 'id="admin-cards"') && str_contains($h, 'id="admin-card-pipelines"') && str_contains($h, 'id="admin-card-purge-words"') && str_contains($h, 'id="admin-card-mailbox-link"'), 'the page: the cards with their ids');
$sp = screen(1, '/admin/pipelines')['pipelines'][0];
ok(array_keys($sp) === ['pipeline_id', 'key', 'name', 'is_default', 'currency', 'rotting_days', 'position', 'archived', 'deals_open', 'deals_all', 'stages'] && array_keys($sp['stages'][0]) === ['stage_id', 'pipeline_id', 'name', 'kind', 'position', 'probability', 'rotting_days', 'required_fields', 'ask_assistant', 'colour', 'wip_limit', 'archived', 'deals_open', 'deals_all'], 'pipelines: the whitelisted fields of a pipeline and its stages');
$sr = screen(1, '/admin/sources');
ok(array_keys($sr['sources'][0]) === ['source_id', 'name', 'kind', 'active', 'sort_order', 'leads', 'deals', 'accounts', 'contacts', 'keys', 'rules'] && array_keys($sr['reasons'][0]) === ['lost_reason_id', 'kind', 'label', 'active', 'sort_order', 'deals', 'leads'], 'sources and reasons: whitelisted');
ok(get_as(1, '/admin/pipelines/99999/edit')['code'] === 404 && get_as(1, '/admin/stages/99999/edit')['code'] === 404 && get_as(1, '/admin/pipelines/99999/stages/new')['code'] === 404, 'a pipeline or stage that is not there: 404');
$agent = req('GET', '/admin/settings', ['headers' => as_agent(run_token(40, 9901))]);
ok($agent['code'] === 403, 'an agent without the right reads no admin screen: 403');

echo "2. The rights of each role\n";
$mkt = role_actor(31, 'marketing');
$roles = ['admin' => fn (string $u) => get_as(1, $u)['code'], 'manager' => fn (string $u) => get_as(27, $u)['code'], 'marketing' => fn (string $u) => get_role($mkt, $u)['code'], 'rep' => fn (string $u) => get_as(26, $u)['code'], 'viewer' => fn (string $u) => get_as(29, $u)['code']];
$allow = ['/admin' => 'admin manager marketing', '/admin/pipelines' => 'admin manager', '/admin/pipelines/new' => 'admin manager', "/admin/pipelines/$pp/edit" => 'admin manager', "/admin/pipelines/$pp/stages/new" => 'admin manager', "/admin/stages/$stg/edit" => 'admin manager',
    '/admin/sources' => 'admin manager marketing', '/admin/rules' => 'admin manager', '/admin/fields' => 'admin', '/admin/tags' => 'admin manager marketing', '/admin/mailbox' => 'admin', '/admin/quotas' => 'admin manager', '/admin/settings' => 'admin',
    '/admin/tokens' => 'admin', '/admin/purge' => 'admin'];
$wrong = []; $n = 0;
foreach ($allow as $url => $who) { foreach ($roles as $role => $fn) { $n++; $want = in_array($role, explode(' ', $who), true) ? 200 : 403; $got = $fn($url); if ($got !== $want) { $wrong[] = "$role $url wanted $want got $got"; } } }
ok($wrong === [], "$n role × screen checks (" . count($allow) . ' screens × 5 roles) answer 200 or 403 as the rights say' . ($wrong ? ': ' . implode('; ', array_slice($wrong, 0, 6)) : ''));
$cards = fn (array $d) => array_column($d['areas'] ?? [], 'area');
ok($cards(screen(27, '/admin')) === ['pipelines', 'sources', 'rules', 'tags', 'quotas'] && $cards(get_role($mkt, '/admin')['json']['data']) === ['sources', 'tags'], 'a card shows only to a holder of its right: the Sales Manager sees five, Marketing two');
ok(!str_contains(html_as(27, '/admin')['body'], 'id="admin-card-settings"') && str_contains(html_as(27, '/admin')['body'], 'id="admin-card-quotas"'), '(and the page agrees)');
$r = get_as(26, '/admin');
ok($r['code'] === 403 && str_contains(message_of($r), 'You may not change the settings.'), 'a Rep is refused in words: ' . message_of($r));

echo "3. Every handler answers JSON\n";
[$kfresh] = [pipeline_made("J9 del $x")[0]];
$stDel = stage_made($pp, "J9 del stage $x");
$srcA = (int) adm('/admin/sources/save.php', ['name' => "J9 src $x"])['json']['record_id'];
$rsA = (int) adm('/admin/reasons/save.php', ['kind' => 'deal', 'label' => "J9 reason $x"])['json']['record_id'];
$tagA = (int) one('INSERT INTO tags (slug, label) VALUES (:s, :l) RETURNING id', ['s' => "j9a-$x", 'l' => "j9a $x"]);
$tagB = (int) one('INSERT INTO tags (slug, label) VALUES (:s, :l) RETURNING id', ['s' => "j9b-$x", 'l' => "j9b $x"]);
$tagC = (int) one('INSERT INTO tags (slug, label) VALUES (:s, :l) RETURNING id', ['s' => "j9c-$x", 'l' => "j9c $x"]);
$mbExists = (int) one('SELECT count(*) FROM mailboxes') > 0;
$tt = tombstone('lead', 45, "J9Purge$x");
$checks = [
    ['pipeline_save', '/admin/pipelines/save.php', ['name' => "J9 saved $x"]], ['pipeline_delete', '/admin/pipelines/delete.php', ['pipeline' => $kfresh]],
    ['stage_save', '/admin/stages/save.php', ['pipeline' => $pp, 'name' => "J9 more $x"]], ['stage_reorder', '/admin/stages/reorder.php', fn () => ['pipeline' => $pp, 'stages' => implode(',', array_reverse(stage_order($pp)))]],
    ['stage_delete', '/admin/stages/delete.php', ['stage' => $stDel]], ['source_save', '/admin/sources/save.php', ['name' => "J9 src2 $x"]], ['source_delete', '/admin/sources/delete.php', ['source' => $srcA]],
    ['lost_reason_save', '/admin/reasons/save.php', ['kind' => 'lead', 'label' => "J9 reason2 $x"]], ['lost_reason_delete', '/admin/reasons/delete.php', ['lost_reason' => $rsA]],
    ['assignment_rules_save', '/admin/rules/save.php', ['rules' => json_encode([['name' => "J9 rule $x", 'assign_kind' => 'member', 'member_id' => 27]])]],
    ['custom_field_save', '/admin/fields/save.php', ['field' => $fld, 'label' => "J9 field renamed $x"]], ['tag_save', '/admin/tags/save.php', ['tag' => $tagA, 'label' => "j9a renamed $x"]],
    ['tag_merge', '/admin/tags/merge.php', ['tag' => $tagA, 'into' => $tagB]], ['tag_delete', '/admin/tags/delete.php', ['tag' => $tagC]],
    ['mailbox_save', '/admin/mailbox/save.php', $mbExists ? ['display_name' => 'Sales CRM'] : ['address' => "j9-$x@example.invalid", 'display_name' => 'J9']], ['quota_set', '/admin/quotas/save.php', ['member' => 26, 'month' => '2031-05', 'amount' => (string) random_int(1000, 99999)]],
    ['settings_save', '/admin/settings/save.php', ['forecast_months' => 8]], ['capture_key_mint', '/admin/tokens/capture-mint.php', ['label' => "J9 key $x", 'source' => $web]], ['records_purge', '/admin/purge.php', ['kind' => 'lead']],
];
foreach ($checks as [$name, $path, $form]) {
    $r = post_as(1, $path, is_callable($form) ? $form() : $form);
    ok($r['code'] === 200 && ($r['json']['ok'] ?? false) === true && ($r['json']['did'] ?? '') !== '' && array_key_exists('record_id', $r['json']) && isset($r['json']['location']), "$name: {ok, did, record_id, location} — " . ($r['json']['did'] ?? $r['body']));
}
pg_run("UPDATE crm_settings SET forecast_months = 6");
$r = post_as(1, '/admin/pipelines/save.php', ['name' => '']);
ok($r['code'] === 422 && ($r['json']['error']['code'] ?? '') === 'invalid' && isset($r['json']['error']['fields']['name']) && is_array($r['json']['error']['errors']), 'a refusal: 422 invalid with the field named and the sentences');
ok(post_as(1, '/admin/tags/delete.php', ['tag' => 99999999])['json']['error']['code'] === 'not_found' && post_as(26, '/admin/tags/delete.php', ['tag' => $tagB])['json']['error']['code'] === 'forbidden', 'a missing tag is 404 not_found; a Rep is 403 forbidden');
$paths = array_unique(array_column($checks, 1));
foreach ($paths as $path) {
    $r = req('GET', $path, ['jar' => actor(1)[0], 'headers' => ['Accept: application/json']]);
    $r2 = req('POST', $path, ['headers' => ['Accept: application/json'], 'form' => ['x' => 1]]);
    $r3 = req('POST', $path, ['jar' => actor(1)[0], 'headers' => ['Accept: application/json'], 'form' => ['x' => 1]]);
    $isScreenToo = $path === '/admin/purge.php';
    ok(($r['code'] === 405 || ($isScreenToo && $r['code'] === 200)) && $r2['code'] === 401 && $r3['code'] === 403, "$path: " . ($isScreenToo ? 'GET is the screen' : 'GET is 405') . ', an anonymous POST is 401, a POST without the CSRF token 403');
}
$t = html_as(1, '/admin/tags')['body'];

echo "4. The log's vocabulary\n";
$rows = q("SELECT action, after::text AS after, before::text AS before FROM activity_log WHERE id > :s", ['s' => $since]);
$seen = array_unique(array_column($rows, 'action'));
foreach (['pipeline.save', 'pipeline.delete', 'stage.save', 'stage.reorder', 'stage.delete', 'source.save', 'source.delete', 'reason.save', 'reason.delete', 'rules.save', 'field.save', 'tag.save', 'tag.merge', 'tag.delete', 'quota.set', 'settings.save', 'key.capture_mint', 'records.purge'] as $ev) {
    ok(in_array($ev, $seen, true), "$ev written");
}
ok(in_array('mailbox.save', array_unique(array_column(q('SELECT DISTINCT action FROM activity_log'), 'action')), true), 'mailbox.save has been written on this database');
$blob = json_encode($rows);
$secret = need('MAILBOX_CRM_WEBHOOK_SECRET');
ok(!preg_match('/crm_[0-9a-f]{48}/', $blob) && !preg_match('/mcp_[0-9a-f]{48}/', $blob) && !str_contains($blob, $secret) && !str_contains($blob, 'token_hash') && !preg_match('/\b[0-9a-f]{64}\b/', $blob), 'no key, no secret and no hash in any payload');
$allBlob = json_encode(q("SELECT after::text, before::text FROM activity_log WHERE action IN ('pipeline.save','stage.save','stage.reorder','source.save','reason.save','rules.save','field.save','tag.save','tag.merge','tag.delete','mailbox.save','quota.set','settings.save','key.capture_mint','records.purge')"));
ok(!str_contains($allBlob, $secret) && !preg_match('/crm_[0-9a-f]{48}/', $allBlob), 'and none in the whole log for this slice\'s events');
$tl = html_as(1, '/admin/tokens')['body'] . html_as(1, '/admin/mailbox')['body'] . html_as(1, '/admin/settings')['body'];
ok(!str_contains($tl, $secret), 'the webhook secret is on no admin page');

echo "5. The registry — the end state\n";
exec('php ' . escapeshellarg($root . '/bin/build_action_registry.php') . ' --check 2>&1', $o, $rc);
ok($rc === 0, 'bin/build_action_registry.php --check: the registry and approvals[] match the manifest');
$reg = json_decode((string) file_get_contents($root . '/mcp/action_registry.json'), true);
$s9 = ['admin', 'admin-pipelines', 'admin-pipeline-add', 'admin-pipeline-edit', 'admin-stage-add', 'admin-stage-edit', 'admin-sources', 'admin-rules', 'admin-fields', 'admin-tags', 'admin-mailbox', 'admin-quotas', 'admin-settings', 'admin-tokens', 'admin-purge'];
ok(count($s9) === 15 && array_filter($s9, static fn ($s) => empty($reg['screens'][$s]['built'])) === [], 'the 15 screens of this slice read as built');
$acts = array_map(static fn ($c) => $c[0], $checks);
ok(count($acts) === 19 && array_filter($acts, static fn ($a) => empty($reg['actions'][$a]['built'])) === [], 'the 19 actions read as built');
$scr = $reg['screens'] ?? []; $act = $reg['actions'] ?? [];
$builtS = count(array_filter($scr, static fn ($s) => !empty($s['built']))); $builtA = count(array_filter($act, static fn ($a) => !empty($a['built'])));
ok(count($scr) === 84 && $builtS === 84 && count($act) === 121 && $builtA === 121, "PHASE 3 COMPLETE: $builtS of " . count($scr) . " screens and $builtA of " . count($act) . ' actions built');
ok(array_filter($scr, static fn ($s) => !empty($s['stub'])) === [], 'no screen of the registry is a placeholder');
$stubFiles = trim((string) shell_exec('grep -rl "render_nav_stub(\|render_module_stub(" ' . escapeshellarg($root . '/html') . ' 2>/dev/null'));
ok($stubFiles === '', 'no controller under html/ calls a placeholder helper');
$paused = array_values(array_filter($acts, static fn ($a) => !empty($reg['actions'][$a]['approval'] ?? null)));
sort($paused);
$expect = ['assignment_rules_save', 'capture_key_mint', 'custom_field_save', 'lost_reason_delete', 'mailbox_save', 'pipeline_delete', 'pipeline_save', 'quota_set', 'records_purge', 'settings_save', 'source_delete', 'stage_delete', 'stage_reorder', 'stage_save', 'tag_delete'];
ok($paused === $expect, 'the manifest pauses ' . count($paused) . ' of this slice\'s actions for an agent (the spec said 14; the manifest\'s rows hold 15): ' . implode(', ', $paused));
ok(($reg['actions']['pipeline_save']['approval'] ?? '') === 'other' && ($reg['actions']['pipeline_delete']['approval'] ?? '') === 'deletion' && ($reg['actions']['records_purge']['approval'] ?? '') === 'deletion' && ($reg['actions']['settings_save']['approval'] ?? '') === 'other', 'an agent\'s pipeline_save pauses as `other` (a delete or a purge as `deletion`) — through the registry');
$free = array_values(array_diff($acts, $paused));
sort($free);
ok($free === ['lost_reason_save', 'source_save', 'tag_merge', 'tag_save'], 'the four that do not pause: ' . implode(', ', $free));
$os = json_decode((string) file_get_contents($root . '/maludb-os.json'), true);
$ap = array_filter($os['approvals'] ?? [], static fn ($a) => in_array($a['action'] ?? ($a['tool'] ?? ''), $paused, true));
ok(count($ap) === 15, 'maludb-os.json approvals[] holds the fifteen');
$people = array_values(array_filter($acts, static fn ($a) => empty($reg['actions'][$a]['agent'])));
sort($people);
ok($people === ['capture_key_mint', 'mailbox_save'], 'two are people-only: ' . implode(', ', $people));
$files = ['pipeline_save' => '/admin/pipelines/save.php', 'pipeline_delete' => '/admin/pipelines/delete.php', 'stage_save' => '/admin/stages/save.php', 'stage_reorder' => '/admin/stages/reorder.php', 'stage_delete' => '/admin/stages/delete.php',
    'source_save' => '/admin/sources/save.php', 'source_delete' => '/admin/sources/delete.php', 'lost_reason_save' => '/admin/reasons/save.php', 'lost_reason_delete' => '/admin/reasons/delete.php', 'assignment_rules_save' => '/admin/rules/save.php',
    'custom_field_save' => '/admin/fields/save.php', 'tag_save' => '/admin/tags/save.php', 'tag_merge' => '/admin/tags/merge.php', 'tag_delete' => '/admin/tags/delete.php', 'mailbox_save' => '/admin/mailbox/save.php', 'quota_set' => '/admin/quotas/save.php',
    'settings_save' => '/admin/settings/save.php', 'capture_key_mint' => '/admin/tokens/capture-mint.php', 'records_purge' => '/admin/purge.php'];
ok(array_filter($files, static fn ($f, $a) => ($reg['actions'][$a]['endpoint'] ?? '') !== $f, ARRAY_FILTER_USE_BOTH) === [], 'the endpoints are where the manifest says');
$k = json_decode((string) file_get_contents($root . '/deploy/kernel-registry-sales_crm.json'), true);
ok(is_array($k) && $k !== [], 'the kernel registry file reads');
$w = worker('tombstones_purge,key_usage_prune');
ok(array_filter($w['ran'] ?? [], static fn ($r) => !empty($r['stub'])) === [] && ($w['errors'] ?? ['x']) === [], 'both passes of this slice are real: ' . json_encode($w['ran'] ?? $w));
finish();
