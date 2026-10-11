<?php
/**
 * Proof — dispatches and the agents' screen (agents-k7-api.md "The worker", "Screens"; binding points 6 and 7): the dispatches pass sends ONE chat turn on the kernel's chat endpoint as the acting person for a lead to qualify, a deal that
 * entered an ask-the-Assistant stage and a reminder that is an agent's; keeps the first 200 characters of the reply; three attempts; a refusal is final; a run still going is asked after; a paused run waits for approval; one nobody can act for is
 * refused; /admin/agents is people-only and shows the agents, the dispatches (Cancel) and the settings.
 */
require __DIR__ . '/lib.php';
$x = run_tag();
$since = last_activity_id();
$t0 = time() + 5;
$reply = "REPLY-$x " . str_repeat('word ', 80);
$chat = static function (?array $set = null): void {
    kernel_state(static function (array $s) use ($set): array {
        unset($s['chat_status'], $s['chat_message'], $s['chat_mode'], $s['chat_runs']);
        $s['chat'] = ['run_id' => 321, 'status' => 'succeeded', 'finished' => true, 'reply' => $GLOBALS['reply'], 'actions' => []];
        foreach ($set ?? [] as $k => $v) { $s[$k] = $v; }
        return $s;
    });
};
$GLOBALS['reply'] = $reply;
$chat();
pg_run("UPDATE agent_dispatches SET status = 'refused', detail = 'left by an earlier run' WHERE status IN ('sent', 'awaiting_approval')");
pg_run("UPDATE crm_settings SET assistant_proposals = true");
$assistant = 42;
$dsp = static fn (int $id): array => q('SELECT * FROM agent_dispatches WHERE id = :i', ['i' => $id])[0];
$make = static fn (string $kind, array $f = []): int => (int) one("INSERT INTO agent_dispatches (record_type, record_id, agent_member_id, kind, via, acting_member_id, deal_id, lead_id, status, detail) VALUES (:rt, :rid, 42, :k, 'chat', :w, :d, :l, 'sent', 'test') RETURNING id",
    ['rt' => $f['rt'] ?? null, 'rid' => $f['rid'] ?? null, 'k' => $kind, 'w' => $f['acting'] ?? null, 'd' => $f['deal'] ?? null, 'l' => $f['lead'] ?? null]);

echo "1. A lead to qualify\n";
$lead = lead_make(26, ['company' => "Qualify me $x"]);
$d1 = $make('lead_qualify', ['rt' => 'lead', 'rid' => $lead, 'lead' => $lead, 'acting' => 26]);
$c0 = count(kernel_chat_log());
$rep = pass('dispatches', gmdate(DATE_ATOM, $t0));
$sent = array_slice(kernel_chat_log(), $c0);
$s = array_values(array_filter($sent, static fn ($c) => str_contains((string) $c['utterance'], "Qualify me $x")))[0] ?? [];
ok(($rep['dispatched'] ?? 0) === 1 && ($rep['answered'] ?? 0) === 1 && count($sent) === 1, 'the pass sends one chat turn and the report counts it: ' . json_encode($rep));
ok(($s['agent'] ?? null) === '42' && ($s['acting'] ?? null) === '26' && str_contains($s['utterance'], 'lead_qualify_propose') && str_contains($s['utterance'], 'lead #' . $lead), 'to the Assistant (agent 42) as Priya (X-Acting-Member 26), asking for lead_qualify_propose about that lead');
$r = $dsp($d1);
ok($r['status'] === 'answered' && mb_strlen($r['reply_excerpt']) === 200 && str_starts_with($r['reply_excerpt'], "REPLY-$x") && $r['answered_at'] !== null && $r['detail'] === null, 'answered: the first 200 characters of the reply kept (of ' . mb_strlen($reply) . '), answered_at set');
$lg = q("SELECT action, source, actor_member_id, after::text AS after FROM activity_log WHERE id > :s AND action LIKE 'dispatch.%' ORDER BY id", ['s' => $since]);
ok(array_column($lg, 'action') === ['dispatch.send', 'dispatch.answered'] && $lg[0]['source'] === 'cron' && $lg[0]['actor_member_id'] === null && !str_contains(json_encode($lg), 'REPLY-') && !str_contains(json_encode($lg), 'Qualify me'), 'logged dispatch.send and dispatch.answered by the worker — ids and kinds, not the words');
$rep = pass('dispatches', gmdate(DATE_ATOM, $t0 + 60));
ok(($rep['dispatched'] ?? -1) === 0 && count(kernel_chat_log()) === $c0 + 1, 'a second pass sends nothing again');

echo "2. A deal entering an ask-the-Assistant stage\n";
$prop = stage('Proposal');
$r = post_as(1, '/admin/agents/save.php', ['ask_assistant_stages' => [$prop], 'ask_assistant_stages_present' => '1']);
ok($r['code'] === 200 && (bool) one('SELECT ask_assistant FROM stages WHERE id = :i', ['i' => $prop]) === true, 'the admin marks Proposal as a stage that asks the Assistant');
$deal = deal_make(26, ['title' => "Asks $x"]);
post_as(26, '/deals/move.php', ['deal' => $deal, 'to_stage' => $prop]);
$d2 = q("SELECT * FROM agent_dispatches WHERE deal_id = :d AND kind = 'stage_entered'", ['d' => $deal])[0] ?? [];
ok($d2 !== [] && (int) $d2['agent_member_id'] === $assistant && (int) $d2['acting_member_id'] === 26 && $d2['status'] === 'sent', 'moving a deal into it makes a stage_entered dispatch for the Assistant, acting as Priya (the schema\'s trigger)');
$c0 = count(kernel_chat_log());
pass('dispatches', gmdate(DATE_ATOM, $t0 + 120));
$l = array_slice(kernel_chat_log(), $c0);
$s = end($l);
ok(count($l) === 1 && str_contains($s['utterance'], one('SELECT number FROM deals WHERE id = :i', ['i' => $deal])) && str_contains($s['utterance'], 'Proposal') && str_contains($s['utterance'], 'next_step_propose') && $dsp((int) $d2['id'])['status'] === 'answered', 'the turn names the deal and the stage and asks for next_step_propose; answered');

echo "3. A reminder that is an agent's\n";
$deal2 = deal_make(26, ['title' => "Agent reminder $x"]);
$ra = act_id(26, ['kind' => 'task', 'subject' => "Agent task $x", 'deal' => $deal2, 'due_at' => at('+1 hour'), 'remind_minutes_before' => 30]);
post_as(26, '/activities/assign.php', ['activity' => $ra, 'owner' => 40]);
worker_at('reminders', gmdate(DATE_ATOM, strtotime('+45 minutes')));
$d3 = q("SELECT * FROM agent_dispatches WHERE record_type = 'activity' AND record_id = :a", ['a' => $ra])[0] ?? [];
ok($d3 !== [] && $d3['kind'] === 'reminder' && (int) $d3['agent_member_id'] === 40 && $d3['status'] === 'sent', 'slice 4\'s reminder pass wrote a reminder dispatch for the Expert (agent 40)');
$c0 = count(kernel_chat_log());
pass('dispatches', gmdate(DATE_ATOM, $t0 + 180));
$l = array_slice(kernel_chat_log(), $c0);
$s = end($l);
ok(count($l) === 1 && $s['agent'] === '40' && $s['acting'] !== null && str_contains($s['utterance'], "Agent task $x") && $dsp((int) $d3['id'])['status'] === 'answered', 'dispatched: one turn to the Expert naming the task; answered — the acting person is ' . ($s['acting'] ?? '?'));

echo "4. A refusal is final; a busy kernel is tried three times\n";
$chat(['chat_status' => 403, 'chat_message' => 'The Assistant holds no access here.']);
$d4 = $make('lead_qualify', ['rt' => 'lead', 'rid' => $lead, 'lead' => $lead, 'acting' => 26]);
$rep = pass('dispatches', gmdate(DATE_ATOM, $t0 + 240));
$r = $dsp($d4);
ok($r['status'] === 'refused' && $r['detail'] === 'The Assistant holds no access here.' && (int) $r['attempts'] === 1 && ($rep['refused'] ?? 0) === 1, '403: refused at once, the kernel\'s own words kept');
$chat(['chat_status' => 503, 'chat_message' => 'busy']);
$d5 = $make('stage_entered', ['rt' => 'deal', 'rid' => $deal, 'deal' => $deal, 'acting' => 26]);
$rep = pass('dispatches', gmdate(DATE_ATOM, $t0 + 300));
$r = $dsp($d5);
ok($r['status'] === 'sent' && (int) $r['attempts'] === 2 && abs(strtotime($r['next_attempt_at']) - ($t0 + 300 + 60)) < 5 && ($rep['retried'] ?? 0) === 1, '503: the first attempt failed — still sent, attempt 2 next, after one minute');
$c0 = count(kernel_chat_log());
pass('dispatches', gmdate(DATE_ATOM, $t0 + 330));
ok(count(kernel_chat_log()) === $c0, '30 seconds later: not yet');
pass('dispatches', gmdate(DATE_ATOM, $t0 + 380));
$r = $dsp($d5);
ok($r['status'] === 'sent' && (int) $r['attempts'] === 3 && abs(strtotime($r['next_attempt_at']) - ($t0 + 380 + 240)) < 5, 'the second attempt fails: attempt 3 next, after four minutes');
$rep = pass('dispatches', gmdate(DATE_ATOM, $t0 + 700));
$r = $dsp($d5);
ok($r['status'] === 'failed' && (int) $r['attempts'] === 3 && $r['detail'] === 'busy' && ($rep['failed'] ?? 0) === 1, 'the third attempt fails: failed, three attempts, the kernel\'s words');
ok(count(activity('dispatch.failed', $since)) === 1, 'logged dispatch.failed once');
$c0 = count(kernel_chat_log());
pass('dispatches', gmdate(DATE_ATOM, $t0 + 1000));
ok(count(kernel_chat_log()) === $c0, 'a failed dispatch is not sent again');

echo "5. A long run, a paused run, a run the kernel lost\n";
$chat(['chat_mode' => 'running']);
$d6 = $make('lead_qualify', ['rt' => 'lead', 'rid' => $lead, 'lead' => $lead, 'acting' => 26]);
pass('dispatches', gmdate(DATE_ATOM, $t0 + 1100));
$r = $dsp($d6);
ok($r['status'] === 'sent' && (int) $r['run_id'] === 77 && $r['reply_excerpt'] === null, '202: the run is going — the dispatch keeps its run id and stays sent');
$c0 = count(kernel_chat_log());
$chat(['chat_mode' => 'running', 'chat_runs' => ['77' => ['run_id' => 77, 'status' => 'running', 'finished' => false, 'reply' => null, 'actions' => []]]]);
pass('dispatches', gmdate(DATE_ATOM, $t0 + 1160));
$l = array_slice(kernel_chat_log(), $c0);
ok(count($l) === 1 && ($l[0]['run'] ?? null) === '77' && $dsp($d6)['status'] === 'sent', 'the next pass asks after run 77 (GET ?run=) — it is not sent again');
$chat(['chat_runs' => ['77' => ['run_id' => 77, 'status' => 'succeeded', 'finished' => true, 'reply' => "Done: $x", 'actions' => []]]]);
pass('dispatches', gmdate(DATE_ATOM, $t0 + 1220));
$r = $dsp($d6);
ok($r['status'] === 'answered' && $r['reply_excerpt'] === "Done: $x" && (int) $r['run_id'] === 77, 'when the run ends it is answered, with the reply');
$chat(['chat' => ['run_id' => 88, 'status' => 'awaiting_approval', 'finished' => true, 'reply' => 'I need approval to send that.', 'approval_request_id' => 5, 'actions' => []]]);
$d7 = $make('lead_qualify', ['rt' => 'lead', 'rid' => $lead, 'lead' => $lead, 'acting' => 26]);
pass('dispatches', gmdate(DATE_ATOM, $t0 + 1300));
$r = $dsp($d7);
ok($r['status'] === 'awaiting_approval' && $r['reply_excerpt'] === 'I need approval to send that.', 'a run that paused for approval: awaiting_approval, its reply kept');
$chat(['chat_mode' => 'running']);
$d8 = $make('lead_qualify', ['rt' => 'lead', 'rid' => $lead, 'lead' => $lead, 'acting' => 26]);
pass('dispatches', gmdate(DATE_ATOM, $t0 + 1400));
$chat(['chat_mode' => 'running', 'chat_runs' => ['5' => []]]);                        // run 77 is no longer known to the kernel
pass('dispatches', gmdate(DATE_ATOM, $t0 + 1460));
$r = $dsp($d8);
ok($r['status'] === 'sent' && $r['run_id'] === null && (int) $r['attempts'] === 2 && str_contains((string) $r['detail'], 'lost the run'), 'a run the kernel no longer knows (404): the dispatch is sent again on its next attempt');
pg_run("UPDATE agent_dispatches SET status = 'refused', detail = 'test over' WHERE id = $d8");

echo "6. No one to act as; a cancelled one is not sent\n";
$chat();
$free = (int) post_as(1, '/leads/save.php', ['company' => "Ownerless $x", 'email' => "own-$x@lead.example.invalid"])['json']['record_id'];
pg_run("UPDATE leads SET owner_member_id = NULL WHERE id = $free");
$d9 = $make('lead_qualify', ['rt' => 'lead', 'rid' => $free, 'lead' => $free]);
$c0 = count(kernel_chat_log());
pass('dispatches', gmdate(DATE_ATOM, $t0 + 1500));
$r = $dsp($d9);
ok($r['status'] === 'refused' && str_contains($r['detail'], 'No one to act as') && count(kernel_chat_log()) === $c0, 'a lead with no owner and no acting person: refused in words, the kernel not asked');
$d10 = $make('lead_qualify', ['rt' => 'lead', 'rid' => $lead, 'lead' => $lead, 'acting' => 26]);
$r = post_as(1, '/admin/agents/dispatch-cancel.php', ['dispatch' => $d10]);
$r10 = $dsp($d10);
ok($r['code'] === 200 && $r10['status'] === 'refused' && $r10['detail'] === 'Cancelled by SMOKE Owner', 'dispatch_cancel: refused, "Cancelled by SMOKE Owner"');
pass('dispatches', gmdate(DATE_ATOM, $t0 + 1560));
ok(count(kernel_chat_log()) === $c0 && count(activity('dispatch.cancel', $since)) === 1, 'the cancelled one is never sent; logged dispatch.cancel');
ok(post_as(1, '/admin/agents/dispatch-cancel.php', ['dispatch' => $d10])['code'] === 422 && post_as(1, '/admin/agents/dispatch-cancel.php', ['dispatch' => $d1])['code'] === 422 && post_as(1, '/admin/agents/dispatch-cancel.php', ['dispatch' => 99999999])['code'] === 404, 'cancelling one that is over, or not there: 422, 404');
$d11 = $make('lead_qualify', ['rt' => 'lead', 'rid' => $lead, 'lead' => $lead, 'acting' => 26]);
ok(post_as(27, '/admin/agents/dispatch-cancel.php', ['dispatch' => $d11])['code'] === 403 && post_as(26, '/admin/agents/dispatch-cancel.php', ['dispatch' => $d11])['code'] === 403, 'a manager and a Rep may not cancel (agents.settings): 403');
$r = req('POST', '/admin/agents/dispatch-cancel.php', ['headers' => as_agent(run_token(40, 4343)), 'form' => ['dispatch' => $d11]]);
ok($r['code'] === 403 && $dsp($d11)['status'] === 'sent', 'an agent may not (people only)');

echo "7. The agents' screen\n";
ok(get_as(26, '/admin/agents')['code'] === 403 && get_as(27, '/admin/agents')['code'] === 403, 'Priya and Marco: 403 — the screen is the admin\'s');
$r = req('GET', '/admin/agents', ['headers' => as_agent(run_token(40, 4344))]);
ok($r['code'] === 403, 'an agent: 403 (people only)');
$ag = get_as(1, '/admin/agents')['json']['data'];
$ids = array_column($ag['agents'], 'member_id');
$a42 = array_values(array_filter($ag['agents'], static fn ($a) => $a['member_id'] === 42))[0] ?? [];
ok(in_array(40, $ids, true) && in_array(42, $ids, true) && !in_array(41, $ids, true) && !in_array(26, $ids, true) && $a42['waiting'] >= 1 && $a42['last_at'] !== null && $a42['answered_week'] >= 2, 'the agents working here (the Expert and the Assistant — not the unadmitted Watcher, not a person) with their last dispatch, what waits and what was answered');
$dd = array_values(array_filter($ag['dispatches'], static fn ($d) => $d['dispatch_id'] === $d1))[0] ?? [];
ok($dd['status'] === 'answered' && $dd['status_label'] === 'Answered' && $dd['tone'] === 'success' && $dd['kind_label'] === 'Qualify a lead' && str_starts_with($dd['reply_excerpt'], 'REPLY-') && $dd['acting_name'] === 'SMOKE Priya' && $dd['agent_name'] === 'SMOKE Assistant', 'a dispatch row: status in words and tone, the kind in words, the excerpt, who it acted for');
$cc = array_values(array_filter($ag['dispatches'], static fn ($d) => $d['dispatch_id'] === $d10))[0] ?? [];
$ff = array_values(array_filter($ag['dispatches'], static fn ($d) => $d['dispatch_id'] === $d5))[0] ?? [];
$aw = array_values(array_filter($ag['dispatches'], static fn ($d) => $d['dispatch_id'] === $d7))[0] ?? [];
ok($cc['status_label'] === 'Cancelled' && $cc['tone'] === 'secondary' && $ff['status_label'] === 'Failed' && $ff['tone'] === 'danger' && $aw['status_label'] === 'Waits for approval' && $aw['tone'] === 'warning', 'cancelled, failed and awaiting-approval read as Cancelled (secondary), Failed (danger), Waits for approval (warning)');
$f = get_as(1, '/admin/agents?status=failed')['json']['data'];
ok($f['dispatches'] !== [] && array_unique(array_column($f['dispatches'], 'status')) === ['failed'], 'the status filter');
ok(isset($ag['settings']['morning_note_hour'], $ag['settings']['stages']) && array_filter($ag['settings']['stages'], static fn ($s) => $s['stage_id'] === $prop && $s['ask_assistant']) !== [], 'the settings and the stages that ask the Assistant');
$h = html_as(1, '/admin/agents')['body'];
ok(str_contains($h, 'id="agents-table"') && str_contains($h, 'id="dispatches-table"') && str_contains($h, 'id="dispatch-' . $d1 . '"') && str_contains($h, 'id="agents-settings-form"') && str_contains($h, 'id="dispatch-' . $d11 . '-cancel"') && !str_contains($h, 'id="dispatch-' . $d1 . '-cancel"'), 'the HTML: agents-table, dispatches-table, dispatch-{id}, agents-settings-form; Cancel only on a waiting one');
ok(str_contains($h, 'Waiting') && str_contains($h, 'Answered') && str_contains($h, 'Cancelled by SMOKE Owner'), 'the words are on the page');

echo "8. The Assistant's settings\n";
$r = post_as(1, '/admin/agents/save.php', ['morning_note_enabled' => 'no', 'morning_note_hour' => 7, 'assistant_proposals' => 'no', 'lead_untouched_days' => 3, 'account_untouched_days' => 45, 'ask_assistant_stages' => [], 'ask_assistant_stages_present' => '1']);
$s = q('SELECT morning_note_enabled, morning_note_hour, assistant_proposals, lead_untouched_days, account_untouched_days FROM crm_settings')[0];
ok($r['code'] === 200 && $s['morning_note_enabled'] === false && (int) $s['morning_note_hour'] === 7 && $s['assistant_proposals'] === false && (int) $s['lead_untouched_days'] === 3 && (int) $s['account_untouched_days'] === 45 && (bool) one('SELECT ask_assistant FROM stages WHERE id = :i', ['i' => $prop]) === false, 'agents_settings_save changes the switches and takes the stage off');
$lg = q("SELECT * FROM activity_log WHERE action = 'agents_settings.save' AND id > :s ORDER BY id DESC LIMIT 1", ['s' => $since])[0];
$la = json_decode($lg['after'], true);
$lb = json_decode($lg['before'], true);
ok($la['morning_note_hour'] === 7 && $lb['morning_note_hour'] === 6 && $la['assistant_proposals'] === false && $lb['ask_assistant_stages'] === [$prop] && $la['ask_assistant_stages'] === [], 'logged agents_settings.save with before and after');
$n = (int) one("SELECT count(*) FROM activity_log WHERE action = 'agents_settings.save'");
$r = post_as(1, '/admin/agents/save.php', ['morning_note_hour' => 7]);
ok($r['code'] === 200 && (int) one("SELECT count(*) FROM activity_log WHERE action = 'agents_settings.save'") === $n && str_contains($r['json']['did'] ?? '', 'Nothing'), 'a save that changes nothing says so and logs nothing');
ok(post_as(1, '/admin/agents/save.php', ['morning_note_hour' => 24])['code'] === 422 && field_of(post_as(1, '/admin/agents/save.php', ['lead_untouched_days' => 0])) === 'lead_untouched_days' && field_of(post_as(1, '/admin/agents/save.php', ['account_untouched_days' => 4000])) === 'account_untouched_days'
    && post_as(1, '/admin/agents/save.php', ['assistant_proposals' => 'maybe'])['code'] === 422 && post_as(1, '/admin/agents/save.php', ['ask_assistant_stages' => [99999]])['code'] === 422, 'an hour past 23, a day count out of range, a yes that is not yes or no, a stage that is not there: 422');
$won = stage('Won');
ok(post_as(1, '/admin/agents/save.php', ['ask_assistant_stages' => [$won]])['code'] === 422, 'the Won stage is not an open stage: 422');
ok(post_as(27, '/admin/agents/save.php', ['morning_note_hour' => 8])['code'] === 403 && post_as(26, '/admin/agents/save.php', ['morning_note_hour' => 8])['code'] === 403 && (int) one('SELECT morning_note_hour FROM crm_settings') === 7, 'a manager and a Rep: 403, nothing changed');
$r = req('POST', '/admin/agents/save.php', ['headers' => as_agent(run_token(40, 4345)), 'form' => ['morning_note_hour' => 9]]);
ok($r['code'] === 403 && (int) one('SELECT morning_note_hour FROM crm_settings') === 7, 'an agent: 403 (it pauses first as `other`, and is then refused: people only)');
$reg = json_decode((string) file_get_contents(dirname(__DIR__, 3) . '/mcp/action_registry.json'), true)['actions'];
ok($reg['agents_settings_save']['approval'] === 'other' && $reg['agents_settings_save']['agent'] === false && $reg['dispatch_cancel']['agent'] === false && $reg['dispatch_cancel']['approval'] === null && $reg['agents_settings_save']['log_event'] === 'agents_settings.save', 'registry: agents_settings_save pauses as other for an agent and is people-only; dispatch_cancel is people-only');
pg_run("UPDATE crm_settings SET morning_note_enabled = true, morning_note_hour = 6, assistant_proposals = true, lead_untouched_days = 2, account_untouched_days = 30");
finish();
