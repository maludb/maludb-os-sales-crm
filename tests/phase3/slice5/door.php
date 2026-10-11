<?php
/**
 * Proof — the unsubscribe door (email.md divergence 3): /u/{token} is public; a GET shows the masked address and one button and changes nothing; the POST (with the page's own CSRF token)
 * calls crm_contact_opt_out(contact, 'email', 'unsubscribe_link') and logs contact.opt_out with the contact — never the token; the token is per contact per send and only its SHA-256 is kept.
 */
require __DIR__ . '/lib.php';
$x = run_tag();
ensure_mailbox();
$since = last_activity_id();
$acc = (int) post_as(26, '/accounts/save.php', ['name' => "Door Acct $x"])['json']['record_id'];
$ann = contact_with("ann-$x@door-$x.example.invalid", 'Ann', $acc);
$cat = contact_with("cat-$x@door-$x.example.invalid", 'Cat', $acc);
$deal = deal_make(26, ['title' => "Door deal $x", 'account' => $acc, 'primary_contact' => $ann]);
$r = post_as(26, '/email/send.php', ['contacts' => [$ann, $cat], 'deal' => $deal, 'subject' => "Door $x", 'body' => 'Hello there.', 'confirm' => 'yes']);
$byTo = [];
foreach (array_slice(sends(), -2) as $m) { preg_match('#/u/([a-f0-9]{48})#', $m['text'], $t); $byTo[$m['to']] = $t[1] ?? ''; }
$tokAnn = $byTo["ann-$x@door-$x.example.invalid"];
$tokCat = $byTo["cat-$x@door-$x.example.invalid"];
ok(strlen($tokAnn) === 48 && strlen($tokCat) === 48 && $tokAnn !== $tokCat, 'setup: two messages, two tokens');

echo "1. A GET shows one button and changes nothing\n";
$g = req('GET', "/u/$tokAnn");
ok($g['code'] === 200 && str_contains($g['body'], 'id="unsubscribe-btn"') && str_contains($g['body'], 'id="unsubscribe-form"') && !str_contains($g['body'], 'id="sidebar"') && !str_contains($g['body'], 'nxl-navigation'), 'the page: one button, no menu, no sign-in');
ok(str_contains($g['body'], 'a•••@door-' . $x . '.example.invalid') && !str_contains($g['body'], "ann-$x@"), 'the address is shown masked');
ok(preg_match('/^Cache-Control: no-store/mi', $g['headers']) === 1 && preg_match('/^Referrer-Policy: no-referrer/mi', $g['headers']) === 1 && preg_match('/^X-Robots-Tag: noindex/mi', $g['headers']) === 1, 'not cached, not passed on by a referrer, not indexed');
ok((int) one('SELECT count(*) FROM contacts WHERE id = :c AND email_opt_out', ['c' => $ann]) === 0, 'opening the link opts nobody out (a mail scanner prefetches links)');
ok(allowed($ann), 'Ann may still be emailed');

echo "2. The wrong token, the same page\n";
$bad = req('GET', '/u/' . str_repeat('a', 48));
ok($bad['code'] === 404 && str_contains($bad['body'], 'id="unsubscribe-invalid"'), 'a token no send has: 404 and the same words as any other');
ok(req('GET', '/u/short')['code'] === 404 && req('GET', '/u/' . strtoupper($tokAnn))['code'] === 404, 'a malformed token, or the token in capitals: 404');
$was = (int) one('SELECT count(*) FROM contacts WHERE email_opt_out AND opt_out_reason = \'unsubscribe_link\'');
$p = req('POST', '/u/' . str_repeat('b', 48));
ok($p['code'] === 404 && (int) one('SELECT count(*) FROM contacts WHERE email_opt_out AND opt_out_reason = \'unsubscribe_link\'') === $was, 'a POST to an unknown token opts nobody out');

echo "3. The POST opts out — with the page's own CSRF token\n";
$jar = jar();
$g = req('GET', "/u/$tokAnn", ['jar' => $jar]);
preg_match('/name="csrf_token" value="([a-f0-9]+)"/', $g['body'], $c);
$no = req('POST', "/u/$tokAnn", ['jar' => $jar, 'form' => []]);
ok($no['code'] === 403 && allowed($ann), 'without the token: 403, nothing changes');
$yes = req('POST', "/u/$tokAnn", ['jar' => $jar, 'form' => ['csrf_token' => $c[1] ?? '']]);
ok($yes['code'] === 200 && str_contains($yes['body'], 'id="unsubscribe-done"') && str_contains($yes['body'], 'will not email'), 'with it: "You are unsubscribed."');
$row = q('SELECT email_opt_out, email_opt_out_at, opt_out_reason FROM contacts WHERE id = :c', ['c' => $ann])[0];
ok($row['email_opt_out'] === true && $row['email_opt_out_at'] !== null && $row['opt_out_reason'] === 'unsubscribe_link', 'Ann is opted out, with the moment and the reason unsubscribe_link');
ok(!allowed($ann) && allowed($cat), 'Ann is no longer allowed; Cat — another token, another contact — still is');
$log = q("SELECT * FROM activity_log WHERE id > :s AND action = 'contact.opt_out'", ['s' => $since]);
ok(count($log) === 1 && (int) $log[0]['contact_id'] === $ann && $log[0]['actor_member_id'] === null && $log[0]['source'] === 'portal' && json_decode($log[0]['after'], true)['reason'] === 'unsubscribe_link', 'contact.opt_out logged once: the contact, no actor, source portal, the reason');
ok(!str_contains(json_encode($log), $tokAnn) && !str_contains(json_encode($log), "ann-$x@"), 'the token and the address are not in the log');
$again = req('GET', "/u/$tokAnn");
ok(str_contains($again['body'], 'id="unsubscribe-done"') && !str_contains($again['body'], 'id="unsubscribe-btn"'), 'the link opened again says it is done');
$jar2 = jar();
$g2 = req('GET', "/u/$tokAnn", ['jar' => $jar2]);
req('POST', "/u/$tokAnn", ['jar' => $jar2, 'form' => ['csrf_token' => csrf_in($g2['body'])]]);
ok(count(q("SELECT 1 FROM activity_log WHERE id > :s AND action = 'contact.opt_out'", ['s' => $since])) === 1, 'doing it twice logs nothing more');

echo "4. After it, the application refuses Ann by name\n";
$r = post_as(26, '/email/send.php', ['contact' => $ann, 'subject' => 's', 'body' => 'b', 'confirm' => 'yes']);
ok($r['code'] === 422 && str_contains((string) ($r['json']['error']['message'] ?? ''), 'Ann Mail') && str_contains((string) ($r['json']['error']['message'] ?? ''), 'opted out'), 'a send to her is refused by name: ' . ($r['json']['error']['message'] ?? $r['body']));
$h = html_as(26, "/email/compose?contact=$ann")['body'];
ok(str_contains($h, 'id="compose-to-optout-' . $ann . '"'), 'compose marks her opted out');
$ch = html_as(26, "/contacts/$ann")['body'];
ok(str_contains($ch, 'opted out') || str_contains($ch, 'opt-out'), 'her contact page shows the opt-out');
$dirty = (int) one("SELECT count(*) FROM outbound_email x WHERE row_to_json(x)::text LIKE :t", ['t' => '%' . $tokAnn . '%']) + (int) one("SELECT count(*) FROM activities x WHERE row_to_json(x)::text LIKE :t", ['t' => '%' . $tokAnn . '%']);
ok($dirty === 0, 'the token is in no row of the database');
$one = (int) one("SELECT count(*) FROM outbound_email WHERE message_id = :m", ['m' => '<' . hash('sha256', $tokAnn) . '@example.invalid>']);
ok($one === 1, 'only its SHA-256 is kept — as that send\'s Message-ID');
finish();

function allowed(int $c): bool { return (bool) one('SELECT crm_email_allowed(:c)', ['c' => $c]); }
function csrf_in(string $html): string { preg_match('/name="csrf_token" value="([a-f0-9]+)"/', $html, $m); return $m[1] ?? ''; }
