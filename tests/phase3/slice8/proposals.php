<?php
/**
 * Proof — proposals (agents-k7-api.md "Proof"): a next step proposed by the Assistant is accepted in one click by the person it is for and becomes THEIR activity, the deal has a step; a qualification sets the score; a duplicate sends
 * a manager to the pair; a dismissed pair is not proposed again by the worker; who may propose and who may decide; the screen's cards, filters and words; the worker's standing proposals (duplicates, rotting).
 */
require __DIR__ . '/lib.php';
$x = run_tag();
$since = last_activity_id();
pg_run("UPDATE crm_settings SET assistant_proposals = true, rotting_days = NULL");
$run = 970;
$prop = static fn (string $path, array $f, int $agent = 42) => agent_post($agent, ++$GLOBALS['run_n'], $path, $f);
$GLOBALS['run_n'] = 971;

echo "1. The Assistant proposes a next step\n";
$d = deal_make(26, ['title' => "Proposal deal $x"]);
$dnum = one('SELECT number FROM deals WHERE id = :i', ['i' => $d]);
$when = at('+1 day 10:00');
$r = $prop('/proposals/propose.php', ['deal' => $d, 'kind' => 'call', 'subject' => "Call about pricing $x", 'at' => $when, 'body' => "SAY-THIS-$x: lead with the discount"]);
$pid = (int) ($r['json']['record_id'] ?? 0);
ok($r['code'] === 200 && ($r['json']['ok'] ?? false) === true && $pid > 0, 'next_step_propose under the Assistant\'s run token: a proposal id — ' . ($r['json']['did'] ?? $r['body']));
$p = proposal_row($pid);
$pay = json_decode($p['payload'], true);
ok($p['kind'] === 'next_step' && (int) $p['deal_id'] === $d && (int) $p['for_member_id'] === 26 && (int) $p['proposed_by'] === 42 && $p['status'] === 'proposed' && str_contains($p['title'], $dnum) && $pay['kind'] === 'call' && $pay['subject'] === "Call about pricing $x"
    && gmdate('Y-m-d H:i', strtotime($pay['at'])) === str_replace('T', ' ', $when), 'for the deal\'s owner Priya, from the Assistant, waiting; the payload holds kind, subject and the moment in her zone (UTC here)');
$lg = q("SELECT * FROM activity_log WHERE action = 'proposal.next_step' AND id > :s", ['s' => $since]);
ok(count($lg) === 1 && $lg[0]['source'] === 'agent' && (int) $lg[0]['actor_member_id'] === 42 && (int) $lg[0]['deal_id'] === $d && !str_contains($lg[0]['after'], 'SAY-THIS') && (int) json_decode($lg[0]['after'], true)['for_member_id'] === 26, 'logged proposal.next_step by the agent on the deal — without what to say');
$r = $prop('/proposals/propose.php', ['deal' => $d, 'kind' => 'email', 'subject' => 'Again', 'at' => $when]);
ok($r['code'] === 422 && str_contains(message_of($r), 'already'), 'a second step while one waits: 422 "' . message_of($r) . '"');
$d2 = deal_make(26, ['title' => "Has a step $x"]);
next_step($d2, 26, '+2 days');
$r = $prop('/proposals/propose.php', ['deal' => $d2, 'kind' => 'call', 'subject' => 'x', 'at' => $when]);
ok($r['code'] === 422 && str_contains(message_of($r), 'has a next step'), 'a deal that has a next step already: 422');
$dc = deal_make(26, ['title' => "Closed $x"]);
post_as(26, '/deals/won.php', ['deal' => $dc, 'close_date' => date('Y-m-d', strtotime('-1 day'))]);
ok($prop('/proposals/propose.php', ['deal' => $dc, 'kind' => 'call', 'subject' => 'x', 'at' => $when])['code'] === 422, 'a closed deal: 422');
$d3 = deal_make(26, ['title' => "Fields $x"]);
foreach ([['kind' => 'visit', 'subject' => 's', 'at' => $when, 'f' => 'kind'], ['kind' => 'call', 'subject' => '', 'at' => $when, 'f' => 'subject'], ['kind' => 'call', 'subject' => 's', 'at' => 'whenever', 'f' => 'at'], ['kind' => 'call', 'subject' => 's', 'f' => 'at'],
          ['kind' => 'call', 'subject' => str_repeat('s', 201), 'at' => $when, 'f' => 'subject'], ['kind' => 'call', 'subject' => 's', 'at' => $when, 'body' => str_repeat('b', 2001), 'f' => 'body'], ['kind' => 'call', 'subject' => 's', 'at' => $when, 'for' => 41, 'f' => 'for']] as $c) {
    $f = $c['f']; unset($c['f']);
    $r = $prop('/proposals/propose.php', ['deal' => $d3] + $c);
    ok($r['code'] === 422 && field_of($r) === $f, "next_step_propose: $f refused with the field named");
}
ok((int) one("SELECT count(*) FROM assistant_proposals WHERE deal_id = :d", ['d' => $d3]) === 0, '…and nothing made');
$hidden = deal_make(27, ['title' => "Marco only $x"]);
post_as(27, '/records/restrict.php', ['kind' => 'deal', 'record' => $hidden, 'member' => 27]);
ok($prop('/proposals/propose.php', ['deal' => $hidden, 'kind' => 'call', 'subject' => 's', 'at' => $when])['code'] === 404, 'a deal behind the wall: 404 (the Assistant is a Rep)');
ok(post_as(29, '/proposals/propose.php', ['deal' => $d3, 'kind' => 'call', 'subject' => 's', 'at' => $when])['code'] === 403, 'a Viewer may not propose: 403');
$orphan = (int) post_as(1, '/deals/save.php', ['title' => "Ownerless $x", 'account' => $d3 ? (int) one('SELECT account_id FROM deals WHERE id = :i', ['i' => $d3]) : 0, 'owner' => ''])['json']['record_id'];
pg_run("UPDATE deals SET owner_member_id = NULL WHERE id = $orphan");
$r = $prop('/proposals/propose.php', ['deal' => $orphan, 'kind' => 'call', 'subject' => 's', 'at' => $when]);
ok($r['code'] === 422 && field_of($r) === 'for', 'a deal with no owner and no `for`: 422 naming `for`');
$r = $prop('/proposals/propose.php', ['deal' => $orphan, 'kind' => 'call', 'subject' => 's', 'at' => $when, 'for' => 27]);
ok($r['code'] === 200 && (int) proposal_row((int) $r['json']['record_id'])['for_member_id'] === 27, '…with `for` named, it is made for that person');
pg_run("UPDATE crm_settings SET assistant_proposals = false");
$r = $prop('/proposals/propose.php', ['deal' => $d3, 'kind' => 'call', 'subject' => 's', 'at' => $when]);
ok($r['code'] === 422 && str_contains(message_of($r), 'switched off'), 'with the Assistant\'s proposals off in the settings: 422 "' . message_of($r) . '"');
pg_run("UPDATE crm_settings SET assistant_proposals = true");

echo "2. The screen\n";
$mine = proposals_of(26);
$card = array_values(array_filter($mine['proposals'], static fn ($c) => $c['proposal_id'] === $pid))[0] ?? null;
ok($card !== null && $card['kind'] === 'next_step' && $card['kind_label'] === 'A next step' && $card['status'] === 'proposed' && $card['status_label'] === 'Waiting' && $card['for_name'] === 'SMOKE Priya' && $card['proposed_by_name'] === 'SMOKE Assistant'
    && $card['record']['url'] === "/deals/$d" && str_contains($card['summary'][0], 'Call') && str_contains($card['summary'][0], "Call about pricing $x") && $mine['waiting'] >= 1, 'Priya\'s Proposals (JSON): the card with the kind in words, Waiting, whose, from whom, the deal, the step\'s kind, moment and subject');
ok(!array_filter($mine['proposals'], static fn ($c) => $c['for_member_id'] !== 26), 'only proposals for her');
ok(!in_array($pid, array_column(proposals_of(30)['proposals'], 'proposal_id'), true) && !in_array($pid, array_column(proposals_of(27)['proposals'], 'proposal_id'), true), 'Dana, and Marco by default, do not see Priya\'s ("for me")');
$all = proposals_of(27, '?who=all');
ok(in_array($pid, array_column($all['proposals'], 'proposal_id'), true), 'the manager\'s who=all shows everyone\'s');
ok(in_array($pid, array_column(proposals_of(27, '?who=26')['proposals'], 'proposal_id'), true) && !in_array($pid, array_column(proposals_of(27, '?who=30')['proposals'], 'proposal_id'), true), '…and who=<member id> one person\'s');
ok(in_array($pid, array_column(proposals_of(26, '?who=all')['proposals'], 'proposal_id'), true) && proposals_of(26, '?who=all')['filters']['who'] === 'me', 'a Rep\'s who=all is ignored: it stays "me"');
ok(array_column(proposals_of(26, '?kind=lead_qualify')['proposals'], 'kind') === [] || array_unique(array_column(proposals_of(26, '?kind=lead_qualify')['proposals'], 'kind')) === ['lead_qualify'], 'the kind filter');
$h = html_as(26, '/proposals')['body'];
ok(str_contains($h, 'id="proposals-list"') && str_contains($h, 'id="proposal-' . $pid . '"') && str_contains($h, 'id="proposal-' . $pid . '-accept"') && str_contains($h, 'id="proposal-' . $pid . '-dismiss"') && str_contains($h, 'Accept') && str_contains($h, "Call about pricing $x"), 'the HTML: the list, the card, Accept and Dismiss, the words');
ok(str_contains($h, 'badge bg-soft-warning') && str_contains($h, "SAY-THIS-$x"), 'Waiting is a warning badge; the Assistant\'s words for the step are on the card (escaped)');
ok(str_contains(html_as(29, '/proposals')['body'], 'Nothing is waiting for you.'), 'a Viewer with none sees the empty state in words');
ok(get_as(26, '/proposals?status=accepted')['json']['data']['filters']['status'] === 'accepted', 'status filter accepted');

echo "3. Accept: one click, THEIR activity\n";
ok(post_as(30, '/proposals/accept.php', ['proposal' => $pid])['code'] === 404, 'Dana cannot see it, so cannot take it: 404');
$bea = post_as(28, '/proposals/accept.php', ['proposal' => $pid]);
ok($bea['code'] === 404, 'a member with no role here: 404');
$r = post_as(26, '/proposals/accept.php', ['proposal' => $pid]);
$aid = (int) ($r['json']['result_activity_id'] ?? 0);
ok($r['code'] === 200 && $aid > 0 && (int) $r['json']['record_id'] === $pid, 'Priya accepts: ok, the proposal id, and the activity it made');
$act = act_row($aid);
ok((int) $act['owner_member_id'] === 26 && $act['kind'] === 'call' && $act['subject'] === "Call about pricing $x" && (int) $act['deal_id'] === $d && $act['source'] === 'proposal' && (int) $act['proposal_id'] === $pid
    && gmdate('Y-m-d H:i', strtotime($act['starts_at'])) === str_replace('T', ' ', $when), 'the activity is hers: a call on the deal at the proposed moment, source proposal, linked back');
$p = proposal_row($pid);
ok($p['status'] === 'accepted' && (int) $p['decided_by'] === 26 && (int) $p['result_activity_id'] === $aid && $p['decided_at'] !== null, 'the proposal is accepted, decided by Priya, pointing at the activity');
ok(one('SELECT next_activity_at FROM deals WHERE id = :i', ['i' => $d]) !== null, 'the deal has a next step now');
$al = activity('proposal.accept', $since);
ok(count($al) === 1 && (int) $al[0]['deal_id'] === $d && json_decode($al[0]['after'], true)['result_activity_id'] === $aid && $al[0]['source'] === 'web', 'logged proposal.accept on the deal with the activity id');
$r = post_as(26, '/proposals/accept.php', ['proposal' => $pid]);
ok($r['code'] === 422 && str_contains(message_of($r), 'decided already'), 'accepted twice: 422 "' . message_of($r) . '"');
ok(post_as(26, '/proposals/dismiss.php', ['proposal' => $pid])['code'] === 422, 'dismissing an accepted one: 422');
$ph = html_as(26, '/proposals?status=accepted')['body'];
ok(str_contains($ph, 'id="proposal-' . $pid . '-activity"') && str_contains($ph, 'Accepted'), 'the accepted card links to the activity it made');
// a manager may take someone else's
$dm = deal_make(26, ['title' => "For the manager $x"]);
$rm = $prop('/proposals/propose.php', ['deal' => $dm, 'kind' => 'task', 'subject' => "Manager takes it $x", 'at' => $when]);
$pm = (int) $rm['json']['record_id'];
$r = post_as(27, '/proposals/accept.php', ['proposal' => $pm]);
$am = act_row((int) ($r['json']['result_activity_id'] ?? 0));
ok($r['code'] === 200 && (int) $am['owner_member_id'] === 27, 'a manager may take another person\'s proposal — the activity is the DECIDER\'s (Marco)');

echo "4. Dismiss\n";
$d4 = deal_make(26, ['title' => "To dismiss $x"]);
$pd = (int) $prop('/proposals/propose.php', ['deal' => $d4, 'kind' => 'email', 'subject' => 'Dismiss me', 'at' => $when])['json']['record_id'];
ok(post_as(30, '/proposals/dismiss.php', ['proposal' => $pd])['code'] === 404, 'another Rep cannot dismiss it: 404');
$r = post_as(26, '/proposals/dismiss.php', ['proposal' => $pd]);
$p = proposal_row($pd);
ok($r['code'] === 200 && $p['status'] === 'dismissed' && (int) $p['decided_by'] === 26 && count(activity('proposal.dismiss', $since)) === 1, 'Priya dismisses: dismissed, decided by her, logged proposal.dismiss');
ok(post_as(26, '/proposals/accept.php', ['proposal' => $pd])['code'] === 422 && (int) one('SELECT count(*) FROM activities WHERE proposal_id = :p', ['p' => $pd]) === 0, 'a dismissed proposal cannot be accepted, and made no activity');
$again = $prop('/proposals/propose.php', ['deal' => $d4, 'kind' => 'email', 'subject' => 'Dismiss me again', 'at' => $when]);
ok($again['code'] === 200, 'a new proposal for the deal is allowed once the old one is decided');
ok(str_contains(html_as(26, '/proposals?status=dismissed')['body'], 'bg-soft-dark'), 'Dismissed is a dark badge');
ok(post_as(26, '/proposals/accept.php', ['proposal' => 99999999])['code'] === 404, 'a proposal that is not there: 404');

echo "5. A qualification\n";
$lead = lead_make(26, ['company' => "Qualify $x"]);
$r = $prop('/proposals/propose-lead.php', ['lead' => $lead, 'score' => 72, 'reasons' => ['Company domain matches a customer', 'Came from the pricing page', 'Asked for a demo'], 'owner' => 27]);
$ql = (int) ($r['json']['record_id'] ?? 0);
$p = proposal_row($ql);
ok($r['code'] === 200 && $p['kind'] === 'lead_qualify' && (int) $p['lead_id'] === $lead && (int) $p['for_member_id'] === 26 && json_decode($p['payload'], true)['score'] === 72 && count(json_decode($p['payload'], true)['reasons']) === 3, 'lead_qualify_propose: for the lead\'s owner, the score and the reasons in the payload');
$card = array_values(array_filter(proposals_of(26, '?kind=lead_qualify')['proposals'], static fn ($c) => $c['proposal_id'] === $ql))[0];
ok(str_contains(implode(' ', $card['summary']), 'Score 72 of 100') && str_contains(implode(' ', $card['summary']), 'Asked for a demo') && str_contains(implode(' ', $card['summary']), 'Suggested owner: SMOKE Marco') && $card['detail']['score'] === 72, 'the card shows the score, the reasons and the suggested owner');
foreach ([['score' => 101], ['score' => -1], ['score' => 'high'], []] as $bad) {
    $r = $prop('/proposals/propose-lead.php', ['lead' => $lead] + $bad);
    ok($r['code'] === 422 && field_of($r) === 'score', 'score ' . json_encode($bad) . ': 422 naming score');
}
ok($prop('/proposals/propose-lead.php', ['lead' => $lead, 'score' => 50, 'reasons' => array_map(static fn ($i) => "reason $i", range(1, 9))])['code'] === 422 && $prop('/proposals/propose-lead.php', ['lead' => $lead, 'score' => 50, 'reasons' => [str_repeat('r', 201)]])['code'] === 422, 'more than 8 reasons, or one over 200 characters: 422');
ok($prop('/proposals/propose-lead.php', ['lead' => $lead, 'score' => 50, 'owner' => 41])['code'] === 422, 'a suggested owner who is not here: 422');
$r = post_as(26, '/proposals/accept.php', ['proposal' => $ql]);
$lr = q('SELECT score, status, owner_member_id FROM leads WHERE id = :i', ['i' => $lead])[0];
ok($r['code'] === 200 && (int) $lr['score'] === 72 && $lr['status'] === 'working' && (int) $lr['owner_member_id'] === 26 && ($r['json']['result_activity_id'] ?? null) === null, 'accepting sets the lead\'s score (72) and moves it to working; the suggested owner is shown, never applied');
$conv = lead_make(26, ['company' => "Converted $x"]);
post_as(26, '/leads/convert.php', ['lead' => $conv, 'make_deal' => 'no']);
ok($prop('/proposals/propose-lead.php', ['lead' => $conv, 'score' => 50])['code'] === 422, 'a converted lead needs no qualification: 422');
$free = (int) post_as(1, '/leads/save.php', ['company' => "Unowned $x", 'email' => "unowned-$x@lead.example.invalid"])['json']['record_id'];
pg_run("UPDATE leads SET owner_member_id = NULL WHERE id = $free");
$rf = $prop('/proposals/propose-lead.php', ['lead' => $free, 'score' => 40]);
$forF = (int) proposal_row((int) ($rf['json']['record_id'] ?? 0))['for_member_id'];
ok($rf['code'] === 200 && (bool) one("SELECT crm_member_roles(:m) && ARRAY['manager', 'admin']", ['m' => $forF]), 'a lead with no owner: the proposal is for a manager');
ok(post_as(30, '/proposals/accept.php', ['proposal' => (int) $rf['json']['record_id']])['code'] === 404, '…and a Rep who may not see it cannot take it');

echo "6. A possible duplicate\n";
$a = contact_with("dup-a-$x@dup.example.invalid", 'Dora', null, 26, ['mobile' => '+1 555 777 ' . random_int(1000, 9999)]);
$b = contact_with("dup-b-$x@dup.example.invalid", 'Dora', null, 26);
$r = $prop('/proposals/propose-duplicate.php', ['kind' => 'contact', 'a' => $b, 'b' => $a, 'matched_on' => 'name']);
$dp = (int) ($r['json']['record_id'] ?? 0);
$p = proposal_row($dp);
$pp = json_decode($p['payload'], true);
ok($r['code'] === 200 && $p['kind'] === 'duplicate' && (int) $p['contact_id'] === min($a, $b) && $pp['other_id'] === max($a, $b) && $pp['kind'] === 'contact' && $pp['matched_on'] === 'name' && (int) $p['for_member_id'] === 27, 'duplicate_propose: the pair stored lowest first, for the manager of Priya\'s department (Marco)');
$card = array_values(array_filter(proposals_of(27, '?who=all&kind=duplicate')['proposals'], static fn ($c) => $c['proposal_id'] === $dp))[0] ?? null;
ok($card !== null && count($card['detail']['sides']) === 2 && $card['detail']['sides'][0]['url'] === '/contacts/' . min($a, $b) && str_contains(implode(' ', $card['summary']), 'Matched on name'), 'the card has both sides as links and what matched');
$h = html_as(27, '/proposals?who=all&kind=duplicate')['body'];
ok(str_contains($h, 'id="proposal-' . $dp . '-sides"') && str_contains($h, 'Review the pair'), 'the HTML: both sides, and the button reads "Review the pair"');
ok($prop('/proposals/propose-duplicate.php', ['kind' => 'contact', 'a' => $a, 'b' => $b, 'matched_on' => 'phone'])['code'] === 422, 'the same pair again (any order): 422');
ok($prop('/proposals/propose-duplicate.php', ['kind' => 'contact', 'a' => $a, 'b' => $a, 'matched_on' => 'name'])['code'] === 422 && $prop('/proposals/propose-duplicate.php', ['kind' => 'nope', 'a' => $a, 'b' => $b, 'matched_on' => 'name'])['code'] === 422 && $prop('/proposals/propose-duplicate.php', ['kind' => 'contact', 'a' => $a, 'b' => $b, 'matched_on' => 'vibes'])['code'] === 422, 'a record against itself, a bad kind, a bad match: 422');
$c = contact_with("dup-c-$x@dup.example.invalid", 'Cara', null, 26);
$r = $prop('/proposals/propose-duplicate.php', ['kind' => 'contact', 'a' => $a, 'b' => $c, 'matched_on' => 'name', 'for' => 26]);
ok($r['code'] === 422 && str_contains(message_of($r), 'manager'), 'a pair sent to someone who is not a manager: 422');
pg_run("INSERT INTO duplicate_dismissals (record_kind, a_id, b_id) VALUES ('contact', " . min($a, $c) . ', ' . max($a, $c) . ')');
ok($prop('/proposals/propose-duplicate.php', ['kind' => 'contact', 'a' => $c, 'b' => $a, 'matched_on' => 'name'])['code'] === 422, 'a pair a person said is not a duplicate: 422');
$hid = contact_with("dup-h-$x@dup.example.invalid", 'Hidden', null, 26);
post_as(27, '/records/restrict.php', ['kind' => 'contact', 'record' => $hid, 'member' => 27]);
ok($prop('/proposals/propose-duplicate.php', ['kind' => 'contact', 'a' => $a, 'b' => $hid, 'matched_on' => 'name'])['code'] === 404, 'a side the proposer cannot see: 404');
$r = post_as(27, '/proposals/accept.php', ['proposal' => $dp]);
ok($r['code'] === 200 && proposal_row($dp)['status'] === 'accepted' && str_contains((string) ($r['json']['location'] ?? ''), '/duplicates?kind=contact') && (int) one('SELECT count(*) FROM contacts WHERE id IN (' . $a . ', ' . $b . ') AND deleted_at IS NULL') === 2, 'accepting a duplicate marks it and sends the manager to the pair\'s page; nothing is merged');

echo "7. The worker's standing proposals\n";
$mk = static fn (string $first, string $last, string $tag, string $mobile): int => (int) post_as(26, '/contacts/save.php', ['first_name' => $first, 'last_name' => $last, 'email' => "$tag-$x@wk.example.invalid", 'mobile' => $mobile])['json']['record_id'];
$wa = $mk('Alvin', 'Quillfeather', 'wk-a', '+1 555 ' . ($ph7 = (string) random_int(1000000, 9999999)));
$wb = $mk('Zelda', 'Brambletoe', 'wk-b', '+1 555 ' . $ph7);
$rep = pass('proposals');
ok(($rep['duplicates'] ?? 0) >= 1 && $rep['proposed'] >= 1, 'the proposals pass proposes the duplicates it finds: ' . json_encode($rep));
$pair = q("SELECT * FROM assistant_proposals WHERE kind = 'duplicate' AND contact_id = :a AND (payload->>'other_id')::bigint = :b", ['a' => min($wa, $wb), 'b' => max($wa, $wb)]);
ok(count($pair) === 1 && (int) $pair[0]['for_member_id'] === 27 && json_decode($pair[0]['payload'], true)['matched_on'] === 'phone' && (int) $pair[0]['proposed_by'] === 42, 'one proposal for the pair, for Marco (the manager of Priya\'s department), matched on phone, from the Assistant');
$al = q("SELECT source, actor_member_id FROM activity_log WHERE action = 'proposal.duplicate' AND entity_id = :e", ['e' => $pair[0]['id']])[0] ?? [];
ok(($al['source'] ?? '') === 'cron', 'logged proposal.duplicate by the worker');
$rep2 = pass('proposals');
ok((int) one("SELECT count(*) FROM assistant_proposals WHERE kind = 'duplicate' AND contact_id = :a AND (payload->>'other_id')::bigint = :b", ['a' => min($wa, $wb), 'b' => max($wa, $wb)]) === 1, 'a second pass makes none again');
post_as(27, '/proposals/dismiss.php', ['proposal' => (int) $pair[0]['id']]);
pass('proposals');
ok((int) one("SELECT count(*) FROM assistant_proposals WHERE kind = 'duplicate' AND contact_id = :a AND (payload->>'other_id')::bigint = :b", ['a' => min($wa, $wb), 'b' => max($wa, $wb)]) === 1, 'the manager dismissed it: the pair is NOT proposed again');
$wc = $mk('Orson', 'Pennywhistle', 'wk-c', '+1 555 ' . ($ph8 = (string) random_int(1000000, 9999999)));
$wd = $mk('Mabel', 'Thistledown', 'wk-d', '+1 555 ' . $ph8);
pg_run("INSERT INTO duplicate_dismissals (record_kind, a_id, b_id) VALUES ('contact', " . min($wc, $wd) . ', ' . max($wc, $wd) . ')');
pass('proposals');
ok((int) one("SELECT count(*) FROM assistant_proposals WHERE kind = 'duplicate' AND contact_id = :a AND (payload->>'other_id')::bigint = :b", ['a' => min($wc, $wd), 'b' => max($wc, $wd)]) === 0, 'a pair marked "not a duplicate" is not proposed either');
$wk = $mk('Ignatius', 'Fogbottom', 'wk-e', '+1 555 ' . ($ph9 = (string) random_int(1000000, 9999999)));
$wl = $mk('Philippa', 'Gumboots', 'wk-f', '+1 555 ' . $ph9);
post_as(27, '/records/restrict.php', ['kind' => 'contact', 'record' => $wl, 'member' => 27]);
pass('proposals');
$pr = q("SELECT for_member_id FROM assistant_proposals WHERE kind = 'duplicate' AND contact_id = :a", ['a' => min($wk, $wl)]);
ok(count($pr) === 1 && (int) $pr[0]['for_member_id'] === 27, 'a pair with a restricted side goes to a manager (who sees everything): Marco');
// rotting
$rd = deal_make(26, ['title' => "Rotting $x"]);
$stage1 = (int) one('SELECT stage_id FROM deals WHERE id = :i', ['i' => $rd]);
pg_run("UPDATE crm_settings SET rotting_days = 5");
pg_run("UPDATE deals SET stage_entered_at = now() - interval '12 days' WHERE id = $rd");
$rep = pass('proposals');
$rot = q("SELECT * FROM assistant_proposals WHERE kind = 'rotting' AND deal_id = :d", ['d' => $rd]);
$rp = json_decode($rot[0]['payload'] ?? '{}', true);
ok(count($rot) === 1 && (int) $rot[0]['for_member_id'] === 26 && $rp['stage_id'] === $stage1 && $rp['days'] >= 11 && str_contains($rot[0]['title'], 'days'), 'a deal newly rotting: one `rotting` proposal for its owner, naming the stage and the days');
pass('proposals');
ok((int) one("SELECT count(*) FROM assistant_proposals WHERE kind = 'rotting' AND deal_id = :d", ['d' => $rd]) === 1, 'once per deal per stage: the next pass makes none');
post_as(26, '/proposals/dismiss.php', ['proposal' => (int) $rot[0]['id']]);
pass('proposals');
ok((int) one("SELECT count(*) FROM assistant_proposals WHERE kind = 'rotting' AND deal_id = :d", ['d' => $rd]) === 1, '…not even after it was dismissed');
$stage2 = stage('Proposal');
post_as(26, '/deals/move.php', ['deal' => $rd, 'to_stage' => $stage2]);
pg_run("UPDATE deals SET stage_entered_at = now() - interval '12 days' WHERE id = $rd");
pass('proposals');
ok((int) one("SELECT count(*) FROM assistant_proposals WHERE kind = 'rotting' AND deal_id = :d", ['d' => $rd]) === 2, 'rotting in a NEW stage is a new proposal');
$h = html_as(26, '/proposals?kind=rotting&status=all')['body'];
ok(str_contains($h, 'has sat in') && str_contains($h, 'bg-soft-dark') , 'the rotting card says how long, in words');
pg_run("UPDATE crm_settings SET rotting_days = NULL, assistant_proposals = false");
$rd2 = deal_make(26, ['title' => "Rotting off $x"]);
pg_run("UPDATE crm_settings SET rotting_days = 5");
pg_run("UPDATE deals SET stage_entered_at = now() - interval '12 days' WHERE id = $rd2");
$rep = pass('proposals');
ok(($rep['off'] ?? false) === true && $rep['proposed'] === 0 && (int) one("SELECT count(*) FROM assistant_proposals WHERE deal_id = :d", ['d' => $rd2]) === 0, 'with the Assistant\'s proposals switched off the pass proposes nothing');
pg_run("UPDATE crm_settings SET rotting_days = NULL, assistant_proposals = true");

echo "8. Nothing sent, nothing leaked\n";
$all = log_text_since($since);
ok(!str_contains($all, 'SAY-THIS'), 'the Assistant\'s suggested words are in no log row');
ok(count(array_filter(sends(), static fn ($m) => str_contains(json_encode($m), 'SAY-THIS'))) === 0, 'a proposal sends no email');
finish();
