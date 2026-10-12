<?php
/**
 * Proof — coverage: every tool of the surface (85 records, 7 activity) was called through the real protocol by some proof in this run; every mcp_* view and every read function the surface names is read by a named tool;
 * the servers' sources hold no write (a share's `share.read` is PHP's, through the bridge), and the two read roles hold no write privilege and no privilege on the unwalled base tables; the kernel's list is app_roles and
 * the five shares of maludb-os.json; the units point at the right files.
 */
require __DIR__ . '/lib.php';
$S = surface();
$root = dirname(__DIR__, 2);
$called = array_values(array_unique(array_filter(array_map('trim', file(need('CRM_DEV_STATE') . '/p4-called.txt') ?: []))));

echo "1. Every tool was called\n";
$never = array_values(array_diff(array_merge($S['records'], $S['activity']), $called));
ok($never === [], 'all ' . (count($S['records']) + count($S['activity'])) . ' tools were called through the protocol in this run' . ($never ? ' — never called: ' . implode(', ', $never) : ''));
ok(count($S['records']) === 85 && count($S['activity']) === 7, '85 records tools (80 for a person or an agent, the five shares, app_roles within the 80) and 7 activity tools: the surface\'s count — ' . count($S['records']) . ' and ' . count($S['activity']));
$live = mcp_tools(REC, ptoken(ADMIN));
$liveAct = mcp_tools(ACT, ptoken(ADMIN));
$kern = mcp_tools(REC, kernel_token());
ok($live === $S['person'] && $liveAct === $S['activity'] && (function () use ($S, $live) { $d = array_values(array_diff($S['records'], $live)); sort($d); $sh = $S['shares']; sort($sh); return $d === $sh; })(), 'the servers offer exactly them (' . count($live) . ' to a person, the five shares to the kernel, ' . count($liveAct) . ' activity): none missing, none extra');
$extra = array_values(array_diff(array_merge($live, $kern, $liveAct), array_merge($S['records'], $S['activity'])));
ok($extra === [], 'every registered tool is named in the surface' . ($extra ? ' — not in the surface: ' . implode(', ', $extra) : ''));
$doc = (string) file_get_contents($root . '/docs/sales-crm-mcp-tool-surface.md');
$orphan = array_values(array_filter(array_merge($S['records'], $S['activity']), fn ($t) => !str_contains($doc, '`' . $t . '`')));
ok($orphan === [], 'and every tool is named in the surface document');
$mods = ['crm_leads', 'crm_accounts', 'crm_deals', 'crm_activities', 'crm_email', 'crm_business', 'crm_shares'];
$byModule = [];
foreach ($mods as $mod) { $src = (string) file_get_contents("$root/mcp/$mod.py"); preg_match_all('/@mcp\.tool\(name="([a-z_0-9]+)"/', $src, $mm); $byModule[$mod] = count($mm[1]); }
preg_match_all('/^@mcp\.tool\(name="([a-z_0-9]+)"/m', (string) file_get_contents($root . '/mcp/records_server.py'), $rs);
ok(array_sum($byModule) + count($rs[1]) === 85 && $rs[1] === ['app_roles'], 'the seven modules hold ' . implode(' + ', $byModule) . ' tools, and records_server.py app_roles: 85');
preg_match_all('/^@mcp\.tool\(name="([a-z_0-9]+)"/m', (string) file_get_contents($root . '/mcp/activity_server.py'), $as);
ok(count($as[1]) === 7, 'activity_server.py holds the seven');

echo "2. Every view and every read function is read by a named tool\n";
$py = '';
foreach (glob($root . '/mcp/crm_*.py') as $f) { $py .= file_get_contents($f) . "\n"; }
$act = (string) file_get_contents($root . '/mcp/activity_server.py');
$rec = (string) file_get_contents($root . '/mcp/records_server.py');
$views = array_column(q("SELECT viewname FROM pg_views WHERE schemaname = 'public' AND viewname LIKE 'mcp\\_%' ORDER BY 1"), 'viewname');
$recordsOnly = ['mcp_exports', 'mcp_notification_prefs'];
$unread = array_values(array_filter(array_diff($views, ['mcp_activity_log', 'mcp_app_roles'], $recordsOnly), fn ($v) => !str_contains($py . $rec . $act, $v)));
ok($unread === [], count($views) . ' mcp_* views; each but the activity log, app_roles and the ' . count($recordsOnly) . ' that only records_search reads is named in a tool\'s query' . ($unread ? ' — unread: ' . implode(', ', $unread) : ''));
ok(str_contains($rec, 'mcp_app_roles') && str_contains($act, 'mcp_activity_log') && !str_contains($py . $rec, 'FROM mcp_activity_log') && !str_contains($py . $rec, 'JOIN mcp_activity_log'), 'mcp_app_roles is app_roles\' and mcp_activity_log the activity server\'s alone (the records role cannot read it)');
preg_match_all('/\b(crm_[a-z0-9_]+)\(/', $doc, $fm);
$docFns = array_values(array_unique($fm[1]));
$notMine = ['crm_wall', 'crm_edit_wall', 'crm_is_member_here', 'crm_is_manager', 'crm_is_admin', 'crm_has_right', 'crm_resolve_key', 'crm_rate_ok', 'crm_key_calls_today', 'crm_lead_label'];     // the last is inside mcp_leads
$unreadFn = array_values(array_filter(array_diff($docFns, $notMine), fn ($f) => !str_contains($py . $act . $rec, $f) && !str_starts_with($f, 'crm_share_')));
ok($unreadFn === [], count($docFns) . ' crm_* functions named in the surface; each is called by a tool (the five crm_share_* by the application\'s bridge, PHP)' . ($unreadFn ? ' — uncalled: ' . implode(', ', $unreadFn) : ''));
$writers = ['crm_lead_convert', 'crm_assign_lead', 'crm_deal_move', 'crm_record_delete', 'crm_records_merge', 'crm_proposal_accept', 'crm_notify', 'crm_touch', 'crm_refresh_next_activity', 'crm_key_rotate', 'crm_rate_ok', 'crm_resolve_key', 'crm_index_record',
           'crm_search_catch_up', 'crm_tombstones_purge', 'crm_tombstones_purge_kinds', 'crm_deliver_reminder', 'crm_contact_opt_out', 'crm_activity_snooze', 'crm_contact_lifecycle', 'crm_account_recount', 'crm_match_inbound_email',
           'crm_prune_key_usage', 'crm_next_number', 'crm_reminders_due'];
$called2 = array_values(array_filter($writers, fn ($f) => preg_match('/\b' . $f . '\s*\(/', $py . $act . $rec)));
ok($called2 === [], 'the ' . count($writers) . ' functions that write (or serve only the worker) are called by no tool' . ($called2 ? ' — ' . implode(', ', $called2) : ''));
$granted = array_column(q("SELECT p.proname FROM pg_proc p JOIN pg_namespace n ON n.oid = p.pronamespace WHERE n.nspname = 'public' AND has_function_privilege('sales_crm_records_ro', p.oid, 'EXECUTE') AND p.proname = ANY (CAST(:w AS text[]))", ['w' => '{' . implode(',', $writers) . '}']), 'proname');
ok($granted === [], 'and the records role holds EXECUTE on none of them: no conversion, move, merge, delete, notice, key resolver, rate counter' . ($granted ? ' — ' . implode(', ', $granted) : ''));
$grantedAct = array_column(q("SELECT p.proname FROM pg_proc p JOIN pg_namespace n ON n.oid = p.pronamespace WHERE n.nspname = 'public' AND has_function_privilege('sales_crm_activity_ro', p.oid, 'EXECUTE') AND p.proname = ANY (CAST(:w AS text[]))", ['w' => '{' . implode(',', $writers) . '}']), 'proname');
ok($grantedAct === [], 'nor does the activity role');
$shareFns = array_column(q("SELECT p.proname FROM pg_proc p JOIN pg_namespace n ON n.oid = p.pronamespace WHERE n.nspname = 'public' AND p.proname LIKE 'crm\\_share\\_%' AND has_function_privilege('sales_crm_activity_ro', p.oid, 'EXECUTE')"), 'proname');
ok($shareFns === [], 'the activity role cannot call a share function; and the records role calls them only through records_search\'s deny list ("crm_share_") — never from a tool');
ok(!str_contains($py . $act . $rec, 'crm_share_'), 'no tool of either server mentions a crm_share_ function: a share is answered by the bridge');

echo "3. The servers never write; the read roles are read-only\n";
$writes = [];
foreach (array_merge(glob($root . '/mcp/{records_server,activity_server,crm_*,server_common,db}.py', GLOB_BRACE)) as $f) {
    foreach (file($f) as $n => $line) { if (preg_match('/\b(INSERT\s+INTO|UPDATE\s+\w+\s+SET|DELETE\s+FROM|TRUNCATE\s|DROP\s+(TABLE|VIEW|FUNCTION)|ALTER\s+(TABLE|ROLE))/', $line) && !preg_match('/^\s*#/', $line) && !str_contains($line, '" forbidden') && !str_contains($line, 'forbidden = (')) { $writes[] = basename($f) . ':' . ($n + 1) . ' ' . trim($line); } }
}
ok($writes === [], 'no INSERT, UPDATE, DELETE or DDL in a server\'s code' . ($writes ? ' — ' . implode(' | ', array_slice($writes, 0, 3)) : ''));
$ro = true;
foreach (['sales_crm_records_ro', 'sales_crm_activity_ro'] as $role) {
    $n = (int) one("SELECT count(*) FROM pg_class c JOIN pg_namespace n ON n.oid = c.relnamespace WHERE n.nspname = 'public' AND c.relkind IN ('r', 'v', 'p') AND (has_table_privilege(:r, c.oid, 'INSERT') OR has_table_privilege(:r2, c.oid, 'UPDATE') OR has_table_privilege(:r3, c.oid, 'DELETE') OR has_table_privilege(:r4, c.oid, 'TRUNCATE'))", ['r' => $role, 'r2' => $role, 'r3' => $role, 'r4' => $role]);
    $ro = $ro && $n === 0;
}
ok($ro, 'the two read roles hold no write privilege on any table or view (asked of pg_catalog, not information_schema, which hides other roles\' grants)');
foreach (['sales_crm_records_ro', 'sales_crm_activity_ro'] as $role) {
    $open = array_column(q("SELECT c.relname FROM pg_class c JOIN pg_namespace n ON n.oid = c.relnamespace WHERE n.nspname = 'public' AND c.relkind = 'r' AND has_table_privilege(:r, c.oid, 'SELECT') ORDER BY 1", ['r' => $role]), 'relname');
    $allowed = ['crm_rights', 'crm_role_rights', 'crm_roles'];
    ok(array_diff($open, $allowed) === [], "$role reads no base table but the three of the roles' catalogue (" . implode(', ', $open) . ') — the notes, emails, search index, tokens, trail and cache are behind the walled views' . (array_diff($open, $allowed) ? ' — also: ' . implode(', ', array_diff($open, $allowed)) : ''));
}
$callers = [];
foreach (glob($root . '/mcp/*.py') as $f) { if (basename($f) !== 'crm_bridge.py' && preg_match('/\bcrm_bridge\.(call|as_share|as_member)\(/', (string) file_get_contents($f))) { $callers[] = basename($f); } }
sort($callers);
ok($callers === ['crm_accounts.py', 'crm_shares.py'], 'the bridge is reached from the account tools (tickets, ledger) and the share tools, and from nowhere else — ' . implode(', ', $callers));
$before = (int) one("SELECT count(*) FROM activity_log WHERE action IN ('share.read', 'search.keyword')");
tool(PRIYA, 'find_deals', ['status' => ['open']]);
tool(PRIYA, 'search', ['q' => 'SMOKE']);
ok((int) one("SELECT count(*) FROM activity_log WHERE action IN ('share.read', 'search.keyword')") === $before, 'a read leaves the share log and the search log where they were — the servers write nothing');

echo "4. The kernel's list, the units\n";
$sc = (string) file_get_contents($root . '/mcp/server_common.py');
preg_match('/SHARES = \{([^}]*)\}/', $sc, $m);
preg_match_all('/"([a-z_]+)"/', $m[1], $sh);
$manifest = json_decode((string) file_get_contents($root . '/maludb-os.json'), true);
$decl = array_column($manifest['shares'], 'tool');
sort($decl); $mine = $sh[1]; sort($mine);
ok($mine === $decl && count($decl) === 5 && str_contains($sc, 'KERNEL_TOOLS = {"app_roles"} | SHARES') && str_contains($sc, 'KERNEL_ONLY: set[str] = set(SHARES)'), 'server_common.KERNEL_TOOLS is app_roles and the five shares[] of maludb-os.json; KERNEL_ONLY is the five shares');
foreach (['sales_crm-records-mcp' => ['records_server.py', 'MCP_RECORDS_PORT'], 'sales_crm-activity-mcp' => ['activity_server.py', 'MCP_ACTIVITY_PORT']] as $unit => [$file, $port]) {
    $u = (string) file_get_contents("$root/deploy/$unit.service");
    ok(str_contains($u, "mcp/venv/bin/python {{APP_DIR}}/mcp/$file") && str_contains($u, '{{' . $port . '}}') && str_contains($u, 'User=www-data') && is_file("$root/mcp/$file"), "$unit.service starts mcp/$file from the application's own venv on {{$port}}");
}
ok(is_file($root . '/mcp/requirements.txt') && str_contains((string) file_get_contents($root . '/.gitignore'), 'mcp/venv/'), 'requirements.txt is there and mcp/venv/ is gitignored');
ok(preg_match('/endpoint_name="Records MCP"/', $rec) === 1 && str_contains($act, 'endpoint_name="Activity MCP"'), 'the endpoint names the agents\' grants use (Records MCP, Activity MCP) are the servers\'');
$eps = array_column($manifest['endpoints'], null, 'name');
ok(isset($eps['Records MCP'], $eps['Activity MCP']) && $eps['Records MCP']['path'] === '/mcp/records' && $eps['Activity MCP']['path'] === '/mcp/activity' && $eps['Records MCP']['agent_reachable'] === true, 'maludb-os.json declares both endpoints, agent-reachable, at /mcp/records and /mcp/activity');
ok((int) one("SELECT count(*) FROM pg_views WHERE viewname IN ('mcp_key_usage', 'mcp_outbox_health')") === 2 && is_file($root . '/db/025_phase4_mcp_reads.sql'), 'db/025 (mcp_key_usage, mcp_outbox_health, the search index revoked from the records role) is the one migration Phase 4 adds');
$vh = (string) file_get_contents($root . '/deploy/apache-sales_crm.conf');
ok(str_contains($vh, '/mcp/records') && str_contains($vh, '/mcp/activity') && str_contains($vh, '{{MCP_RECORDS_PORT}}') && str_contains($vh, '{{MCP_ACTIVITY_PORT}}'), 'the vhost proxies /mcp/records and /mcp/activity to the two ports');
finish();
