<?php
/**
 * Proof — the lead-capture route (agents-k7-api.md "The API", leads-and-conversion.md §6; binding point 3): a website posts a lead with a capture key; the source is the key's and nothing the body claims; a filled honeypot
 * is success and makes nothing; lengths are capped; the 600 a day limit is the key's; nothing but the new lead's id comes back; every other route is 403 capture_key; the Assistant is woken for a first qualification.
 */
require __DIR__ . '/lib.php';
$x = run_tag();
$since = last_activity_id();
pg_run("UPDATE crm_settings SET assistant_proposals = true");
$web = source_id('Website');
[$kid, $key] = capture_key(1, $web, "Website form $x");
$day = (int) one('SELECT rate_per_day FROM mcp_access_tokens WHERE id = :i', ['i' => $kid]);
$lead = static fn (string $email) => ['first_name' => 'Wanda', 'last_name' => "Web $email", 'email' => $email, 'company' => "Webco $email", 'phone' => '+1 555 0100', 'message' => 'Please call me about the demo.'];

echo "1. A website posts a lead\n";
$email = "wanda-$x@web.example.invalid";
$r = api($key, 'POST', '/api/v1/leads', $lead($email) + ['utm_source' => 'newsletter', 'utm_medium' => 'email', 'utm_campaign' => "autumn-$x"]);
$lid = (int) ($r['json']['lead_id'] ?? 0);
ok($r['code'] === 201 && ($r['json']['ok'] ?? false) === true && $lid > 0 && array_keys($r['json']) === ['ok', 'lead_id'], 'POST /api/v1/leads with a capture key: 201 {ok, lead_id} and nothing else — ' . $r['body']);
$l = q('SELECT * FROM leads WHERE id = :i', ['i' => $lid])[0] ?? [];
ok((int) ($l['source_id'] ?? 0) === $web && $l['status'] === 'new' && $l['email'] === $email && $l['company'] === "Webco $email" && (int) $l['created_by'] === 1, 'a new lead from the key\'s source (Website), made by the key\'s person');
$utm = json_decode($l['utm'], true);
ksort($utm);
ok($utm === ['utm_campaign' => "autumn-$x", 'utm_medium' => 'email', 'utm_source' => 'newsletter'], 'the utm_* fields are kept as the lead\'s utm');
ok($l['owner_member_id'] === null || (int) one("SELECT count(*) FROM members WHERE id = :m AND status = 'active'", ['m' => $l['owner_member_id']]) === 1, 'the owner is the assignment rules\' (a member here) or none: the inbox');
$cl = q("SELECT * FROM activity_log WHERE action = 'lead.create' AND entity_id = :i", ['i' => $lid])[0] ?? [];
$ca = json_decode($cl['after'] ?? '{}', true);
ok(($cl['source'] ?? '') === 'api' && (int) ($cl['token_id'] ?? 0) === $kid && ($ca['via'] ?? '') === 'capture' && ($ca['key'] ?? '') === "Website form $x" && !str_contains($cl['after'], 'Please call'), 'logged lead.create with source api and the key\'s id, never the message');
ok((int) one("SELECT count(*) FROM activity_log WHERE action = 'api.call' AND token_id = :t AND (after->>'capture')::boolean", ['t' => $kid]) >= 1, '…and an api.call row marked as a capture call');
$day2 = (int) one('SELECT rate_per_day FROM mcp_access_tokens WHERE id = :i', ['i' => $kid]);
ok($day2 === 600 && $day === 600, 'a capture key\'s day limit is the setting\'s capture_rate_per_day: 600');

echo "2. The body cannot choose\n";
$r = api($key, 'POST', '/api/v1/leads', $lead("choose-$x@web.example.invalid") + ['source_id' => source_id('Outbound'), 'source' => 'Outbound', 'owner' => 27, 'owner_member_id' => 27, 'status' => 'qualified', 'score' => 99, 'id' => $lid, 'lead' => $lid,
    'converted_at' => '2020-01-01', 'custom_values' => ['x' => 1], 'campaign' => 1, 'created_by' => 27, 'restricted_to_member_id' => 27]);
$l2 = q('SELECT * FROM leads WHERE id = :i', ['i' => (int) ($r['json']['lead_id'] ?? 0)])[0] ?? [];
ok($r['code'] === 201 && (int) $l2['id'] !== $lid && (int) $l2['source_id'] === $web && $l2['status'] === 'new' && $l2['score'] === null && $l2['converted_at'] === null && (int) $l2['created_by'] === 1 && $l2['restricted_to_member_id'] === null && $l2['campaign_id'] === null,
    'source, owner, status, score, id, restriction and the rest are ignored: a new lead, the key\'s source, status new, nobody\'s secret');
ok(q('SELECT company FROM leads WHERE id = :i', ['i' => $lid])[0]['company'] === "Webco $email", 'and the first lead was not touched by the id in the body');

echo "3. The honeypot\n";
$n0 = (int) one('SELECT count(*) FROM leads');
$r = api($key, 'POST', '/api/v1/leads', $lead("bot-$x@web.example.invalid") + ['website_url' => 'http://spam.example.invalid']);
ok($r['code'] === 200 && ($r['json']['ok'] ?? false) === true && !isset($r['json']['lead_id']) && (int) one('SELECT count(*) FROM leads') === $n0, 'a filled website_url: 200 {ok: true}, no lead_id, nothing made');
ok(count(activity('lead.capture_trap', $since)) === 1, '…logged lead.capture_trap (no fields)');
$r = api($key, 'POST', '/api/v1/leads', $lead("bot2-$x@web.example.invalid") + ['website_url' => ' ']);
ok($r['code'] === 201, 'a blank website_url is not a trap');

echo "4. Lengths and shapes\n";
$too = static fn (string $f, int $n) => api($key, 'POST', '/api/v1/leads', [$f => str_repeat('a', $n)] + $lead("len-$x@web.example.invalid"));
foreach (['first_name' => 100, 'last_name' => 100, 'company' => 200, 'phone' => 40, 'message' => 2000, 'utm_source' => 200, 'utm_content' => 200] as $f => $max) {
    $r = $too($f, $max + 1);
    ok($r['code'] === 422 && str_contains($r['json']['error']['message'] ?? '', $f), "$f over $max characters: 422 naming it");
}
$r = api($key, 'POST', '/api/v1/leads', ['company' => str_repeat('c', 200), 'message' => str_repeat('m', 2000)] + $lead("len2-$x@web.example.invalid"));
ok($r['code'] === 201, 'exactly at the ceilings: accepted');
$r = api($key, 'POST', '/api/v1/leads', ['email' => 'not-an-email', 'company' => 'X']);
ok($r['code'] === 422 && str_contains($r['json']['error']['message'] ?? '', 'email'), 'a malformed email: 422');
$r = api($key, 'POST', '/api/v1/leads', ['message' => 'only a message']);
ok($r['code'] === 422 && str_contains($r['json']['error']['message'] ?? '', 'company'), 'nothing to call a lead by: 422');
$r = api($key, 'POST', '/api/v1/leads', ['company' => ['a'], 'email' => true]);
ok($r['code'] === 422, 'a list or a boolean where text belongs: 422');
$n1 = (int) one('SELECT count(*) FROM leads');
$r = req('POST', '/api/v1/leads', ['headers' => ['Accept: application/json', 'Authorization: Bearer ' . $key, 'Content-Type: application/json'], 'raw' => json_encode(['company' => 'Big', 'message' => str_repeat('A', 40000)])]);
ok($r['code'] === 413 && (int) one('SELECT count(*) FROM leads') === $n1, 'a body over 32 kB: 413, nothing made');
$r = req('POST', '/api/v1/leads', ['headers' => ['Accept: application/json', 'Authorization: Bearer ' . $key], 'form' => ['company' => "Form post $x", 'email' => "form-$x@web.example.invalid"]]);
ok($r['code'] === 201, 'a urlencoded form works as well as JSON');
$r = api($key, 'POST', '/api/v1/leads', ['company' => "<script>alert('x')</script> $x", 'email' => "xss-$x@web.example.invalid"]);
$xl = (int) ($r['json']['lead_id'] ?? 0);
$page = html_as(1, "/leads/$xl")['body'];
ok($r['code'] === 201 && !str_contains($page, "<script>alert('x')") && str_contains($page, '&lt;script&gt;'), 'markup in a field is stored as text and shown escaped');

echo "4b. Nothing else is readable or writable with a capture key\n";
$lead0 = (int) one('SELECT min(id) FROM leads');
$acc = (int) one('SELECT min(id) FROM accounts');
foreach ([['GET', '/api/v1/leads'], ['GET', "/api/v1/leads/$lid"], ['GET', '/api/v1/accounts'], ['GET', "/api/v1/accounts/$acc"], ['GET', '/api/v1/contacts'], ['GET', '/api/v1/deals'], ['GET', '/api/v1/activities'],
          ['POST', '/api/v1/accounts'], ['POST', '/api/v1/contacts'], ['POST', '/api/v1/deals'], ['POST', '/api/v1/activities'], ['PATCH', "/api/v1/leads/$lid"], ['PATCH', "/api/v1/accounts/$acc"]] as [$m, $p]) {
    $r = api($key, $m, $p, $m === 'GET' ? null : ['name' => 'x', 'company' => 'x']);
    ok($r['code'] === 403 && ($r['json']['error']['code'] ?? '') === 'capture_key' && !isset($r['json']['data']), "$m $p: 403 capture_key and no data");
}
ok(q('SELECT company FROM leads WHERE id = :i', ['i' => $lid])[0]['company'] === "Webco $email", 'a PATCH with a capture key changed nothing');
ok(req('GET', '/api/v1/health')['code'] === 200, 'the health check stays public');

echo "5. Rates: the day limit and the quota counting honeypot hits\n";
[$sid, $small] = capture_key(1, $web, "Small $x", 3);
$c = [];
for ($i = 0; $i < 3; $i++) { $c[] = api($small, 'POST', '/api/v1/leads', $lead("small$i-$x@web.example.invalid"))['code']; }
$r = api($small, 'POST', '/api/v1/leads', $lead("small4-$x@web.example.invalid"));
ok($c === [201, 201, 201] && $r['code'] === 429 && (int) header_of($r, 'Retry-After') > 0, 'three leads, then 429 with Retry-After');
[$tid, $trap] = capture_key(1, $web, "Trap $x", 2);
api($trap, 'POST', '/api/v1/leads', ['website_url' => 'x']); api($trap, 'POST', '/api/v1/leads', ['website_url' => 'x']);
ok(api($trap, 'POST', '/api/v1/leads', $lead("trap-$x@web.example.invalid"))['code'] === 429, 'honeypot hits count against the day limit: a bot cannot probe for free');

echo "6. CORS from the website\n";
$r = req('OPTIONS', '/api/v1/leads', ['headers' => ['Origin: https://www.example.invalid', 'Access-Control-Request-Method: POST', 'Access-Control-Request-Headers: authorization,content-type']]);
ok($r['code'] === 204 && header_of($r, 'Access-Control-Allow-Origin') === 'https://www.example.invalid', 'the preflight from the listed site passes');
$r = api($key, 'POST', '/api/v1/leads', $lead("cors-$x@web.example.invalid"), ['Origin: https://www.example.invalid']);
ok($r['code'] === 201 && header_of($r, 'Access-Control-Allow-Origin') === 'https://www.example.invalid' && !has_header($r, 'Access-Control-Allow-Credentials'), 'the post is answered with that origin, no credentials header');

echo "7. The Assistant is woken for a first qualification\n";
$d = q("SELECT * FROM agent_dispatches WHERE lead_id = :l", ['l' => $lid])[0] ?? [];
$assistant = (int) one("SELECT id FROM members WHERE member_kind = 'agent' AND status = 'active' AND capability IS NOT NULL AND display_name ILIKE '%assistant%' ORDER BY id LIMIT 1");
ok($assistant > 0 && ($d['kind'] ?? '') === 'lead_qualify' && (int) ($d['agent_member_id'] ?? 0) === $assistant && $d['status'] === 'sent' && $d['via'] === 'chat' && (int) $d['attempts'] === 1, 'a lead_qualify dispatch for the Assistant, sent, via chat');
ok($d['acting_member_id'] !== null, '…with a person to act as (the lead\'s owner, else the key\'s person)');
pg_run("UPDATE crm_settings SET assistant_proposals = false");
$r = api($key, 'POST', '/api/v1/leads', $lead("quiet-$x@web.example.invalid"));
ok($r['code'] === 201 && (int) one('SELECT count(*) FROM agent_dispatches WHERE lead_id = :l', ['l' => $r['json']['lead_id']]) === 0, 'with the Assistant\'s proposals off, the lead is made and nobody is woken');
pg_run("UPDATE crm_settings SET assistant_proposals = true");

echo "8. Revoked, and no cookie\n";
[$rid, $rk] = capture_key(1, $web, "Revoked $x");
pg_run("UPDATE mcp_access_tokens SET revoked_at = now() WHERE id = $rid");
$r = api($rk, 'POST', '/api/v1/leads', $lead("revoked-$x@web.example.invalid"));
ok($r['code'] === 401 && (int) one("SELECT count(*) FROM leads WHERE email = :e", ['e' => "revoked-$x@web.example.invalid"]) === 0, 'a revoked capture key: 401');
ok(!has_header(api($key, 'POST', '/api/v1/leads', $lead("cookie-$x@web.example.invalid")), 'Set-Cookie'), 'the capture route sets no cookie');
$all = log_text_since($since);
ok(!str_contains($all, $key) && !preg_match('/crm_[0-9a-f]{48}/', $all) && !str_contains($all, 'Please call me'), 'no key and no message in the log');
finish();
