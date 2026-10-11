<?php
/**
 * Proof — the logging mailbox (admin-settings.md "Proof"): the row saved with the NAME of the env key that holds the webhook secret — never a value (a value-shaped text is refused, the real secret is on no page, in no JSON and in no log) —,
 * the health shown from the row's own columns, an address change starting the mailbox afresh, the recipe, people only, the rights.
 */
require __DIR__ . '/lib.php';
$x = run_tag();
$since = last_activity_id();
$secret = need('MAILBOX_CRM_WEBHOOK_SECRET');
pg_run("UPDATE crm_settings SET mailbox_id = NULL");
pg_run("DELETE FROM inbound_email; DELETE FROM mailboxes");

echo "1. Not set up yet\n";
$s = screen(1, '/admin/mailbox');
ok($s['mailbox'] === null && $s['health']['state'] === 'none' && str_ends_with($s['webhook_url'], '/channels/malumail/mail'), 'no mailbox: health "none", the webhook URL given');
$page = html_as(1, '/admin/mailbox')['body'];
ok(str_contains($page, 'id="mailbox-form"') && str_contains($page, 'id="mailbox-recipe"') && str_contains($page, 'ROOT_STEPS') && str_contains($page, 'id="mailbox-health-none"'), 'the page: the form, the recipe of ROOT_STEPS step 3, "no mailbox yet"');
ok(str_contains(html_as(1, '/admin')['body'], 'id="admin-mailbox-health"'), 'the admin home warns that the mailbox is not set up');

echo "2. Saving it — a name, never a secret\n";
$addr = "crm-log9-$x@example.invalid";
$r = adm('/admin/mailbox/save.php', ['address' => strtoupper($addr), 'display_name' => 'Sales CRM log', 'provider' => 'malumail', 'webhook_secret_ref' => 'MAILBOX_CRM_WEBHOOK_SECRET', 'signature' => "-- \nThe team", 'poll_enabled' => 'yes']);
$mb = (int) ($r['json']['record_id'] ?? 0);
$row = q('SELECT * FROM mailboxes WHERE id = :i', ['i' => $mb])[0] ?? [];
ok($r['code'] === 200 && $row['address'] === $addr && $row['webhook_secret_ref'] === 'MAILBOX_CRM_WEBHOOK_SECRET' && $row['provider'] === 'malumail' && $row['poll_enabled'] === true && $row['active'] === true, 'mailbox_save makes the mailbox (the address lower-cased): ' . ($r['json']['did'] ?? $r['body']));
ok((int) one('SELECT mailbox_id FROM crm_settings WHERE id = 1') === $mb, 'and it is the one the application logs through (crm_settings.mailbox_id)');
foreach ([$secret, 'whsec_3f9a8b7c6d5e4f3a2b1c0d9e8f7a6b5c', '0123456789ABCDEF0123456789ABCDEF', 'lower_case_name', 'MAILBOX CRM', 'X'] as $bad) {
    $r = adm('/admin/mailbox/save.php', ['mailbox' => $mb, 'webhook_secret_ref' => $bad]);
    ok($r['code'] === 422 && field_of($r) === 'webhook_secret_ref' && str_contains(says($r), 'NAME of the key') && one('SELECT webhook_secret_ref FROM mailboxes WHERE id = :i', ['i' => $mb]) === 'MAILBOX_CRM_WEBHOOK_SECRET', 'a value-shaped reference is refused, the field named: ' . substr($bad, 0, 12) . '…');
}
$blob = '';
foreach (['/admin', '/admin/mailbox'] as $u) { $blob .= html_as(1, $u)['body'] . get_as(1, $u)['body']; }
$blob .= json_encode(q('SELECT after::text, before::text FROM activity_log WHERE id > :s', ['s' => $since])) . json_encode(q('SELECT * FROM mailboxes'));
ok(!str_contains($blob, $secret), 'the real webhook secret is on no page, in no JSON, in no log row and in no table');
$page = html_as(1, '/admin/mailbox')['body'];
ok(str_contains($page, 'value="MAILBOX_CRM_WEBHOOK_SECRET"') && str_contains($page, 'That key is set.'), 'the form shows the key\'s NAME and that it is set (the scratch environment has it)');
$j = screen(1, '/admin/mailbox')['mailbox'];
ok($j['webhook_secret_ref'] === 'MAILBOX_CRM_WEBHOOK_SECRET' && $j['secret_set'] === true && !array_key_exists('webhook_secret', $j) && !array_key_exists('secret', $j), 'JSON: the name and a yes/no, no value');
$r = adm('/admin/mailbox/save.php', ['mailbox' => $mb, 'webhook_secret_ref' => 'MAILBOX_NOT_SET_SECRET']);
ok($r['code'] === 200 && str_contains(html_as(1, '/admin/mailbox')['body'], 'That key is not set') && screen(1, '/admin')['mailbox']['state'] === 'quiet', 'a name whose key is not in the environment is saved and the page says so (health: quiet)');
adm('/admin/mailbox/save.php', ['mailbox' => $mb, 'webhook_secret_ref' => 'MAILBOX_CRM_WEBHOOK_SECRET']);
$r = adm('/admin/mailbox/save.php', ['address' => 'not an address']);
ok($r['code'] === 422 && field_of($r) === 'address', 'a bad address names its field');
$r = adm('/admin/mailbox/save.php', ['mailbox' => $mb, 'provider' => 'pop3']);
ok($r['code'] === 422 && field_of($r) === 'provider', 'a provider that is neither MaluMail nor IMAP names its field');
$r = adm('/admin/mailbox/save.php', ['mailbox' => 99999, 'address' => 'x@example.invalid']);
ok($r['code'] === 404, 'a mailbox that is not there is 404');

echo "3. Health\n";
$h = screen(1, '/admin/mailbox')['health'];
ok($h['state'] === 'quiet' && str_contains($h['words'], 'Nothing has arrived'), 'saved but nothing yet: quiet — "' . $h['words'] . '"');
pg_run("UPDATE mailboxes SET last_webhook_at = now() - interval '5 minutes', last_polled_at = now() - interval '1 minute' WHERE id = $mb");
pg_run("INSERT INTO inbound_email (mailbox_id, message_id, from_address, to_addresses, subject, received_at, match_status, status) VALUES ($mb, '<h9a-$x@example.invalid>', 'a@b.example.invalid', ARRAY['x'], 'H9 one', now(), 'pending', 'received'), ($mb, '<h9b-$x@example.invalid>', 'a@b.example.invalid', ARRAY['x'], 'H9 two', now() - interval '10 days', 'matched', 'processed')");
$m = screen(1, '/admin/mailbox');
ok($m['health']['state'] === 'ok' && $m['mailbox']['received_7d'] === 1 && $m['mailbox']['pending'] === 1 && $m['mailbox']['last_webhook_at'] !== null && $m['mailbox']['last_polled_at'] !== null, 'a webhook and a poll arrived: ok — one received this week (the ten-day-old is not counted), one waiting to be matched');
$page = html_as(1, '/admin/mailbox')['body'];
ok(str_contains($page, 'id="mailbox-last-webhook"') && str_contains($page, 'id="mailbox-last-poll"') && str_contains($page, 'id="mailbox-received"') && str_contains($page, 'id="mailbox-pending"') && preg_match('/id="mailbox-last-error">none/', $page) === 1, 'the page shows the last webhook, the last poll, the week\'s count, the waiting count and "none" for the last failure');
pg_run("UPDATE mailboxes SET last_error = 'MaluMail answered 503 on the poll', last_error_at = now() WHERE id = $mb");
$m = screen(1, '/admin/mailbox');
ok($m['health']['state'] === 'error' && str_contains($m['mailbox']['last_error'], '503'), 'a failure on the row: health error, the sentence shown');
ok(str_contains(html_as(1, '/admin/mailbox')['body'], 'MaluMail answered 503 on the poll') && str_contains(html_as(1, '/admin')['body'], 'The logging mailbox:'), 'on the page and as a warning on the admin home');
$r = adm('/admin/mailbox/save.php', ['mailbox' => $mb, 'active' => 'no']);
ok($r['code'] === 200 && screen(1, '/admin/mailbox')['health']['state'] === 'off', 'switched off: health "off"');
adm('/admin/mailbox/save.php', ['mailbox' => $mb, 'active' => 'yes']);
$r = adm('/admin/mailbox/save.php', ['mailbox' => $mb, 'address' => "crm-moved9-$x@example.invalid"]);
$row = q('SELECT last_uid, last_polled_at, last_error FROM mailboxes WHERE id = :i', ['i' => $mb])[0];
ok($r['code'] === 200 && $row['last_polled_at'] === null && $row['last_error'] === null && $row['last_uid'] === null, 'a new address starts afresh: the poll position and the old failure are cleared');
$r = adm('/admin/mailbox/save.php', ['address' => "crm-log9-$x@example.invalid", 'display_name' => 'Second']);
ok($r['code'] === 200 && (int) $r['json']['record_id'] === $mb && (int) one('SELECT count(*) FROM mailboxes') === 1, 'with a mailbox in place a save without an id changes THAT mailbox — the application logs through one');

echo "4. People only, rights, the log\n";
$ag = req('POST', '/admin/mailbox/save.php', ['headers' => as_agent(run_token(42, 9402)), 'form' => ['display_name' => 'By the Assistant']]);
ok(in_array($ag['code'], [403, 401], true) && one('SELECT display_name FROM mailboxes WHERE id = :i', ['i' => $mb]) !== 'By the Assistant', 'an agent\'s mailbox_save is refused (people only)');
ok(get_as(26, '/admin/mailbox')['code'] === 403 && post_as(26, '/admin/mailbox/save.php', ['display_name' => 'x'])['code'] === 403 && get_as(27, '/admin/mailbox')['code'] === 403, 'a Rep and the Sales Manager may not (mailbox.manage is the CRM admin\'s)');
$ev = q("SELECT after::text AS a, before::text AS b FROM activity_log WHERE action = 'mailbox.save' AND id > :s ORDER BY id", ['s' => $since]);
ok(count($ev) >= 4 && str_contains($ev[0]['a'], 'MAILBOX_CRM_WEBHOOK_SECRET') && !str_contains(json_encode($ev), $secret) && !str_contains(json_encode($ev), 'The team'), 'mailbox.save is logged with the key\'s name; no secret, no signature text');
$sig = q("SELECT after::text AS a FROM activity_log WHERE action = 'mailbox.save' AND after::text LIKE '%(changed)%' AND id > :s", ['s' => $since]);
ok(count($sig) === 0, 'a signature that did not change is not logged (and one that does is "(changed)", never its text)');
pg_run("UPDATE mailboxes SET address = 'crm-log@example.invalid', display_name = 'Sales CRM', webhook_secret_ref = 'MAILBOX_CRM_WEBHOOK_SECRET', last_error = NULL, last_error_at = NULL WHERE id = $mb");
finish();
