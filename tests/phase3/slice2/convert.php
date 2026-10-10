<?php
/**
 * Proof — the conversion (leads-and-conversion.md §3, §8): the form shows "Found" and "New" right for four fixtures — (a) the account by the address's
 * domain and the contact by the address; (b) the account by the company's name, a new contact; (c) nothing known: a new account and a new contact; (d) a
 * free-mail address and no company: no account, a new contact — and the POST makes exactly what it said; the deal in the default pipeline's first stage
 * with the title, value and close date given; record_id the deal; lead.convert logs the three; the conversion refuses a second time; leads.convert.
 */
require __DIR__ . '/lib.php';
$x = run_tag();
as_member(26);                                                             // the views answer an admitted member only
$accA = (int) post_as(26, '/accounts/save.php', ['name' => "Acme $x", 'website' => "https://acme-$x.example.com"])['json']['record_id'];
$conA = (int) post_as(26, '/contacts/save.php', ['first_name' => 'Dana', 'last_name' => "Known $x", 'email' => "dana@acme-$x.example.com"])['json']['record_id'];
$accB = (int) post_as(26, '/accounts/save.php', ['name' => "Initrode $x"])['json']['record_id'];
$lead = static fn (array $f): int => (int) post_as(26, '/leads/save.php', $f + ['owner' => 26])['json']['record_id'];
$la = $lead(['first_name' => 'Dana', 'last_name' => "Known $x", 'email' => "dana@acme-$x.example.com", 'company' => 'Whatever they typed']);
$lb = $lead(['first_name' => 'Bob', 'last_name' => "Slydell $x", 'email' => "bob@elsewhere-$x.example.com", 'company' => "initrode $x"]);
$lc = $lead(['first_name' => 'Jordan', 'last_name' => "New $x", 'email' => "jordan@globex-$x.example.com", 'company' => "Globex $x"]);
$ld = $lead(['first_name' => 'Free', 'last_name' => "Mail $x", 'email' => "free.$x@gmail.com"]);

echo "1. What the form says\n";
$p = static fn (int $id): array => get_as(26, "/leads/$id/convert")['json']['data']['preview'] ?? [];
$pa = $p($la);
ok(($pa['account']['account_id'] ?? 0) === $accA && $pa['account']['by'] === 'domain' && ($pa['contact']['contact_id'] ?? 0) === $conA, '(a) Found the account by the domain and Found the contact by the address');
$pb = $p($lb);
ok(($pb['account']['account_id'] ?? 0) === $accB && $pb['account']['by'] === 'name' && $pb['contact'] === null && ($pb['contact_new']['name'] ?? '') === "Bob Slydell $x", '(b) Found the account by the company\'s name (any case); New: the contact');
$pc = $p($lc);
ok($pc['account'] === null && ($pc['account_new']['name'] ?? '') === "Globex $x" && $pc['contact'] === null && ($pc['contact_new']['email'] ?? '') === "jordan@globex-$x.example.com", '(c) New: the account Globex and New: the contact');
$pd = $p($ld);
ok($pd['account'] === null && $pd['account_new'] === null && ($pd['contact_new']['name'] ?? '') === "Free Mail $x", '(d) a free-mail address and no company: no account; New: the contact');
ok(($pc['deal_defaults']['title'] ?? '') === "Globex $x — new business" && ($pc['deal_defaults']['pipeline_name'] ?? '') === 'New business', 'the deal\'s defaults: "<account> — new business" in the default pipeline');
$h = html_as(26, "/leads/$la/convert")['body'];
ok(str_contains($h, 'id="convert-account-found"') && str_contains($h, "Found: Acme $x") && str_contains($h, 'id="convert-contact-found"') && str_contains($h, 'id="convert-form-field-make_deal"'), 'the page says Found for both and offers the deal');
$h = html_as(26, "/leads/$lc/convert")['body'];
ok(str_contains($h, 'id="convert-account-new"') && str_contains($h, "New: Globex $x") && str_contains($h, 'id="convert-contact-new"'), '…and New for the unknown ones');

echo "2. The POST makes exactly what it said\n";
$check = static function (int $id, array $pre, array $form, string $label) use ($x): array {
    $before = ['a' => (int) one('SELECT count(*) FROM accounts'), 'c' => (int) one('SELECT count(*) FROM contacts')];
    $r = post_as(26, "/leads/$id/convert", ['lead' => $id] + $form);
    $c = $r['json']['converted'] ?? [];
    $after = ['a' => (int) one('SELECT count(*) FROM accounts'), 'c' => (int) one('SELECT count(*) FROM contacts')];
    $accountOk = $pre['account'] !== null ? ($c['account_id'] === $pre['account']['account_id'] && $c['account_made'] === false && $after['a'] === $before['a'])
        : ($pre['account_new'] !== null ? ($c['account_made'] === true && $after['a'] === $before['a'] + 1) : ($c['account_id'] === null && $after['a'] === $before['a']));
    $contactOk = $pre['contact'] !== null ? ($c['contact_id'] === $pre['contact']['contact_id'] && $c['contact_made'] === false && $after['c'] === $before['c'])
        : ($c['contact_made'] === true && $after['c'] === $before['c'] + 1);
    ok($r['code'] === 200 && $accountOk && $contactOk, "$label: the account and the contact are exactly the preview's (found or made)");
    return $r;
};
$r = $check($la, $pa, ['value' => '24000', 'close_date' => '2026-12-31', 'deal_title' => "Acme $x — 20 seats"], '(a)');
$deal = (int) ($r['json']['converted']['deal_id'] ?? 0);
$d = q('SELECT d.title, d.value, d.expected_close_date::text AS close, d.account_id, d.primary_contact_id, d.lead_id, s.name AS stage, p.key FROM deals d JOIN stages s ON s.id = d.stage_id JOIN pipelines p ON p.id = d.pipeline_id WHERE d.id = :i', ['i' => $deal])[0];
ok($d['title'] === "Acme $x — 20 seats" && (float) $d['value'] === 24000.0 && $d['close'] === '2026-12-31' && (int) $d['account_id'] === $accA && (int) $d['primary_contact_id'] === $conA && (int) $d['lead_id'] === $la, 'the deal: the title, value and close date given, on the account and contact, pointing at the lead');
ok($d['key'] === 'new_business' && $d['stage'] === 'Qualification', '…in the default pipeline\'s first open stage');
ok((int) $r['json']['record_id'] === $deal, 'record_id is the deal');
$l = q('SELECT status, converted_account_id, converted_contact_id, converted_deal_id FROM leads WHERE id = :i', ['i' => $la])[0];
ok($l['status'] === 'qualified' && (int) $l['converted_account_id'] === $accA && (int) $l['converted_contact_id'] === $conA && (int) $l['converted_deal_id'] === $deal, 'the lead is qualified and points at the three');
$a = activity('lead.convert');
$after = json_decode((string) end($a)['after'], true);
ok($after == ['account_id' => $accA, 'contact_id' => $conA, 'deal_id' => $deal, 'account_made' => false, 'contact_made' => false] && (int) end($a)['deal_id'] === $deal && (int) end($a)['account_id'] === $accA, 'lead.convert logs {account_id, contact_id, deal_id, account_made, contact_made} with the three as audit keys');
$check($lb, $pb, ['make_deal' => 'no'], '(b)');
ok(one('SELECT converted_deal_id FROM leads WHERE id = :i', ['i' => $lb]) === null, '(b) without a deal when make_deal is no');
$r = $check($lc, $pc, [], '(c)');
ok(one('SELECT name FROM accounts WHERE id = :i', ['i' => $r['json']['converted']['account_id']]) === "Globex $x" && one('SELECT domain::text FROM accounts WHERE id = :i', ['i' => $r['json']['converted']['account_id']]) === "globex-$x.example.com", '(c) the new account has the company\'s name and the address\'s domain');
ok(one('SELECT account_id FROM contacts WHERE id = :i', ['i' => $r['json']['converted']['contact_id']]) === $r['json']['converted']['account_id'], '…and the new contact is at it');
$check($ld, $pd, ['make_deal' => 'no'], '(d)');
ok(post_as(26, "/leads/$la/convert", ['lead' => $la])['code'] === 422, 'a converted lead is refused a second time');
$e = $lead(['company' => "Chosen $x"]);
$r = post_as(26, "/leads/$e/convert", ['lead' => $e, 'account' => $accB, 'make_deal' => 'no']);
ok($r['code'] === 200 && ($r['json']['converted']['account_id'] ?? 0) === $accB && ($r['json']['converted']['account_made'] ?? true) === false, 'an account chosen in the picker is used instead of making one');
$f = $lead(['company' => "Rights $x"]);
ok(post_as(30, "/leads/$f/convert", ['lead' => $f])['code'] === 403 && post_as(29, "/leads/$f/convert", ['lead' => $f])['code'] === 403, 'another Rep (the edit rule) and a Viewer (no leads.convert) may not convert it');
finish();
