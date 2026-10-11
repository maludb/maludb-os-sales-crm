<?php
/**
 * Proof — keys (agents-k7-api.md "The API", binding point 2): key_mint shows the raw key ONCE and stores only its hash; the key is a person's door that acts as them; a person may lower the limits but not raise them
 * above the settings' (only tokens.manage may); at most ten live keys; rotate gives a new key and lets the old one live the overlap; a rotated lead-capture key stays a capture key (db/023); revoke is the owner's or tokens.manage's;
 * an agent's key_mint pauses through the registry and is refused in the handler; no log row ever holds a key or its hash.
 */
require __DIR__ . '/lib.php';
$x = run_tag();
$since = last_activity_id();

echo "1. Mint: shown once, only the hash stored\n";
$r = post_as(26, '/settings/tokens/key-mint.php', ['label' => "Nightly $x"]);
$raw = $r['json']['key'] ?? '';
$id = (int) ($r['json']['record_id'] ?? 0);
ok($r['code'] === 200 && preg_match('/^crm_[0-9a-f]{48}$/', $raw) === 1 && $id > 0, 'key_mint answers the raw key (crm_ + 48 hex) and the key id');
$row = q('SELECT * FROM mcp_access_tokens WHERE id = :i', ['i' => $id])[0];
ok($row['token_hash'] === hash('sha256', $raw) && $row['scope'] === 'api' && (int) $row['member_id'] === 26 && (int) $row['rate_per_minute'] === 60 && (int) $row['rate_per_day'] === 5000 && $row['lead_source_id'] === null, 'stored: sha256 only, scope api, Priya\'s, the settings\' 60 a minute and 5,000 a day');
ok(!str_contains(json_encode($row), $raw), 'the raw key is nowhere in the row');
$list = get_as(26, '/settings/tokens/')['json']['data'];
$mine = array_values(array_filter($list['keys'], static fn ($k) => $k['key_id'] === $id))[0] ?? null;
ok($mine !== null && $mine['kind'] === 'person' && $mine['live'] === true && !str_contains(json_encode($list), $raw) && !str_contains(json_encode($list), $row['token_hash']), 'the tokens screen lists it (JSON) without the key or its hash');
$h = html_as(26, '/settings/tokens/')['body'];
ok(str_contains($h, 'id="keys-list"') && str_contains($h, 'id="key-' . $id . '"') && !str_contains($h, $raw), 'the HTML screen: keys-list, key-' . $id . ', and no value');
$log = q("SELECT * FROM activity_log WHERE action = 'key.mint' AND id > :s", ['s' => $since]);
ok(count($log) === 1 && (int) $log[0]['token_id'] === $id && $log[0]['source'] === 'web' && json_decode($log[0]['after'], true)['label'] === "Nightly $x", 'key.mint logged with the key id and its label');
// the browser path: the value is shown once on the next screen
[$j, ] = sign_on(26);
$csrf = page_csrf($j);
$r = req('POST', '/settings/tokens/key-mint.php', ['jar' => $j, 'headers' => ['X-CSRF-Token: ' . $csrf], 'form' => ['label' => "Browser $x"]]);
ok(in_array($r['code'], [302, 303], true) && str_contains($r['location'], '/settings/tokens/'), 'a browser is sent back to the tokens screen');
$p1 = req('GET', '/settings/tokens/', ['jar' => $j])['body'];
preg_match('#id="keys-minted-value">(crm_[0-9a-f]{48})<#', $p1, $m);
ok(isset($m[1]) && hash('sha256', $m[1]) === q('SELECT token_hash FROM mcp_access_tokens WHERE label = :l', ['l' => "Browser $x"])[0]['token_hash'], 'the new screen shows the key once…');
$p2 = req('GET', '/settings/tokens/', ['jar' => $j])['body'];
ok(!str_contains($p2, 'keys-minted') && !str_contains($p2, $m[1]), '…and the next visit shows nothing');

echo "2. Limits: lower, never higher\n";
$r = post_as(26, '/settings/tokens/key-mint.php', ['label' => "Low $x", 'rate_per_minute' => 10, 'rate_per_day' => 100, 'expires_in_days' => 30]);
$lk = q('SELECT * FROM mcp_access_tokens WHERE id = :i', ['i' => (int) $r['json']['record_id']])[0];
ok($r['code'] === 200 && (int) $lk['rate_per_minute'] === 10 && (int) $lk['rate_per_day'] === 100 && abs(strtotime($lk['expires_at']) - strtotime('+30 days')) < 120, 'a Rep may lower the limits and set an expiry');
$r = post_as(26, '/settings/tokens/key-mint.php', ['label' => "High $x", 'rate_per_minute' => 100000]);
ok($r['code'] === 422 && field_of($r) === 'rate_per_minute', 'a Rep may not raise the minute limit above the settings\': ' . message_of($r));
$r = post_as(26, '/settings/tokens/key-mint.php', ['label' => "High $x", 'rate_per_day' => 9999999]);
ok($r['code'] === 422 && field_of($r) === 'rate_per_day', '…nor the day limit');
$r = post_as(1, '/settings/tokens/key-mint.php', ['label' => "Admin high $x", 'rate_per_minute' => 500, 'rate_per_day' => 20000]);
$ak = q('SELECT * FROM mcp_access_tokens WHERE id = :i', ['i' => (int) ($r['json']['record_id'] ?? 0)])[0] ?? [];
ok($r['code'] === 200 && (int) ($ak['rate_per_minute'] ?? 0) === 500, 'the admin (tokens.manage) may raise them');
$r = post_as(26, '/settings/tokens/key-mint.php', ['label' => '']);
ok($r['code'] === 422 && field_of($r) === 'label', 'a label is required');
$r = post_as(26, '/settings/tokens/key-mint.php', ['label' => str_repeat('x', 81)]);
ok($r['code'] === 422 && field_of($r) === 'label', 'a label is up to 80 characters');
$r = post_as(26, '/settings/tokens/key-mint.php', ['label' => "Exp $x", 'expires_in_days' => 4000]);
ok($r['code'] === 422 && field_of($r) === 'expires_in_days', 'an expiry is 1 to 3650 days');

echo "3. At most ten live keys\n";
pg_run("UPDATE mcp_access_tokens SET revoked_at = now() WHERE member_id = 30 AND scope = 'api'");
$ids = [];
for ($i = 1; $i <= 10; $i++) { $r = post_as(30, '/settings/tokens/key-mint.php', ['label' => "Dana $i $x"]); $ids[] = (int) ($r['json']['record_id'] ?? 0); }
ok(!in_array(0, $ids, true), 'Dana mints ten');
$r = post_as(30, '/settings/tokens/key-mint.php', ['label' => "Dana 11 $x"]);
ok($r['code'] === 422 && str_contains(message_of($r), 'revoke one'), 'the eleventh is refused in words: ' . message_of($r));
ok(post_as(30, '/settings/tokens/key-revoke.php', ['api_key' => $ids[0]])['code'] === 200, 'she revokes one…');
ok(post_as(30, '/settings/tokens/key-mint.php', ['label' => "Dana 11 $x"])['code'] === 200, '…and may mint again');

echo "4. Rotate: a new key, the old one lives the overlap\n";
[$rid, $rraw] = key_as(26, "Rotate me $x", ['expires_in_days' => 60]);
$r = post_as(26, '/settings/tokens/key-rotate.php', ['api_key' => $rid]);
$nraw = $r['json']['key'] ?? '';
$nid = (int) ($r['json']['record_id'] ?? 0);
ok($r['code'] === 200 && preg_match('/^crm_[0-9a-f]{48}$/', $nraw) === 1 && $nraw !== $rraw && $nid > 0 && $nid !== $rid, 'key_rotate answers a new key once');
$n = q('SELECT * FROM mcp_access_tokens WHERE id = :i', ['i' => $nid])[0];
$o = q('SELECT * FROM mcp_access_tokens WHERE id = :i', ['i' => $rid])[0];
ok((int) $n['rotated_from'] === $rid && $n['label'] === "Rotate me $x" && (int) $n['member_id'] === 26 && abs(strtotime($n['expires_at']) - strtotime('+60 days')) < 120, 'the new key: the same person and label, rotated_from the old, the old one\'s own expiry kept (db/023)');
ok($o['revoked_at'] === null && abs(strtotime($o['expires_at']) - strtotime('+24 hours')) < 120, 'the old key now expires in 24 hours (the overlap)');
ok(api($rraw, 'GET', '/api/v1/leads')['code'] === 200 && api($nraw, 'GET', '/api/v1/leads')['code'] === 200, 'both work during the overlap');
pg_run("UPDATE mcp_access_tokens SET expires_at = now() - interval '1 minute' WHERE id = $rid");
ok(api($rraw, 'GET', '/api/v1/leads')['code'] === 401 && api($nraw, 'GET', '/api/v1/leads')['code'] === 200, 'after the overlap the old one is refused, the new one works');
ok(post_as(26, '/settings/tokens/key-rotate.php', ['api_key' => $rid])['code'] === 404, 'an expired key cannot be rotated');
ok(post_as(30, '/settings/tokens/key-rotate.php', ['api_key' => $nid])['code'] === 404, 'another person\'s key cannot be rotated: 404 (the view hides it)');
ok(post_as(1, '/settings/tokens/key-rotate.php', ['api_key' => $nid])['code'] === 404, 'not even the admin rotates it (a key is rotated by its person)');
ok(count(activity('key.rotate', $since)) === 1, 'key.rotate logged');

echo "5. A lead-capture key stays a capture key when rotated (db/023)\n";
[$cid, $craw] = capture_key(1, source_id('Website'), "Website form $x", 600);
$r = post_as(1, '/settings/tokens/key-rotate.php', ['api_key' => $cid]);
$cn = q('SELECT * FROM mcp_access_tokens WHERE id = :i', ['i' => (int) ($r['json']['record_id'] ?? 0)])[0] ?? [];
ok($r['code'] === 200 && (int) ($cn['lead_source_id'] ?? 0) === source_id('Website') && (int) $cn['rate_per_day'] === 600, 'the rotated capture key keeps its source and its day limit — it is not turned into the admin\'s full key');
$cr = api($r['json']['key'] ?? '', 'GET', '/api/v1/accounts');
ok($cr['code'] === 403 && ($cr['json']['error']['code'] ?? '') === 'capture_key', 'and it still answers 403 capture_key anywhere but the capture route');

echo "6. Revoke\n";
[$vid, $vraw] = key_as(26, "Revoke me $x");
ok(api($vraw, 'GET', '/api/v1/leads')['code'] === 200, 'a live key works');
ok(post_as(30, '/settings/tokens/key-revoke.php', ['api_key' => $vid])['code'] === 404, 'another Rep cannot revoke it: 404');
$r = post_as(26, '/settings/tokens/key-revoke.php', ['api_key' => $vid]);
ok($r['code'] === 200 && api($vraw, 'GET', '/api/v1/leads')['code'] === 401, 'its owner revokes it: it stops at once');
ok(post_as(26, '/settings/tokens/key-revoke.php', ['api_key' => $vid])['code'] === 404, 'revoking twice: 404');
[$v2, $v2raw] = key_as(26, "Admin revokes $x");
$r = post_as(1, '/settings/tokens/key-revoke.php', ['api_key' => $v2]);
ok($r['code'] === 200 && api($v2raw, 'GET', '/api/v1/leads')['code'] === 401, 'the admin (tokens.manage) revokes anyone\'s');
[$mcid, ] = capture_key(27, source_id('Website'), "Marco's form $x", 600);
$adminView = get_as(1, '/settings/tokens/')['json']['data'];
$seen = array_values(array_filter($adminView['other_keys'], static fn ($k) => $k['key_id'] === $mcid));
ok($seen !== [] && $seen[0]['kind'] === 'lead_capture' && $seen[0]['member_id'] === 27, 'the admin\'s screen also lists other people\'s keys, the lead-capture ones among them');
ok(get_as(26, '/settings/tokens/')['json']['data']['other_keys'] === [], '…a Rep\'s does not');

echo "7. An agent: the key is a person's door\n";
$tok = run_token(40, 981);
$r = req('POST', '/settings/tokens/key-mint.php', ['headers' => as_agent($tok), 'form' => ['label' => "Agent key $x"]]);
ok($r['code'] === 403 && (int) one("SELECT count(*) FROM mcp_access_tokens WHERE label = :l", ['l' => "Agent key $x"]) === 0, 'key_mint under an agent\'s run token is refused (people only) and makes nothing');
$reg = json_decode((string) file_get_contents(dirname(__DIR__, 3) . '/mcp/action_registry.json'), true);
ok(($reg['actions']['key_mint']['approval'] ?? null) === 'other' && ($reg['actions']['key_mint']['log_event'] ?? '') === 'key.mint' && !empty($reg['actions']['key_mint']['built']) && ($reg['actions']['key_mint']['agent'] ?? true) === false,
    'through the registry key_mint is a built action that PAUSES for an agent as `other` (and is then refused: people only)');
ok(($reg['actions']['key_rotate']['approval'] ?? null) === null && ($reg['actions']['key_revoke']['approval'] ?? null) === null, 'key_rotate and key_revoke carry no pause');
$w = json_decode((string) file_get_contents(dirname(__DIR__, 3) . '/maludb-os.json'), true)['approvals'] ?? [];
ok(count(array_filter($w, static fn ($a) => ($a['action'] ?? $a['tool'] ?? '') === 'key_mint')) === 1, 'maludb-os.json approvals[] holds key_mint');

echo "8. The log never holds a key\n";
$all = log_text_since($since);
ok(!str_contains($all, $raw) && !str_contains($all, $nraw) && !str_contains($all, $rraw) && !str_contains($all, hash('sha256', $raw)) && !preg_match('/crm_[0-9a-f]{48}/', $all), 'no key, no hash, in the activity log');
finish();
