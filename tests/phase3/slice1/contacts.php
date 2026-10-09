<?php
/**
 * Proof — contacts (accounts-contacts.md "Proof" 2 and 3): a contact made at an account is its primary, a second is not, set-primary swaps; the
 * address mirror holds every address; a new contact is placed at the account its address's domain names (never a free-mail domain); the duplicate
 * warning fires by email and by name (and by domain and name for accounts); block_exact_email refuses with the field named, warn lets through; the
 * opt-out and its banner; a name or an email is required; the edit rule.
 */
require __DIR__ . '/lib.php';
$x = run_tag();
setting('duplicates_policy', "'warn'");
$acc = (int) post_as(26, '/accounts/save.php', ['name' => "Globex $x", 'website' => "https://globex-$x.example.com"])['json']['record_id'];

echo "1. Primary, the mirror, placement by domain\n";
$r = post_as(26, '/contacts/save.php', ['first_name' => 'Hank', 'last_name' => "Scorpio $x", 'email' => "hank@globex-$x.example.com", 'title' => 'CEO']);
$hank = (int) ($r['json']['record_id'] ?? 0);
ok($r['code'] === 200 && (int) one('SELECT account_id FROM contacts WHERE id = :i', ['i' => $hank]) === $acc, 'a contact whose address carries the account\'s domain is placed at it (no account chosen)');
$made = activity('contact.create');
ok(json_decode((string) end($made)['after'], true)['placed_by_domain'] === true, '…and the log says it was placed by the domain');
ok(one('SELECT is_primary FROM contacts WHERE id = :i', ['i' => $hank]) === true, 'the first contact of an account is its primary');
$r = post_as(26, '/contacts/save.php', ['first_name' => 'Mindy', 'last_name' => "Simmons $x", 'email' => "mindy@globex-$x.example.com", 'email_alt' => "mindy.s@example.org"]);
$mindy = (int) $r['json']['record_id'];
ok(one('SELECT is_primary FROM contacts WHERE id = :i', ['i' => $mindy]) === false, 'a second is not');
$r = post_as(26, '/contacts/primary.php', ['contact' => $mindy]);
ok($r['code'] === 200 && one('SELECT is_primary FROM contacts WHERE id = :i', ['i' => $mindy]) === true && one('SELECT is_primary FROM contacts WHERE id = :i', ['i' => $hank]) === false, 'set-primary swaps: Mindy is primary, Hank no longer');
ok((int) one('SELECT count(*) FROM contacts WHERE account_id = :a AND is_primary AND deleted_at IS NULL', ['a' => $acc]) === 1, 'one primary per account');
post_as(26, '/contacts/save.php', ['contact' => $hank, 'email' => "h.scorpio@globex-$x.example.com"]);
$addr = array_column(q('SELECT address::text AS a FROM contact_emails WHERE contact_id = :c', ['c' => $hank]), 'a');
sort($addr);
ok($addr === ["h.scorpio@globex-$x.example.com", "hank@globex-$x.example.com"], 'the address mirror keeps every address a contact used: ' . implode(', ', $addr));
$v = html_as(26, "/contacts/$hank")['body'];
ok(str_contains($v, "hank@globex-$x.example.com") && str_contains($v, "h.scorpio@globex-$x.example.com"), 'the contact page lists every address');
$r = post_as(26, '/contacts/save.php', ['first_name' => 'Gmail', 'last_name' => "User $x", 'email' => "someone$x@gmail.com"]);
ok((int) $r['json']['record_id'] > 0 && one('SELECT account_id FROM contacts WHERE id = :i', ['i' => (int) $r['json']['record_id']]) === null, 'a free-mail address places nobody');
$r = post_as(26, '/contacts/save.php', ['first_name' => 'Solo', 'last_name' => "Primary $x", 'is_primary' => 'yes']);
ok($r['code'] === 422 && field_of($r) === 'is_primary', 'primary without an account is refused on its field');
$r = post_as(26, '/contacts/save.php', ['title' => 'Nobody']);
ok($r['code'] === 422 && field_of($r) === 'first_name' && str_contains(message_of($r), 'name or an email'), 'a name or an email is required');
$r = post_as(26, '/contacts/save.php', ['first_name' => 'Bad', 'email' => 'not-an-address']);
ok($r['code'] === 422 && field_of($r) === 'email', 'a malformed address is refused on `email`');
$r = post_as(26, '/contacts/save.php', ['first_name' => 'Prefill']);
ok($r['code'] === 200, 'a first name alone is enough');
$f = html_as(26, "/contacts/new?account=$acc&email=" . rawurlencode("new@globex-$x.example.com"))['body'];
ok(str_contains($f, 'value="' . $acc . '"') && str_contains($f, "Globex $x") && str_contains($f, "new@globex-$x.example.com"), 'the add form prefills the account (its picker shows the name) and the email');

echo "2. The duplicate warning, the policy\n";
$w = html_as(26, '/contacts/new?dupes=1&email=' . rawurlencode("HANK@globex-$x.example.com"))['body'];
ok(str_contains($w, 'id="duplicate-warning-' . $hank . '"') && str_contains($w, 'the same email'), 'by email (any address, any case): Hank is named');
$w = html_as(26, '/contacts/new?dupes=1&first_name=Hank&last_name=' . rawurlencode("Scorpioo $x"))['body'];
ok(str_contains($w, 'id="duplicate-warning-' . $hank . '"') && str_contains($w, 'name, nearly'), 'by a name like it');
$w = html_as(26, '/contacts/new?dupes=1&except=' . $hank . '&email=' . rawurlencode("hank@globex-$x.example.com"))['body'];
ok(!str_contains($w, 'id="duplicate-warning-' . $hank . '"'), 'editing Hank, Hank is not his own duplicate');
$w = html_as(26, '/accounts/new?dupes=1&website=' . rawurlencode("https://globex-$x.example.com/x"))['body'];
ok(str_contains($w, 'id="duplicate-warning-' . $acc . '"') && str_contains($w, 'the same domain'), 'accounts: by the domain a website names');
$w = html_as(26, '/accounts/new?dupes=1&name=' . rawurlencode("Globexx $x"))['body'];
ok(str_contains($w, 'id="duplicate-warning-' . $acc . '"'), 'accounts: by a name like it');
$r = post_as(26, '/contacts/save.php', ['first_name' => 'Twin', 'email' => "hank@globex-$x.example.com"]);
ok($r['code'] === 200, 'warn (the default) lets a second contact with the same address through');
setting('duplicates_policy', "'block_exact_email'");
$w = html_as(26, '/contacts/new?dupes=1&email=' . rawurlencode("hank@globex-$x.example.com"))['body'];
ok(str_contains($w, 'does not allow a second one'), 'under block_exact_email the warning says the business does not allow a second');
$r = post_as(26, '/contacts/save.php', ['first_name' => 'Triplet', 'email' => "mindy@globex-$x.example.com"]);
ok($r['code'] === 422 && field_of($r) === 'email' && str_contains(message_of($r), "Simmons $x"), 'block_exact_email refuses on `email`, naming who holds it: ' . message_of($r));
setting('duplicates_policy', "'warn'");

echo "3. Opt-out, the banner, the edit rule\n";
$r = post_as(26, '/contacts/opt-out.php', ['contact' => $hank, 'channel' => 'email', 'reason' => 'Asked on the phone']);
ok($r['code'] === 200 && one('SELECT email_opt_out AND email_opt_out_at IS NOT NULL FROM contacts WHERE id = :i', ['i' => $hank]), 'contact_opt_out records the opt-out and its moment');
$v = html_as(26, "/contacts/$hank")['body'];
ok(str_contains($v, 'id="contact-opt-out-banner"') && str_contains($v, 'No email.') && str_contains($v, 'Asked on the phone') && str_contains($v, 'id="contact-opt-in-email-btn"'), 'the page shows the banner with the reason and the way back');
ok(str_contains(html_as(26, '/contacts?opt_out=email')['body'], 'id="contact-card-' . $hank . '-no-email"'), 'the card marks No email, and the filter finds it');
$oo = activity('contact.opt_out');
$a = end($oo);
ok(json_decode((string) $a['after'], true) == ['channel' => 'email', 'opted_out' => true, 'reason_given' => true] && (int) $a['account_id'] === $acc, 'logged contact.opt_out with the account key — the reason given, never its words');
ok(post_as(26, '/contacts/opt-out.php', ['contact' => $hank, 'channel' => 'fax'])['code'] === 422, 'a channel other than email or sms is refused');
post_as(26, '/contacts/opt-out.php', ['contact' => $hank, 'channel' => 'email', 'undo' => 'yes']);
ok(one('SELECT email_opt_out FROM contacts WHERE id = :i', ['i' => $hank]) === false && !str_contains(html_as(26, "/contacts/$hank")['body'], 'id="contact-opt-out-banner"'), 'undo lifts it and the banner goes');
$r = post_as(30, '/contacts/save.php', ['contact' => $hank, 'title' => 'Hijacked']);
ok($r['code'] === 403, 'another Rep may not change Priya\'s contact');
ok(post_as(30, '/contacts/primary.php', ['contact' => $hank])['code'] === 403 && post_as(30, '/contacts/opt-out.php', ['contact' => $hank, 'channel' => 'sms'])['code'] === 403, '…nor set primary or record an opt-out');
ok(post_as(27, '/contacts/save.php', ['contact' => $hank, 'role' => 'decision_maker'])['code'] === 200 && one('SELECT role FROM contacts WHERE id = :i', ['i' => $hank]) === 'decision_maker', 'the manager may');
ok(post_as(26, '/contacts/save.php', ['contact' => $hank, 'role' => 'boss'])['code'] === 422, 'a role outside the list is refused');
$l = get_as(26, "/contacts?account=$acc")['json']['data']['contacts'] ?? [];
ok(count($l) >= 3 && array_column($l, 'account_id') === array_fill(0, count($l), $acc), 'the account filter');
$h = html_as(26, "/accounts/$acc?tab=contacts")['body'];
ok(strpos($h, 'id="account-contact-' . $mindy . '"') < strpos($h, 'id="account-contact-' . $hank . '"') && str_contains($h, 'id="account-contact-' . $mindy . '-primary"'), 'the account\'s Contacts tab lists the primary first');
finish();
