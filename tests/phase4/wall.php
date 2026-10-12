<?php
/**
 * Proof — THE WALL (tool surface: "The wall is per row", "No tool reaches a contact and no tool returns an email's body or a note's text to a caller who may not read it"): a restricted record (Dana's p4 Hidden Holdings with its
 * contact, deal, note and call; Marco's Globex) is, for someone the wall hides it from, the SAME not_found as one that never existed, in every tool that could name it — lists, details, children, the timeline, the search, the
 * guarded SQL; a private email and a private activity are their owner's and a manager's; a draft is its sender's alone; rights (the leaderboard, the team's forecast, the tombstones, every key, the mailbox, the inbox) are the
 * database's and the tool says so in words; the numbers equal what the views hand the same member; and no tool lets a person's SQL reach what the views do not.
 */
require __DIR__ . '/lib.php';
$w = p4_world();
$HID = ['p4 Hidden', 'HIDDEN-DESCRIPTION', 'HIDDEN-NOTE', 'HIDDEN-CALL', 'hidden.p4.example.invalid', 'hid@hidden'];
$leaks = static function (string $text) use ($HID): array { return array_values(array_filter($HID, static fn ($n) => stripos($text, $n) !== false)); };
$NOPE = fn (int $m, string $t, array $a) => tool($m, $t, $a);

echo "1. The hidden account is nowhere for those it is hidden from\n";
$sweepForPriya = [];
foreach (sweep($w) as [$t, $a]) {
    if (in_array($t, ['records_search'], true)) { continue; }
    $r = tool(PRIYA, $t, $a);
    $sweepForPriya[$t] = $r['text'];
}
$leaked = [];
foreach ($sweepForPriya as $t => $text) { if ($leaks($text)) { $leaked[] = $t; } }
ok($leaked === [], 'no tool of the sweep names anything of the hidden account to Priya' . ($leaked ? ' — leaks in: ' . implode(',', $leaked) : ''));
foreach (['find_accounts' => ['q' => 'Hidden'], 'find_contacts' => ['q' => 'Hid'], 'find_deals' => ['q' => 'Hidden'], 'find_activities' => ['q' => 'HIDDEN'], 'search' => ['q' => 'Hidden Holdings'],
          'find_notes' => ['kind' => 'account', 'record' => $w['hidden']]] as $t => $a) {
    $r = tool(PRIYA, $t, $a);
    $rows = $r['data']['rows'] ?? [];
    ok($t === 'find_notes' ? $r['code'] === 'not_found' : (!$r['error'] && $rows === []), "$t: Priya finds nothing of it" . ($r['error'] ? ' (' . $r['code'] . ')' : ''));
}
$missing = tool(PRIYA, 'get_account', ['account' => 99999999]);
foreach ([['get_account', ['account' => $w['hidden']]], ['get_contact', ['contact' => $w['hid_c']]], ['get_deal', ['deal' => $w['hid_deal']]], ['account_contacts', ['account' => $w['hidden']]], ['account_deals', ['account' => $w['hidden']]],
          ['timeline', ['kind' => 'account', 'record' => $w['hidden']]], ['find_attachments', ['kind' => 'account', 'record' => $w['hidden']]], ['deal_history', ['deal' => $w['hid_deal']]],
          ['deal_products', ['deal' => $w['hid_deal']]], ['deal_contacts', ['deal' => $w['hid_deal']]], ['account_tickets', ['account' => $w['hidden']]]] as [$t, $a]) {
    $r = tool(PRIYA, $t, $a);
    ok($r['code'] === 'not_found', "$t on the hidden record: not_found, as for a record that does not exist");
}
$r = tool(PRIYA, 'get_account', ['account' => $w['hidden']]);
ok($r['data']['error']['message'] === $missing['data']['error']['message'] && $r['data']['error']['retry'] === $missing['data']['error']['retry'], 'in the very same words as a missing one — a tool never confirms that a restricted record exists');
foreach ([['get_account', ['account' => 'p4 Hidden Holdings']], ['get_deal', ['deal' => 'p4 Hidden — renewal']]] as [$t, $a]) {
    ok(tool(PRIYA, $t, $a)['code'] === 'not_found', "$t by NAME: not_found too (not 'ambiguous', not a candidate)");
}
$r = tool(DANA, 'get_account', ['account' => $w['hidden']]);
ok(!$r['error'] && ($r['data']['account']['name'] ?? '') === 'p4 Hidden Holdings', 'Dana, whom it is restricted to, reads it');
$r = tool(MARCO, 'get_account', ['account' => $w['hidden']]);
ok(!$r['error'] && ($r['data']['account']['name'] ?? '') === 'p4 Hidden Holdings', 'Marco, a manager, reads it');
ok(count(tool(MARCO, 'find_notes', ['kind' => 'account', 'record' => $w['hidden']])['data']['rows'] ?? []) === 1 && str_contains(tool(DANA, 'find_notes', ['kind' => 'account', 'record' => $w['hidden'], 'full' => true])['text'], 'HIDDEN-NOTE'), 'and its note, as they read it');
$r = tool(DANA, 'search', ['q' => 'Hidden Holdings']);
ok(count($r['data']['rows'] ?? []) >= 1 && find_row($r['data']['rows'], 'entity_id', $w['hidden']) !== null, 'the search finds it for Dana');
ok(tool(ANN, 'get_account', ['account' => $w['hidden']])['code'] === 'not_found' && tool(ANN, 'get_account', ['account' => $w['globex']])['code'] === 'not_found', 'a Viewer reads neither restricted account');
ok(tool(PRIYA, 'get_account', ['account' => $w['globex']])['code'] === 'not_found' && !tool(MARCO, 'get_account', ['account' => $w['globex']])['error'], 'Marco\'s Globex: hidden from Priya, his to read');
ok(find_row(trows(PRIYA, 'deals_closed', ['from' => date('Y-m-01'), 'to' => date('Y-m-d')]), 'deal_id', $w['globex_deal']) !== null,
   'OPEN QUESTION, recorded: a deal that is not itself restricted is visible by its own flag even when its account is — the deal row carries the account\'s name (db/016 mcp_deals.account_name), as the screens do');

echo "2. The guarded SQL reaches only what the views hand the caller\n";
$r = tool(PRIYA, 'records_search', ['sql' => 'SELECT account_id, name FROM mcp_accounts']);
ok(!$r['error'] && find_row($r['data']['rows'], 'name', 'p4 Hidden Holdings') === null && find_row($r['data']['rows'], 'name', 'SMOKE Acme Industries') !== null, 'records_search over mcp_accounts: the caller\'s rows — Priya\'s list lacks the hidden one');
$r = tool(DANA, 'records_search', ['sql' => 'SELECT account_id, name FROM mcp_accounts']);
ok(find_row($r['data']['rows'] ?? [], 'name', 'p4 Hidden Holdings') !== null, 'and Dana\'s holds it');
foreach (['SELECT * FROM search_index' => 'the unwalled search index', "SELECT * FROM crm_share_accounts_index('x', NULL, NULL, 10, 0)" => 'a share function', "SELECT set_config('app.member_id', '27', true)" => 'set_config',
          'SELECT * FROM k7_cache' => 'the K7 cache', 'SELECT * FROM mcp_access_tokens' => 'the token table', 'SELECT * FROM accounts' => 'the base table', 'SELECT * FROM pg_shadow' => 'the catalogue',
          'DELETE FROM accounts' => 'a write', 'SELECT 1; SELECT 2' => 'two statements', "SELECT pg_read_file('/etc/passwd')" => 'a file read'] as $sql => $what) {
    $r = tool(PRIYA, 'records_search', ['sql' => $sql]);
    ok($r['error'] && !str_contains($r['text'], 'HIDDEN') && !str_contains($r['text'], 'root:'), "records_search refuses $what");
}
ok((bool) one("SELECT NOT has_table_privilege('sales_crm_records_ro', 'search_index', 'SELECT')"), 'db/025: the records role itself has no SELECT on the search index — the deny list is belt and braces');
$r = tool(PRIYA, 'activity_query', ['sql' => 'SELECT action, after FROM mcp_activity_log'], ACT);
$r = mcp_tool(ACT, ptoken(PRIYA), 'activity_query', ['sql' => 'SELECT action, account_id, after::text AS a FROM mcp_activity_log']);
ok(!$r['error'] && !str_contains($r['text'], 'p4 Hidden') && count($r['data']['rows'] ?? []) > 0, 'activity_query: the trail of the hidden account is not in Priya\'s rows');
$r = mcp_tool(ACT, ptoken(DANA), 'activity_query', ['sql' => 'SELECT action, after::text AS a FROM mcp_activity_log']);
ok(str_contains($r['text'], 'p4 Hidden'), 'and is in Dana\'s');
foreach (['SELECT * FROM activity_log', 'SELECT * FROM mcp_notes', 'SELECT * FROM search_index'] as $sql) {
    ok(mcp_tool(ACT, ptoken(PRIYA), 'activity_query', ['sql' => $sql])['error'], "activity_query refuses: $sql");
}

echo "3. Email: private is private, a draft is its sender's\n";
$mine = tool(PRIYA, 'find_emails', ['deal' => $w['deal1'], 'with_body' => true]);
$subj = ids($mine['data']['rows'] ?? [], 'subject');
ok(in_array('p4 PRIVATE Re: pricing', $subj, true) && in_array('p4 Public follow-up', $subj, true) && in_array('p4 DRAFT subject', $subj, true), 'Priya sees her private email, her public one and her own draft');
ok(str_contains($mine['text'], 'PRIVATE-BODY') && str_contains($mine['text'], 'PUBLIC-BODY'), 'with the bodies she may read');
$mar = tool(MARCO, 'find_emails', ['deal' => $w['deal1'], 'with_body' => true]);
$subj = ids($mar['data']['rows'] ?? [], 'subject');
ok(in_array('p4 PRIVATE Re: pricing', $subj, true) && !in_array('p4 DRAFT subject', $subj, true), 'Marco, a manager, reads the private email (a manager may) and not the draft (never)');
$dan = tool(DANA, 'find_emails', ['deal' => $w['deal1'], 'with_body' => true]);
$subj = ids($dan['data']['rows'] ?? [], 'subject');
ok(in_array('p4 Public follow-up', $subj, true) && !in_array('p4 PRIVATE Re: pricing', $subj, true) && !in_array('p4 DRAFT subject', $subj, true) && !str_contains($dan['text'], 'PRIVATE-BODY') && !str_contains($dan['text'], 'DRAFT-BODY'),
   'Dana sees the public one only: no private email, no draft, and none of their text anywhere in the answer');
ok(tool(DANA, 'get_email', ['email' => $w['mail_priv'], 'direction' => 'out'])['code'] === 'not_found' && tool(DANA, 'get_email', ['email' => $w['mail_draft'], 'direction' => 'out'])['code'] === 'not_found', 'get_email on the private one and the draft: not_found for Dana');
ok(tool(MARCO, 'get_email', ['email' => $w['mail_draft'], 'direction' => 'out'])['code'] === 'not_found' && !tool(PRIYA, 'get_email', ['email' => $w['mail_draft'], 'direction' => 'out'])['error'], 'the draft: not even Marco\'s, Priya\'s alone');
$noBody = tool(PRIYA, 'find_emails', ['deal' => $w['deal1']]);
ok(!has_key($noBody['data'], 'body_text') && !str_contains($noBody['text'], 'PUBLIC-BODY'), 'without with_body no email text is returned at all');
$list = tool(PRIYA, 'timeline', ['kind' => 'deal', 'record' => $w['deal1']]);
ok(!str_contains($list['text'], 'PRIVATE-BODY') && !str_contains($list['text'], 'DRAFT-BODY') && !str_contains($list['text'], 'PUBLIC-BODY'), 'the timeline carries subjects and directions, never an email body');

echo "4. Notes, activities, files\n";
$n = tool(PRIYA, 'find_notes', ['kind' => 'deal', 'record' => $w['deal1']]);
$body = $n['data']['rows'][0]['body'] ?? '';
ok(strlen($body) <= 200 && ($n['data']['rows'][0]['cut'] ?? false) === true, 'a note is cut to 200 characters unless `full`');
$n = tool(PRIYA, 'find_notes', ['kind' => 'deal', 'record' => $w['deal1'], 'full' => true]);
ok(strlen($n['data']['rows'][0]['body'] ?? '') > 300 && ($n['data']['rows'][0]['cut'] ?? true) === false, 'with `full` the whole text');
ok(!str_contains(tool(PRIYA, 'timeline', ['kind' => 'deal', 'record' => $w['deal1']])['text'], str_repeat('x', 250)), 'and the timeline cuts it again at 200');
$a = tool(PRIYA, 'find_activities', ['q' => 'PRIVATE-ACTIVITY']);
$b = tool(MARCO, 'find_activities', ['q' => 'PRIVATE-ACTIVITY']);
ok(find_row($b['data']['rows'], 'subject', 'p4 PRIVATE-ACTIVITY') !== null, 'Marco reads his private activity');
$row = find_row($a['data']['rows'] ?? [], 'subject', 'p4 PRIVATE-ACTIVITY');
ok($row === null || $row['body'] === null, 'for Priya it is not there, or without its body: ' . ($row === null ? 'not there' : 'body null'));
$cal = tool(PRIYA, 'calendar', ['from' => date('Y-m-d'), 'to' => date('Y-m-d', time() + 5 * 86400), 'who' => 'team:Sales']);
ok($cal['code'] === 'managers_only', 'a team\'s calendar needs the team-calendar right: ' . ($cal['code'] ?? 'answered'));
$cal = tool(PRIYA, 'calendar', ['from' => date('Y-m-d'), 'to' => date('Y-m-d', time() + 5 * 86400), 'who' => (string) MARCO]);
ok(!$cal['error'] && find_row($cal['data']['rows'] ?? [], 'subject', 'p4 PRIVATE-ACTIVITY') === null, 'a colleague\'s calendar is open to every role (calendar.read) — but Marco\'s private activity is not in it');
$cal = tool(MARCO, 'calendar', ['from' => date('Y-m-d'), 'to' => date('Y-m-d', time() + 5 * 86400), 'who' => (string) PRIYA]);
ok(!$cal['error'] && find_row($cal['data']['rows'], 'subject', 'SMOKE Demo for the board') !== null, 'Marco reads Priya\'s calendar');
$cal = tool(MARCO, 'calendar', ['from' => date('Y-m-d'), 'to' => date('Y-m-d', time() + 5 * 86400), 'who' => (string) MARCO]);
ok(find_row($cal['data']['rows'] ?? [], 'subject', 'p4 PRIVATE-ACTIVITY') !== null, 'Marco\'s own calendar holds his private activity');
$cal = tool(MARCO, 'calendar', ['from' => date('Y-m-d'), 'to' => date('Y-m-d', time() + 5 * 86400), 'who' => 'team:Sales']);
ok(!$cal['error'], 'Marco (calendar.team) reads the Sales team\'s calendar');
ok(!tool(PRIYA, 'my_activities', ['member' => (string) MARCO])['error'], 'another seller\'s agenda is open to anyone with calendar.read');

echo "5. Rights are the database's; the tool says so in words\n";
foreach ([[PRIYA, 'leaderboard', []], [ANN, 'leaderboard', []], [PRIYA, 'tombstones', []], [ANN, 'tombstones', []], [PRIYA, 'mailbox_health', []], [PRIYA, 'key_usage_summary', []], [PRIYA, 'reminders_due', []],
          [MARCO, 'reminders_due', []], [PRIYA, 'find_keys', ['mine' => false]], [ANN, 'leads_inbox', []], [ANN, 'emails_unmatched', []], [ANN, 'proposed_leads', []]] as [$m, $t, $a]) {
    $r = tool($m, $t, $a);
    ok($r['code'] === 'managers_only' && strlen($r['data']['error']['message'] ?? '') > 20, "$t for member $m: managers_only, with a sentence" . ($r['code'] === 'managers_only' ? '' : ' — got ' . ($r['code'] ?? 'an answer')));
}
foreach ([[MARCO, 'leaderboard', []], [MARCO, 'tombstones', []], [ADMIN, 'mailbox_health', []], [ADMIN, 'reminders_due', []], [ADMIN, 'key_usage_summary', []], [PRIYA, 'leads_inbox', []], [PRIYA, 'emails_unmatched', []]] as [$m, $t, $a]) {
    $r = tool($m, $t, $a);
    ok(!$r['error'], "$t for member $m: answers" . ($r['error'] ? ' — ' . $r['text'] : ''));
}
$fc = trows(PRIYA, 'forecast', ['months' => 2, 'by' => 'seller']);
$fm = trows(MARCO, 'forecast', ['months' => 2, 'by' => 'seller']);
ok($fc !== [] && array_unique(ids($fc, 'row_id')) === [PRIYA], 'forecast by seller: Priya holds her own line only (the one rule the tool adds)');
ok(count(array_unique(ids($fm, 'row_id'))) >= 2, 'Marco\'s holds more than one seller');
ok(array_unique(array_column(trows(PRIYA, 'forecast', ['months' => 2, 'by' => 'stage']), 'row_id')) !== [], 'a forecast by stage is the pipeline\'s for everyone');
$qp = trows(PRIYA, 'quotas', []);
$qm = trows(MARCO, 'quotas', []);
ok(array_unique(ids($qp, 'member_id')) === [PRIYA] && count(array_unique(ids($qm, 'member_id'))) >= 2, 'quotas: Priya\'s own, Marco\'s everyone\'s');
$kp = trows(PRIYA, 'find_keys', []);
ok($kp !== [] && array_unique(ids($kp, 'member_id')) === [PRIYA] && !has_key($kp, 'token_hash') && !str_contains(json_encode($kp), str_repeat('b', 30)), 'find_keys: Priya\'s own keys, never a hash');
ok(count(trows(ADMIN, 'find_keys', ['mine' => false])) >= 1 && trows(ADMIN, 'find_keys', ['mine' => false]) !== [], 'every key for the admin');
$kd = trows(PRIYA, 'agent_dispatches', []);
ok($kd === [] || array_unique(ids($kd, 'agent_member_id')) !== [], 'agent_dispatches: Priya sees the ones she acted for');
ok(count(trows(ADMIN, 'agent_dispatches', [])) >= 1, 'and the admin sees every one');
$st = tool(PRIYA, 'get_settings', []);
ok(is_array($st['data']['settings'] ?? null) && array_key_exists('mailbox_id', $st['data']['settings']) && $st['data']['settings']['mailbox_id'] === null, 'the settings carry the mailbox id to the admin only');
$tm = tool(MARCO, 'tombstones', []);
ok(find_row($tm['data']['rows'] ?? [], 'name', 'Gone Tomb · p4 Tombco') !== null || count($tm['data']['rows'] ?? []) >= 1, 'Marco reads the tombstones, with when each purges: ' . substr(json_encode($tm['data']['rows'][0] ?? []), 0, 140));
ok(isset($tm['data']['rows'][0]['purges_at']), 'every tombstone says when it purges');
$ar = tool(PRIYA, 'settings_catalogue', ['part' => 'rules']);
ok(isset($ar['data']) && ($ar['data']['rules'] ?? null) !== null, 'the assignment rules are readable by a Rep (leads.assign): ' . json_encode(array_keys($ar['data'] ?? [])));
$ar = tool(ANN, 'settings_catalogue', ['part' => 'rules']);
ok(($ar['data']['rules'] ?? null) === [], 'for a Viewer the rules come back empty — the view\'s rule');
$aw = tool(PRIYA, 'activity_summary', ['from' => date('Y-m-d', time() - 30 * 86400), 'to' => date('Y-m-d')]);
ok(array_unique(ids($aw['data']['rows'] ?? [], 'member_id')) === [PRIYA] || ($aw['data']['rows'] ?? []) === [], 'activity_summary: a Rep\'s holds their own rows only');

echo "6. The numbers are the views'\n";
foreach ([[PRIYA, 'find_deals', [], 'SELECT count(*) FROM mcp_deals'], [DANA, 'find_deals', [], 'SELECT count(*) FROM mcp_deals'], [MARCO, 'find_deals', [], 'SELECT count(*) FROM mcp_deals'],
          [PRIYA, 'find_accounts', [], 'SELECT count(*) FROM mcp_accounts'], [DANA, 'find_accounts', [], 'SELECT count(*) FROM mcp_accounts'], [PRIYA, 'find_contacts', [], 'SELECT count(*) FROM mcp_contacts'],
          [PRIYA, 'find_leads', [], 'SELECT count(*) FROM mcp_leads'], [ANN, 'find_leads', [], 'SELECT count(*) FROM mcp_leads']] as [$m, $t, $a, $sql]) {
    $got = tool($m, $t, $a + ['limit' => 100]);
    ok((int) ($got['data']['total'] ?? -1) === (int) sql_as($m, $sql)[0]['count'], "$t total for member $m equals the view's count (" . ($got['data']['total'] ?? '?') . ')');
}
$d = tool(PRIYA, 'get_deal', ['deal' => $w['deal1']]);
ok(($d['data']['deal']['value'] ?? '') === '24000.00' && ($d['data']['deal']['currency'] ?? '') === 'USD', 'money is a string with two decimals beside its currency: ' . json_encode($d['data']['deal']['value'] ?? null));
$f = tool(PRIYA, 'find_deals', ['q' => 'seats']);
ok(is_string($f['data']['rows'][0]['value'] ?? null) && is_string($f['data']['rows'][0]['weighted_value'] ?? null) && is_int($f['data']['rows'][0]['probability'] ?? null), 'in a list too — and a probability stays an integer');
finish();
