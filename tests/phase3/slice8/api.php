<?php
/**
 * Proof — the records API (agents-k7-api.md "The API", binding point 2): only a Bearer key — no cookie, no session, none set; one 401 body for every failure; rates 429 with Retry-After; CORS from the listed origins echoed
 * exactly, never with credentials; a key acts as its person, so the walls apply (a restricted account is no one else's, a Viewer reads and cannot write, a Rep edits only what a Rep may); the routes are the screens' and the
 * actions' own code; every call is logged api.call with the key's id, never the key; an agent's key writes nothing.
 */
require __DIR__ . '/lib.php';
$x = run_tag();
$since = last_activity_id();
$book = seed_book();
pg_run("UPDATE mcp_access_tokens SET revoked_at = now() WHERE member_id IN (26, 27, 29, 30) AND scope = 'api' AND revoked_at IS NULL");      // the keys proof left some: start from none
[$pk, $priya] = key_as(26, "Priya's script $x");
[$mk, $marco] = key_as(27, "Marco's script $x");
[$ak, $ann] = key_as(29, "Ann's script $x");
$globex = (int) one("SELECT id FROM accounts WHERE name = 'SMOKE Globex'");

echo "1. Who is let in: a key, nothing else\n";
$bad = [api(null, 'GET', '/api/v1/leads'), api('crm_' . str_repeat('0', 48), 'GET', '/api/v1/leads'), api('garbage', 'GET', '/api/v1/leads')];
ok(count(array_filter($bad, static fn ($r) => $r['code'] === 401)) === 3 && count(array_unique(array_map(static fn ($r) => $r['body'], $bad))) === 1, 'no key, an unknown key and garbage: the same 401 body — ' . $bad[0]['body']);
ok(has_header($bad[0], 'WWW-Authenticate'), '…with WWW-Authenticate: Bearer');
[$mtok, $mraw] = [null, 'mcp_' . bin2hex(random_bytes(24))];
pg_run("INSERT INTO mcp_access_tokens (member_id, label, token_hash, scope) VALUES (26, 'a read token $x', '" . hash('sha256', $mraw) . "', 'mcp')");
ok(api($mraw, 'GET', '/api/v1/leads')['code'] === 401, 'a person\'s read-only MCP token is not a key: 401');
[$j, ] = sign_on(26);
$csrf = page_csrf($j);
$r = req('GET', '/api/v1/accounts', ['jar' => $j, 'headers' => ['Accept: application/json']]);
ok($r['code'] === 401, 'a signed-in browser session with no key: 401 — the API reads no cookie');
$r = req('POST', '/api/v1/accounts', ['jar' => $j, 'headers' => ['Accept: application/json', 'X-CSRF-Token: ' . $csrf], 'json' => ['name' => "Session cookie $x"]]);
ok($r['code'] === 401 && (int) one("SELECT count(*) FROM accounts WHERE name = :n", ['n' => "Session cookie $x"]) === 0, 'a session cookie with a CSRF token cannot write through the API either');
$r = req('GET', '/api/v1/accounts', ['jar' => $j, 'headers' => ['Accept: application/json', 'Authorization: Bearer ' . $priya]]);
ok($r['code'] === 200 && !has_header($r, 'Set-Cookie'), 'a key with a cookie jar attached: served as the key, no Set-Cookie');
foreach ([['200', api($priya, 'GET', '/api/v1/accounts')], ['401', $bad[0]], ['404', api($priya, 'GET', '/api/v1/accounts/99999999')]] as [$c, $resp]) {
    ok(!has_header($resp, 'Set-Cookie') && (string) $resp['code'] === $c, "$c answer: no Set-Cookie");
}
$sessions = (int) one('SELECT count(*) FROM member_sessions');
api($priya, 'GET', '/api/v1/leads');
ok((int) one('SELECT count(*) FROM member_sessions') === $sessions, 'the API opens no session');

echo "2. CORS\n";
$r = req('OPTIONS', '/api/v1/leads', ['headers' => ['Origin: https://www.example.invalid', 'Access-Control-Request-Method: POST', 'Access-Control-Request-Headers: authorization,content-type']]);
ok($r['code'] === 204 && header_of($r, 'Access-Control-Allow-Origin') === 'https://www.example.invalid' && str_contains(strtolower(header_of($r, 'Access-Control-Allow-Headers')), 'authorization') && str_contains(header_of($r, 'Access-Control-Allow-Methods'), 'PATCH'),
    'a preflight from a listed origin: 204, that origin echoed exactly, Authorization allowed, PATCH among the methods');
ok(!has_header($r, 'Access-Control-Allow-Credentials') && header_of($r, 'Vary') !== '', '…no Allow-Credentials, Vary: Origin');
$r = req('OPTIONS', '/api/v1/leads', ['headers' => ['Origin: https://evil.example.invalid', 'Access-Control-Request-Method: POST']]);
ok($r['code'] === 204 && !has_header($r, 'Access-Control-Allow-Origin'), 'a preflight from an unlisted origin: no Allow-Origin');
$r = api($priya, 'GET', '/api/v1/leads', null, ['Origin: https://shop.example.invalid']);
ok($r['code'] === 200 && header_of($r, 'Access-Control-Allow-Origin') === 'https://shop.example.invalid' && header_of($r, 'Access-Control-Allow-Origin') !== '*', 'a real call from the second listed origin: echoed exactly, never *');
$r = api($priya, 'GET', '/api/v1/leads', null, ['Origin: https://www.example.invalid.evil.example']);
ok(!has_header($r, 'Access-Control-Allow-Origin'), 'a look-alike origin is not echoed');

echo "3. Rates: 429 with Retry-After\n";
[$lowId, $low] = key_as(26, "Three a minute $x", ['rate_per_minute' => 3, 'rate_per_day' => 5]);
$codes = [];
for ($i = 0; $i < 3; $i++) { $codes[] = api($low, 'GET', '/api/v1/leads')['code']; }
$r = api($low, 'GET', '/api/v1/leads');
$ra = (int) header_of($r, 'Retry-After');
ok($codes === [200, 200, 200] && $r['code'] === 429 && ($r['json']['error']['code'] ?? '') === 'rate_limited' && $ra >= 1 && $ra <= 60, 'three calls pass, the fourth in the minute is 429 rate_limited with Retry-After ' . $ra);
$u = q("SELECT * FROM key_usage WHERE token_id = :t AND bucket_kind = 'minute'", ['t' => $lowId])[0] ?? [];
ok((int) ($u['calls'] ?? 0) === 3 && (int) ($u['refused'] ?? 0) >= 1, 'key_usage counts three calls and the refusal');
pg_run("UPDATE key_usage SET calls = 0 WHERE token_id = $lowId AND bucket_kind = 'minute'");
api($low, 'GET', '/api/v1/leads'); api($low, 'GET', '/api/v1/leads');
$r = api($low, 'GET', '/api/v1/leads');
ok($r['code'] === 429 && str_contains($r['json']['error']['message'] ?? '', 'day') && (int) header_of($r, 'Retry-After') > 60, 'the day limit (5) answers 429 too, its Retry-After running to midnight');
ok((int) one("SELECT count(*) FROM activity_log WHERE action = 'api.call' AND token_id = :t AND (after->>'status')::int = 429", ['t' => $lowId]) >= 2, 'the refused calls are logged api.call with status 429');

echo "4. A key sees what its person sees\n";
$list = fn (string $k, string $q = '') => api($k, 'GET', '/api/v1/accounts' . $q)['json']['data'] ?? [];
$ids = static fn (array $d) => array_column($d['accounts'] ?? [], 'account_id');
$mine = $list($priya, '?q=SMOKE');
ok(in_array($book['acme'] ?? (int) one("SELECT id FROM accounts WHERE name = 'SMOKE Acme Industries'"), $ids($mine), true) && !in_array($globex, $ids($mine), true), 'Priya\'s key: Acme is listed, Marco\'s restricted Globex is not');
ok(in_array($globex, $ids($list($marco, '?q=SMOKE')), true), 'Marco\'s key lists Globex');
ok(api($priya, 'GET', "/api/v1/accounts/$globex")['code'] === 404 && api($marco, 'GET', "/api/v1/accounts/$globex")['code'] === 200, 'GET one: Globex is a 404 for Priya, a 200 for Marco');
$one = api($marco, 'GET', "/api/v1/accounts/$globex")['json']['data']['account'] ?? [];
ok(($one['name'] ?? '') === 'SMOKE Globex' && !isset($one['password']) && !str_contains(json_encode($one), 'search_index'), 'the record is the screen\'s whitelisted presentation');
$acme = (int) one("SELECT id FROM accounts WHERE name = 'SMOKE Acme Industries'");
$viaApi = api($priya, 'GET', "/api/v1/accounts/$acme")['json'];
$viaScreen = get_as(26, "/accounts/$acme")['json'];
ok($viaApi === $viaScreen, 'GET /api/v1/accounts/{id} is the screen\'s JSON, byte for byte');
$viaApiList = api($priya, 'GET', '/api/v1/leads?status=new&page=1')['json'];
$viaScreenList = get_as(26, '/leads?status=new&page=1')['json'];
ok($viaApiList === $viaScreenList && isset($viaApiList['data']['leads']), 'GET /api/v1/leads with filters is the screen\'s JSON (the find_leads filters)');
foreach (['deals', 'contacts', 'activities', 'leads'] as $kind) {
    $r = api($priya, 'GET', "/api/v1/$kind");
    ok($r['code'] === 200 && isset($r['json']['data']), "GET /api/v1/$kind lists");
}

echo "5. Create and change, as the person\n";
$r = api($priya, 'POST', '/api/v1/accounts', ['name' => "Via API $x", 'website' => "https://api-$x.example.invalid"]);
$aid = (int) ($r['json']['record_id'] ?? 0);
ok($r['code'] === 200 && $aid > 0 && ($r['json']['ok'] ?? false) === true, 'POST /api/v1/accounts creates (account_create) and answers the record id');
$row = q('SELECT * FROM accounts WHERE id = :i', ['i' => $aid])[0] ?? [];
ok((int) ($row['owner_member_id'] ?? 0) === 26 && (int) ($row['created_by'] ?? 0) === 26, 'owned by and created by Priya — the key\'s person');
$cl = q("SELECT * FROM activity_log WHERE action = 'account.create' AND entity_id = :i", ['i' => $aid])[0] ?? [];
ok(($cl['source'] ?? '') === 'api' && (int) ($cl['token_id'] ?? 0) === $pk && (int) $cl['actor_member_id'] === 26, 'logged account.create with source api and the key\'s token id');
$r = api($priya, 'PATCH', "/api/v1/accounts/$aid", ['industry' => 'Software', 'employees' => '11-50']);
$row2 = q('SELECT * FROM accounts WHERE id = :i', ['i' => $aid])[0];
ok($r['code'] === 200 && $row2['industry'] === 'Software' && $row2['employees'] === '11-50' && $row2['name'] === "Via API $x" && $row2['website'] === "https://api-$x.example.invalid", 'PATCH changes the fields sent and leaves the rest as they were');
$r = api($priya, 'PATCH', "/api/v1/accounts/$aid", ['industry' => null]);
ok($r['code'] === 200 && q('SELECT industry FROM accounts WHERE id = :i', ['i' => $aid])[0]['industry'] === null, 'a JSON null clears a field');
$other = api($priya, 'PATCH', "/api/v1/accounts/$acme", ['industry' => 'Hijacked']);
$acmeOwner = (int) one('SELECT owner_member_id FROM accounts WHERE id = :i', ['i' => $acme]);
$r30 = api(key_as(30, "Dana $x")[1], 'PATCH', "/api/v1/accounts/$acme", ['industry' => 'Hijacked']);
ok($acmeOwner === 26 && $other['code'] === 200 && $r30['code'] === 403 && str_contains($r30['json']['error']['message'] ?? '', 'Only'), 'Dana\'s key may not change Priya\'s account: 403 in the screen\'s own sentence — ' . ($r30['json']['error']['message'] ?? ''));
ok(api($priya, 'PATCH', "/api/v1/accounts/$globex", ['industry' => 'x'])['code'] === 404, 'PATCH on a record behind the wall: 404');
ok(api($ann, 'POST', '/api/v1/accounts', ['name' => "Viewer $x"])['code'] === 403, 'a Viewer\'s key reads and may not write: 403');
ok(api($ann, 'GET', '/api/v1/accounts')['code'] === 200, '…it reads');
$r = api($priya, 'POST', '/api/v1/accounts', ['name' => "Spoof $x", 'id' => $acme, 'account' => $acme]);
$spoof = (int) ($r['json']['record_id'] ?? 0);
ok($r['code'] === 200 && $spoof > 0 && $spoof !== $acme && q('SELECT name FROM accounts WHERE id = :i', ['i' => $acme])[0]['name'] === 'SMOKE Acme Industries', 'an id in the body names nothing: it still creates a new record and touches no other');
$r = api($priya, 'POST', "/api/v1/accounts/$aid", ['name' => 'x']);
ok($r['code'] === 405 && header_of($r, 'Allow') !== '', 'POST on a record: 405 with Allow (use PATCH)');
ok(api($priya, 'DELETE', "/api/v1/accounts/$aid")['code'] === 405 && api($priya, 'PATCH', '/api/v1/accounts', ['name' => 'x'])['code'] === 405, 'DELETE, and PATCH with no id: 405');
ok(api($priya, 'POST', '/api/v1/accounts', '{not json', ['Content-Type: application/json'])['code'] === 400, 'a body that is not JSON: 400');
ok(api($priya, 'POST', '/api/v1/accounts', ['name' => ''])['code'] === 422, 'a refusal of the screen\'s own: 422');
$big = req('POST', '/api/v1/accounts', ['headers' => ['Accept: application/json', 'Authorization: Bearer ' . $priya, 'Content-Type: application/json'], 'raw' => json_encode(['name' => 'x', 'notes' => str_repeat('A', 1100000)])]);
ok($big['code'] === 413, 'a body over 1 MB: 413');
$ct = api($priya, 'POST', '/api/v1/contacts', ['first_name' => 'Api', 'last_name' => "Contact $x", 'email' => "api-$x@api.example.invalid", 'account' => $aid, 'email_opt_out' => false]);
ok($ct['code'] === 200 && (int) one('SELECT account_id FROM contacts WHERE id = :i', ['i' => $ct['json']['record_id'] ?? 0]) === $aid, 'POST /api/v1/contacts with a JSON false and a related id');
$ld = api($priya, 'POST', '/api/v1/leads', ['company' => "Api Lead $x", 'email' => "lead-$x@api.example.invalid", 'owner' => 26]);
ok($ld['code'] === 200 && (int) one("SELECT owner_member_id FROM leads WHERE id = :i", ['i' => $ld['json']['record_id'] ?? 0]) === 26, 'POST /api/v1/leads as a person (lead_create) — a person\'s key, not a capture key');
$dl = api($priya, 'POST', '/api/v1/deals', ['title' => "Api Deal $x", 'account' => $aid, 'primary_contact' => $ct['json']['record_id'], 'value' => 5000, 'expected_close_date' => date('Y-m-d', strtotime('+10 days'))]);
ok($dl['code'] === 200 && (float) one('SELECT value FROM deals WHERE id = :i', ['i' => $dl['json']['record_id'] ?? 0]) === 5000.0, 'POST /api/v1/deals with a JSON number');
$ac = api($priya, 'POST', '/api/v1/activities', ['kind' => 'task', 'subject' => "Api task $x", 'deal' => $dl['json']['record_id'], 'due_at' => at('+2 days 10:00')]);
ok($ac['code'] === 200 && (int) one('SELECT deal_id FROM activities WHERE id = :i', ['i' => $ac['json']['record_id'] ?? 0]) === (int) $dl['json']['record_id'], 'POST /api/v1/activities (activity_create)');
$r = api($priya, 'PATCH', '/api/v1/deals/' . $dl['json']['record_id'], ['title' => "Api Deal renamed $x"]);
ok($r['code'] === 200 && one('SELECT title FROM deals WHERE id = :i', ['i' => $dl['json']['record_id']]) === "Api Deal renamed $x", 'PATCH /api/v1/deals/{id}');
$r = api($priya, 'PATCH', '/api/v1/activities/' . $ac['json']['record_id'], ['subject' => "Api task renamed $x"]);
ok($r['code'] === 200 && one('SELECT subject FROM activities WHERE id = :i', ['i' => $ac['json']['record_id']]) === "Api task renamed $x", 'PATCH /api/v1/activities/{id}');

echo "6. An agent's key writes nothing\n";
$rawAgent = 'crm_' . bin2hex(random_bytes(24));
pg_run("INSERT INTO mcp_access_tokens (member_id, label, token_hash, scope) VALUES (40, 'agent key $x', '" . hash('sha256', $rawAgent) . "', 'api')");
ok(api($rawAgent, 'GET', '/api/v1/accounts')['code'] === 200, 'an agent\'s key reads as the agent');
$r = api($rawAgent, 'POST', '/api/v1/accounts', ['name' => "Agent via API $x"]);
ok($r['code'] === 403 && (int) one("SELECT count(*) FROM accounts WHERE name = :n", ['n' => "Agent via API $x"]) === 0, 'but a write is refused (it acts through the tools, where the approval pause lives): ' . ($r['json']['error']['message'] ?? ''));

echo "7. The log\n";
$calls = q("SELECT * FROM activity_log WHERE action = 'api.call' AND id > :s AND token_id = :t ORDER BY id", ['s' => $since, 't' => $pk]);
$a0 = json_decode($calls[0]['after'], true);
ok(count($calls) >= 10 && $calls[0]['source'] === 'api' && isset($a0['method'], $a0['route'], $a0['status'], $a0['ms']) && $a0['key'] === "Priya's script $x", 'every authenticated call: api.call with source api, the token id, the method, the route, the status and the key\'s label');
ok(count(array_filter($calls, static fn ($c) => str_contains($c['after'], '{id}'))) >= 3, '…routes name their shape ({id}), not the record');
$all = log_text_since($since);
ok(!preg_match('/crm_[0-9a-f]{48}/', $all) && !str_contains($all, $priya) && !str_contains($all, hash('sha256', $priya)), 'no key and no hash in any log row');
$keysSeen = [];
foreach ($calls as $c) { $keysSeen = array_merge($keysSeen, array_keys(json_decode($c['after'], true))); }
ok(array_diff(array_unique($keysSeen), ['method', 'route', 'status', 'ms', 'key', 'capture']) === [], 'an api.call row carries only the method, route shape, status, time and the key\'s label — no query, no body');
$r401 = (int) one("SELECT count(*) FROM activity_log WHERE action = 'api.call' AND id > :s AND token_id IS NULL", ['s' => $since]);
ok($r401 === 0, 'an unauthenticated call has no identity and writes no row (the web server\'s access log has it)');

echo "8. The health check and the feed still answer\n";
ok(req('GET', '/api/v1/health')['code'] === 200 && !has_header(req('GET', '/api/v1/health'), 'Set-Cookie'), 'GET /api/v1/health: 200, no cookie');
ok(req('GET', '/api/v1/calendar.ics?token=nothing')['code'] === 404, 'GET /api/v1/calendar.ics with a wrong token: 404');
ok(req('GET', '/api/v1/members')['code'] === 404 && req('GET', '/api/v1/campaigns')['code'] === 404, 'a route that is not on the list: 404');
finish();
