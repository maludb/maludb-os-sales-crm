<?php
/**
 * Proof — keys and tokens (admin-settings.md "Proof"): the admin lists EVERY key and token (label, owner, kind, source, limits, calls today, last use, state — never a hash), mints a lead-capture key with slice 8's key minting (shown ONCE,
 * only its hash kept), the key does what slice 8's capture route says and nothing else, revoke is slice 8's key_revoke, the caps, people only, the rights.
 */
require __DIR__ . '/lib.php';
$x = run_tag();
$since = last_activity_id();
$web = source_id('Website');
$cap = (int) one('SELECT capture_rate_per_day FROM crm_settings WHERE id = 1');

echo "1. Minting a lead-capture key\n";
$r = adm('/admin/tokens/capture-mint.php', ['label' => "Contact form $x", 'source' => $web]);
$kid = (int) ($r['json']['record_id'] ?? 0);
$raw = (string) ($r['json']['key'] ?? '');
$row = q('SELECT * FROM mcp_access_tokens WHERE id = :i', ['i' => $kid])[0] ?? [];
ok($r['code'] === 200 && preg_match('/^crm_[0-9a-f]{48}$/', $raw) === 1 && $kid > 0, 'capture_key_mint answers the key ONCE in the JSON: crm_ + 48 hex — ' . ($r['json']['did'] ?? $r['body']));
ok($row['scope'] === 'api' && (int) $row['lead_source_id'] === $web && (int) $row['member_id'] === 1 && $row['token_hash'] === hash('sha256', $raw) && (int) $row['rate_per_day'] === $cap && $row['revoked_at'] === null, 'stored: scope api, bound to the source, the minter\'s own, only the SHA-256, the day limit the settings\' capture rate (' . $cap . ')');
$r2 = adm('/admin/tokens/capture-mint.php', ['label' => "Newsletter $x", 'source' => $web, 'rate_per_day' => 5]);
ok($r2['code'] === 200 && (int) one('SELECT rate_per_day FROM mcp_access_tokens WHERE id = :i', ['i' => (int) $r2['json']['record_id']]) === 5, 'a lower day limit may be given (5)');
[$j, $t] = actor(1);
$post = req('POST', '/admin/tokens/capture-mint.php', ['jar' => $j, 'form' => ['csrf_token' => $t, 'label' => "Landing page $x", 'source' => $web]]);
ok(in_array($post['code'], [302, 303], true) && str_contains($post['location'], '/admin/tokens'), 'from the form the browser is sent back to the keys screen: ' . $post['code'] . ' ' . $post['location']);
$page = req('GET', '/admin/tokens', ['jar' => $j]);
ok(preg_match('/id="keys-minted-value">(crm_[0-9a-f]{48})</', $page['body'], $mm) === 1 && str_contains($page['body'], "Landing page $x") && str_contains($page['body'], 'shown once'), 'the next screen shows the value once, in a banner: ' . substr($mm[1] ?? '', 0, 8) . '…');
$again = req('GET', '/admin/tokens', ['jar' => $j]);
ok(!str_contains($again['body'], $mm[1]) && !str_contains($again['body'], 'keys-minted-value'), 'and never again');
ok(!str_contains(get_as(1, '/admin/tokens')['body'], $raw) && !str_contains(html_as(1, '/admin/tokens')['body'], $raw) && !str_contains(html_as(1, '/settings/tokens/')['body'], $raw), 'the first key appears on no later screen, JSON or HTML');
$blob = json_encode(q('SELECT action, before::text, after::text FROM activity_log WHERE id > :s', ['s' => $since])) . json_encode(q('SELECT label, token_hash FROM mcp_access_tokens'));
ok(!str_contains($blob, $raw) && !str_contains($blob, $mm[1]) && !preg_match('/crm_[0-9a-f]{48}/', $blob), 'no log row and no table holds a key\'s value');
$lg = q("SELECT after::text AS a, source FROM activity_log WHERE action = 'key.capture_mint' AND id > :s ORDER BY id LIMIT 1", ['s' => $since])[0];
ok(str_contains($lg['a'], "Contact form $x") && str_contains($lg['a'], '"source_id"') && str_contains($lg['a'], '"rate_per_day"'), 'key.capture_mint logs the label, the source and the limit: ' . $lg['a']);

echo "2. The list\n";
[$pkid, ] = key_as(26, "Priya export $x");
$mcp = post_as(26, '/settings/tokens/mint.php', ['label' => "Priya AI $x"]);
$data = screen(1, '/admin/tokens');
$byLabel = [];
foreach ($data['tokens'] as $tk) { $byLabel[$tk['label']] = $tk; }
$cap1 = $byLabel["Contact form $x"] ?? [];
ok(($cap1['kind'] ?? '') === 'lead_capture_key' && $cap1['source_name'] === 'Website' && $cap1['member_name'] !== null && $cap1['rate_per_day'] === $cap && $cap1['live'] === true && $cap1['calls_today'] === 0 && $cap1['last_used_at'] === null, 'the capture key: kind, source, owner, limit, no calls yet, live');
ok(($byLabel["Priya export $x"]['kind'] ?? '') === 'api_key' && $byLabel["Priya export $x"]['member_id'] === 26 && ($byLabel["Priya AI $x"]['kind'] ?? '') === 'mcp_token', 'a Rep\'s API key and her MCP token are in the list, each with its kind — "every key and token"');
ok(!str_contains(json_encode($data), 'token_hash') && !preg_match('/[0-9a-f]{64}/', json_encode($data)), 'no hash anywhere in the JSON');
$h = html_as(1, '/admin/tokens')['body'];
ok(str_contains($h, 'id="tokens-table"') && str_contains($h, 'id="key-' . $kid . '-kind"') && str_contains($h, 'id="key-' . $kid . '-revoke-btn"') && str_contains($h, 'id="capture-key-form"') && !str_contains($h, 'id="key-' . (int) $mcp['json']['record_id'] . '-revoke-btn"'), 'the page: the table, a Revoke on each live key (not on a person\'s MCP token), the mint form');
$hashes = array_column(q('SELECT token_hash FROM mcp_access_tokens'), 'token_hash');
ok(array_filter($hashes, static fn (string $x2): bool => str_contains($h, $x2) || str_contains(get_as(1, '/admin/tokens')['body'], $x2)) === [], 'and no stored hash on the page or in the JSON');

echo "3. What the key does\n";
$lead = api($raw, 'POST', '/api/v1/leads', ['first_name' => 'Cap', 'last_name' => "Tured $x", 'email' => "cap-$x@web.example.invalid", 'company' => "Capco $x"]);
$lid = (int) ($lead['json']['lead_id'] ?? 0);
ok($lead['code'] === 201 && $lid > 0 && (int) one('SELECT source_id FROM leads WHERE id = :i', ['i' => $lid]) === $web, 'POST /api/v1/leads with it: a lead from the key\'s source (slice 8\'s capture route)');
$el = api($raw, 'GET', '/api/v1/accounts');
ok($el['code'] === 403 && ($el['json']['error']['code'] ?? '') === 'capture_key', 'and nothing else: 403 capture_key on a read');
$cap1 = array_values(array_filter(screen(1, '/admin/tokens')['tokens'], static fn ($t) => $t['key_id'] === $kid))[0];
ok($cap1['calls_today'] >= 1 && $cap1['last_used_at'] !== null, 'the list shows its calls today and its last use');

echo "4. Revoke — slice 8's key_revoke\n";
$rv = req('POST', '/settings/tokens/key-revoke.php', ['jar' => $j, 'form' => ['csrf_token' => $t, 'api_key' => $kid, 'return_to' => '/admin/tokens']]);
ok(in_array($rv['code'], [302, 303], true) && str_starts_with($rv['location'], '/admin/tokens'), 'revoking from the admin\'s list lands back on it: ' . $rv['location']);
$cap1 = array_values(array_filter(screen(1, '/admin/tokens')['tokens'], static fn ($t) => $t['key_id'] === $kid))[0];
ok($cap1['revoked'] === true && $cap1['live'] === false && str_contains(html_as(1, '/admin/tokens')['body'], 'id="key-' . $kid . '-state">revoked<'), 'it reads revoked');
ok(api($raw, 'POST', '/api/v1/leads', ['last_name' => 'Late', 'company' => "Late $x"])['code'] === 401, 'and the key is dead at once: 401');
$ro = post_as(1, '/settings/tokens/key-revoke.php', ['api_key' => $pkid]);
ok($ro['code'] === 200, 'a holder of tokens.manage revokes another person\'s key too');
ok(post_as(26, '/settings/tokens/key-revoke.php', ['api_key' => (int) $r2['json']['record_id']])['code'] === 404, 'a Rep cannot revoke a capture key (404: it is not hers to see)');

echo "5. Refusals, caps, people only, rights\n";
$e = adm('/admin/tokens/capture-mint.php', ['source' => $web]);
ok($e['code'] === 422 && field_of($e) === 'label', 'no label names the field');
$e = adm('/admin/tokens/capture-mint.php', ['label' => 'x']);
ok($e['code'] === 422 && field_of($e) === 'source', 'no source names the field');
$off = (int) one("INSERT INTO lead_sources (name, kind, active) VALUES (:n, 'inbound', false) RETURNING id", ['n' => "Off $x"]);
$e = adm('/admin/tokens/capture-mint.php', ['label' => 'x', 'source' => $off]);
ok($e['code'] === 422 && field_of($e) === 'source', 'a source that is switched off is refused');
$e = adm('/admin/tokens/capture-mint.php', ['label' => 'x', 'source' => $web, 'rate_per_day' => 0]);
ok($e['code'] === 422 && field_of($e) === 'rate_per_day', 'a day limit of 0 names its field');
$e = adm('/admin/tokens/capture-mint.php', ['label' => str_repeat('l', 81), 'source' => $web]);
ok($e['code'] === 422 && field_of($e) === 'label', 'a label over 80 characters');
$live = (int) one("SELECT count(*) FROM mcp_access_tokens WHERE scope = 'api' AND lead_source_id IS NOT NULL AND revoked_at IS NULL AND (expires_at IS NULL OR expires_at > now())");
pg_run("INSERT INTO mcp_access_tokens (member_id, label, token_hash, scope, lead_source_id) SELECT 1, 'fill ' || g, md5(g::text || '$x') || md5('$x' || g::text), 'api', $web FROM generate_series(1, " . (50 - $live) . ") g");
$e = adm('/admin/tokens/capture-mint.php', ['label' => 'one too many', 'source' => $web]);
ok($e['code'] === 422 && str_contains(says($e), '50 lead-capture keys are live'), '50 live lead-capture keys is the cap: ' . message_of($e));
$pk = post_as(1, '/settings/tokens/key-mint.php', ['label' => "Admin personal $x"]);
ok($pk['code'] === 200, 'the capture keys do not count against the admin\'s ten personal keys: a personal key still mints');
pg_run("UPDATE mcp_access_tokens SET revoked_at = now() WHERE label LIKE 'fill %'");
foreach ([42, 40] as $agent) {
    $e = agent_post($agent, 9700 + $agent, '/admin/tokens/capture-mint.php', ['label' => "By agent $agent", 'source' => $web]);
    ok($e['code'] === 403 && (int) one("SELECT count(*) FROM mcp_access_tokens WHERE label = :l", ['l' => "By agent $agent"]) === 0, "an agent ($agent) cannot mint a capture key: " . $e['code']);
}
foreach ([26 => 'a Rep', 27 => 'the Sales Manager', 29 => 'a Viewer'] as $m => $who) {
    ok(get_as($m, '/admin/tokens')['code'] === 403 && post_as($m, '/admin/tokens/capture-mint.php', ['label' => 'x', 'source' => $web])['code'] === 403, "$who: the screen and the mint are 403");
}
$mkt = role_actor(31, 'marketing');
ok(get_role($mkt, '/admin/tokens')['code'] === 403, 'Marketing too');
$tk = html_as(26, '/settings/tokens/')['body'];
ok(!str_contains($tk, "Contact form $x"), 'a Rep\'s own Tokens screen does not list a capture key');
finish();
