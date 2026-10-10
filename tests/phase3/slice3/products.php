<?php
/** Proof — a deal's product lines (D11) and its people with their roles: the value follows the lines when the deal says so, a line changes and goes, the
 * provider pair, the bounds; a person added with a role, made primary, never the primary taken off. */
require __DIR__ . '/lib.php';
$x = run_tag();
as_member(27);
echo "1. Product lines\n";
$d = deal_make(26, ['title' => "Lines $x", 'value' => 500]);
$since = last_activity_id();
$r = post_as(26, '/deals/products/add.php', ['deal' => $d, 'name' => 'Licence', 'quantity' => 3, 'unit_price' => 1000, 'discount_percent' => 10, 'recurring' => 'yearly']);
$l1 = (int) ($r['json']['record_id'] ?? 0);
ok($r['code'] === 200 && (float) one('SELECT line_total FROM deal_products WHERE id = :l', ['l' => $l1]) === 2700.0, 'a line: 3 × 1,000 less 10% = 2,700');
ok((float) one('SELECT value FROM deals WHERE id = :d', ['d' => $d]) === 500.0, 'the deal\'s own value stands while it does not follow the lines');
$a = activity('deal.product_add', $since)[0] ?? null;
ok($a !== null && json_decode($a['after'], true)['line_total'] == 2700 && (int) $a['deal_id'] === $d, 'deal.product_add logged with the line\'s total');
post_as(26, '/deals/save.php', ['deal' => $d, 'value_from_products' => 'yes']);
ok((float) one('SELECT value FROM deals WHERE id = :d', ['d' => $d]) === 2700.0, 'value from products: the value becomes the lines\' total');
$l2 = (int) post_as(26, '/deals/products/add.php', ['deal' => $d, 'name' => 'Onboarding', 'unit_price' => 800])['json']['record_id'];
ok((float) one('SELECT value FROM deals WHERE id = :d', ['d' => $d]) === 3500.0 && (int) one('SELECT position FROM deal_products WHERE id = :l', ['l' => $l2]) === 1, 'a second line (1 by default, after the first): the value follows, 3,500');
$r = post_as(26, '/deals/products/save.php', ['product_line' => $l1, 'quantity' => 5]);
ok($r['code'] === 200 && (float) one('SELECT value FROM deals WHERE id = :d', ['d' => $d]) === 5300.0 && one('SELECT name FROM deal_products WHERE id = :l', ['l' => $l1]) === 'Licence', 'deal_product_update: 5 licences, the name kept; the value 5,300');
$r = post_as(26, '/deals/products/remove.php', ['product_line' => $l2]);
ok($r['code'] === 200 && (float) one('SELECT value FROM deals WHERE id = :d', ['d' => $d]) === 4500.0 && activity('deal.product_remove', $since) !== [], 'deal_product_remove: the line goes, the value follows, logged');
$h = html_as(26, "/deals/$d")['body'];
ok(str_contains($h, 'id="deal-product-' . $l1 . '"') && str_contains($h, 'id="deal-products-drives-value"') && preg_match('/id="deal-products-total">\$4,500</', $h) === 1, 'the page: the line, the total, "the deal\'s value"');
ok(field_of(post_as(26, '/deals/products/add.php', ['deal' => $d, 'name' => 'X', 'quantity' => 0])) === 'quantity' && field_of(post_as(26, '/deals/products/add.php', ['deal' => $d, 'name' => 'X', 'discount_percent' => 120])) === 'discount_percent',
    'a quantity of 0 and a discount over 100 are refused by name');
ok(field_of(post_as(26, '/deals/products/add.php', ['deal' => $d, 'name' => 'X', 'provider_app' => 'inventory'])) === 'provider_id', 'a product from Inventory names its id too');
$r = post_as(26, '/deals/products/add.php', ['deal' => $d, 'product' => 'gl:ITEM-7', 'name' => 'Support plan', 'sku' => 'SUP-1']);
ok($r['code'] === 200 && one('SELECT provider_app || \':\' || provider_id FROM deal_products WHERE id = :l', ['l' => $r['json']['record_id']]) === 'gl:ITEM-7', 'a line from the ledger keeps its provider and id');
ok(field_of(post_as(26, '/deals/products/add.php', ['deal' => $d])) === 'name', 'a line needs a name');
ok(post_as(30, '/deals/products/add.php', ['deal' => $d, 'name' => 'Sneaky'])['code'] === 403 && post_as(30, '/deals/products/remove.php', ['product_line' => $l1])['code'] === 403, 'another Rep adds or removes nothing');
ok(post_as(26, '/deals/products/save.php', ['quantity' => 2])['code'] === 422 && post_as(26, '/deals/products/remove.php', ['product_line' => 999999])['code'] === 404, 'a line is named; a missing one is 404');
$p = get_as(26, "/deals/$d")['json']['data']['deal'];
ok(count($p['products']) === 2 && $p['products_total'] == (float) one('SELECT sum(line_total) FROM deal_products WHERE deal_id = :d', ['d' => $d]), 'get_deal carries the lines and their total');

echo "2. People with roles\n";
$acc = (int) one('SELECT account_id FROM deals WHERE id = :d', ['d' => $d]);
$first = (int) one('SELECT primary_contact_id FROM deals WHERE id = :d', ['d' => $d]);
$cfo = (int) post_as(26, '/contacts/save.php', ['first_name' => 'Cora', 'last_name' => "CFO $x", 'account' => $acc, 'role' => 'finance'])['json']['record_id'];
$since = last_activity_id();
$r = post_as(26, '/deals/contacts/add.php', ['deal' => $d, 'contact' => $cfo]);
ok($r['code'] === 200 && one('SELECT role FROM deal_contacts WHERE deal_id = :d AND contact_id = :c', ['d' => $d, 'c' => $cfo]) === 'finance', 'added with their usual role (finance)');
ok(activity('deal.contact_add', $since) !== [] && post_as(26, '/deals/contacts/add.php', ['deal' => $d, 'contact' => $cfo])['code'] === 422, 'logged; adding them twice is refused');
post_as(26, '/deals/contacts/save.php', ['deal' => $d, 'contact' => $cfo, 'role' => 'decision_maker']);
ok(one('SELECT role FROM deal_contacts WHERE deal_id = :d AND contact_id = :c', ['d' => $d, 'c' => $cfo]) === 'decision_maker' && activity('deal.contact_update', $since) !== [], 'deal_contact_update: the role changed, logged');
$r = post_as(26, '/deals/contacts/remove.php', ['deal' => $d, 'contact' => $first]);
ok($r['code'] === 422 && str_contains(message_of($r), 'primary contact'), 'the primary is never taken off');
post_as(26, '/deals/contacts/save.php', ['deal' => $d, 'contact' => $cfo, 'is_primary' => 'yes']);
ok((int) one('SELECT primary_contact_id FROM deals WHERE id = :d', ['d' => $d]) === $cfo && (int) one('SELECT count(*) FROM deal_contacts WHERE deal_id = :d AND is_primary', ['d' => $d]) === 1, 'made primary: the deal\'s primary contact, one primary only');
ok(field_of(post_as(26, '/deals/contacts/save.php', ['deal' => $d, 'contact' => $cfo, 'is_primary' => 'no'])) === 'is_primary', 'the primary is not unset without another');
$r = post_as(26, '/deals/contacts/remove.php', ['deal' => $d, 'contact' => $first]);
ok($r['code'] === 200 && (int) one('SELECT count(*) FROM deal_contacts WHERE deal_id = :d', ['d' => $d]) === 1 && activity('deal.contact_remove', $since) !== [], 'the former primary is taken off now, logged');
ok(post_as(26, '/deals/contacts/save.php', ['deal' => $d, 'contact' => $first, 'role' => 'user'])['code'] === 404, 'changing someone not on the deal is 404');
ok(one('SELECT lifecycle FROM contacts WHERE id = :c', ['c' => $cfo]) === 'prospect', 'a person on an open deal is a prospect');
$h = html_as(26, "/deals/$d")['body'];
ok(str_contains($h, 'id="deal-contact-' . $cfo . '-primary"') && str_contains($h, 'id="deal-contact-add-form"'), 'the page: the primary marked, Add a person');
finish();
