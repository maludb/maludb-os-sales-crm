<?php
/**
 * Proof — notices, texts and the bridge's notify (agents-k7-api.md "Who may do what", divergence 1): an agent's notification reaches a seller's bell and, by their preferences, the email outbox; a Rep sends only to themselves, a
 * manager to anyone; the morning note is once a day; the body is never logged; sms_send is refused `no_sender` (K28 is not built) and logged without words; the bridge's notify is an agent's door only and writes nothing under an evaluation run.
 */
require __DIR__ . '/lib.php';
$x = run_tag();
$since = last_activity_id();
pg_run("UPDATE notification_prefs SET email_enabled = true, text_enabled = false, kinds = '{reminder,assigned,lead_assigned,reply_received,deal_won,mention,proposal,morning_note,import_done,export_ready}' WHERE member_id IN (26, 27, 29, 30)");
pg_run("UPDATE notification_outbox SET status = 'skipped', detail = 'left by an earlier run' WHERE status = 'queued'");
pg_run("DELETE FROM notification_outbox WHERE dedupe_key LIKE 'morning:%'");       // today's morning notes of an earlier run on this scratch database
$d = deal_make(26, ['title' => "Notice deal $x"]);
$ag = static fn (string $path, array $f, int $agent = 42, ?int $run = null) => agent_post($agent, $run ?? random_int(1000, 99999), $path, $f);

echo "1. An agent's notice to a seller\n";
$r = $ag('/notify/send.php', ['member' => 26, 'kind' => 'morning_note', 'title' => "Good morning $x", 'body' => "BODY-$x: three things today\n\n- call Dana", 'deal' => $d]);
$nid = (int) ($r['json']['record_id'] ?? 0);
ok($r['code'] === 200 && $nid > 0, 'notification from the Assistant to Priya: ' . ($r['json']['did'] ?? $r['body']));
$n = q('SELECT * FROM notifications WHERE id = :i', ['i' => $nid])[0] ?? [];
ok((int) $n['member_id'] === 26 && $n['kind'] === 'morning_note' && $n['title'] === "Good morning $x" && str_contains($n['body'], "BODY-$x") && (int) $n['deal_id'] === $d && $n['record_type'] === 'deal' && (int) $n['record_id'] === $d && (int) $n['actor_member_id'] === 42 && $n['read_at'] === null, 'the bell row: Priya\'s, the kind, the title, the body, the deal, from the Assistant, unread');
$o = q("SELECT * FROM notification_outbox WHERE member_id = 26 AND kind = 'morning_note' AND subject = :s", ['s' => "Good morning $x"]);
ok(count($o) === 1 && $o[0]['channel'] === 'email' && $o[0]['status'] === 'queued' && $o[0]['to_email'] === 'priya@example.invalid' && str_contains($o[0]['body'], "BODY-$x") && $o[0]['dedupe_key'] === 'morning:26:' . gmdate('Y-m-d'), 'her preferences ask for email: one queued outbox row to her address, deduped per day');
$mine = get_as(26, '/notifications')['json']['data']['notifications'] ?? [];
$row = array_values(array_filter($mine, static fn ($m) => $m['notification_id'] === $nid))[0] ?? null;
ok($row !== null && $row['url'] === "/deals/$d" && $row['read'] === false, 'it is on her Notifications screen, pointing at the deal');
$lg = q("SELECT * FROM activity_log WHERE action = 'notification.send' AND id > :s", ['s' => $since]);
ok(count($lg) === 1 && $lg[0]['source'] === 'agent' && (int) $lg[0]['deal_id'] === $d && !str_contains($lg[0]['after'], "BODY-$x") && !str_contains($lg[0]['after'], 'Good morning') && json_decode($lg[0]['after'], true)['has_body'] === true, 'logged notification.send by the agent on the deal — no title, no body');
$r = $ag('/notify/send.php', ['member' => 26, 'kind' => 'morning_note', 'title' => 'Again', 'body' => 'x']);
ok($r['code'] === 422 && str_contains(message_of($r), 'already sent') && (int) one("SELECT count(*) FROM notifications WHERE member_id = 26 AND kind = 'morning_note' AND title = 'Again'") === 0, 'a second morning note the same day: 422 "' . message_of($r) . '"');
$r = $ag('/notify/send.php', ['member' => 26, 'kind' => 'proposal', 'title' => "Proposal notice $x"]);
ok($r['code'] === 200, 'other kinds are not once a day');
pg_run("UPDATE notification_prefs SET kinds = array_remove(kinds, 'mention') WHERE member_id = 30");
$r = $ag('/notify/send.php', ['member' => 30, 'kind' => 'mention', 'title' => "Quiet mention $x"]);
ok($r['code'] === 200 && (int) one("SELECT count(*) FROM notifications WHERE member_id = 30 AND title = :t", ['t' => "Quiet mention $x"]) === 1 && (int) one("SELECT count(*) FROM notification_outbox WHERE member_id = 30 AND subject = :t", ['t' => "Quiet mention $x"]) === 0, 'a kind the person turned off email for: the bell, no outbox row');
$r = $ag('/notify/send.php', ['member' => 26, 'kind' => 'digest', 'title' => "Digest $x"]);
ok($r['code'] === 200 && (int) one("SELECT count(*) FROM notification_outbox WHERE subject = :t", ['t' => "Digest $x"]) === 0, 'a digest is in the bell only (not among her emailed kinds)');

echo "2. Who may send to whom\n";
$r = $ag('/notify/send.php', ['member' => 29, 'kind' => 'proposal', 'title' => 'To a viewer']);
ok($r['code'] === 403 && str_contains(message_of($r), 'sellers and managers'), 'an agent to a Viewer: 403 "' . message_of($r) . '"');
ok($ag('/notify/send.php', ['member' => 40, 'kind' => 'proposal', 'title' => 'To an agent'])['code'] === 404 && $ag('/notify/send.php', ['member' => 99999, 'kind' => 'proposal', 'title' => 'To nobody'])['code'] === 404, 'an agent, or nobody, has no bell: 404');
ok(post_as(26, '/notify/send.php', ['member' => 30, 'kind' => 'mention', 'title' => 'Hi Dana'])['code'] === 403, 'a Rep to another person: 403');
$r = post_as(26, '/notify/send.php', ['member' => 26, 'kind' => 'mention', 'title' => "Note to self $x"]);
ok($r['code'] === 200, 'a Rep to themselves: ok');
ok(post_as(27, '/notify/send.php', ['member' => 29, 'kind' => 'mention', 'title' => "From the manager $x"])['code'] === 200 && post_as(27, '/notify/send.php', ['member' => 30, 'kind' => 'digest', 'title' => "Manager digest $x"])['code'] === 200, 'a manager to anyone, a Viewer included');
ok(post_as(29, '/notify/send.php', ['member' => 29, 'kind' => 'mention', 'title' => 'Viewer'])['code'] === 403, 'a Viewer (no activities.write) sends nothing: 403');

echo "3. Fields and links\n";
$f = ['member' => 26, 'kind' => 'mention'];
foreach ([['kind' => 'alarm', 'title' => 't', 'f' => 'kind'], ['title' => '', 'f' => 'title'], ['title' => str_repeat('t', 201), 'f' => 'title'], ['title' => 't', 'body' => str_repeat('b', 4001), 'f' => 'body']] as $c) {
    $fld = $c['f']; unset($c['f']);
    $r = post_as(27, '/notify/send.php', $c + $f);
    ok($r['code'] === 422 && field_of($r) === $fld, "$fld refused, the field named");
}
$r = post_as(27, '/notify/send.php', ['member' => '', 'kind' => 'mention', 'title' => 'No member']);
ok($r['code'] === 422 && field_of($r) === 'member', 'no member: 422');
$hid = deal_make(27, ['title' => "Hidden deal $x"]);
post_as(27, '/records/restrict.php', ['kind' => 'deal', 'record' => $hid, 'member' => 27]);
$r = $ag('/notify/send.php', ['member' => 26, 'kind' => 'proposal', 'title' => 'Points behind the wall', 'deal' => $hid]);
ok($r['code'] === 404 && (int) one("SELECT count(*) FROM notifications WHERE title = 'Points behind the wall'") === 0, 'a link to a deal the SENDER cannot see: 404, nothing sent');
$ct = contact_with("notice-$x@n.example.invalid", 'Nora');
$ac = act_id(26, ['kind' => 'task', 'subject' => "Notice task $x", 'deal' => $d, 'due_at' => at('+1 day 10:00')]);
$r = $ag('/notify/send.php', ['member' => 26, 'kind' => 'proposal', 'title' => "Links $x", 'contact' => $ct, 'activity' => $ac, 'deal' => $d]);
$ln = q("SELECT * FROM notifications WHERE title = :t", ['t' => "Links $x"])[0] ?? [];
ok($r['code'] === 200 && (int) $ln['activity_id'] === $ac && (int) $ln['deal_id'] === $d && (int) $ln['contact_id'] === $ct && $ln['record_type'] === 'activity' && (int) $ln['record_id'] === $ac, 'links to an activity, a deal and a contact: all kept, the most specific (the activity) is the record');
$reg = json_decode((string) file_get_contents(dirname(__DIR__, 3) . '/mcp/action_registry.json'), true)['actions'];
ok(!empty($reg['notification']['built']) && $reg['notification']['approval'] === null && $reg['notification']['agent'] === true && $reg['notification']['log_event'] === 'notification.send', 'the registry: notification is built, free for an agent, logs notification.send');

echo "4. sms_send: refused until K28\n";
$kc = contact_with("sms-$x@n.example.invalid", 'Sam', null, 26, ['mobile' => '+1 555 0142']);
$s0 = count(kernel_sms_log());
$r = post_as(26, '/sms/send.php', ['contact' => $kc, 'body' => "SMS-WORDS-$x see you at 10"]);
ok($r['code'] === 422 && str_contains(message_of($r), 'no_sender') && str_contains(message_of($r), 'Nothing was sent'), 'sms_send: refused in words with the code — "' . message_of($r) . '"');
ok(count(kernel_sms_log()) === $s0 && (int) one("SELECT count(*) FROM notification_outbox WHERE body LIKE :b", ['b' => "%SMS-WORDS-$x%"]) === 0, 'nothing reached the kernel and nothing was stored');
$lg = activity('sms.send', $since);
$la = json_decode($lg[0]['after'] ?? '{}', true);
ok(count($lg) === 1 && (int) $lg[0]['contact_id'] === $kc && $la['outcome'] === 'refused' && $la['code'] === 'no_sender' && !str_contains($lg[0]['after'], 'SMS-WORDS'), 'logged sms.send {outcome: refused, code: no_sender} on the contact, never the words');
pg_run("UPDATE contacts SET sms_opt_out = true WHERE id = $kc");
$r = post_as(26, '/sms/send.php', ['contact' => $kc, 'body' => 'hi']);
ok($r['code'] === 422 && str_contains(message_of($r), 'opted out'), 'an opted-out contact is refused by name first');
pg_run("UPDATE contacts SET sms_opt_out = false WHERE id = $kc");
ok(post_as(29, '/sms/send.php', ['contact' => $kc, 'body' => 'hi'])['code'] === 403, 'a Viewer: 403 (email.send)');
$hc = contact_with("smshid-$x@n.example.invalid", 'Hidden', null, 27);
post_as(27, '/records/restrict.php', ['kind' => 'contact', 'record' => $hc, 'member' => 27]);
ok(post_as(26, '/sms/send.php', ['contact' => $hc, 'body' => 'hi'])['code'] === 404, 'a contact behind the wall: 404');
$r = post_as(26, '/sms/send.php', ['contact' => $kc, 'body' => str_repeat('s', 301)]);
ok($r['code'] === 422 && field_of($r) === 'body', 'over 300 characters: 422');
ok(post_as(26, '/sms/send.php', ['contact' => $kc, 'body' => ''])['code'] === 422, 'no words: 422');
ok(!empty($reg['sms_send']['built']) && $reg['sms_send']['approval'] === 'external_send' && $reg['sms_send']['confirm'] === true, 'registry: sms_send is built, confirmed and PAUSES for an agent as external_send');
$tok = run_token(40, 4242);
$r = req('POST', '/sms/send.php', ['headers' => as_agent($tok), 'form' => ['contact' => $kc, 'body' => 'from an agent']]);
ok($r['code'] === 422 && count(kernel_sms_log()) === $s0, '(the pause is the kernel\'s, before the call; if it is approved the handler still refuses no_sender)');

echo "5. The bridge's notify: an agent's door\n";
$call = static fn (array $caller, array $args, array $extra = []) => bridge('notify', ['caller' => $caller, 'door' => 'agent', 'run_id' => 5151, 'args' => $args] + $extra);
$ok = $call(['kind' => 'member', 'member_id' => 42], ['member' => 27, 'kind' => 'proposal', 'title' => "Bridge notice $x", 'body' => "BRIDGE-BODY-$x", 'deal' => $d]);
ok($ok['code'] === 200 && ($ok['json']['ok'] ?? false) === true && (int) ($ok['json']['notification_id'] ?? 0) > 0, 'notify through the bridge as the Assistant: ' . $ok['body']);
$bl = q("SELECT * FROM activity_log WHERE action = 'notification.send' AND entity_id = :e", ['e' => $ok['json']['notification_id']])[0] ?? [];
ok(($bl['source'] ?? '') === 'agent' && (int) $bl['actor_member_id'] === 42 && (int) $bl['agent_run_id'] === 5151 && !str_contains($bl['after'], 'BRIDGE-BODY'), 'logged by the agent with the run id, no body');
ok($call(['kind' => 'member', 'member_id' => 26], ['member' => 26, 'kind' => 'mention', 'title' => 'Human via bridge'])['code'] === 403, 'a person\'s identity through the bridge is refused: the action is theirs (agents_only)');
ok($call(['kind' => 'member', 'member_id' => 42], ['member' => 29, 'kind' => 'proposal', 'title' => 'To a viewer'])['code'] === 403, 'the same recipient rules: an agent to a Viewer, 403');
ok($call(['kind' => 'member', 'member_id' => 42], ['member' => 26, 'kind' => 'proposal', 'title' => 'Eval'], ['is_eval' => true])['code'] === 403 && (int) one("SELECT count(*) FROM notifications WHERE title = 'Eval'") === 0, 'under an evaluation run: 403 and nothing written');
ok($call(['kind' => 'member', 'member_id' => 41], ['member' => 26, 'kind' => 'proposal', 'title' => 'Unadmitted'])['code'] === 403, 'an agent not admitted here: 403');
ok($call(['kind' => 'member', 'member_id' => 42], ['member' => 26, 'kind' => 'proposal', 'title' => 'Hidden link', 'deal' => $hid])['code'] === 404, 'a link behind the wall: 404');
ok($call(['kind' => 'member', 'member_id' => 42], ['member' => 26, 'kind' => 'bogus', 'title' => 't'])['code'] === 422 && $call(['kind' => 'member', 'member_id' => 42], ['member' => 26, 'kind' => 'proposal', 'title' => ''])['code'] === 422, 'a bad kind or an empty title: 422');
$r = $call(['kind' => 'member', 'member_id' => 42], ['member' => 26, 'kind' => 'morning_note', 'title' => 'Bridge morning']);
ok($r['code'] === 422 && str_contains($r['json']['error']['message'] ?? '', 'already sent'), 'the morning note is once a day through the bridge too');
ok($call(['kind' => 'kernel'], ['member' => 26, 'kind' => 'proposal', 'title' => 't'])['code'] === 403, 'no member as caller: 403');
$all = log_text_since($since);
ok(!str_contains($all, "BODY-$x") && !str_contains($all, 'SMS-WORDS') && !str_contains($all, "BRIDGE-BODY-$x"), 'no body, no text in the log');
finish();
