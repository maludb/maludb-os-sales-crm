<?php
/**
 * Proof — who may call, and what each caller is offered (tool surface "Who may call"; server_common.make_app): no token and a bad one are 401; a records-API key (scope api) is refused by the MCP servers; a person's token
 * sees every tool but the five shares; the tenant's signed action token resolves a member the mirror knows and ADMITS, never creates one; an agent's run token is judged by the kernel (run-facts, cached 300 s) and offers
 * exactly the tools the kernel named for THIS endpoint — a token the kernel does not vouch for, or cannot be asked about, offers nothing (fail closed); an evaluation run may read; the kernel's own 60-second token reaches
 * app_roles and the five shares and nothing else, and who asks a share comes from the K26 headers; every tool is read-only, closed-world, described, and a validation failure is an answer.
 */
require __DIR__ . '/lib.php';
$w = p4_world();
$S = surface();
echo "1. Tokens\n";
ok(mcp_tools(REC, '') === null && mcp_tools(ACT, '') === null, 'no token: 401 on both servers');
ok(mcp_tools(REC, 'mcp_' . str_repeat('0', 48)) === null && mcp_tools(REC, 'junk') === null, 'an unknown mcp_ token and a junk string: 401');
ok(mcp_tools(REC, api_token(PRIYA)) === null && mcp_tools(ACT, api_token(PRIYA)) === null, 'a records-API key (scope api) is refused by both servers — keys are for the API');
$expired = 'mcp_' . bin2hex(random_bytes(24));
q("INSERT INTO mcp_access_tokens (member_id, label, token_hash, scope, expires_at) VALUES (26, 'SMOKE p4 expired', :h, 'mcp', now() - interval '1 hour')", ['h' => hash('sha256', $expired)]);
$revoked = 'mcp_' . bin2hex(random_bytes(24));
q("INSERT INTO mcp_access_tokens (member_id, label, token_hash, scope, revoked_at) VALUES (26, 'SMOKE p4 revoked', :h, 'mcp', now())", ['h' => hash('sha256', $revoked)]);
ok(mcp_tools(REC, $expired) === null && mcp_tools(REC, $revoked) === null, 'an expired token and a revoked one: 401');
$names = mcp_tools(REC, ptoken(PRIYA));
ok($names === $S['person'], 'a person\'s token lists every records tool of the surface but the five shares — ' . count($names ?? []) . ' of ' . count($S['person']) . ($names === $S['person'] ? '' : ' | missing: ' . implode(',', array_diff($S['person'], $names ?? [])) . ' | extra: ' . implode(',', array_diff($names ?? [], $S['person']))));
ok(array_intersect($names ?? [], $S['shares']) === [], 'no share is among them: a person never lists or calls the kernel\'s shares');
$an = mcp_tools(ACT, ptoken(PRIYA));
ok($an === $S['activity'] && count($an) === 7, 'the activity server lists its seven — ' . implode(',', $an ?? []));
ok(in_array('record_history', $an ?? [], true) && !in_array('record_history', $names ?? [], true), 'record_history is the activity server\'s (the records role reads no part of the log)');
ok(mcp_tools(REC, ptoken(ANN)) === $S['person'] && mcp_tools(REC, ptoken(ADMIN)) === $S['person'], 'a Viewer and the admin see the same list of tools — what differs is the rows (the views)');

echo "2. Every tool: read-only, closed-world, described\n";
$defs = mcp_tool_defs(REC, ptoken(PRIYA));
$bad = [];
foreach ($defs as $d) {
    $a = $d['annotations'] ?? [];
    if (($a['readOnlyHint'] ?? null) !== true || ($a['openWorldHint'] ?? null) !== false) { $bad[] = $d['name'] . ' annotations'; }
    if (strlen($d['description'] ?? '') < 50) { $bad[] = $d['name'] . ' description'; }
    $schema = $d['inputSchema'] ?? [];
    $props = $schema['properties']['params'] ?? null;
    $defs2 = $schema['$defs'] ?? [];
    $model = $props === null ? null : (isset($props['$ref']) ? ($defs2[basename($props['$ref'])] ?? null) : $props);
    if ($model !== null) {
        if (($model['additionalProperties'] ?? null) !== false) { $bad[] = $d['name'] . ' extra=forbid'; }
        foreach ($model['properties'] ?? [] as $pn => $pd) { if (strlen($pd['description'] ?? '') < 3) { $bad[] = $d['name'] . ".$pn description"; } }
        foreach (['limit' => 100, 'offset' => 10000] as $lk => $top) {
            $m = $model['properties'][$lk]['maximum'] ?? null;
            if ($m !== null && $m > ($lk === 'limit' ? 500 : 100000)) { $bad[] = $d['name'] . ".$lk maximum $m"; }
        }
    }
}
ok($bad === [], 'all ' . count($defs) . ' tools are readOnlyHint true, openWorldHint false, described, take one params object that forbids extras with every field described' . ($bad ? ' — ' . implode('; ', array_slice($bad, 0, 8)) : ''));
$adefs = mcp_tool_defs(ACT, ptoken(PRIYA));
$bad = [];
foreach ($adefs as $d) { $a = $d['annotations'] ?? []; if (($a['readOnlyHint'] ?? null) !== true || ($a['openWorldHint'] ?? null) !== false || strlen($d['description'] ?? '') < 50) { $bad[] = $d['name']; } }
ok($bad === [] && count($adefs) === 7, 'the seven activity tools are read-only, closed-world and described');

echo "3. A person's action token (the command bar's), and the mirror\n";
ok(mcp_tools(REC, atoken(PRIYA)) === $S['person'], 'a signed action token for a member the mirror knows and admits: the same tools');
ok(mcp_tools(REC, atoken(99999)) === null, 'an unknown member id: 401 — the server never creates a member');
ok(mcp_tools(REC, atoken(WATCHER)) === null, 'an agent the directory has not admitted: 401 on its plain action token');
ok(mcp_tools(REC, atoken(PRIYA, -10)) === null, 'an expired action token: 401');
$t = atoken(PRIYA); $forged = substr($t, 0, -4) . (substr($t, -4) === 'aaaa' ? 'bbbb' : 'aaaa');
ok(mcp_tools(REC, $forged) === null, 'a forged signature: 401');
$bea = tool(BEA, 'find_deals', ['status' => ['open']]);
$pri = tool(PRIYA, 'find_deals', ['status' => ['open']]);
ok(!$bea['error'] && ids($bea['data']['rows'], 'deal_id') === ids($pri['data']['rows'], 'deal_id') && !str_contains($bea['text'], 'p4 Hidden'), 'Bea (capability write, her roles have not arrived) is a Sales Rep by capability: she reads what a Rep reads, and nothing restricted');

echo "4. An agent's run token — the kernel decides\n";
$r1 = runid(1);
facts($r1, EXPERT, ['Records MCP' => ['find_deals' => [], 'get_deal' => []]]);
ok(mcp_tools(REC, rtoken(EXPERT, $r1)) === ['find_deals', 'get_deal'], 'the Expert, granted find_deals and get_deal on Records MCP, is offered exactly those');
$c = mcp_tool(REC, rtoken(EXPERT, $r1), 'find_deals', ['status' => ['open']]);
ok(!$c['error'] && count($c['data']['rows'] ?? []) >= 1, 'a granted tool answers as the agent (a Rep: it reads what a Rep reads)');
$c = mcp_tool(REC, rtoken(EXPERT, $r1), 'find_leads', []);
ok($c['error'] && str_contains($c['text'], 'not among the tools'), 'a tool it was not granted is refused by name: ' . substr($c['text'], 0, 90));
ok(mcp_tools(ACT, rtoken(EXPERT, $r1)) === [], 'on the activity endpoint the same run is offered nothing (the grants are per endpoint)');
$r2 = runid(2);
facts($r2, EXPERT, ['Records MCP' => ['find_deals' => []], 'Activity MCP' => ['record_history' => []]]);
ok(mcp_tools(ACT, rtoken(EXPERT, $r2)) === ['record_history'], 'granted on both endpoints: each server offers its own');
$r3 = runid(3);
facts($r3, EXPERT, [], ['valid' => false]);
ok(mcp_tools(REC, rtoken(EXPERT, $r3)) === [], 'a token the kernel does not vouch for (valid: false): admitted agent, no tools');
$r4 = runid(4);
facts($r4, EXPERT, ['Records MCP' => ['find_deals' => []]], ['trigger' => 'eval']);
$c = mcp_tool(REC, rtoken(EXPERT, $r4), 'find_deals', []);
ok(!$c['error'], 'an evaluation run may read (these servers never write)');
$r5 = runid(5);
facts($r5, WATCHER, ['Records MCP' => ['find_deals' => []]]);
$names = mcp_tools(REC, rtoken(WATCHER, $r5));
ok($names === ['find_deals'], 'a vouched agent the directory has not yet admitted is admitted on first contact (mcp_admit_agent) — ' . json_encode($names));
$r6 = runid(6);
facts($r6, ASSISTANT, []);
ok(mcp_tools(REC, rtoken(ASSISTANT, $r6)) === [], 'an admitted agent whose run names no endpoint here is offered nothing');
echo "   -- fail closed\n";
$r7 = runid(7);
facts($r7, EXPERT, ['Records MCP' => ['find_deals' => []]]);
kernel_ctl('stop');
ok(mcp_tools(REC, rtoken(EXPERT, $r7)) === [], 'the kernel cannot be asked: an uncached run is offered nothing');
$c = mcp_tool(REC, rtoken(EXPERT, $r7), 'find_deals', []);
ok($c['error'], 'and calls nothing');
kernel_ctl('start');
ok(mcp_tools(REC, rtoken(EXPERT, $r7)) === ['find_deals'], 'the kernel back: the same run is offered its tool');
mcp_ctl('variant notoken');
$r8 = runid(8);
facts($r8, EXPERT, ['Records MCP' => ['find_deals' => []]]);
ok(mcp_tools(VARIANT, rtoken(EXPERT, $r8)) === [], 'a server with no application token cannot ask the kernel: an agent is offered nothing');
$r9 = runid(9);
facts($r9, WATCHER, ['Records MCP' => ['find_deals' => []]]);
ok(mcp_tools(VARIANT, rtoken(WATCHER, $r9)) === null || mcp_tools(VARIANT, rtoken(WATCHER, $r9)) === [], 'and an agent it has not admitted is not admitted by it');
ok(mcp_tools(VARIANT, ptoken(PRIYA)) === $S['person'], 'a person is unaffected');
mcp_ctl('variant stop');

echo "5. The kernel's own token\n";
$kt = kernel_token();
$kn = mcp_tools(REC, $kt);
ok($kn === $S['kernel_tools'] && count($kn) === 6, 'lists app_roles and the five shares, nothing else — ' . implode(',', $kn ?? []));
ok(mcp_tools(ACT, kernel_token()) === [], 'the activity server offers the kernel nothing');
$c = mcp_tool(REC, kernel_token(), 'find_leads', []);
ok($c['error'] && str_contains($c['text'], 'reaches'), 'a records tool is refused to the kernel: ' . substr($c['text'], 0, 80));
$c = mcp_tool(REC, kernel_token(), 'app_roles', []);
ok(!$c['error'] && ($c['data']['schema'] ?? '') === 'os.app-roles/1' && count($c['data']['roles'] ?? []) === 5 && count($c['data']['rights'] ?? []) === 41, 'app_roles answers os.app-roles/1: 5 roles, 41 rights');
$roles = $c['data']['roles'] ?? [];
ok(array_column($roles, 'key') === ['viewer', 'user', 'marketing', 'manager', 'admin'] && ($roles[4]['is_admin'] ?? null) === true && count($roles[4]['rights'] ?? []) === 41 && count($roles[0]['rights'] ?? []) === 2, 'the roles in order with their rights: Viewer 2, Sales Rep (user), Marketing, Sales Manager, CRM admin (all 41)');
$c = mcp_tool(REC, ptoken(PRIYA), 'app_roles', []);
ok(!$c['error'] && ($c['data']['application'] ?? '') === 'sales_crm', 'a person may read the catalogue too');
$c = mcp_tool(REC, kernel_token(), 'accounts_index', [], true);
ok($c['code'] === 'consumer_unknown', 'a share with no X-OS-Consumer header: consumer_unknown, nothing shared');
foreach (['GL', 'a b', '1gl', str_repeat('x', 61), "gl;drop"] as $bad) {
    $c = mcp_tool(REC, kernel_token(), 'accounts_index', [], true, ['X-OS-Consumer: ' . $bad]);
    if ($c['code'] !== 'consumer_unknown') { ok(false, "a malformed consumer header '$bad' must be consumer_unknown, got " . json_encode($c['code'])); }
}
ok(true, 'a malformed consumer header (upper case, spaces, leading digit, 61 characters, punctuation) is consumer_unknown');
$c = mcp_tool(REC, kernel_token(), 'accounts_index', [], true, ['X-OS-Consumer: gl']);
ok(!$c['error'] && ($c['data']['schema'] ?? '') === 'os.sales-crm-accounts/1', 'with the header the share answers its document');
$c = mcp_tool(REC, kernel_token(), 'accounts_index', ['consumer' => 'evil', 'as_agent' => 'x', 'scope_id' => 3, 'limit' => 5], true, ['X-OS-Consumer: gl']);
ok(!$c['error'] && ($c['data']['schema'] ?? '') === 'os.sales-crm-accounts/1', 'a `consumer`, `as_agent` or `scope_id` the consumer put in the arguments is dropped, not an error');
$c = mcp_tool(REC, ptoken(PRIYA), 'accounts_index', []);
ok($c['error'] && (str_contains($c['text'], 'kernel') || str_contains($c['text'], 'Unknown tool')), 'a person calling a share is refused');
$c = mcp_tool(REC, rtoken(EXPERT, $r1), 'accounts_index', []);
ok($c['error'], 'so is an agent, even with the name in its grants');
$expiredKernel = 'kernel.' . (time() - 5) . '.sales_crm.' . bin2hex(random_bytes(16));
$expiredKernel .= '.' . hash_hmac('sha256', 'kernel:' . implode('.', array_slice(explode('.', $expiredKernel), 1, 3)), need('ACTION_TOKEN_KEY'));
ok(mcp_tools(REC, $expiredKernel) === null, 'an expired kernel token: 401');
ok(mcp_tools(REC, kernel_token('other_app')) === null, 'a kernel token bound to another application: 401');
$kt2 = kernel_token(); $kt2 = substr($kt2, 0, -3) . (substr($kt2, -3) === 'abc' ? 'def' : 'abc');
ok(mcp_tools(REC, $kt2) === null, 'a forged kernel token: 401');

echo "6. Errors are answers\n";
$c = mcp_tool(REC, ptoken(PRIYA), 'find_deals', ['limit' => 9999]);
ok($c['code'] === 'invalid_argument' && in_array('limit', $c['data']['error']['fields'] ?? [], true) && !str_contains($c['text'], '9999'), 'an out-of-range limit: invalid_argument naming the field, without echoing the value');
$c = mcp_tool(REC, ptoken(PRIYA), 'get_deal', []);
ok($c['code'] === 'invalid_argument' && in_array('deal', $c['data']['error']['fields'] ?? [], true), 'a missing required field: invalid_argument naming it');
$c = mcp_tool(REC, ptoken(PRIYA), 'find_deals', ['frobnicate' => 1]);
ok($c['code'] === 'invalid_argument', 'an unknown field is refused (extra = forbid)');
$c = mcp_tool(REC, ptoken(PRIYA), 'no_such_tool', []);
ok($c['error'], 'an unknown tool is an error');
$c = mcp_tool(REC, ptoken(PRIYA), 'get_deal', ['deal' => 99999999]);
$d = mcp_tool(REC, ptoken(PRIYA), 'get_deal', ['deal' => 'No Such Deal Anywhere']);
ok($c['code'] === 'not_found' && $d['code'] === 'not_found' && ($c['data']['error']['retry'] ?? null) === false, 'a missing record, by id or by name: not_found, retry false');
$c = mcp_tool(REC, ptoken(PRIYA), 'get_deal', ['deal' => 'p4']);
ok($c['code'] === 'ambiguous' && count($c['data']['error']['candidates'] ?? []) >= 2 && count($c['data']['error']['candidates']) <= 6 && isset($c['data']['error']['candidates'][0]['id'], $c['data']['error']['candidates'][0]['label']), 'a name that matches several: ambiguous with at most six candidates (id and label)');
finish();
