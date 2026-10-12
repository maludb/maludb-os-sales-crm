<?php
/**
 * Proof — the registry wrapper and the manifest, against the KERNEL's own code (read only): deploy/kernel-registry-sales_crm.json + mcp/action_registry.json, composed as the installer writes
 * mcp/registries/sales_crm.json, are loaded by the kernel's loader (application_actions.load_registries) and registered on a FastMCP exactly as the kernel's actions server does
 * (application_actions.register): a tool per built action, approval categories the kernel knows, the `confirmed` guard on destructive ones; every resolve entity is answered by THIS application's live records server through
 * the kernel's own resolver (application_actions.resolve_via_mcp) with the caller's token; and whole actions are driven through the kernel's tool (the approval hook and the handler stubbed — nothing is written): an agent's
 * email_send is a paused action, the Assistant's proposals are not. Then maludb-os.json: the agents' grants name only tools and actions that exist (Phase 4 reconciled them), the Assistant sends nothing, deletes nothing and
 * changes no rule, and the Assistant's duty is declared. K55: our action names that other registries also use are listed.
 */
require __DIR__ . '/lib.php';
$w = p4_world();
$S = surface();
$root = dirname(__DIR__, 2);
$wrapper = json_decode((string) file_get_contents($root . '/deploy/kernel-registry-sales_crm.json'), true);
$reg = json_decode((string) file_get_contents($root . '/mcp/action_registry.json'), true);
$manifest = json_decode((string) file_get_contents($root . '/maludb-os.json'), true);
$actions = $reg['actions'];
$KNOWN_CATEGORIES = ['money_out', 'deletion', 'external_send', 'other'];
$KERNEL_TOOLS = ['message_send', 'inbox_read'];

echo "1. The wrapper, the registry and the manifest read right\n";
ok(($wrapper['schema'] ?? '') === 'maludb-os.registry/1' && $wrapper['app_key'] === 'sales_crm' && count($wrapper['resolve']) === 17, 'the wrapper: schema maludb-os.registry/1, app_key sales_crm, seventeen resolve entities');
$php = (int) shell_exec('cd ' . escapeshellarg($root) . ' && php bin/build_action_registry.php --check >/dev/null 2>&1; echo $?');
ok($php === 0, 'the registry and maludb-os.json approvals[] are current with the action manifest (bin/build_action_registry.php --check)');
ok(count($actions) === 121 && count(array_filter($actions, fn ($x) => !empty($x['built']))) === 121, 'the registry holds 121 actions, every one built');
$cats = array_unique(array_filter(array_column($actions, 'approval')));
$withCat = count(array_filter($actions, fn ($x) => $x['approval'] !== null));
ok(count(array_diff($cats, $KNOWN_CATEGORIES)) === 0 && $withCat === 33, 'every approval category is one the kernel knows (' . implode(', ', $cats) . '); ' . $withCat . ' actions carry one');
$missing = [];
foreach ($actions as $key => $x) { $p = $root . '/html' . parse_url($x['endpoint'], PHP_URL_PATH); if (!is_file($p) && !is_file(preg_replace('/\{[a-z_]+\}/', '1', $p)) && !is_file(preg_replace('/\{[a-z_]+\}/', 'x', $p)) && !glob(preg_replace('/\{[a-z_]+\}/', '*', $p))) { $missing[] = $key . ' → ' . $x['endpoint']; } }
ok($missing === [], 'every action\'s endpoint exists under html/' . ($missing ? ' — missing: ' . implode(', ', array_slice($missing, 0, 5)) : ''));
$phantom = [];
foreach ($actions as $key => $x) { foreach ($x['params'] as $p) { if (($p['name'] ?? null) === null) { $phantom[] = $key; } } }
ok($phantom === [], 'no action carries a phantom parameter');
ok(array_filter($actions, fn ($x) => empty($x['log_event'])) === [], 'every action names the log event the approval hook asks about');

echo "2. Every resolve entity: its tool exists on the records server and answers the list envelope\n";
foreach ($wrapper['resolve'] as $entity => $spec) {
    ok(in_array($spec['tool'], $S['person'], true) && ($spec['query_param'] ?? '') === 'q' && !empty($spec['id_field']) && !empty($spec['label_field']) && !empty($spec['params']), "$entity: tool {$spec['tool']} is on the records server; id {$spec['id_field']}, label {$spec['label_field']}, params " . implode(', ', $spec['params']));
}
$pm = [];
foreach ($wrapper['resolve'] as $e => $spec) { foreach ($spec['params'] as $p) { $pm[$p][] = $e; } }
ok(count(array_filter($pm, fn ($e) => count($e) > 1)) === 0, 'no param is claimed by two entities');
$used = [];
foreach ($actions as $x) { foreach ($x['params'] as $p) { if (isset($pm[$p['name']])) { $used[$p['name']] = true; } } }
ok(count($used) >= 15, 'the resolved params are really used by actions (' . count($used) . ' distinct names)');
$resolve = [
    ['lead', 'SMOKE Initech', ptoken(PRIYA)], ['account', 'SMOKE Acme Industries', ptoken(PRIYA)], ['account', 'acme.example.invalid', ptoken(PRIYA)], ['contact', 'Dana Reyes', ptoken(PRIYA)], ['contact', 'dana.reyes@acme.example.invalid', ptoken(PRIYA)],
    ['deal', 'SMOKE Acme — 20 seats', ptoken(PRIYA)], ['deal', 'D-000001', ptoken(PRIYA)], ['pipeline', 'New business', ptoken(PRIYA)], ['pipeline', 'renewals', ptoken(PRIYA)], ['stage', 'Discovery', ptoken(PRIYA)],
    ['activity', 'SMOKE Send the proposal', ptoken(PRIYA)], ['campaign', 'p4 Spring Push', ptoken(PRIYA)], ['list', 'p4 Smart new leads', ptoken(PRIYA)], ['template', 'Follow-up', ptoken(PRIYA)], ['source', 'Website', ptoken(PRIYA)],
    ['lost_reason', 'Went with competitor', ptoken(PRIYA)], ['lost_reason', 'Spam', ptoken(PRIYA)], ['tag', 'p4-vip', ptoken(PRIYA)], ['saved_view', 'p4 My open deals', ptoken(PRIYA)], ['api_key', 'p4 priya key', ptoken(PRIYA)],
    ['member', 'SMOKE Marco', ptoken(PRIYA)], ['department', 'Sales', ptoken(PRIYA)],
];
$drive = [
    ['deal_move', ['deal' => 'SMOKE Acme — 20 seats', 'to_stage' => 'Discovery'], ptoken(PRIYA)],
    ['email_send', ['draft' => (string) $w['mail_draft'], 'confirm' => 'yes', 'confirmed' => true], rtoken(EXPERT, runid(90)), 'pending_approval'],
    ['email_send', ['draft' => (string) $w['mail_draft'], 'confirm' => 'yes'], ptoken(PRIYA)],
    ['records_merge', ['kind' => 'lead', 'survivor' => (string) $w['lead1'], 'loser' => (string) $w['lead_old'], 'confirmed' => true], ptoken(MARCO), 'pending_approval'],
    ['settings_save', ['business_name' => 'p4 never renamed'], ptoken(ADMIN), 'pending_approval'],
    ['deal_move', ['deal' => 'a deal that was never made', 'to_stage' => 'Discovery'], ptoken(PRIYA)],
    ['deal_move', ['deal' => 'SMOKE', 'to_stage' => 'Discovery'], ptoken(PRIYA)],
    ['next_step_propose', ['deal' => 'SMOKE Acme — support add-on', 'kind' => 'call', 'subject' => 'p4 propose', 'at' => date('Y-m-d\TH:i', time() + 86400)], rtoken(ASSISTANT, runid(91))],
    ['tag_apply', ['kind' => 'deal', 'record' => (string) $w['deal2'], 'tag' => 'p4-vip'], ptoken(PRIYA)],
    ['lead_update', ['lead' => 'SMOKE Initech', 'source' => 'Website'], ptoken(PRIYA)],
    ['lead_convert', ['lead' => 'SMOKE Initech', 'make_deal' => 'no'], ptoken(PRIYA)],
    ['key_mint', ['label' => 'p4 never minted'], ptoken(PRIYA), 'pending_approval'],
    ['activity_create', ['kind' => 'call', 'subject' => 'p4 Called Dana', 'deal' => 'SMOKE Acme — 20 seats', 'contact' => 'Dana Reyes', 'done' => 'yes', 'outcome' => 'connected'], ptoken(PRIYA)],
    ['activity_create', ['kind' => 'call', 'subject' => 'p4 Call Dana back', 'deal' => 'SMOKE Acme — 20 seats', 'contact' => 'Dana Reyes', 'due_at' => date('Y-m-d\TH:i', time() + 3 * 86400)], ptoken(PRIYA)],
];
facts(runid(90), EXPERT, agent_grants('expert'));
facts(runid(91), ASSISTANT, agent_grants('assistant'));
$kpy = '/var/www/mcp/venv/bin/python';
if (!is_executable($kpy)) { $kpy = trim((string) shell_exec('command -v python3')); }
$cmd = 'PYTHONDONTWRITEBYTECODE=1 ' . escapeshellarg($kpy) . ' -I ' . escapeshellarg(__DIR__ . '/registry_check.py') . ' ' . escapeshellarg($root) . ' ' . escapeshellarg('http://127.0.0.1:' . REC . '/mcp') . ' ' . escapeshellarg(json_encode(['resolve' => $resolve, 'drive' => $drive, 'env' => need('CRM_DEV_ENV')])) . ' 2>/tmp/crm-registry-check.err';
$out = (string) shell_exec($cmd);
$kr = json_decode($out, true);
ok(is_array($kr), 'the kernel\'s loader and tool factory ran against the composed registry' . (is_array($kr) ? '' : ' — ' . substr((string) @file_get_contents('/tmp/crm-registry-check.err'), -600)));
if (!is_array($kr)) { finish(); }
ok($kr['loaded'] === ['sales_crm'] && $kr['registered'] === 121, 'application_actions.load_registries() accepts it and register() adds 121 tools');
ok(count($kr['tools']) === 121 && count(array_diff(array_keys($actions), array_keys($kr['tools']))) === 0, 'one tool per action, by the action\'s name');
$noApp = [];
foreach ($actions as $key => $x) { if ($x['approval'] !== null && !str_contains($kr['tools'][$key]['description'], 'category: ' . $x['approval'])) { $noApp[] = $key; } }
ok($noApp === [], 'a tool\'s description states its approval category (' . $withCat . ' of them)');
$noConfirm = [];
foreach ($actions as $key => $x) { $txt = json_encode($kr['tools'][$key]['schema']); if (!empty($x['confirm']) && !str_contains($txt, 'confirmed')) { $noConfirm[] = $key; } }
ok($noConfirm === [], 'every destructive action carries the `confirmed` guard (' . count(array_filter($actions, fn ($x) => !empty($x['confirm']))) . ' of them)');
$noParam = [];
foreach ($actions as $key => $x) { $props = array_keys($kr['tools'][$key]['schema']['$defs'][array_key_first($kr['tools'][$key]['schema']['$defs'] ?? [])]['properties'] ?? []); foreach ($x['params'] as $p) { if (($p['name'] ?? null) && !in_array($p['name'], $props, true)) { $noParam[] = "$key.{$p['name']}"; } } }
ok($noParam === [], 'every manifest parameter is a field of its tool' . ($noParam ? ' — missing: ' . implode(', ', array_slice($noParam, 0, 5)) : ''));
echo "   resolving through the kernel's resolver, as the person who asks:\n";
foreach ($resolve as [$entity, $value]) {
    $r = $kr['resolved'][$entity . '|' . $value] ?? null;
    $spec = $wrapper['resolve'][$entity];
    ok($r !== null && !isset($r['error']) && $r['matches'] === 1 && $r['id'] !== null && $r['label'] !== null && in_array($spec['id_field'], $r['keys'], true) && in_array($spec['label_field'], $r['keys'], true),
        "resolver: $entity '" . substr((string) $value, 0, 30) . "' → 1 match" . ($r && isset($r['label']) ? ', ' . $spec['id_field'] . ' ' . substr((string) $r['id'], 0, 8) . ', "' . substr((string) $r['label'], 0, 40) . '"' : ' — ' . json_encode($r)));
}
$rid = fn ($e, $v) => (string) $kr['resolved'][$e . '|' . $v]['id'];
ok($rid('deal', 'D-000001') === (string) $w['deal1'] && $rid('account', 'acme.example.invalid') === (string) $w['acme'] && $rid('contact', 'dana.reyes@acme.example.invalid') === (string) $w['dana_c'], 'a deal by its number, an account by its domain, a contact by its email resolve to the right ids');
$d = $kr['driven'];
echo "3. Whole actions through the kernel's tool\n";
ok(($d[0]['answer']['status'] ?? '') === 'success' && count($d[0]['posts']) === 1 && $d[0]['posts'][0]['fields']['deal'] === (string) $w['deal1'] && $d[0]['posts'][0]['fields']['to_stage'] === $rid('stage', 'Discovery') && str_contains($d[0]['posts'][0]['path'], 'move'),
    'deal_move (no approval): the deal and the stage resolved by name to their ids; the handler is posted the ids and the kernel\'s hook is not asked — ' . ($d[0]['posts'][0]['path'] ?? ''));
$logEvent = $actions['email_send']['log_event'];
ok(($d[1]['answer']['status'] ?? '') === 'pending_approval' && count($d[1]['posts']) === 1 && $d[1]['posts'][0]['path'] === '/approvals/hook.php' && $d[1]['posts'][0]['fields']['action_key'] === 'email_send' && $d[1]['posts'][0]['fields']['log_event'] === $logEvent,
    'THE EXPERT AGENT\'S email_send PAUSES: the hook is asked about ' . $logEvent . ' (external_send) and answers pending_approval — nothing is sent');
ok(($d[2]['answer']['status'] ?? '') === 'needs_confirmation' && $d[2]['posts'] === [], 'email_send without `confirmed`: needs_confirmation, nothing posted — not even the hook');
ok(($d[3]['answer']['status'] ?? '') === 'pending_approval' && $d[3]['posts'][0]['fields']['action_key'] === 'records_merge' && str_contains($d[3]['posts'][0]['fields']['parameters'], '"loser"') && str_contains($d[3]['posts'][0]['fields']['parameters'], '"survivor"'),
    'records_merge (deletion, confirmed) pauses; survivor and loser travel as the ids they are (they are not resolved by name — the surface lists them as ids only)');
ok(($d[4]['answer']['status'] ?? '') === 'pending_approval' && $d[4]['posts'][0]['fields']['log_event'] === $actions['settings_save']['log_event'], 'settings_save (other) pauses');
ok(($d[5]['answer']['status'] ?? '') === 'error' && str_contains($d[5]['answer']['message'], "No deal matching 'a deal that was never made'") && $d[5]['posts'] === [], 'an unknown deal: "No deal matching …" and nothing posted');
ok(($d[6]['answer']['status'] ?? '') === 'error' && str_contains($d[6]['answer']['message'], 'Several match') && $d[6]['posts'] === [], 'an ambiguous name (SMOKE): the kernel lists the candidates and posts nothing');
ok(($d[7]['answer']['status'] ?? '') === 'success' && $d[7]['posts'][0]['fields']['deal'] === (string) $w['deal2'] && count($d[7]['posts']) === 1, 'next_step_propose by the Assistant runs unpaused: it is a proposal, a person decides');
ok(($d[8]['answer']['status'] ?? '') === 'success' && $d[8]['posts'][0]['fields']['tag'] === $rid('tag', 'p4-vip'), 'tag_apply: the tag resolved by label');
ok(($d[9]['answer']['status'] ?? '') === 'success' && $d[9]['posts'][0]['fields']['source'] === $rid('source', 'Website'), 'lead_update: the source resolved by name');
ok(($d[10]['answer']['status'] ?? '') === 'success' && $d[10]['posts'][0]['fields']['lead'] === (string) $w['lead1'], 'lead_convert: the lead resolved by its company');
ok(($d[11]['answer']['status'] ?? '') === 'pending_approval', 'key_mint pauses');
$logged = $d[12]['posts'][0]['fields'] ?? [];
$next = $d[13]['posts'][0]['fields'] ?? [];
ok(($d[12]['answer']['status'] ?? '') === 'success' && ($d[13]['answer']['status'] ?? '') === 'success' && $logged['deal'] === (string) $w['deal1'] && $logged['contact'] === (string) $w['dana_c'] && $logged['outcome'] === 'connected' && $next['deal'] === (string) $w['deal1'] && !isset($next['outcome']),
   'THE COMMAND BAR\'S ONE SENTENCE — "I called Dana about the seats; call her back Friday" — is two unpaused activity_create calls: the call logged as done with its outcome and the next step scheduled, the deal and the contact resolved by name to their ids');
ok((int) one("SELECT count(*) FROM crm_settings WHERE business_name LIKE 'p4 never%'") === 0 && (int) one("SELECT count(*) FROM mcp_access_tokens WHERE label = 'p4 never minted'") === 0, 'and nothing was written to the application: the handler posts were stubs');

echo "4. maludb-os.json is in step with what the servers offer\n";
$agents = array_column($manifest['agents'], null, 'key');
ok(array_keys($agents) === ['expert', 'assistant'], 'two agents hired on install: the expert and the Sales Assistant');
$badTool = [];
foreach ($manifest['agents'] as $ag) {
    foreach ($ag['tool_grants']['Records MCP'] ?? [] as $t) { if (!in_array($t, $S['person'], true)) { $badTool[] = $ag['key'] . ' Records ' . $t; } }
    foreach ($ag['tool_grants']['Activity MCP'] ?? [] as $t) { if (!in_array($t, $S['activity'], true)) { $badTool[] = $ag['key'] . ' Activity ' . $t; } }
    foreach ($ag['tool_grants']['Actions MCP'] ?? [] as $t) { if (!isset($actions[$t]) && !in_array($t, $KERNEL_TOOLS, true)) { $badTool[] = $ag['key'] . ' Actions ' . $t; } }
}
ok($badTool === [], 'every tool an agent is granted exists: a records or activity tool of the surface, an action of the registry (or the kernel\'s message tools)' . ($badTool ? ' — ' . implode(', ', $badTool) : ''));
$live = mcp_tools(REC, ptoken(MARCO));
$liveAct = mcp_tools(ACT, ptoken(MARCO));
$offered = [];
foreach ($manifest['agents'] as $ag) {
    foreach ($ag['tool_grants']['Records MCP'] ?? [] as $t) { if (!in_array($t, $live, true)) { $offered[] = $ag['key'] . ' ' . $t; } }
    foreach ($ag['tool_grants']['Activity MCP'] ?? [] as $t) { if (!in_array($t, $liveAct, true)) { $offered[] = $ag['key'] . ' ' . $t; } }
}
ok($offered === [], 'and every one of them is a tool the running servers really list');
$expectNot = ['find_keys', 'key_usage_summary', 'mailbox_health', 'find_imports', 'get_import', 'import_rows', 'reminders_due', 'agent_dispatches', 'records_search', 'app_roles'];
ok(array_intersect($expectNot, $agents['expert']['tool_grants']['Records MCP']) === [], 'the Expert holds none of the admin\'s reads (keys, mailbox, imports, reminders, dispatches, the guarded SQL, the catalogue)');
$expertRead = array_diff($S['person'], $expectNot);
$notGranted = array_values(array_diff($expertRead, $agents['expert']['tool_grants']['Records MCP']));
ok($notGranted === [], 'and every other records tool of the surface — the spec\'s rule' . ($notGranted ? ' — missing: ' . implode(', ', $notGranted) : ''));
$aAct = $agents['assistant']['tool_grants']['Actions MCP'];
$bad = array_values(array_filter($aAct, fn ($t) => in_array($actions[$t]['approval'] ?? null, ['money_out', 'deletion', 'external_send'], true) || !empty($actions[$t]['confirm']) || (preg_match('/(_send|_delete|_save|_purge|_merge|rules)/', $t) && !in_array($t, $KERNEL_TOOLS, true))));
ok($bad === [] && !in_array('email_send', $aAct, true), 'THE ASSISTANT SENDS NOTHING, DELETES NOTHING, CHANGES NO RULE: it holds ' . implode(', ', $aAct) . ' — a proposal, a draft, a notice, a message');
$eAct = $agents['expert']['tool_grants']['Actions MCP'];
ok(in_array('email_send', $eAct, true) && ($actions['email_send']['approval'] ?? '') === 'external_send' && $actions['email_send']['confirm'] === true, 'the Expert may ask to send an email — a paused, confirmed action (external_send)');
$weighty = [];
foreach ($eAct as $t) { if (preg_match('/delete|purge|credential|send|merge|revoke|rollback|reopen/', $t) && ($actions[$t]['approval'] ?? null) === null && empty($actions[$t]['confirm']) && !in_array($t, $KERNEL_TOOLS, true)) { $weighty[] = $t; } }
ok($weighty === [], 'every action of the Expert that sends, deletes, merges or revokes is a paused or confirmed one');
$grantedShares = array_intersect($S['shares'], array_merge($agents['expert']['tool_grants']['Records MCP'], $agents['assistant']['tool_grants']['Records MCP']));
ok($grantedShares === [] && !in_array('app_roles', $agents['expert']['tool_grants']['Records MCP'], true), 'no agent is granted a share or app_roles: those are the kernel\'s');
$duty = $agents['assistant']['duty'] ?? null;
ok($duty !== null && preg_match('/^(\S+\s+){4}\S+$/', $duty['schedule_cron']) === 1 && $duty['schedule_cron'] === '30 6 * * 1-5', 'the Assistant\'s duty is declared as the design says — weekdays 06:30');
ok(!isset($agents['expert']['duty']) && !isset($agents['expert']['duties']), 'and the Expert has none');
$shares = array_column($manifest['shares'], 'tool');
sort($shares);
$want = $S['shares']; sort($want);
ok($shares === $want, 'shares[] names exactly the five tools');
$bad = [];
foreach ($manifest['approvals'] as $x) { if (!isset($actions[$x['action']]) || $actions[$x['action']]['approval'] !== $x['category']) { $bad[] = $x['action']; } }
ok($bad === [] && count($manifest['approvals']) === $withCat, 'approvals[] is the manifest\'s ' . $withCat . ' pauses, each an action of the registry with that category');
foreach ($manifest['skills'] as $sk) { $p = is_array($sk) ? ($sk['path'] ?? '') : $sk; ok(is_file($root . '/' . $p . '/SKILL.md'), "skill $p has its SKILL.md"); }
foreach ($manifest['agents'] as $ag) { ok(is_file($root . '/' . $ag['job_description']), "the job description {$ag['job_description']} exists"); }
$known = array_merge($S['records'], $S['activity'], array_keys($actions), $KERNEL_TOOLS);
$unknown = [];
foreach (array_merge(glob($root . '/skills/*/SKILL.md'), glob($root . '/os/*.md')) as $f) {
    preg_match_all('/`([a-z]+(?:_[a-z0-9]+)+)`/', (string) file_get_contents($f), $m);
    foreach (array_unique($m[1]) as $name) {
        if (!in_array($name, $known, true) && $name !== 'contact_emails' && preg_match('/^(find|get|my|deal|lead|account|contact|activity|campaign|list|email|note|tag|pipeline|stage|forecast|import|record|records|actor|recent|agent|saved|search|source|template|timeline|settings|proposal|record|key|mailbox|segment|upcoming|leaders|win|funnel|cycle|products|quotas|tombstones|members|reminders|source|lost|stage|next)_/', $name)) { $unknown[] = basename(dirname($f)) . '/' . basename($f) . ': ' . $name; }
    }
}
ok($unknown === [], 'every tool or action a skill or a job description names exists' . ($unknown ? ' — ' . implode('; ', array_slice($unknown, 0, 14)) : ''));

echo "5. K55: our action names against the other registries on this host (information)\n";
$ours = array_keys($actions);
$collide = [];
foreach (glob('/var/www/mcp/registries/*.json') as $f) {
    if (basename($f) === 'sales_crm.json') { continue; }
    $r = json_decode((string) file_get_contents($f), true);
    foreach (array_keys($r['registry']['actions'] ?? []) as $name) { if (in_array($name, $ours, true)) { $collide[$name][] = basename($f, '.json'); } }
}
echo '   ' . count($collide) . ' of our ' . count($ours) . ' action names are also another registry\'s: ' . ($collide ? implode(', ', array_map(fn ($n, $a) => "$n (" . implode('/', $a) . ')', array_keys($collide), $collide)) : 'none') . "\n";
ok(true, 'listed (the kernel\'s installer gives a colliding action a tool_name "<app_key>_<action>"; no action is renamed here)');
finish();
