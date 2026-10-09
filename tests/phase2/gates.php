<?php
/**
 * Proof: the shell's gates and its screens (sso-shell.md "Proof"): rights per role on the six screens and the eleven placeholders (200 vs 403,
 * refused in words), the placeholders' behaviour (a page naming its slice, 501 for JSON and a POST, `stub` in the registry), the menu and the tab
 * bar per role, no-session answers, the home's regions for a Rep and a manager (Today, closing, the flags, replies, the note, the forecast tile and
 * the leaders), the one search box (kinds, a misspelling, the wall, the log, escaping), the person's own screens and their six actions, the
 * command bar through the kernel's chat endpoint, the action-token / run-token gate, the mirror (notification preferences on sync and on sign-on,
 * a revoked grant shutting the door). Run after the browser proof through tests/phase2/run.sh (seed_book() is idempotent).
 */
require __DIR__ . '/lib.php';
$json = ['headers' => ['Accept: application/json']];
$seed = seed_book();
$board = '/pipeline/new_business/board';

echo "1. Rights per role — every screen and placeholder answers 200 or 403\n";
// The roles: admin (the owner, 1), manager (Marco, 27), rep (Priya, 26), bea (a Rep by capability, 28), viewer (Ann, 29), marketing (Lee, 31, signed on with the Marketing role).
$fixture = fixture();
$mkt = $fixture['claims']['31']; $mkt['roles'] = ['marketing']; $mkt['role'] = 'marketing';
$who = ['admin' => sign_on(1)[0], 'manager' => sign_on(27)[0], 'rep' => sign_on(26)[0], 'bea' => sign_on(28)[0], 'viewer' => sign_on(29)[0], 'marketing' => sign_on(31, ['claims' => $mkt])[0]];
$everyone = implode(' ', array_keys($who));
$matrix = [
    '/' => $everyone, '/search' => $everyone, '/notifications' => $everyone, '/activity' => $everyone, '/settings/' => $everyone, '/settings/tokens/' => $everyone,
    '/leads' => $everyone, '/accounts' => $everyone, '/contacts' => $everyone, $board => $everyone, '/calendar' => $everyone, '/activities' => $everyone,
    '/campaigns' => $everyone, '/reports' => $everyone,
    '/email/unmatched' => 'admin manager rep bea', '/lists' => 'admin manager rep bea marketing', '/admin' => 'admin manager marketing',
];
$wrong = [];
$n = 0;
foreach ($matrix as $route => $allowed) {
    foreach ($who as $role => $jarf) {
        $want = in_array($role, explode(' ', $allowed), true) ? 200 : 403;
        $code = page($jarf, $route)['code'];
        $n++;
        if ($code !== $want) { $wrong[] = "$role $route wanted $want got $code"; }
    }
}
ok($wrong === [], "$n role × screen checks (" . count($matrix) . ' screens × 6 roles) answer 200 or 403 as the rights say' . ($wrong ? ': ' . implode('; ', array_slice($wrong, 0, 8)) : ''));
$r = page($who['rep'], '/admin');
ok(str_contains($r['body'], 'You may not change the settings.') && str_contains($r['body'], 'id="message-text"'), 'a refusal says what the person may not do, in words ("You may not change the settings.")');
$r = page($who['viewer'], '/lists');
ok($r['code'] === 403 && str_contains($r['body'], 'You may not keep lists.'), 'a Viewer on Lists: 403 in words');
$r = page($who['marketing'], '/email/unmatched');
ok($r['code'] === 403 && str_contains($r['body'], 'You may not log an email.'), 'Marketing on the unmatched mail: 403 in words (Marketing logs no email)');
$badges = array_map('badge_of', $who);
ok($badges === ['admin' => 'Super-admin', 'manager' => 'Sales Manager', 'rep' => 'Sales Rep', 'bea' => 'Sales Rep', 'viewer' => 'Viewer', 'marketing' => 'Marketing'],
    'the header badge reads the highest role held: ' . implode(', ', $badges));

echo "2. The placeholders — a page naming its slice, 501 for JSON and for a POST\n";
$stubs = trim((string) shell_exec('grep -rl "render_nav_stub(" ' . escapeshellarg(dirname(__DIR__, 2) . '/html') . ' 2>/dev/null | wc -l'));
ok((int) $stubs === 15, "fifteen controllers call render_nav_stub — eleven placeholders, the three key handlers (slice 8) and the feed handler (slice 4): $stubs");
$ph = ['leads' => ['/leads', 'slice 2', 'Leads'], 'accounts' => ['/accounts', 'slice 1', 'Accounts'], 'contacts' => ['/contacts', 'slice 1', 'Contacts'],
       'pipeline' => [$board, 'slice 3', 'Pipeline'], 'calendar' => ['/calendar', 'slice 4', 'Calendar'], 'activities' => ['/activities', 'slice 4', 'Activities'],
       'email' => ['/email/unmatched', 'slice 5', 'Email'], 'campaigns' => ['/campaigns', 'slice 6', 'Campaigns'], 'lists' => ['/lists', 'slice 6', 'Lists'],
       'reports' => ['/reports', 'slice 7', 'Reports'], 'admin' => ['/admin', 'slice 9', 'Admin']];
$bad = [];
foreach ($ph as $id => [$url, $slice, $title]) {
    $b = page($who['admin'], $url)['body'];
    if (!(str_contains($b, 'id="' . $id . '-coming"') && str_contains($b, $title . ' is not built yet') && str_contains($b, $slice . ' builds this screen') && str_contains($b, '<title>' . $title . ' · Sales CRM</title>'))) { $bad[] = $url; }
}
ok($bad === [], 'every placeholder: the shell, the header and one card saying which slice builds it' . ($bad ? ' — wrong: ' . implode(', ', $bad) : ''));
$r = page($who['admin'], '/accounts', $json);
ok($r['code'] === 501 && (json_decode($r['body'], true)['error']['code'] ?? '') === 'not_built', 'a JSON caller on a placeholder: 501 not_built');
$r = req('POST', '/accounts', ['jar' => $who['admin'], 'form' => ['csrf_token' => page_csrf($who['admin'])]]);
ok($r['code'] === 501 && str_contains($r['body'], 'This is built by slice 1.'), 'a POST to a placeholder: 501, in words');
$r = req('POST', '/settings/tokens/key-mint.php', ['jar' => $who['rep'], 'headers' => ['Accept: application/json'], 'form' => ['csrf_token' => page_csrf($who['rep']), 'label' => 'x']]);
ok($r['code'] === 501 && str_contains((string) (json_decode($r['body'], true)['error']['message'] ?? ''), 'slice 8'), 'key_mint answers 501 naming slice 8 until it is built');
$r = req('POST', '/settings/feed.php', ['jar' => $who['rep'], 'form' => ['csrf_token' => page_csrf($who['rep']), 'confirm' => 'yes']]);
ok($r['code'] === 501 && str_contains($r['body'], 'This is built by slice 4.'), 'feed_rotate answers 501 naming slice 4 until it is built');
$r = page($who['admin'], '/calendar', ['headers' => ['HX-Request: true']]);
ok($r['code'] === 200 && str_contains($r['body'], 'id="calendar-coming"') && !str_contains($r['body'], '<html') && preg_match('/^HX-Title: Calendar/mi', $r['headers']) === 1 && preg_match('/^X-Screen: calendar\r?$/mi', $r['headers']) === 1, 'over HTMX a placeholder is the partial with HX-Title and X-Screen, so the tab highlight and the command bar follow');
$v = (int) one("SELECT count(*) FROM activity_log WHERE action = 'screen.view' AND screen = 'calendar'");
ok($v >= 2, "opening a placeholder logs screen.view for its screen ($v for calendar)");
$built = json_decode((string) file_get_contents(dirname(__DIR__, 2) . '/mcp/action_registry.json'), true);
$sc = $built['screens'] ?? [];
$ac = $built['actions'] ?? [];
$stubIds = array_keys(array_filter($sc, static fn ($s) => !empty($s['stub'])));
$builtIds = array_keys(array_filter($sc, static fn ($s) => !empty($s['built'])));
$builtActions = array_keys(array_filter($ac, static fn ($a) => !empty($a['built'])));
sort($builtIds); sort($builtActions);
ok(count($stubIds) === 11 && $builtIds === ['activity', 'home', 'notifications', 'search', 'settings', 'settings-tokens'],
    'the registry marks eleven screens as stubs and the shell\'s six as built, so the voice surface never offers an unbuilt screen (' . implode(', ', $builtIds) . ')');
ok($builtActions === ['notification_read', 'notifications_read_all', 'prefs_save', 'signature_save', 'token_mint', 'token_revoke'] && empty($ac['key_mint']['built']) && empty($ac['feed_rotate']['built']),
    'and the shell\'s six actions as built — key_mint and feed_rotate (stubs) are not: ' . implode(', ', $builtActions));

echo "3. The menu and the tab bar per role, and no session\n";
$menu = static fn (string $j): array => (preg_match_all('/id="nav-([a-z-]+)"/', page($j, '/')['body'], $m) ? array_values(array_filter($m[1], static fn ($i) => $i !== 'leads-inbox')) : []);
$groups = static fn (string $j): array => (preg_match_all('/nxl-caption"><label>([^<]+)</', page($j, '/')['body'], $m) ? $m[1] : []);
$full = ['home', 'leads', 'accounts', 'contacts', 'pipeline', 'calendar', 'activities', 'email', 'campaigns', 'lists', 'reports', 'my-settings', 'notifications', 'tokens', 'activity'];
ok($menu($who['rep']) === $full && $groups($who['rep']) === ['Me'], 'a Rep sees the eleven items and Me — no Admin: ' . implode(', ', $menu($who['rep'])));
ok($menu($who['viewer']) === array_values(array_diff($full, ['email', 'lists'])), 'a Viewer sees no Email and no Lists');
ok($menu($who['manager']) === array_merge($full, ['admin']) && $groups($who['manager']) === ['Me', 'Admin'], 'a Sales Manager sees Admin (pipelines, rules, quotas are theirs)');
ok(in_array('admin', $menu($who['marketing']), true) && !in_array('email', $menu($who['marketing']), true), 'Marketing sees Admin (sources and tags) and no Email');
ok(str_contains(page($who['rep'], '/')['body'], 'href="' . $board . '"'), 'the Pipeline item opens the default pipeline\'s board by its key (' . $board . ')');
$tabs = static fn (string $j): array => (preg_match_all('/id="tab-(home|pipeline|calendar|log|more)"/', page($j, '/')['body'], $m) ? $m[1] : []);
ok($tabs($who['rep']) === ['home', 'pipeline', 'calendar', 'log', 'more'], 'the phone\'s tab bar is Today · Pipeline · Calendar · Log · More for a Rep: ' . implode(', ', $tabs($who['rep'])));
ok($tabs($who['viewer']) === ['home', 'pipeline', 'calendar', 'more'], 'a Viewer has no Log tab (nothing to log): ' . implode(', ', $tabs($who['viewer'])));
$h = page($who['rep'], '/')['body'];
ok(str_contains($h, 'id="tab-log-sheet"') && str_contains($h, 'id="tab-log-log-call"') && str_contains($h, 'Log a call') && str_contains($h, '· slice 4') && !str_contains($h, 'href="/log/call"'), 'the Log sheet lists the quick-log links, greyed with their slice until built (no link to a 404)');
$r = page(jar(), '/accounts');
ok($r['code'] === 302 && $r['location'] === LAUNCHER, 'no session: a screen goes to the launcher with ?app=sales_crm');
$r = req('GET', '/accounts', $json);
ok($r['code'] === 401 && (json_decode($r['body'], true)['error']['code'] ?? '') === 'unauthorized', 'no session, JSON: 401 unauthorized');
$r = req('GET', '/search?q=acme');
ok($r['code'] === 302 && $r['location'] === LAUNCHER, 'no session on the search: the launcher too');

echo "4. Today — a Rep's regions and a manager's tiles\n";
as_member(26);
one("SELECT crm_notify(26, 'morning_note', 'Your morning', 'Call Dana about the seats; the proposal is a day late.', NULL, NULL, NULL, NULL, NULL, 42, 'p2:note:' || current_date)");
$d = json_decode(page($who['rep'], '/', $json)['body'], true)['data'] ?? [];
$subjects = array_column($d['today'] ?? [], 'subject');
ok(($d['first_run'] ?? null) === false && $subjects === ['SMOKE Send the proposal', 'SMOKE Call Dana about the seats'] && ($d['today'][0]['state'] ?? '') === 'overdue' && ($d['today'][1]['state'] ?? '') === 'today',
    'Priya\'s Today: the overdue task first, then today\'s call — the meeting in three days is not today (' . implode(' · ', $subjects) . ')');
ok(($d['overdue_count'] ?? 0) === 1 && ($d['closing'] ?? []) == ['count' => 1, 'value' => 24000.0, 'weighted' => 2400.0, 'other_currency' => 0, 'currency' => 'USD'],
    'one overdue; closing this month: one deal, $24,000, $2,400 weighted (Qualification is 10 %)');
ok(($d['flags'] ?? []) === ['no_next_step' => 1, 'rotting' => 0], 'the flags: one deal with no next step (the support add-on), none rotting');
ok(count($d['replies'] ?? []) === 1 && ($d['replies'][0]['subject'] ?? '') === 'SMOKE Re: the seats' && ($d['replies'][0]['deal_title'] ?? '') === 'SMOKE Acme — 20 seats', 'the reply from Dana an hour ago, on her deal');
ok(str_contains((string) ($d['note'] ?? ''), 'Call Dana about the seats') && array_key_exists('manager', $d) && $d['manager'] === null, 'the morning note is there; a Rep has no manager tiles');
$h = page($who['rep'], '/')['body'];
ok(str_contains($h, 'id="home-today-list"') && str_contains($h, 'class="next-chip next-overdue') && str_contains($h, '>Overdue<') && str_contains($h, 'id="home-note-text"') && str_contains($h, '$24,000'),
    'the page shows Today with the chips in words (Overdue), the note and the closing value');
ok(str_contains($h, 'href="' . $board . '?owner=me&amp;flag=no_next_step"') && str_contains($h, 'href="/deals/' . $seed['deal'] . '?back=%2F"') && str_contains($h, 'href="/email/in/' . $seed['mail'] . '?back=%2F"'),
    'the flag links to the board filtered; an activity opens its deal and the reply opens the email, each carrying ?back= (click-around)');
$d = json_decode(page($who['manager'], '/', $json)['body'], true)['data'] ?? [];
ok(($d['manager']['forecast']['won_value'] ?? null) == 9000 && ($d['manager']['leaders'][0]['display_name'] ?? '') === 'SMOKE Marco' && count($d['manager']['leaders']) === 1,
    'a manager\'s home: the team won $9,000 this month and the leaderboard lists who won something (Marco)');
$h = page($who['manager'], '/')['body'];
ok(str_contains($h, 'id="home-forecast"') && str_contains($h, 'id="home-leaders-list"') && str_contains($h, '$9,000'), 'the forecast tile and the leaders render');
$d = json_decode(page($who['viewer'], '/', $json)['body'], true)['data'] ?? [];
ok(($d['first_run'] ?? null) === false && ($d['may']['lead'] ?? null) === false && array_key_exists('manager', $d) && $d['manager'] === null, 'a Viewer sees the book (open by default) but may not add a lead');

echo "5. The one search box\n";
$rows = static fn (string $j, string $q): array => array_map(static fn ($r) => $r['entity_kind'] . '-' . $r['entity_id'], json_decode(page($j, '/search?q=' . rawurlencode($q), $json)['body'], true)['data']['rows'] ?? []);
$acme = $rows($who['rep'], 'acme');
ok(in_array('account-' . $seed['acme'], $acme, true) && in_array('contact-' . $seed['dana'], $acme, true) && in_array('deal-' . $seed['deal'], $acme, true), 'acme finds the account, its contact and its deals: ' . implode(', ', $acme));
ok(in_array('account-' . $seed['acme'], $rows($who['rep'], 'Acmee'), true), 'a misspelt name (Acmee) still finds the account (word trigram)');
ok(in_array('lead-' . $seed['lead'], $rows($who['rep'], 'initech'), true), 'a lead by its company');
ok(!in_array('account-' . $seed['globex'], $rows($who['rep'], 'globex'), true) && in_array('account-' . $seed['globex'], $rows($who['manager'], 'globex'), true), 'THE WALL: Globex, restricted to Marco, is not found by Priya and is found by Marco');
$kinds = array_values(array_unique(array_map(static fn ($r) => $r['entity_kind'], json_decode(page($who['rep'], '/search?q=acme&kinds[]=deal', $json)['body'], true)['data']['rows'] ?? [])));
ok($kinds === ['deal'], 'kinds[]=deal narrows to deals: ' . implode(', ', $kinds));
$since = last_activity_id();
$long = str_repeat('acme ', 30);
page($who['rep'], '/search?q=' . rawurlencode($long));
$a = activity('search.keyword', $since);
$la = $a !== [] ? json_decode((string) end($a)['after'], true) : [];
ok($a !== [] && mb_strlen((string) ($la['q'] ?? '')) === 120 && isset($la['count']), 'every search is logged search.keyword with the query cut at 120 characters and the count');
$r = page($who['rep'], '/search?q=a', $json);
ok($r['code'] === 422, 'one character: 422 (two at least)');
ok(str_contains(page($who['rep'], '/search?q=a')['body'], 'id="search-too-short"'), '…and the page says so in words');
as_member(26);
pdo()->exec("INSERT INTO accounts (name, owner_member_id, created_by) SELECT 'SMOKE <script>alert(1)</script> Corp', 26, 26 WHERE NOT EXISTS (SELECT 1 FROM accounts WHERE name LIKE 'SMOKE <script>%')");
$b = page($who['rep'], '/search?q=' . rawurlencode('Corp'))['body'];
ok(str_contains($b, '&lt;script&gt;alert(1)&lt;/script&gt;') && !str_contains($b, '<script>alert(1)</script>'), 'a record\'s name is escaped in the results and the excerpt (no markup from the data)');
$b = page($who['rep'], '/search?q=acme')['body'];
ok(str_contains($b, '<mark>') && str_contains($b, 'id="search-group-account"') && str_contains($b, 'id="search-group-deal"'), 'the words matched are highlighted and the results grouped by kind');
ok(str_contains(page($who['rep'], '/')['body'], 'id="header-search"'), 'the header carries the one search box on every page');

echo "6. The person's own screens and their six actions\n";
$t = page_csrf($who['rep']);
$post = static fn (string $path, array $form, ?string $jarf = null) => req('POST', $path, ['jar' => $jarf ?? $who['rep'], 'headers' => ['Accept: application/json', 'X-CSRF-Token: ' . ($jarf === null ? $t : page_csrf($jarf))], 'form' => $form]);
$d = json_decode(page($who['rep'], '/settings/', $json)['body'], true)['data'] ?? [];
ok(count($d['kinds'] ?? []) === 15 && isset($d['kinds']['reminder'], $d['kinds']['morning_note'], $d['remind_choices'], $d['defaults']['default_remind_minutes']) && isset($d['notify']['morning_note']), '/settings/: the fifteen kinds the schema knows, the reminder choices and the business\'s defaults');
ok($post('/settings/prefs.php', ['kinds' => ['reminder', 'nonsense']])['code'] === 422, 'prefs_save refuses an unknown kind: 422');
ok($post('/settings/prefs.php', ['default_remind_minutes' => '20000'])['code'] === 422 && $post('/settings/prefs.php', ['morning_hour' => '24'])['code'] === 422, 'and a reminder beyond a week or an hour past 23');
$r = $post('/settings/prefs.php', ['email_enabled' => 'no', 'kinds' => ['reminder', 'deal_won'], 'default_remind_minutes' => '15', 'morning_note' => 'no', 'morning_hour' => '7', 'digest' => 'yes']);
$p = q('SELECT email_enabled, kinds::text AS kinds, default_remind_minutes, morning_note, morning_hour, digest FROM notification_prefs WHERE member_id = 26')[0];
ok($r['code'] === 200 && (json_decode($r['body'], true)['did'] ?? '') === 'Saved how you are told and reminded' && $p['kinds'] === '{reminder,deal_won}' && (int) $p['default_remind_minutes'] === 15 && !$p['morning_note'] && (int) $p['morning_hour'] === 7 && $p['digest'] && !$p['email_enabled'], 'prefs_save writes every choice');
$post('/settings/prefs.php', ['default_remind_minutes' => '', 'morning_hour' => '']);
ok(one('SELECT default_remind_minutes IS NULL AND morning_hour IS NULL FROM notification_prefs WHERE member_id = 26'), 'blank means the business\'s default again');
$a = activity('settings.prefs');
ok($a !== [] && end($a)['entity_type'] === 'notification_prefs', 'logged settings.prefs (the manifest\'s event)');
$since = last_activity_id();
$r = $post('/settings/signature.php', ['signature' => "Priya Patel\nSales, SMOKE Inc."]);
ok($r['code'] === 200 && one('SELECT email_signature FROM members WHERE id = 26') === "Priya Patel\nSales, SMOKE Inc.", 'signature_save keeps the words under my emails');
$a = activity('settings.signature', $since);
ok($a !== [] && json_decode((string) end($a)['after'], true) === ['length' => 29] && !str_contains((string) end($a)['after'], 'Priya'), '…logged settings.signature with the length, never the words');
ok($post('/settings/signature.php', ['signature' => str_repeat('x', 1001)])['code'] === 422, 'a signature over 1000 characters: 422');
$r = $post('/settings/tokens/mint.php', ['label' => 'proof', 'scope' => 'api']);
ok($r['code'] === 422, 'token_mint refuses the api scope (a key is slice 8\'s key_mint)');
ok($post('/settings/tokens/mint.php', ['label' => 'proof', 'expires_in_days' => '0'])['code'] === 422, 'and an expiry of 0 days');
$r = $post('/settings/tokens/mint.php', ['label' => 'proof', 'expires_in_days' => '30']);
$mb = json_decode($r['body'], true) ?: [];
$tid = (int) ($mb['record_id'] ?? 0);
ok($r['code'] === 200 && str_starts_with((string) ($mb['token'] ?? ''), 'mcp_') && $tid > 0 && (int) one("SELECT round(extract(epoch FROM expires_at - now()) / 86400) FROM mcp_access_tokens WHERE id = :i", ['i' => $tid]) === 30, 'token_mint answers the token once (JSON) with its record_id, expiring in 30 days');
ok(one('SELECT token_hash FROM mcp_access_tokens WHERE id = :i', ['i' => $tid]) === hash('sha256', (string) $mb['token']) && !str_contains((string) json_encode(activity('token.mint')), (string) $mb['token']), 'only the hash is stored and the log never holds the token');
ok($post('/settings/tokens/revoke.php', ['token' => $tid], $who['manager'])['code'] === 404, 'another person cannot revoke it: 404');
$r = $post('/settings/tokens/revoke.php', ['token' => $tid]);
ok($r['code'] === 200 && one('SELECT revoked_at IS NOT NULL FROM mcp_access_tokens WHERE id = :i', ['i' => $tid]), 'token_revoke revokes it');
as_member(26);
$nid = (int) one("SELECT crm_notify(26, 'reply_received', 'SMOKE: Dana replied', NULL, 'deal', {$seed['deal']}, {$seed['deal']}, NULL, NULL, 27, 'p2:reply:' || random()::text)");
$r = $post('/notifications/read.php', ['notification' => $nid]);
ok($r['code'] === 200 && one('SELECT read_at IS NOT NULL FROM notifications WHERE id = :i', ['i' => $nid]), 'notification_read marks one read');
$n = json_decode(page($who['rep'], '/notifications', $json)['body'], true)['data']['notifications'] ?? [];
$mine = array_values(array_filter($n, static fn ($x) => $x['notification_id'] === $nid));
ok(($mine[0]['url'] ?? '') === '/deals/' . $seed['deal'], 'a notification names the screen of its record (the deal)');
one("SELECT crm_notify(26, 'deal_won', 'SMOKE: a win', NULL, 'deal', {$seed['won']}, {$seed['won']}, NULL, NULL, 27, 'p2:won:' || random()::text)");
ok($post('/notifications/read-all.php', ['all' => 'no'])['code'] === 422, 'notifications_read_all wants yes');
$since = last_activity_id();
$r = $post('/notifications/read-all.php', ['all' => 'yes']);
ok($r['code'] === 200 && (int) one('SELECT count(*) FROM notifications WHERE member_id = 26 AND read_at IS NULL') === 0 && activity('notification.read_all', $since) !== [], 'notifications_read_all marks every unread one read (logged notification.read_all)');
ok(preg_match('/id="header-bell-count"/', page($who['rep'], '/')['body']) === 0, 'the bell shows no count once all are read');
one("SELECT crm_notify(26, 'mention', 'SMOKE: you were mentioned', NULL, NULL, NULL, NULL, NULL, NULL, 27, 'p2:m:' || random()::text)");
ok(preg_match('/id="header-bell-count">1</', page($who['rep'], '/')['body']) === 1 && trim(strip_tags(page($who['rep'], '/notifications?count=1')['body'])) === '1', 'a new one shows on the bell, and the bell\'s own partial counts it');
$d = json_decode(page($who['rep'], '/activity', $json)['body'], true)['data']['rows'] ?? [];
ok($d !== [] && array_filter($d, static fn ($x) => str_contains($x['sentence'], 'searched for “')) !== [], 'My activity reads my trail in words ("searched for …")');

echo "7. The command bar through the kernel's chat endpoint\n";
$since = last_activity_id();
$r = req('POST', '/assistant/ask.php', ['jar' => $who['rep'], 'headers' => ['HX-Request: true'], 'form' => ['utterance' => 'What is due on the Acme deal?', 'screen' => 'home', 'csrf_token' => $t]]);
ok($r['code'] === 200 && str_contains($r['body'], 'id="assistant-reply-text"') && str_contains($r['body'], 'Hello from the fake expert'), 'the expert\'s reply is rendered above the bar');
$a = activity('assistant.ask', $since);
$la = $a !== [] ? json_decode((string) end($a)['after'], true) : [];
ok($a !== [] && ($la['length'] ?? 0) === 29 && ($la['run_id'] ?? 0) >= 1 && !str_contains((string) end($a)['after'], 'Acme'), 'assistant.ask logs the length and the run id, never the words');
$chatLines = file(need('FAKE_KERNEL_STATE') . '.chat'); $chat = json_decode((string) end($chatLines), true);
ok($chat['agent'] === 'expert' && (int) $chat['acting'] === 26, 'the kernel saw the call for the expert as the acting person');
ok(req('POST', '/assistant/ask.php', ['jar' => $who['rep'], 'form' => ['utterance' => 'hi']])['code'] === 403, 'no CSRF token: 403');
kernel_state(function ($s) { $s['chat_status'] = 409; $s['chat_message'] = 'The expert is busy with another turn.'; return $s; });
$r = req('POST', '/assistant/ask.php', ['jar' => $who['rep'], 'headers' => ['HX-Request: true'], 'form' => ['utterance' => 'hi', 'csrf_token' => $t]]);
ok($r['code'] === 409 && str_contains($r['body'], 'The expert is busy with another turn.'), 'a refusal is shown in the kernel\'s own words');
kernel_state(function ($s) { unset($s['chat_status'], $s['chat_message']); $s['chat'] = ['run_id' => 2, 'status' => 'awaiting_approval', 'finished' => true, 'reply' => 'I drafted the email to Dana; sending it waits for approval.', 'actions' => [['tool' => 'email_send', 'status' => 'awaiting_approval']], 'approval_request_id' => 12]; return $s; });
$r = req('POST', '/assistant/ask.php', ['jar' => $who['rep'], 'headers' => ['HX-Request: true'], 'form' => ['utterance' => 'email Dana the proposal', 'csrf_token' => $t]]);
ok($r['code'] === 200 && str_contains($r['body'], 'id="assistant-reply-approval"') && str_contains($r['body'], 'request #12') && str_contains($r['body'], 'email_send'), 'an action awaiting approval is shown as such, with the request number');
kernel_state(function ($s) { $s['chat'] = ['run_id' => 3, 'status' => 'succeeded', 'finished' => true, 'reply' => 'Logged the call.', 'actions' => [['tool' => 'activity_log_call', 'status' => 'ok', 'record_id' => 9]]]; return $s; });
$r = req('POST', '/assistant/ask.php', ['jar' => $who['rep'], 'headers' => ['HX-Request: true'], 'form' => ['utterance' => 'log a call with Dana', 'csrf_token' => $t]]);
ok($r['code'] === 200 && preg_match('/^HX-Trigger: .*activityChanged/mi', $r['headers']) === 1, 'a tool that changed an activity fires activityChanged for the screens listening');
kernel_state(function ($s) { $s['chat'] = ['run_id' => 4, 'status' => 'succeeded', 'finished' => true, 'reply' => 'Opening your tokens.', 'actions' => [], 'navigate' => '/settings/tokens/']; return $s; });
$r = req('POST', '/assistant/ask.php', ['jar' => $who['rep'], 'headers' => ['HX-Request: true'], 'form' => ['utterance' => 'open my tokens', 'csrf_token' => $t]]);
ok($r['code'] === 200 && str_contains($r['headers'], 'HX-Location') && str_contains($r['headers'], '/settings/tokens/') && str_contains($r['body'], 'id="assistant-reply-navigate"'), 'a navigate answer is followed (HX-Location)');
kernel_state(function ($s) { $s['chat'] = ['run_id' => 5, 'status' => 'succeeded', 'finished' => true, 'reply' => 'x', 'actions' => [], 'navigate' => 'https://evil.invalid/x']; return $s; });
$r = req('POST', '/assistant/ask.php', ['jar' => $who['rep'], 'headers' => ['HX-Request: true'], 'form' => ['utterance' => 'go', 'csrf_token' => $t]]);
ok($r['code'] === 200 && !str_contains($r['headers'], 'HX-Location'), 'a navigate that is not a local path is ignored');
kernel_state(function ($s) { unset($s['chat']); return $s; });
ok(req('POST', '/assistant/ask.php', ['jar' => $who['rep'], 'headers' => ['HX-Request: true'], 'form' => ['utterance' => str_repeat('x', 2100), 'csrf_token' => $t]])['code'] === 422, 'over 2,000 characters: 422, in words');

echo "8. The action-token and run-token gate\n";
$r = req('GET', '/activity', ['headers' => ['Accept: application/json', 'X-Action-Token: ' . person_token(26)]]);
ok($r['code'] === 200 && preg_match('/^Set-Cookie:/mi', $r['headers']) === 0, 'a person\'s action token opens a read for one request, with no cookie set');
ok(req('GET', '/activity', ['headers' => ['Accept: application/json', 'X-Action-Token: ' . person_token(26, -5)]])['code'] === 401, 'an expired action token: 401');
$r = req('POST', '/settings/prefs.php', ['headers' => ['Accept: application/json', 'X-Action-Token: ' . person_token(26)], 'form' => ['email_enabled' => 'yes']]);
ok($r['code'] === 200 && (json_decode($r['body'], true)['ok'] ?? false) === true && one('SELECT email_enabled::text FROM notification_prefs WHERE member_id = 26') === 'true', 'a write under an action token answers JSON (prefs_save) and stands in for CSRF');
$tok = run_token(40, 77);
ok(req('GET', '/', ['headers' => as_agent($tok)])['code'] === 200, 'an admitted agent (the Expert, 40) under a run token reads the home');
$since = last_activity_id();
req('GET', '/activity', ['headers' => array_merge(as_agent($tok), ['X-Screen-View: 1'])]);
$v = activity('screen.view', $since);
ok($v !== [] && (int) end($v)['agent_run_id'] === 77 && end($v)['source'] === 'agent', 'its screen view is logged with the run id and source agent');
ok(req('POST', '/settings/prefs.php', ['headers' => as_agent($tok), 'form' => ['digest' => 'yes']])['code'] === 200, 'an agent may set its own preferences (prefs_save admits agents)');
ok(req('POST', '/settings/tokens/mint.php', ['headers' => as_agent($tok), 'form' => ['label' => 'agent']])['code'] === 403, 'but not mint a person\'s token: 403 (people only)');
ok(req('POST', '/settings/signature.php', ['headers' => as_agent($tok), 'form' => ['signature' => 'x']])['code'] === 403, 'nor set a signature: 403');
ok(req('POST', '/assistant/ask.php', ['headers' => as_agent($tok), 'form' => ['utterance' => 'hi']])['code'] === 403, 'nor use the command bar: 403');
ok(req('GET', '/activity', ['headers' => ['Accept: application/json', 'X-Action-Token: ' . $tok]])['code'] === 401, 'a run token without its relay signature: 401');
$since = last_activity_id();
ok(req('GET', '/activity', ['headers' => as_agent(run_token(900, 78))])['code'] === 401 && (int) one('SELECT count(*) FROM members WHERE id = 900') === 0, 'an id with no mirror row is refused and never created');
$ref = activity('member.refused', $since);
ok($ref !== [] && (int) end($ref)['entity_id'] === 900, '…and logged member.refused with the id');
ok(req('GET', '/', ['headers' => as_agent(run_token(41, 79))])['code'] === 401 || req('GET', '/', ['headers' => as_agent(run_token(41, 79))])['code'] === 403, 'the Watcher (an agent not admitted here) is refused');

echo "9. The mirror — preferences before anyone looks, a revoked grant\n";
ok((int) one("SELECT count(*) FROM members m WHERE m.status = 'active' AND m.capability IS NOT NULL AND NOT EXISTS (SELECT 1 FROM notification_prefs p WHERE p.member_id = m.id)") === 0, 'every admitted member has notification preferences (made by the sync)');
pdo()->exec('DELETE FROM notification_prefs WHERE member_id = 30');
sign_on(30);
ok((int) one('SELECT count(*) FROM notification_prefs WHERE member_id = 30') === 1, 'a sign-on makes them too (Dana\'s were removed, she signed on, they are back)');
[$jBea, ] = sign_on(28);
ok(req('GET', '/', ['jar' => $jBea])['code'] === 200, 'Bea is let in while her grant stands');
kernel_state(function ($s) { $s['incremental'] = ['schema' => 'os.directory-changes/1', 'since' => '2026-01-01T00:00:00.000000Z', 'next' => '2026-01-03T00:00:00.000000Z', 'full' => false, 'members' => [], 'departments' => [], 'memberships' => [], 'deleted_departments' => [], 'scopes' => [],
    'access' => [['member_id' => 28, 'capability' => null, 'roles' => [], 'scopes' => []]]]; return $s; });
exec('php bin/directory_sync.php 2>&1', $syncOut, $rc);
ok($rc === 0 && req('GET', '/', ['jar' => $jBea])['code'] === 302, 'a grant the kernel revoked shuts the door within one sync');
ok(one("SELECT ended_by FROM member_sessions WHERE member_id = 28 ORDER BY created_at DESC LIMIT 1") === 'directory', '…the session ended by the directory');
kernel_state(function ($s) { unset($s['incremental']); return $s; });
finish();
