<?php
/**
 * Helpers for the Phase 2 proofs (docs/build-specs/sso-shell.md "Proof"). Run them through tests/phase2/run.sh, which builds the
 * scratch database, starts the application (:8801), a fake kernel (:8802), a fake MaluDB (:8803) and a fake MaluMail (:8806) and puts the
 * scratch environment in the process environment. Every proof prints "  ok   …" / "  FAIL …" and ends "all N passed" or "N FAILED".
 * The fixture (bin/dev_directory.json): 1 the owner (super-admin, CRM admin, IT); 26 Priya a Sales Rep (Sales); 27 Marco the Sales Manager
 * (Sales, its admin); 28 Bea a Rep whose roles have not arrived (capability write); 29 Ann a Viewer (external, read); 30 Dana a Rep and 31 Lee
 * (roles not yet sent) in Partnerships; 40 the Expert (an agent granted Sales Rep); 41 the Watcher (an agent, not admitted); 42 the Assistant.
 */
chdir(dirname(__DIR__, 2));
$GLOBALS['fails'] = 0;
$GLOBALS['passes'] = 0;
const BASE = 'http://127.0.0.1:8801';
const APP = 'sales_crm';
const LAUNCHER = 'https://app.example.invalid/launcher?app=sales_crm';
function need(string $k): string { $v = getenv($k); if ($v === false || $v === '') { fwrite(STDERR, "$k is not set — run tests/phase2/run.sh\n"); exit(2); } return $v; }
function ok(bool $c, string $l): void { $GLOBALS[$c ? 'passes' : 'fails']++; echo ($c ? '  ok   ' : '  FAIL ') . $l . "\n"; }
function finish(): never { echo $GLOBALS['fails'] ? "{$GLOBALS['fails']} FAILED ({$GLOBALS['passes']} passed)\n" : "all {$GLOBALS['passes']} passed\n"; exit($GLOBALS['fails'] ? 1 : 0); }
function pdo(): PDO {
    static $p = null;
    return $p ??= new PDO(sprintf('pgsql:host=%s;port=%s;dbname=%s', need('DB_HOST'), need('DB_PORT'), need('DB_NAME')), need('DB_USER'), need('DB_PASSWORD'),
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
}
function q(string $sql, array $args = []): array { $st = pdo()->prepare($sql); $st->execute($args); return $st->fetchAll(); }
function one(string $sql, array $args = []) { $st = pdo()->prepare($sql); $st->execute($args); return $st->fetchColumn(); }
function fixture(): array { return json_decode(file_get_contents(dirname(__DIR__, 2) . '/bin/dev_directory.json'), true); }
/** crm_has_right() as the database answers it for a member. */
function right_of(int $member, string $right): bool { pdo()->exec("SELECT set_config('app.member_id', '$member', false)"); return (bool) one('SELECT crm_has_right(:r)', ['r' => $right]); }

/** One request. $o: jar, headers[], form[], json[], raw. Returns code, headers (string), body, and the Location. */
function req(string $method, string $path, array $o = []): array
{
    $ch = curl_init((str_starts_with($path, 'http') ? '' : BASE) . $path);
    $h = $o['headers'] ?? [];
    $opt = [CURLOPT_CUSTOMREQUEST => $method, CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_TIMEOUT => 30,
            CURLOPT_COOKIEJAR => $o['jar'] ?? '/dev/null', CURLOPT_COOKIEFILE => $o['jar'] ?? '/dev/null'];
    if (isset($o['form'])) { $opt[CURLOPT_POSTFIELDS] = http_build_query($o['form']); }
    if (isset($o['json'])) { $opt[CURLOPT_POSTFIELDS] = json_encode($o['json']); $h[] = 'Content-Type: application/json'; }
    if (isset($o['raw'])) { $opt[CURLOPT_POSTFIELDS] = $o['raw']; }
    $opt[CURLOPT_HTTPHEADER] = $h;
    curl_setopt_array($ch, $opt);
    $r = (string) curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $hs = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    $headers = substr($r, 0, $hs);
    return ['code' => $code, 'headers' => $headers, 'body' => substr($r, $hs), 'location' => preg_match('/^Location: (.+)$/mi', $headers, $m) ? trim($m[1]) : ''];
}
function jar(): string { return tempnam(sys_get_temp_dir(), 'crmjar'); }
function csrf_of(string $html): string { preg_match('/name="csrf-token" id="csrf-token-meta" content="([^"]+)"/', $html, $m); return $m[1] ?? ''; }

/** A single-use hand-off URL path (/sso?token=&claims=), signed as the kernel signs one. */
function handoff(int $member, array $o = []): string
{
    $key = $o['key'] ?? need('ACTION_TOKEN_KEY');
    $payload = $member . '.' . (time() + ($o['ttl'] ?? 60)) . '.' . ($o['app'] ?? APP) . '.' . bin2hex(random_bytes(16));
    $token = $payload . '.' . hash_hmac('sha256', 'sso:' . $payload, $key);
    $claims = ($o['claims'] ?? (fixture()['claims'][(string) $member] ?? [])) + ['member_id' => $member];
    $text = rtrim(strtr(base64_encode(json_encode($claims)), '+/', '-_'), '=');
    return '/sso?' . http_build_query(['token' => $token, 'claims' => $text . '.' . hash_hmac('sha256', $text, $key)]);
}
/** Sign a member on with a fresh jar; returns [jar, response]. */
function sign_on(int $member, array $o = []): array { $j = jar(); return [$j, req('GET', handoff($member, $o), ['jar' => $j])]; }
function page(string $jar, string $path, array $o = []): array { return req('GET', $path, ['jar' => $jar] + $o); }
function page_csrf(string $jar): string { return csrf_of(req('GET', '/', ['jar' => $jar])['body']); }
function badge_of(string $jar): string { preg_match('/id="header-role-badge">([^<]+)</', req('GET', '/', ['jar' => $jar])['body'], $m); return trim($m[1] ?? ''); }
/** A person's action token and an agent's run token with its relay, as the kernel mints them. */
function person_token(int $m, int $ttl = 300): string { $p = $m . '.' . (time() + $ttl); return $p . '.' . hash_hmac('sha256', $p, need('ACTION_TOKEN_KEY')); }
function run_token(int $m, int $runId, int $ttl = 300): string { $p = $m . '.' . (time() + $ttl) . '.' . $runId; return $p . '.' . hash_hmac('sha256', 'run:' . $p, need('ACTION_TOKEN_KEY')); }
function relay_of(string $tok): string { return hash_hmac('sha256', $tok, need('ACTIONS_RELAY_KEY')); }
function as_agent(string $tok): array { return ['Accept: application/json', 'X-Action-Token: ' . $tok, 'X-Action-Relay: ' . relay_of($tok)]; }

/** The fake kernel's state file: read, change, write. */
function kernel_state(?callable $change = null): array
{
    $f = need('FAKE_KERNEL_STATE');
    $s = json_decode((string) file_get_contents($f), true) ?: [];
    if ($change) { $s = $change($s) ?? $s; file_put_contents($f, json_encode($s)); }
    return $s;
}
function activity(string $action, ?int $since = null): array { return q('SELECT * FROM activity_log WHERE action = :a AND id > :s ORDER BY id', ['a' => $action, 's' => $since ?? 0]); }
function last_activity_id(): int { return (int) one('SELECT COALESCE(max(id), 0) FROM activity_log'); }
/** Act as a member on the proofs' own connection (the database's rules judge them). */
function as_member(int $m): void { pdo()->exec("SELECT set_config('app.member_id', '$m', false)"); }

/**
 * The proofs' SMOKE book (idempotent; created as the members who own it, through the schema's own rules): Priya's account "SMOKE Acme Industries"
 * (acme.example.invalid) with her contact Dana Reyes and her open deal "SMOKE Acme — 20 seats" in Proposal closing this month at 24,000, with a call
 * due today, a task overdue since yesterday and a meeting later this week; a second deal of hers with NO next step; Marco's account "SMOKE Globex"
 * restricted to himself; a lead "SMOKE Initech" (Priya's); a deal of Marco's won this month (the leaderboard); a reply from Dana an hour ago matched to
 * Priya's deal. Returns the ids.
 */
function seed_book(): array
{
    $p = pdo();
    $id = static fn (string $sql, array $a = []): ?int => ($v = one($sql, $a)) === false || $v === null ? null : (int) $v;
    as_member(26);
    $acme = $id("SELECT id FROM accounts WHERE name = 'SMOKE Acme Industries'") ?? $id("INSERT INTO accounts (name, website, owner_member_id, created_by) VALUES ('SMOKE Acme Industries', 'https://acme.example.invalid', 26, 26) RETURNING id");
    $dana = $id("SELECT id FROM contacts WHERE last_name = 'Reyes' AND account_id = :a", ['a' => $acme]) ?? $id("INSERT INTO contacts (first_name, last_name, email, account_id, owner_member_id, created_by) VALUES ('Dana', 'Reyes', 'dana.reyes@acme.example.invalid', :a, 26, 26) RETURNING id", ['a' => $acme]);
    $pipe = (int) one("SELECT id FROM pipelines WHERE key = 'new_business'");
    $qual = (int) one("SELECT id FROM stages WHERE pipeline_id = :p AND name = 'Qualification'", ['p' => $pipe]);
    $deal = $id("SELECT id FROM deals WHERE title = 'SMOKE Acme — 20 seats'");
    if ($deal === null) {
        $deal = $id("INSERT INTO deals (title, account_id, primary_contact_id, pipeline_id, stage_id, value, currency, expected_close_date, owner_member_id, created_by)
                     VALUES ('SMOKE Acme — 20 seats', :a, :c, :p, :s, 24000, 'USD', (date_trunc('month', current_date) + interval '1 month - 1 day')::date, 26, 26) RETURNING id",
                     ['a' => $acme, 'c' => $dana, 'p' => $pipe, 's' => $qual]);
        $p->prepare("INSERT INTO activities (kind, subject, due_at, deal_id, contact_id, owner_member_id, created_by) VALUES ('call', 'SMOKE Call Dana about the seats', date_trunc('day', now()) + interval '23 hours', :d, :c, 26, 26)")->execute(['d' => $deal, 'c' => $dana]);
        $p->prepare("INSERT INTO activities (kind, subject, due_at, deal_id, owner_member_id, created_by) VALUES ('task', 'SMOKE Send the proposal', now() - interval '1 day', :d, 26, 26)")->execute(['d' => $deal]);
        $p->prepare("INSERT INTO activities (kind, subject, starts_at, ends_at, deal_id, owner_member_id, created_by) VALUES ('meeting', 'SMOKE Demo for the board', now() + interval '3 days', now() + interval '3 days 1 hour', :d, 26, 26)")->execute(['d' => $deal]);
    }
    $bare = $id("SELECT id FROM deals WHERE title = 'SMOKE Acme — support add-on'") ?? $id("INSERT INTO deals (title, account_id, pipeline_id, stage_id, value, currency, owner_member_id, created_by)
                     VALUES ('SMOKE Acme — support add-on', :a, :p, :s, 3000, 'USD', 26, 26) RETURNING id", ['a' => $acme, 'p' => $pipe, 's' => $qual]);
    $lead = $id("SELECT id FROM leads WHERE company = 'SMOKE Initech'") ?? $id("INSERT INTO leads (first_name, last_name, company, email, owner_member_id, created_by) VALUES ('Peter', 'Gibbons', 'SMOKE Initech', 'peter@initech.example.invalid', 26, 26) RETURNING id");
    as_member(27);
    $globex = $id("SELECT id FROM accounts WHERE name = 'SMOKE Globex'") ?? $id("INSERT INTO accounts (name, website, owner_member_id, restricted_to_member_id, created_by) VALUES ('SMOKE Globex', 'https://globex.example.invalid', 27, 27, 27) RETURNING id");
    $won = $id("SELECT id FROM deals WHERE title = 'SMOKE Globex — pilot'");
    if ($won === null) {
        $won = $id("INSERT INTO deals (title, account_id, pipeline_id, stage_id, value, currency, owner_member_id, created_by) VALUES ('SMOKE Globex — pilot', :a, :p, :s, 9000, 'USD', 27, 27) RETURNING id",
                   ['a' => $globex, 'p' => $pipe, 's' => $qual]);
        $wonStage = (int) one("SELECT id FROM stages WHERE pipeline_id = :p AND kind = 'won'", ['p' => $pipe]);
        $p->prepare('SELECT crm_deal_move(:d, :s, NULL)')->execute(['d' => $won, 's' => $wonStage]);
    }
    $mail = $id("SELECT id FROM inbound_email WHERE subject = 'SMOKE Re: the seats'");
    if ($mail === null) {
        $mb = $id("SELECT id FROM mailboxes WHERE address = 'crm-log@example.invalid'") ?? $id("INSERT INTO mailboxes (address, display_name, provider, active) VALUES ('crm-log@example.invalid', 'SMOKE log', 'malumail', true) RETURNING id");
        $mail = $id("INSERT INTO inbound_email (mailbox_id, message_id, from_address, from_name, to_addresses, subject, body_text, received_at, deal_id, matched_contact_ids, match_status, status)
                     VALUES (:mb, '<smoke-seats@acme.example.invalid>', 'dana.reyes@acme.example.invalid', 'Dana Reyes', ARRAY['priya@example.invalid'], 'SMOKE Re: the seats', 'Twenty is right.', now() - interval '1 hour',
                             :d, ARRAY[:c]::bigint[], 'matched', 'processed') RETURNING id", ['mb' => $mb, 'd' => $deal, 'c' => $dana]);
    }
    as_member(26);
    return ['acme' => $acme, 'dana' => $dana, 'deal' => $deal, 'bare_deal' => $bare, 'lead' => $lead, 'globex' => $globex, 'won' => $won, 'mail' => $mail, 'pipeline' => $pipe];
}
