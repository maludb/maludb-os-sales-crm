<?php
/**
 * Proof — JSON mode on every screen and handler of slice 8, the HTMX stamps, the log's vocabulary (agents-k7-api.md "Activity log events": never a body, a key or a token), the worker's passes, the bridge's doors, and the registry:
 * 2 screens built, 12 actions built, the paused ones in step (sms_send, agents_settings_save, key_mint), the people-only ones refused to an agent.
 */
require __DIR__ . '/lib.php';
$x = run_tag();
$since = last_activity_id() - 1;
pg_run("UPDATE notification_prefs SET kinds = '{reminder,assigned,lead_assigned,reply_received,deal_won,mention,proposal,morning_note,import_done,export_ready}' WHERE member_id IN (26, 27)");
pg_run("DELETE FROM notification_outbox WHERE dedupe_key LIKE 'morning:%'");
pg_run("UPDATE crm_settings SET lead_untouched_days = 2");
$deal = deal_make(26, ['title' => "Json8 deal $x"]);
$ct = contact_with("j8-$x@json8.example.invalid", 'Jay');
$ag = static fn (string $path, array $f, int $agent = 42) => agent_post($agent, random_int(1000, 99999), $path, $f);

echo "1. Every screen answers JSON\n";
$p1 = (int) $ag('/proposals/propose.php', ['deal' => $deal, 'kind' => 'call', 'subject' => "J8 step $x", 'at' => at('+1 day 10:00')])['json']['record_id'];
foreach (['/proposals' => ['proposals', 26], '/admin/agents' => ['agents', 1], '/settings/tokens/' => ['keys', 26]] as $url => [$key, $m]) {
    $g = get_as($m, $url);
    ok($g['code'] === 200 && array_key_exists($key, $g['json']['data'] ?? []), "GET $url as JSON: 200 with `$key`");
}
$g = get_as(26, '/proposals')['json']['data'];
ok(isset($g['filters'], $g['page'], $g['total'], $g['more'], $g['waiting']) && array_keys($g['proposals'][0]) === ['proposal_id', 'kind', 'kind_label', 'title', 'status', 'status_label', 'for_member_id', 'for_name', 'proposed_by_name', 'record', 'summary', 'detail', 'decided', 'result_activity_id', 'created_at'], 'proposals: the filters, the page, the total, the waiting count, and the card\'s whitelisted fields');
$g = get_as(1, '/admin/agents')['json']['data'];
ok(isset($g['agents'], $g['dispatches'], $g['filters'], $g['settings']['stages']) && array_keys($g['dispatches'][0]) === ['dispatch_id', 'agent_member_id', 'agent_name', 'kind', 'kind_label', 'status', 'status_label', 'tone', 'attempts', 'acting_member_id', 'acting_name', 'record_type', 'record_id', 'deal_id', 'lead_id', 'reply_excerpt', 'detail', 'created_at', 'answered_at'], 'admin-agents: the agents, the dispatches (whitelisted), the filters, the settings with its stages');
ok(get_as(29, '/proposals')['code'] === 200 && get_as(26, '/admin/agents')['code'] === 403 && get_as(26, '/proposals?kind=bogus')['code'] === 200, 'a Viewer reads her proposals; a Rep may not open the agents\' screen; a bad kind filter is ignored');
$tok = req('GET', '/proposals', ['headers' => as_agent(run_token(42, 6001))]);
ok($tok['code'] === 200 && isset(json_decode($tok['body'], true)['data']['proposals']), 'an agent reads proposals (what it proposed, as JSON)');
foreach (['/proposals' => 'proposals', '/admin/agents' => 'admin-agents', '/settings/tokens/' => 'settings-tokens'] as $u => $screen) {
    $m = $u === '/admin/agents' ? 1 : 26;
    ok(preg_match('/^X-Screen: ' . preg_quote($screen, '/') . '\r?$/mi', html_as($m, $u, ['HX-Request: true'])['headers']) === 1 && !str_contains(html_as($m, $u, ['HX-Request: true'])['body'], '<html'), "over HTMX $u is the partial stamped $screen");
}
$full = html_as(26, '/proposals')['body'];
ok(str_contains($full, 'id="page-content"') && str_contains($full, 'data-screen="proposals"'), 'the full page stamps data-screen on #page-content');
ok(str_contains(html_as(26, '/')['body'], 'href="/proposals"') && str_contains(html_as(26, '/proposals')['body'], 'feather-zap'), 'Proposals is in the menu');

echo "2. Every handler answers JSON\n";
[$kid, ] = key_as(26, "J8 key $x");
$lead = lead_make(26, ['company' => "J8 lead $x"]);
$p2 = (int) $ag('/proposals/propose-lead.php', ['lead' => $lead, 'score' => 60, 'reasons' => ['x']])['json']['record_id'];
$a = contact_with("j8a-$x@json8.example.invalid", 'Ada');
$b = contact_with("j8b-$x@json8.example.invalid", 'Ada');
$d1 = $make = (int) one("INSERT INTO agent_dispatches (agent_member_id, kind, via, acting_member_id, status) VALUES (42, 'ask', 'chat', 26, 'sent') RETURNING id");
$checks = [
    [42, '/proposals/propose.php', ['deal' => deal_make(26, ['title' => "J8b $x"]), 'kind' => 'task', 'subject' => 'S', 'at' => at('+2 days 10:00')], 'next_step_propose'],
    [42, '/proposals/propose-lead.php', ['lead' => lead_make(26), 'score' => 10], 'lead_qualify_propose'],
    [42, '/proposals/propose-duplicate.php', ['kind' => 'contact', 'a' => $a, 'b' => $b, 'matched_on' => 'name'], 'duplicate_propose'],
    [26, '/proposals/accept.php', ['proposal' => $p1], 'proposal_accept'],
    [26, '/proposals/dismiss.php', ['proposal' => $p2], 'proposal_dismiss'],
    [42, '/notify/send.php', ['member' => 26, 'kind' => 'morning_note', 'title' => "J8 note $x", 'body' => "J8-BODY-$x"], 'notification'],
    [1, '/admin/agents/dispatch-cancel.php', ['dispatch' => $d1], 'dispatch_cancel'],
    [1, '/admin/agents/save.php', ['lead_untouched_days' => 4], 'agents_settings_save'],
    [26, '/settings/tokens/key-mint.php', ['label' => "J8 mint $x"], 'key_mint'],
    [26, '/settings/tokens/key-rotate.php', ['api_key' => $kid], 'key_rotate'],
];
foreach ($checks as [$m, $path, $form, $name]) {
    $r = $m >= 40 ? agent_post($m, random_int(1000, 99999), $path, $form) : post_as($m, $path, $form);
    ok($r['code'] === 200 && ($r['json']['ok'] ?? false) === true && ($r['json']['did'] ?? '') !== '' && array_key_exists('record_id', $r['json']), "$name: {ok, did, record_id} — " . ($r['json']['did'] ?? $r['body']));
}
$k2 = (int) q("SELECT id FROM mcp_access_tokens WHERE label = :l", ['l' => "J8 mint $x"])[0]['id'];
$r = post_as(26, '/settings/tokens/key-revoke.php', ['api_key' => $k2]);
ok($r['code'] === 200 && ($r['json']['ok'] ?? false) === true && $r['json']['record_id'] === $k2, 'key_revoke: {ok, did, record_id}');
$r = post_as(26, '/sms/send.php', ['contact' => $ct, 'body' => 'hello']);
ok($r['code'] === 422 && ($r['json']['error']['code'] ?? '') === 'invalid' && str_contains($r['json']['error']['message'] ?? '', 'no_sender'), 'sms_send: 422 invalid, the sentence names no_sender');
$r = post_as(26, '/proposals/propose.php', ['deal' => deal_make(26, ['title' => "J8c $x"]), 'kind' => 'visit', 'subject' => 's', 'at' => at('+1 day 10:00')]);
ok($r['code'] === 422 && ($r['json']['error']['code'] ?? '') === 'invalid' && isset($r['json']['error']['fields']['kind']) && is_array($r['json']['error']['errors']), 'a refusal: 422 invalid with the field named and the sentences');
ok(post_as(26, '/proposals/accept.php', ['proposal' => 99999999])['json']['error']['code'] === 'not_found' && post_as(29, '/notify/send.php', ['member' => 29, 'kind' => 'mention', 'title' => 't'])['json']['error']['code'] === 'forbidden', 'a missing proposal is 404 not_found; a Viewer\'s notice is 403 forbidden');
foreach (['/proposals/accept.php', '/proposals/dismiss.php', '/proposals/propose.php', '/notify/send.php', '/sms/send.php', '/admin/agents/save.php', '/admin/agents/dispatch-cancel.php', '/settings/tokens/key-mint.php', '/settings/tokens/key-rotate.php', '/settings/tokens/key-revoke.php'] as $path) {
    $r = req('GET', $path, ['jar' => actor(1)[0], 'headers' => ['Accept: application/json']]);
    $r2 = req('POST', $path, ['headers' => ['Accept: application/json'], 'form' => ['x' => 1]]);
    ok($r['code'] === 405 && $r2['code'] === 401, "$path: GET is 405, an anonymous POST is 401");
}
$csrf = req('POST', '/proposals/dismiss.php', ['jar' => actor(26)[0], 'headers' => ['Accept: application/json'], 'form' => ['proposal' => $p1]]);
ok($csrf['code'] === 403 && ($csrf['json']['error']['code'] ?? json_decode($csrf['body'], true)['error']['code'] ?? '') === 'csrf_failed', 'a POST without the CSRF token: 403 csrf_failed');
$csrf = req('POST', '/settings/tokens/key-mint.php', ['jar' => actor(26)[0], 'headers' => ['Accept: application/json'], 'form' => ['label' => 'no csrf']]);
ok($csrf['code'] === 403, '…key_mint included');

echo "3. The log's vocabulary\n";
$rows = q("SELECT action, source, after::text AS after, before::text AS before FROM activity_log WHERE id > :s", ['s' => $since]);
$seen = array_unique(array_column($rows, 'action'));
$allLog = q("SELECT DISTINCT action FROM activity_log");
$have = array_column($allLog, 'action');
foreach (['proposal.accept', 'proposal.dismiss', 'proposal.next_step', 'proposal.lead_qualify', 'proposal.duplicate', 'notification.send', 'sms.send', 'dispatch.cancel', 'agents_settings.save', 'key.mint', 'key.rotate', 'key.revoke'] as $ev) {
    ok(in_array($ev, $seen, true), "$ev written");
}
foreach (['dispatch.send', 'dispatch.answered', 'dispatch.failed', 'share.read', 'k7.read', 'outbox.send', 'outbox.skip', 'outbox.fail', 'lead.create', 'api.call', 'lead.capture_trap', 'proposal.rotting'] as $ev) {
    ok(in_array($ev, $have, true), "$ev has been written on this database (the earlier proofs)");
}
ok((int) one("SELECT count(*) FROM activity_log WHERE action = 'lead.create' AND source = 'api'") >= 1, 'lead.create with source api (the capture route)');
$j = json_encode($rows);
ok(!str_contains($j, "J8-BODY-$x") && !preg_match('/crm_[0-9a-f]{48}/', $j) && !preg_match('/mcp_[0-9a-f]{48}/', $j) && !str_contains($j, 'token_hash'), 'no body, no key, no token in any payload');
$wide = json_encode(q("SELECT action, after::text, before::text FROM activity_log WHERE action IN ('notification.send', 'sms.send', 'key.mint', 'key.rotate', 'key.revoke', 'proposal.next_step', 'share.read', 'k7.read', 'api.call', 'dispatch.send', 'dispatch.answered', 'outbox.send', 'outbox.skip', 'outbox.fail')"));
ok(!preg_match('/crm_[0-9a-f]{48}/', $wide) && !preg_match('/[0-9a-f]{64}/', $wide) , 'and none of the vocabulary\'s events in the whole log holds a key or a 64-hex hash');

echo "4. The worker's passes\n";
$w = worker('dispatches,proposals,outbox');
ok(isset($w['ran']['dispatches']['dispatched'], $w['ran']['proposals']['proposed'], $w['ran']['outbox']['sent']) && empty($w['ran']['dispatches']['stub']) && empty($w['ran']['proposals']['stub']) && empty($w['ran']['outbox']['stub']) && ($w['errors'] ?? []) === [], 'dispatches, proposals and outbox are real passes: ' . json_encode($w['ran'] ?? $w));
$w = worker('tombstones_purge,key_usage_prune');
ok(count(array_filter($w['ran'] ?? [], static fn ($r) => !empty($r['stub']))) === 2, 'the passes of slice 9 are still stubs');

echo "5. The doors\n";
ok(is_file(dirname(__DIR__, 3) . '/html/internal/bridge.php') && bridge('share_accounts_index', ['consumer' => 'gl'])['code'] === 200, 'the bridge answers');
$vh = (string) file_get_contents(dirname(__DIR__, 3) . '/deploy/apache-sales_crm.conf');
ok(str_contains($vh, 'RewriteRule ^/internal/ - [R=404,L]') && substr_count($vh, 'internal/bridge') === 0, 'the public vhost answers 404 under /internal/ and routes nothing to the bridge');

echo "6. The registry\n";
exec('php ' . escapeshellarg(dirname(__DIR__, 3) . '/bin/build_action_registry.php') . ' --check 2>&1', $o, $rc);
ok($rc === 0, 'bin/build_action_registry.php --check: the registry and approvals[] match the manifest');
$reg = json_decode((string) file_get_contents(dirname(__DIR__, 3) . '/mcp/action_registry.json'), true);
ok(array_filter(['proposals', 'admin-agents'], static fn ($s) => empty($reg['screens'][$s]['built'])) === [], 'the 2 screens read as built');
$acts = ['proposal_accept', 'proposal_dismiss', 'next_step_propose', 'lead_qualify_propose', 'duplicate_propose', 'notification', 'sms_send', 'dispatch_cancel', 'agents_settings_save', 'key_mint', 'key_rotate', 'key_revoke'];
ok(count($acts) === 12 && array_filter($acts, static fn ($a) => empty($reg['actions'][$a]['built'])) === [], 'the 12 actions read as built');
$paused = array_values(array_filter($acts, static fn ($a) => !empty($reg['actions'][$a]['approval'] ?? null)));
sort($paused);
ok($paused === ['agents_settings_save', 'key_mint', 'sms_send'], 'the approvals of this slice in step with the manifest: ' . implode(', ', $paused));
$os = json_decode((string) file_get_contents(dirname(__DIR__, 3) . '/maludb-os.json'), true);
$ap = array_filter($os['approvals'] ?? [], static fn ($a) => in_array($a['action'] ?? ($a['tool'] ?? ''), $paused, true));
ok(count($ap) === 3, 'maludb-os.json approvals[] holds the three');
$people = array_values(array_filter($acts, static fn ($a) => empty($reg['actions'][$a]['agent'])));
sort($people);
ok($people === ['agents_settings_save', 'dispatch_cancel', 'key_mint', 'key_revoke', 'key_rotate'], 'five are people-only: ' . implode(', ', $people));
$files = ['proposal_accept' => '/proposals/accept.php', 'next_step_propose' => '/proposals/propose.php', 'lead_qualify_propose' => '/proposals/propose-lead.php', 'duplicate_propose' => '/proposals/propose-duplicate.php', 'notification' => '/notify/send.php',
    'sms_send' => '/sms/send.php', 'dispatch_cancel' => '/admin/agents/dispatch-cancel.php', 'agents_settings_save' => '/admin/agents/save.php', 'key_mint' => '/settings/tokens/key-mint.php'];
ok(array_filter($files, static fn ($f, $a) => ($reg['actions'][$a]['endpoint'] ?? '') !== $f, ARRAY_FILTER_USE_BOTH) === [], 'the endpoints are where the manifest says');
$k = json_decode((string) file_get_contents(dirname(__DIR__, 3) . '/deploy/kernel-registry-sales_crm.json'), true);
ok(is_array($k) && $k !== [], 'the kernel registry file reads');
finish();
