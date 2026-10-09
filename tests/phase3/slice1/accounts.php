<?php
/**
 * Proof — accounts (accounts-contacts.md "Proof" 1 and 4): an account made with a website gets its domain; a second on the same domain is refused
 * with the field named; the parent picker offers only live accounts (and never the account itself); a parent cycle is refused; the kind is a person's
 * only for partner, other and former; the custom fields render per kind and refuse a wrong type naming the field; a field left out stays; the edit
 * rule (another Rep refused in words naming the owner, the manager allowed); a Viewer may not add; the list's filters and the cards.
 */
require __DIR__ . '/lib.php';
$x = run_tag();
echo "1. The domain\n";
$r = post_as(26, '/accounts/save.php', ['name' => "Acme $x", 'website' => "https://www.acme-$x.example.com/about/us", 'industry' => 'Manufacturing', 'city' => 'Chicago', 'country' => 'us']);
$acme = (int) ($r['json']['record_id'] ?? 0);
ok($r['code'] === 200 && $acme > 0 && ($r['json']['location'] ?? '') === "/accounts/$acme?notice=created", 'account_create answers ok with the record_id and lands on the account');
ok(one('SELECT domain::text FROM accounts WHERE id = :i', ['i' => $acme]) === "acme-$x.example.com" && one('SELECT country FROM accounts WHERE id = :i', ['i' => $acme]) === 'US', 'the website\'s domain is derived (www and the path off) and the country upper-cased');
$r = post_as(26, '/accounts/save.php', ['name' => "Acme Two $x", 'domain' => "ACME-$x.example.com"]);
ok($r['code'] === 422 && field_of($r) === 'domain' && str_contains(message_of($r), "Acme $x"), 'a second account on the same domain is refused on the `domain` field, naming the account that holds it: ' . message_of($r));
$r = post_as(26, '/accounts/save.php', ['name' => "Acme Three $x", 'website' => "http://acme-$x.example.com"]);
ok($r['code'] === 422 && field_of($r) === 'domain', '…the same through a website');
$r = post_as(26, '/accounts/save.php', ['name' => "Bad $x", 'domain' => 'not a domain']);
ok($r['code'] === 422 && field_of($r) === 'domain', 'a domain that is not one is refused on its field');
$r = post_as(26, '/accounts/save.php', ['name' => '']);
ok($r['code'] === 422 && field_of($r) === 'name', 'a name is required, on its field');
$a = (int) one('SELECT count(*) FROM activity_log WHERE action = \'account.create\' AND entity_id = :i', ['i' => $acme]);
ok($a === 1, 'logged account.create once, with the account as the audit key');

echo "2. The parent, the kind\n";
$sub = (int) post_as(26, '/accounts/save.php', ['name' => "Acme Labs $x", 'parent_account' => $acme])['json']['record_id'];
ok((int) one('SELECT parent_account_id FROM accounts WHERE id = :i', ['i' => $sub]) === $acme, 'a parent is chosen by id (the picker\'s value)');
$r = post_as(26, '/accounts/save.php', ['account' => $acme, 'parent_account' => $sub]);
ok($r['code'] === 422 && field_of($r) === 'parent_account', 'a parent below the account (a cycle) is refused on its field');
$r = post_as(26, '/accounts/save.php', ['account' => $acme, 'parent_account' => $acme]);
ok($r['code'] === 422 && field_of($r) === 'parent_account', '…and the account itself');
$gone = (int) post_as(26, '/accounts/save.php', ['name' => "Gone $x"])['json']['record_id'];
post_as(26, '/records/delete.php', ['kind' => 'account', 'record' => $gone]);
$pick = html_as(26, '/pick/?source=account&q=' . rawurlencode($x));
ok(str_contains($pick['body'], 'id="record-picker-row-' . $acme . '"') && !str_contains($pick['body'], 'id="record-picker-row-' . $gone . '"'), 'the parent picker offers live accounts only (the deleted one is not offered)');
$pick = html_as(26, '/pick/?source=account&q=' . rawurlencode($x) . '&except=' . $acme);
ok(!str_contains($pick['body'], 'id="record-picker-row-' . $acme . '"') && str_contains($pick['body'], 'id="record-picker-row-' . $sub . '"'), 'editing, the account itself is left out (except)');
$r = post_as(26, '/accounts/save.php', ['account' => $acme, 'kind' => 'customer']);
ok($r['code'] === 422 && field_of($r) === 'kind', 'customer is not set by hand (a won deal makes it), refused on `kind`');
post_as(26, '/accounts/save.php', ['account' => $sub, 'kind' => 'partner']);
ok(one('SELECT kind || \':\' || kind_set_by_hand FROM accounts WHERE id = :i', ['i' => $sub]) === 'partner:true', 'partner is set by hand (and remembered as such)');

echo "3. Custom fields\n";
pdo()->exec("INSERT INTO custom_field_defs (key, label, field_type, applies_to, options, sort_order) VALUES
    ('seats_$x', 'Seats $x', 'number', 'account', '{}', 1), ('tier_$x', 'Tier $x', 'select', 'account', '{gold,silver}', 2), ('nda_$x', 'NDA $x', 'checkbox', 'account', '{}', 3),
    ('linkedin_$x', 'Profile $x', 'url', 'contact', '{}', 1)");
$f = html_as(26, '/accounts/new')['body'];
ok(str_contains($f, 'id="account-form-field-custom-seats_' . $x . '"') && str_contains($f, 'id="account-form-field-custom-tier_' . $x . '"') && !str_contains($f, 'custom-linkedin_' . $x), 'the account form renders the account\'s custom fields and not a contact\'s');
ok(str_contains(html_as(26, '/contacts/new')['body'], 'id="contact-form-field-custom-linkedin_' . $x . '"'), 'the contact form renders the contact\'s');
$r = post_as(26, '/accounts/save.php', ['account' => $acme, 'custom' => ["seats_$x" => 'many'], 'custom_present' => ["seats_$x" => 1]]);
ok($r['code'] === 422 && str_contains(message_of($r), "seats_$x") && str_contains(message_of($r), 'number'), 'a wrong type is refused naming the field: ' . message_of($r));
$r = post_as(26, '/accounts/save.php', ['account' => $acme, 'custom' => ["tier_$x" => 'bronze'], 'custom_present' => ["tier_$x" => 1]]);
ok($r['code'] === 422 && str_contains(message_of($r), "tier_$x"), 'a select refuses a value not among its options');
$r = post_as(26, '/accounts/save.php', ['account' => $acme, 'custom' => ["seats_$x" => '120', "tier_$x" => 'gold', "nda_$x" => 'yes'], 'custom_present' => ["seats_$x" => 1, "tier_$x" => 1, "nda_$x" => 1]]);
$cv = json_decode((string) one('SELECT custom_values FROM accounts WHERE id = :i', ['i' => $acme]), true);
ok($r['code'] === 200 && ($cv["seats_$x"] ?? null) === 120 && ($cv["tier_$x"] ?? null) === 'gold' && ($cv["nda_$x"] ?? null) === true, 'typed values are stored as the definition says (number 120, text gold, true)');
$r = post_as(26, '/accounts/save.php', ['account' => $acme, 'custom_values' => json_encode(["seats_$x" => 130])]);
$cv = json_decode((string) one('SELECT custom_values FROM accounts WHERE id = :i', ['i' => $acme]), true);
ok($r['code'] === 200 && ($cv["seats_$x"] ?? null) === 130 && ($cv["tier_$x"] ?? null) === 'gold', 'an agent\'s custom_values object changes its keys and keeps the others');
$v = html_as(26, "/accounts/$acme")['body'];
ok(str_contains($v, 'Seats ' . $x) && str_contains($v, '130'), 'the page shows the custom values by their labels');

echo "4. A field left out stays; the edit rule\n";
post_as(26, '/accounts/save.php', ['account' => $acme, 'phone' => '+1 312 555 0100']);
ok(one('SELECT industry FROM accounts WHERE id = :i', ['i' => $acme]) === 'Manufacturing' && one('SELECT phone FROM accounts WHERE id = :i', ['i' => $acme]) === '+1 312 555 0100', 'an update of one field keeps the others (industry stays)');
post_as(26, '/accounts/save.php', ['account' => $acme, 'industry' => '']);
ok(one('SELECT industry FROM accounts WHERE id = :i', ['i' => $acme]) === null, 'an emptied field is cleared');
$r = post_as(30, '/accounts/save.php', ['account' => $acme, 'name' => 'Hijacked']);
ok($r['code'] === 403 && str_contains(message_of($r), 'SMOKE Priya, the owner,'), 'another Rep may not change it — refused naming the owner: ' . message_of($r));
$r = post_as(27, '/accounts/save.php', ['account' => $acme, 'description' => 'Key account']);
ok($r['code'] === 200, 'the Sales Manager may');
$u = q("SELECT before, after FROM activity_log WHERE action = 'account.update' AND entity_id = :i ORDER BY id DESC LIMIT 1", ['i' => $acme])[0];
ok(json_decode((string) $u['after'], true)['description'] === '(changed)', 'account.update logs the description as changed, never its words');
ok(post_as(29, '/accounts/save.php', ['name' => "Viewer $x"])['code'] === 403, 'a Viewer may not add an account');
ok(html_as(29, '/accounts/new')['code'] === 403 && html_as(30, "/accounts/$acme/edit")['code'] === 403 && html_as(27, "/accounts/$acme/edit")['code'] === 200, 'the forms follow the same rules (a Viewer\'s add, another Rep\'s edit: 403; the manager\'s edit: 200)');
$r = post_as(26, '/accounts/save.php', ['account' => $acme]);
ok($r['code'] === 200 && ($r['json']['did'] ?? '') === 'Nothing to change', 'saving nothing says so');

echo "5. The list\n";
$l = get_as(26, '/accounts?q=' . rawurlencode("Acme $x"))['json']['data'] ?? [];
ok(($l['accounts'][0]['account_id'] ?? 0) === $acme && $l['total'] >= 2, 'a search finds the account first (name order by likeness)');
$l = get_as(26, '/accounts?q=' . rawurlencode($x) . '&kind=partner')['json']['data'] ?? [];
ok(array_column($l['accounts'], 'account_id') === [$sub], 'the kind filter');
$l = get_as(26, '/accounts?q=' . rawurlencode($x) . '&owner=me')['json']['data'] ?? [];
ok(count($l['accounts']) >= 2, 'the owner filter (mine)');
post_as(26, '/tags/apply.php', ['kind' => 'account', 'record' => $acme, 'tag' => "vip-$x"]);
$l = get_as(26, '/accounts?tag=' . rawurlencode("vip-$x"))['json']['data'] ?? [];
ok(array_column($l['accounts'], 'account_id') === [$acme], 'the tag filter (by label)');
$h = html_as(26, '/accounts?q=' . rawurlencode($x))['body'];
ok(str_contains($h, 'id="account-card-' . $acme . '"') && str_contains($h, 'href="/accounts/' . $acme . '?back=') && str_contains($h, 'No next step'), 'the cards: a link carrying ?back=, the next step\'s word');
ok(get_as(26, "/accounts/$gone")['code'] === 404, 'a deleted account answers 404');
finish();
