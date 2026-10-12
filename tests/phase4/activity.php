<?php
/**
 * Proof — the activity server (tool surface "Activity server"): the trail through mcp_activity_log, as the asking member. record_history is oldest first and names the actor (and whether it was an agent); a record the wall
 * hides has no history for the caller, in the words of one that has none; another member's trail is a manager's (everyone else sees only what that actor did on records they own); agent_activity is the agents' settings
 * right's or a manager's, an agent sees its own; screen views are not events; the weekly timeline counts touches; the search and the guarded SQL read the same rows. The vocabulary rule (design §6.5): no body, token or key
 * value is in any row — a key is its label.
 */
require __DIR__ . '/lib.php';
$w = p4_world();
$A = static fn (int $m, string $t, array $a = []) => mcp_tool(ACT, ptoken($m), $t, $a);
$AR = static fn (int $m, string $t, array $a = []) => ($A($m, $t, $a)['data']['rows'] ?? []);
$sorted = static function (array $x): array { sort($x); return $x; };
$day = date('Y-m-d', time() - 3 * 86400);

echo "1. record_history\n";
$h = $A(PRIYA, 'record_history', ['entity_type' => 'deal', 'entity_id' => $w['deal1']])['data'];
$acts = array_column($h['rows'], 'action');
ok(in_array('deal.move', $acts, true) && in_array('activity.create', $acts, true) && !in_array('screen.view', $acts, true), 'the deal\'s trail: the move and the agent\'s activity — the screen view is not an event');
ok($h['rows'][0]['occurred_at'] <= $h['rows'][1]['occurred_at'], 'oldest first');
$mv = find_row($h['rows'], 'action', 'deal.move');
$ag = find_row($h['rows'], 'action', 'activity.create');
ok($mv['actor_name'] === 'SMOKE Priya' && $mv['actor_is_agent'] === false && $mv['after']['stage_name'] === 'Proposal' && $mv['after']['seconds_in_stage'] === 86400 && str_contains($mv['summary'], 'moved a deal'), 'a move: who, the stage and the time in the stage it left, in a sentence: ' . $mv['summary']);
ok($ag['actor_is_agent'] === true && $ag['agent_run_id'] === 424242 && $ag['source'] === 'agent', 'an agent\'s row says so, and which run');
$lh = $A(PRIYA, 'record_history', ['entity_type' => 'lead', 'entity_id' => $w['lead_work']])['data'];
ok(count($lh['rows']) === 1 && $lh['rows'][0]['action'] === 'lead.create' && $lh['rows'][0]['lead_id'] === $w['lead_work'], 'a lead\'s trail by its lead_id');
$kh = $A(MARCO, 'record_history', ['entity_type' => 'key', 'entity_id' => $w['key_p']])['data'];
ok($sorted(array_column($kh['rows'], 'action')) === $sorted(['key.mint', 'api.call']) && !str_contains(json_encode($kh), str_repeat('b', 30)), 'a key\'s trail is its mint and its calls — the label, never the key or its hash');
$hh = $A(PRIYA, 'record_history', ['entity_type' => 'deal', 'entity_id' => $w['hid_deal']]);
$none = $A(PRIYA, 'record_history', ['entity_type' => 'deal', 'entity_id' => 99999999]);
ok(!$hh['error'] && $hh['data']['rows'] === [] && $hh['data']['rows'] === $none['data']['rows'] && $hh['data']['total'] === $none['data']['total'], 'the hidden deal has no history for Priya — the same empty answer as a deal that does not exist');
ok(count($A(DANA, 'record_history', ['entity_type' => 'deal', 'entity_id' => $w['hid_deal']])['data']['rows']) === 1, 'Dana reads it');
ok($A(PRIYA, 'record_history', ['entity_type' => 'issuer', 'entity_id' => 1])['code'] === 'invalid_argument' && $A(PRIYA, 'record_history', ['entity_type' => 'deal', 'entity_id' => 1, 'limit' => 500])['code'] === 'invalid_argument', 'an entity type outside the list and a limit over 200 are refused');

echo "2. actor_history — whose trail it is\n";
$own = $A(PRIYA, 'actor_history', ['member' => 'me'])['data'];
ok($own['count'] >= 3 && array_unique(array_column($own['rows'], 'actor_member_id')) === [PRIYA] && $own['rows'][0]['occurred_at'] >= $own['rows'][1]['occurred_at'], 'your own trail: yours, newest first');
ok(!in_array('screen.view', array_column($own['rows'], 'action'), true), 'with no screen views');
ok($A(PRIYA, 'actor_history', ['member' => 'Dana'])['data']['rows'] === [] && $A(PRIYA, 'actor_history', ['member' => 'SMOKE Dana'])['data']['member_id'] === DANA, 'another seller\'s trail for a Rep: only what they did on records the Rep owns — here nothing');
$dm = $A(MARCO, 'actor_history', ['member' => 'Dana'])['data'];
ok(count($dm['rows']) === 1 && $dm['rows'][0]['entity_id'] === $w['hid_deal'], 'a manager reads it — including the hidden deal he may see');
ok(count($A(ADMIN, 'actor_history', ['member' => (string) DANA])['data']['rows']) === 1, 'so does the admin');
ok(count($A(MARCO, 'actor_history', ['key' => $w['key_p']])['data']['rows']) === 1 && $A(PRIYA, 'actor_history', ['key' => $w['key_p']])['data']['rows'] === [], 'a key\'s calls: a manager reads them; for a Rep, only those on records they own (none)');
ok($A(PRIYA, 'actor_history', [])['code'] === 'invalid_argument' && $A(PRIYA, 'actor_history', ['member' => 'me', 'key' => 1])['code'] === 'invalid_argument', 'name one actor — not none, not both');
ok($A(PRIYA, 'actor_history', ['member' => 'Nobody At All'])['code'] === 'not_found', 'an unknown member is not_found');
ok(count($A(PRIYA, 'actor_history', ['member' => 'me', 'since' => date('Y-m-d', time() + 86400)])['data']['rows']) === 0, 'since narrows (tomorrow: nothing)');

echo "3. recent_activity\n";
ok($A(PRIYA, 'recent_activity', [])['code'] === 'invalid_argument', 'since is required');
$ra = $A(PRIYA, 'recent_activity', ['since' => $day])['data'];
ok(find_row($ra['rows'], 'action', 'activity.create') !== null && find_row($ra['rows'], 'action', 'deal.move') === null, 'on my book since three days ago: the agent\'s new activity, and not my own move (exclude_mine defaults on)');
$ra = $A(PRIYA, 'recent_activity', ['since' => $day, 'exclude_mine' => false])['data'];
ok(find_row($ra['rows'], 'action', 'deal.move') !== null, 'with exclude_mine false, my own too');
ok(count($A(PRIYA, 'recent_activity', ['since' => $day, 'deal' => $w['deal1'], 'exclude_mine' => false])['data']['rows']) === 2 && count($A(PRIYA, 'recent_activity', ['since' => $day, 'account' => 'SMOKE Acme Industries', 'exclude_mine' => false])['data']['rows']) >= 2, 'by deal; by account name');
$ow = $A(MARCO, 'recent_activity', ['since' => $day, 'owner' => 'Dana'])['data'];
ok(find_row($ow['rows'], 'action', 'deal.update') !== null, 'by owner (Marco, Dana\'s book): Dana\'s hidden deal\'s update, as Marco may see it');
$ow = $A(PRIYA, 'recent_activity', ['since' => $day, 'owner' => 'Dana'])['data'];
ok(!str_contains(json_encode($ow), 'p4 Hidden'), 'and for Priya the same query holds nothing of it');

echo "4. agent_activity\n";
ok($A(PRIYA, 'agent_activity', [])['code'] === 'managers_only' && $A(ANN, 'agent_activity', [])['code'] === 'managers_only', 'a Rep and a Viewer are refused in words');
$aa = $A(MARCO, 'agent_activity', [])['data'];
ok(find_row($aa['actions'], 'action', 'activity.create') !== null && array_unique(array_column(array_filter($aa['actions'], fn ($r) => $r['action'] === 'activity.create'), 'actor_is_agent')) === [true], 'a manager reads the agents\' actions of the week');
ok($aa['proposals'] === ['open' => 1, 'accepted' => 0, 'dismissed' => 1], 'and the proposals: one waiting, none accepted, one dismissed — ' . json_encode($aa['proposals']));
$one = $A(MARCO, 'agent_activity', ['agent' => 'SMOKE Assistant'])['data'];
ok(array_unique(array_column($one['actions'], 'actor_member_id')) === [ASSISTANT] && $one['proposals']['open'] === 1, 'for one agent');
$rid = runid(51);
facts($rid, ASSISTANT, ['Activity MCP' => ['agent_activity' => []]]);
$mine = mcp_tool(ACT, rtoken(ASSISTANT, $rid), 'agent_activity', []);
ok(!$mine['error'] && array_unique(array_column($mine['data']['actions'], 'actor_member_id')) === [ASSISTANT], 'an agent sees its own actions only');
$rid = runid(52);
facts($rid, EXPERT, ['Activity MCP' => ['agent_activity' => []]]);
$mine = mcp_tool(ACT, rtoken(EXPERT, $rid), 'agent_activity', ['agent' => 'SMOKE Assistant']);
ok(!$mine['error'] && !in_array(ASSISTANT, array_column($mine['data']['actions'], 'actor_member_id'), true), 'and cannot ask for another agent\'s');
ok(!$A(ADMIN, 'agent_activity', [])['error'], 'the admin reads them');

echo "5. activity_timeline\n";
$tl = $A(PRIYA, 'activity_timeline', ['kind' => 'deal', 'record' => $w['deal1']])['data'];
$touches = array_sum(array_column($tl['rows'], 'touches'));
$direct = count(array_filter(sql_as(26, "SELECT item_kind FROM crm_timeline('deal', {$w['deal1']}, 500, NULL)"), fn ($r) => str_starts_with($r['item_kind'], 'activity.') && true));
ok($touches > 0 && $tl['rows'][0]['week'] >= $tl['rows'][count($tl['rows']) - 1]['week'] && array_sum(array_column($tl['rows'], 'calls')) >= 1 && array_sum(array_column($tl['rows'], 'emails_out')) >= 1, "by week, newest first: touches $touches, calls, emails out");
$wide = $A(PRIYA, 'activity_timeline', ['kind' => 'deal', 'record' => $w['deal1'], 'from' => date('Y-m-d', time() - 400 * 86400), 'to' => date('Y-m-d', time() + 30 * 86400)])['data'];
ok(array_sum(array_column($wide['rows'], 'touches')) === $direct, 'over a wide window the weeks add up to every activity of the record (' . $direct . ')');
$accWeeks = $A(PRIYA, 'activity_timeline', ['kind' => 'account', 'record' => 'SMOKE Acme Industries', 'from' => date('Y-m-d', time() - 400 * 86400), 'to' => date('Y-m-d', time() + 30 * 86400)])['data'];
$accDirect = count(array_filter(sql_as(26, "SELECT item_kind FROM crm_timeline('account', {$w['acme']}, 500, NULL)"), fn ($r) => str_starts_with($r['item_kind'], 'activity.')));
ok(array_sum(array_column($accWeeks['rows'], 'touches')) === $accDirect, 'an account\'s weeks add up to the activities crm_timeline() gives the account (' . $accDirect . ')');
ok($A(PRIYA, 'activity_timeline', ['kind' => 'deal', 'record' => $w['hid_deal']])['code'] === 'not_found' && $A(PRIYA, 'activity_timeline', ['kind' => 'lead', 'record' => 1])['code'] === 'invalid_argument' && $A(PRIYA, 'activity_timeline', ['kind' => 'deal', 'record' => 1, 'from' => '2000-01-01', 'to' => '2026-01-01'])['code'] === 'too_large', 'a hidden record is not_found; a lead is not a kind here; over 800 days is too_large');

echo "6. activity_search and the guarded SQL\n";
$s = $AR(PRIYA, 'activity_search', ['q' => 'Proposal']);
ok(count($s) === 1 && $s[0]['action'] === 'deal.move', 'a phrase found in the payload: "Proposal" → the move');
ok(count($AR(PRIYA, 'activity_search', ['q' => 'Done call'])) === 1 && count($AR(PRIYA, 'activity_search', ['q' => 'SMOKE Priya move'])) === 1, 'in a subject; in the actor\'s name AND an action word (each word must match)');
ok(count($AR(PRIYA, 'activity_search', ['q' => 'renewal'])) === 0 && count($AR(DANA, 'activity_search', ['q' => 'renewal'])) === 1, 'the hidden deal\'s update: not in Priya\'s search, in Dana\'s');
ok(count($AR(PRIYA, 'activity_search', ['q' => 'create', 'account' => 'SMOKE Acme Industries'])) >= 1 && $A(PRIYA, 'activity_search', ['q' => 'x'])['code'] === 'invalid_argument', 'by account; one letter refused');
$q = $A(PRIYA, 'activity_query', ['sql' => "SELECT a.action, m.display_name FROM mcp_activity_log a JOIN mcp_members m ON m.member_id = a.actor_member_id WHERE a.action = 'deal.move'"]);
ok(!$q['error'] && count($q['data']['rows']) === 1 && $q['data']['rows'][0]['display_name'] === 'SMOKE Priya', 'activity_query joins the log to the name views');

echo "7. Nothing a trail must not hold\n";
$everything = json_encode(sql_as(ADMIN, 'SELECT action, entity_type, after::text FROM mcp_activity_log'));
ok(!preg_match('/"(body|body_text|token|password|secret)"/', $everything) && !str_contains($everything, str_repeat('b', 40)) && !str_contains($everything, 'PRIVATE-BODY'), 'no body, token, password or key hash in any log row the view serves');
$swept = '';
foreach (sweep_activity($w) as [$t, $a]) { if ($t !== 'activity_query') { $swept .= $A(PRIYA, $t, $a)['text']; } }
foreach (['record_history', 'actor_history', 'recent_activity', 'activity_search'] as $t) { $swept .= $A(PRIYA, $t, ['record_history' => ['entity_type' => 'deal', 'entity_id' => $w['deal1']], 'actor_history' => ['member' => 'me'], 'recent_activity' => ['since' => '2020-01-01', 'exclude_mine' => false], 'activity_search' => ['q' => 'view']][$t])['text']; }
ok(!str_contains($swept, '"screen.view"') && !str_contains($swept, 'p4 Hidden'), 'and no tool but the raw SQL returns a screen view, and none returns the hidden account\'s trail to Priya');
finish();
