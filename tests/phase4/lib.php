<?php
/**
 * Helpers for the Phase 4 proofs (docs/sales-crm-mcp-tool-surface.md). Run through tests/phase4/run.sh (under the shared lock): a SCRATCH database (crm_dev40 — never the installed one), the application
 * on :8801 (under Apache :8801 is the PUBLIC vhost and :8807 the internal one), a fake kernel on :8802 (run-facts, the chat and read doors), a fake MaluDB on :8803, a fake MaluMail on :8806, and the TWO MCP
 * SERVERS on :8804 (records) and :8805 (activity); `variant notoken` on :8809. Everything a proof makes is named "SMOKE …" or "p4 …".
 * Builds on slice 9's library (so 8's … 1's and Phase 2's). THE CAST (bin/dev_directory.json): 1 the admin (super-admin), 26 Priya (Rep), 27 Marco (Sales Manager), 28 Bea (roles not arrived), 29 Ann (a VIEWER,
 * external), 30 Dana and 31 Lee (Partnerships), 40 the Expert (an agent, Rep), 41 the Watcher (an agent, not admitted), 42 the Assistant (an agent, Rep).
 */
require_once dirname(__DIR__) . '/phase3/slice9/lib.php';

const REC = 8804;
const ACT = 8805;
const VARIANT = 8809;
const ADMIN = 1; const PRIYA = 26; const MARCO = 27; const BEA = 28; const ANN = 29; const DANA = 30; const LEE = 31; const EXPERT = 40; const WATCHER = 41; const ASSISTANT = 42;
/** The tools that take no arguments (the client sends no `params`). */
const NO_ARGS = ['app_roles', 'get_settings', 'mailbox_health'];

/** A person's own mcp_ token (the servers resolve it through mcp_resolve_token). Cached per member. */
function ptoken(int $member): string
{
    static $t = [];
    if (isset($t[$member])) { return $t[$member]; }
    $raw = 'mcp_' . bin2hex(random_bytes(24));
    q("INSERT INTO mcp_access_tokens (member_id, label, token_hash, scope) VALUES (:m, 'SMOKE p4', :h, 'mcp')", ['m' => $member, 'h' => hash('sha256', $raw)]);
    return $t[$member] = $raw;
}
/** A token of scope api (a records-API key's shape: not for the MCP servers). */
function api_token(int $member): string
{
    $raw = 'crm_' . bin2hex(random_bytes(24));
    q("INSERT INTO mcp_access_tokens (member_id, label, token_hash, scope) VALUES (:m, 'SMOKE p4 api', :h, 'api')", ['m' => $member, 'h' => hash('sha256', $raw)]);
    return $raw;
}
/** The tenant's signed RUN token an agent presents ('mid.exp.run.hmac' over run:mid.exp.run). */
function rtoken(int $member, int $run, int $ttl = 300): string { return run_token($member, $run, $ttl); }
/** A person's action token (the command bar's): 'mid.exp.hmac'. */
function atoken(int $member, int $ttl = 300): string { return person_token($member, $ttl); }
/** The kernel's own 60-second token: 'kernel.exp.app.nonce.hmac' over kernel:exp.app.nonce. */
function kernel_token(string $app = 'sales_crm', int $ttl = 60): string
{
    $p = (time() + $ttl) . '.' . $app . '.' . bin2hex(random_bytes(16));
    return 'kernel.' . $p . '.' . hash_hmac('sha256', 'kernel:' . $p, need('ACTION_TOKEN_KEY'));
}
/** A run id for this execution of a proof (the server caches a run's facts for 300 s, so a rerun must not reuse one). */
function runid(int $n): int { static $b = null; $b ??= (time() % 100000) * 100; return $b + $n; }
/** Tell the fake kernel what the run-facts call answers for a run id. $endpoints: [name => [tool => constraints]] or [] for another application's. */
function facts(int $run, int $member, array $endpoints, array $o = []): void
{
    kernel_state(function ($s) use ($run, $member, $endpoints, $o) {
        $eps = [];
        foreach ($endpoints as $name => $tools) { $eps[] = ['id' => 1, 'name' => $name, 'tools' => (object) $tools]; }
        $s['facts'][(string) $run] = ['valid' => $o['valid'] ?? true, 'is_agent' => $o['is_agent'] ?? true, 'member_id' => $member, 'run_id' => $run, 'request_id' => "req-run-$run",
            'trigger' => $o['trigger'] ?? 'chat', 'is_eval' => ($o['trigger'] ?? '') === 'eval', 'endpoints' => $eps];
        return $s;
    });
}

// ---- the MCP client (the kernel's own: initialize, initialized, then the call) ------------------------------------
function mcp_post(int $port, string $token, array $msg, ?string $session = null, string $path = '/mcp', array $extra = []): array
{
    $h = array_merge(['Content-Type: application/json', 'Accept: application/json, text/event-stream', 'MCP-Protocol-Version: 2025-06-18'], $extra);
    if ($token !== '') { $h[] = 'Authorization: Bearer ' . $token; }
    if ($session) { $h[] = 'Mcp-Session-Id: ' . $session; }
    $sess = $session;
    $ch = curl_init("http://127.0.0.1:$port$path");
    curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => json_encode($msg), CURLOPT_HTTPHEADER => $h, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 90,
        CURLOPT_HEADERFUNCTION => function ($c, $line) use (&$sess) { if (stripos($line, 'mcp-session-id:') === 0) { $sess = trim(substr($line, 15)); } return strlen($line); }]);
    $body = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $type = (string) curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
    if (!is_string($body) || $body === '') { return [null, $sess, $status]; }
    if (stripos($type, 'text/event-stream') !== false) {
        foreach (preg_split('/\r?\n/', $body) as $line) {
            if (str_starts_with($line, 'data:')) { $m = json_decode(trim(substr($line, 5)), true); if (is_array($m) && ($m['id'] ?? null) === ($msg['id'] ?? null)) { return [$m, $sess, $status]; } }
        }
        return [null, $sess, $status];
    }
    return [json_decode($body, true), $sess, $status];
}
function mcp_open(int $port, string $token, string $path = '/mcp', array $extra = []): array
{
    [$init, $sess, $st] = mcp_post($port, $token, ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize', 'params' => ['protocolVersion' => '2025-06-18', 'capabilities' => (object) [], 'clientInfo' => ['name' => 'p4', 'version' => '1']]], null, $path, $extra);
    if ($st === 200) { mcp_post($port, $token, ['jsonrpc' => '2.0', 'method' => 'notifications/initialized'], $sess, $path, $extra); }
    return [$sess, $st, $init];
}
/** The tool names a bearer is offered, or null when the server refused it (401). */
function mcp_tools(int $port, string $token, array $extra = [], string $path = '/mcp'): ?array
{
    [$sess, $st] = mcp_open($port, $token, $path, $extra);
    if ($st !== 200) { return null; }
    [$a] = mcp_post($port, $token, ['jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/list'], $sess, $path, $extra);
    $names = array_map(fn ($t) => $t['name'], $a['result']['tools'] ?? []);
    sort($names);
    return $names;
}
/** The full tools/list answer (names, descriptions, annotations, input schemas). */
function mcp_tool_defs(int $port, string $token, array $extra = []): array
{
    [$sess] = mcp_open($port, $token, '/mcp', $extra);
    [$a] = mcp_post($port, $token, ['jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/list'], $sess, '/mcp', $extra);
    return $a['result']['tools'] ?? [];
}
/**
 * Call a tool as a bearer. Answers ['http', 'error' => bool (the tool refused), 'text', 'data' => decoded JSON or null, 'code' => the error code when the tool answered {ok:false,error:{code}}]. Arguments are
 * wrapped as {"params": ...} the way FastMCP's models take them, except the kernel's flat call ($flat) and the tools that take none (app_roles, get_settings, mailbox_health).
 */
function mcp_tool(int $port, string $token, string $tool, array $args = [], bool $flat = false, array $extra = [], string $path = '/mcp'): array
{
    @file_put_contents(need('CRM_DEV_STATE') . '/p4-called.txt', $tool . "\n", FILE_APPEND);
    [$sess, $st] = mcp_open($port, $token, $path, $extra);
    if ($st !== 200) { return ['http' => $st, 'error' => true, 'text' => '', 'data' => null, 'code' => null]; }
    $arguments = $flat || ($args === [] && in_array($tool, NO_ARGS, true)) ? (object) $args : ['params' => (object) $args];
    [$a] = mcp_post($port, $token, ['jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/call', 'params' => ['name' => $tool, 'arguments' => $arguments]], $sess, $path, $extra);
    $res = $a['result'] ?? [];
    $text = (string) ($res['content'][0]['text'] ?? ($a['error']['message'] ?? ''));
    $data = json_decode($text, true);
    $code = is_array($data) && ($data['ok'] ?? null) === false ? ($data['error']['code'] ?? null) : null;
    return ['http' => 200, 'error' => !empty($res['isError']) || isset($a['error']) || $code !== null, 'text' => $text, 'data' => is_array($data) ? $data : null, 'code' => $code];
}
/** A tool as a person (their own token): the whole answer, or just the decoded data. */
function tool(int $member, string $name, array $args = [], int $port = REC): array { return mcp_tool($port, ptoken($member), $name, $args); }
function tdata(int $member, string $name, array $args = [], int $port = REC) { return tool($member, $name, $args, $port)['data']; }
/** The rows of a list answer, or []. */
function trows(int $member, string $name, array $args = [], int $port = REC): array { $d = tdata($member, $name, $args, $port); return is_array($d) ? ($d['rows'] ?? []) : []; }
/** Every key anywhere in a decoded answer. */
function has_key($data, string $key): bool
{
    if (!is_array($data)) { return false; }
    foreach ($data as $k => $v) { if ($k === $key || has_key($v, $key)) { return true; } }
    return false;
}
function find_row(?array $rows, string $key, $value): ?array { foreach ($rows ?? [] as $r) { if ((string) ($r[$key] ?? '') === (string) $value) { return $r; } } return null; }
function ids(?array $rows, string $key): array { return array_values(array_map(fn ($r) => $r[$key], $rows ?? [])); }
/** The newest activity row of an action (any actor), or null. */
function last_event(string $action): ?array { $r = q('SELECT * FROM activity_log WHERE action = :a ORDER BY id DESC LIMIT 1', ['a' => $action]); return $r[0] ?? null; }
/** The fake kernel is stopped (its port closed) for a moment, then started again by the caller: the fail-closed proofs. */
function kernel_ctl(string $what): void { shell_exec(escapeshellarg(__DIR__ . '/kernel_ctl.sh') . ' ' . $what); }
function mcp_ctl(string $what): void { shell_exec(escapeshellarg(__DIR__ . '/mcp_servers.sh') . ' ' . $what); }
/** An agent's grants as maludb-os.json declares them: ['Records MCP' => [tool => []], 'Activity MCP' => ..., 'Actions MCP' => ...]. */
function agent_grants(string $key): array
{
    $m = json_decode((string) file_get_contents(dirname(__DIR__, 2) . '/maludb-os.json'), true);
    foreach ($m['agents'] as $a) { if ($a['key'] === $key) { return array_map(fn ($tools) => array_fill_keys($tools, []), $a['tool_grants']); } }
    return [];
}

/** What the database says a member sees through the views — the same rows the server hands them (the application's role reads the views too). */
function sql_as(int $member, string $sql, array $args = []): array
{
    pdo()->exec("SELECT set_config('app.member_id', '$member', false)");
    $rows = q($sql, $args);
    pdo()->exec("SELECT set_config('app.member_id', '', false)");
    return $rows;
}

/**
 * The Phase 4 world, once per run: Phase 2's seed_book() (Priya's Acme, Dana Reyes, two open deals, Marco's restricted Globex with a won deal, a lead, a reply) and tests/phase4/world.sql ("p4 …": more leads, accounts
 * incl. Dana's RESTRICTED "p4 Hidden Holdings", a won/lost/rotting deal, products, a campaign, lists, a saved view, quotas, activities incl. Marco's private one, three emails out, an import, a key with usage, proposals,
 * a dispatch, notices and the trail). Answers the ids by name.
 */
function p4_world(): array
{
    static $w = null;
    if ($w !== null) { return $w; }
    $cache = need('CRM_DEV_STATE') . '/world-p4.json';
    if (is_file($cache)) { return $w = json_decode((string) file_get_contents($cache), true); }
    $w = seed_book();
    $out = (string) shell_exec('cd ' . escapeshellarg(dirname(__DIR__, 2)) . ' && sudo -n -u postgres psql -v ON_ERROR_STOP=1 -X -q -d ' . escapeshellarg(need('DB_NAME')) . ' -f tests/phase4/world.sql 2>&1');
    $line = '';
    foreach (explode("\n", trim($out)) as $l) { if (str_starts_with($l, 'p4world|')) { $line = $l; } }
    if ($line === '') { fwrite(STDERR, "the Phase 4 world failed:\n" . $out . "\n"); exit(1); }
    $v = explode('|', $line);
    $keys = ['acme', 'dana_c', 'deal1', 'deal2', 'lead1', 'globex', 'globex_deal', 'pipe', 'umbrella', 'pat', 'hidden', 'hid_c', 'hid_deal', 'hid_note', 'lead_work', 'lead_inbox', 'lead_prop', 'lead_old', 'lead_tomb',
             'mail_unmatched', 'mail_prop', 'deal_won', 'deal_lost', 'deal_rot', 'note1', 'att1', 'camp', 'list_smart', 'list_static', 'list_private', 'view_p', 'act_done', 'act_mail', 'act_private', 'mail_priv',
             'mail_pub', 'mail_draft', 'imp', 'key_p', 'prop_open', 'prop_done', 'disp', 'tag_vip', 'lead_dup'];
    foreach ($keys as $i => $k) { $w[$k] = (int) ($v[$i + 1] ?? 0); }
    file_put_contents($cache, json_encode($w));
    return $w;
}

/** The tool surface (docs/sales-crm-mcp-tool-surface.md) as the proofs read it: the named tools of each server, sorted. */
function surface(): array
{
    static $s = null;
    if ($s !== null) { return $s; }
    $t = (string) file_get_contents(dirname(__DIR__, 2) . '/docs/sales-crm-mcp-tool-surface.md');
    $a = strpos($t, '## Records server'); $b = strpos($t, '## The shares'); $c = strpos($t, '## Activity server'); $d = strpos($t, '### The activity vocabulary');
    preg_match_all('/^\| `([a-z_0-9]+)`/m', substr($t, $a, $b - $a), $rec);
    preg_match_all('/^\| `([a-z_0-9]+)`/m', substr($t, $c, $d - $c), $act);
    $shares = ['accounts_index', 'contacts_index', 'deals_won', 'deals_open_by_account', 'account_timeline_summary'];
    $records = array_values(array_unique(array_merge($rec[1], ['app_roles'], $shares))); sort($records);
    $activity = $act[1]; sort($activity);
    $person = array_values(array_diff($records, $shares));
    return ['records' => $records, 'activity' => $activity, 'shares' => $shares, 'person' => $person, 'kernel_tools' => (static function () use ($shares) { $k = array_merge(['app_roles'], $shares); sort($k); return $k; })()];
}

/** Every records tool a person may call, with arguments that make it answer in the world. [[tool, args], …]. The activity tools are in sweep_activity(). */
function sweep(array $w): array
{
    $today = date('Y-m-d'); $week = date('Y-m-d', time() + 7 * 86400); $back = date('Y-m-d', time() - 60 * 86400);
    return [
        ['find_leads', ['q' => 'Initech']], ['get_lead', ['lead' => $w['lead1']]], ['leads_inbox', []], ['leads_untouched', ['days' => 30]], ['source_performance', ['from' => $back, 'to' => $today]],
        ['find_duplicates', ['kind' => 'lead']], ['find_imports', []], ['get_import', ['import' => $w['imp']]], ['import_rows', ['import' => $w['imp']]], ['find_lists', []], ['list_members', ['list' => $w['list_smart']]],
        ['segment_preview', ['record_kind' => 'lead', 'filters' => ['status' => 'new']]],
        ['find_accounts', ['q' => 'Acme']], ['get_account', ['account' => $w['acme']]], ['account_contacts', ['account' => $w['acme']]], ['account_deals', ['account' => $w['acme']]], ['account_tickets', ['account' => $w['acme']]],
        ['accounts_untouched', ['days' => 30]], ['find_contacts', ['q' => 'Reyes']], ['get_contact', ['contact' => $w['dana_c']]], ['contacts_untouched', ['days' => 1]], ['upcoming_dates', []],
        ['find_notes', ['kind' => 'deal', 'record' => $w['deal1']]], ['find_attachments', ['kind' => 'deal', 'record' => $w['deal1']]],
        ['find_pipelines', []], ['find_stages', ['pipeline' => $w['pipe']]], ['pipeline_summary', []], ['forecast', ['months' => 2]], ['funnel', ['from' => $back, 'to' => $today]], ['win_rate', ['from' => $back, 'to' => $today]],
        ['cycle_time', ['from' => $back, 'to' => $today]], ['deals_at_risk', []], ['deals_without_next_step', []], ['deals_rotting', []], ['find_deals', ['status' => ['open']]], ['get_deal', ['deal' => $w['deal1']]],
        ['deal_history', ['deal' => $w['deal1']]], ['deal_products', ['deal' => $w['deal1']]], ['deal_contacts', ['deal' => $w['deal1']]], ['deals_closed', ['from' => $back, 'to' => $today]],
        ['lost_reasons_summary', ['from' => $back, 'to' => $today]], ['leaderboard', []], ['stage_requirements_check', ['deal' => $w['deal1'], 'stage' => 'Proposal']], ['products_pipeline', []], ['saved_views', []], ['quotas', []],
        ['my_activities', []], ['calendar', ['from' => $today, 'to' => $week]], ['find_activities', ['deal' => $w['deal1']]], ['get_activity', ['activity' => $w['act_done']]],
        ['activity_summary', ['from' => $back, 'to' => $today]], ['reminders_due', []], ['timeline', ['kind' => 'deal', 'record' => $w['deal1']]],
        ['find_emails', ['deal' => $w['deal1']]], ['get_email', ['email' => $w['mail_pub'], 'direction' => 'out']], ['emails_unmatched', []], ['proposed_leads', []], ['find_templates', []], ['mailbox_health', []],
        ['find_campaigns', []], ['get_campaign', ['campaign' => $w['camp']]], ['campaign_results', []], ['campaign_members', ['campaign' => $w['camp']]], ['find_sources', []], ['find_lost_reasons', []], ['find_tags', []],
        ['settings_catalogue', []], ['members_with_roles', []], ['find_members', ['q' => 'SMOKE']], ['find_departments', []], ['find_keys', []], ['key_usage_summary', []], ['assistant_proposals', []],
        ['agent_dispatches', []], ['my_notifications', []], ['get_settings', []], ['tombstones', []], ['search', ['q' => 'Acme']], ['records_search', ['sql' => 'SELECT account_id, name FROM mcp_accounts']], ['app_roles', []],
    ];
}
/** The seven activity tools with arguments that answer in the world. */
function sweep_activity(array $w): array
{
    return [
        ['record_history', ['entity_type' => 'deal', 'entity_id' => $w['deal1']]], ['actor_history', ['member' => 'me']], ['recent_activity', ['since' => date('Y-m-d', time() - 3 * 86400)]],
        ['agent_activity', []], ['activity_timeline', ['kind' => 'deal', 'record' => $w['deal1']]], ['activity_search', ['q' => 'deal']], ['activity_query', ['sql' => 'SELECT action FROM mcp_activity_log']],
    ];
}
