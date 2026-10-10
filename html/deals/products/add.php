<?php
declare(strict_types=1);
/**
 * Actions `deal_product_add` (`deal`, no `product_line`; log deal.product_add) and — through products/save.php — `deal_product_update`. Fields: name (required on add;
 * or `product` = "inventory:<id>" / "gl:<id>" from the picker when a sibling's catalog is connected), provider_app + provider_id, sku, quantity (1), unit_price,
 * discount_percent (0–100), recurring (none, monthly, yearly), position. The edit rule on the deal. A deal whose value follows its lines changes with them.
 */
require_once dirname(__DIR__, 3) . '/app/bootstrap.php';
require_once dirname(__DIR__, 3) . '/app/features/deals/write.php';
crm_handler_begin();
$pdo = db();
$line = request_integer('product_line');
if ($line !== null && basename($_SERVER['SCRIPT_FILENAME'] ?? '') === 'add.php') { refuse(422, 'To change a line, use products/save.php.'); }
if ($line !== null) {
    $lineRow = find_deal_product($pdo, $line);
    if ($lineRow === null) { refuse(404, 'Product line not found.'); }
    $dealId = (int) $lineRow['deal_id'];
} else {
    $dealId = request_integer('deal') ?? request_integer('id');
}
$row = require_record($pdo, 'deal', $dealId);
require_editable($pdo, 'deal', $row);
$cur = $line === null ? ['name' => null, 'provider_app' => null, 'provider_id' => null, 'sku' => null, 'quantity' => '1', 'unit_price' => '0', 'discount_percent' => '0', 'recurring' => 'none', 'position' => null] : deal_product_current($pdo, $line);
$errors = [];
$f = [];
if (req_has('product') && preg_match('/^(inventory|gl):([A-Za-z0-9_.-]{1,80})$/', (string) req_val('product'), $m)) {
    $_POST['provider_app'] = $m[1];
    $_POST['provider_id'] = $m[2];
}
$f['name'] = crm_text('name', $cur['name'], 200, 'The product', $errors, true);
if ($line === null && $f['name'] === null && !isset($errors['name'])) { $errors['name'] = 'Name the product.'; }
$f['provider_app'] = crm_choice('provider_app', $cur['provider_app'], ['inventory', 'gl'], 'Where it comes from', $errors);
$f['provider_id'] = crm_text('provider_id', $cur['provider_id'], 80, 'The product\'s id', $errors);
if (($f['provider_app'] === null) !== ($f['provider_id'] === null)) { $errors['provider_id'] = 'A product from Inventory or the ledger names both the application and its id.'; }
$f['sku'] = crm_text('sku', $cur['sku'], 80, 'The SKU', $errors);
$num = static function (string $k, ?string $keep, float $min, float $max, string $label, array &$errors, bool $positive = false): ?string {
    if (!req_has($k)) { return $keep; }
    $v = str_replace([',', ' '], '', (string) req_val($k));
    if ($v === '') { return $keep; }
    if (!is_numeric($v) || (float) $v < $min || (float) $v > $max || ($positive && (float) $v <= 0)) { $errors[$k] = $label; return $keep; }
    return $v;
};
$f['quantity'] = $num('quantity', $cur['quantity'], 0, 1e8, 'The quantity is more than 0.', $errors, true);
$f['unit_price'] = $num('unit_price', $cur['unit_price'], 0, 1e11, 'The unit price is an amount of 0 or more.', $errors);
$f['discount_percent'] = $num('discount_percent', $cur['discount_percent'], 0, 100, 'The discount is a percent from 0 to 100.', $errors);
$f['recurring'] = crm_choice('recurring', $cur['recurring'], array_keys(DEAL_RECURRING), 'How often', $errors, false) ?? 'none';
$f['position'] = crm_int('position', $cur['position'], 0, 10000, 'The position', $errors, true);
if ($errors !== []) { crm_refuse_fields($errors); }
if ($line === null) { unset($f['position']); $f = array_filter($f, static fn ($v) => $v !== null); }
$diff = $line === null ? null : crm_diff(array_intersect_key($cur, $f), $f);
$newLine = crm_guard($pdo, static function () use ($pdo, $dealId, $line, $f, $diff): int {
    $pdo->beginTransaction();
    $id = deal_product_save($pdo, $dealId, $line, $line === null ? $f : $diff['after']);
    $saved = deal_product_current($pdo, $id);
    $value = (float) one_value($pdo, 'SELECT value FROM deals WHERE id = :d', ['d' => $dealId]);
    if ($line === null) {
        log_activity($pdo, 'deal.product_add', 'deal', $dealId, ['deal_id' => $dealId, 'after' => ['deal_product_id' => $id, 'name' => $saved['name'], 'quantity' => (float) $saved['quantity'], 'line_total' => (float) $saved['line_total'], 'deal_value' => $value]]);
    } elseif ($diff['after'] !== []) {
        log_activity($pdo, 'deal.product_update', 'deal', $dealId, ['deal_id' => $dealId, 'before' => $diff['before'] + ['deal_product_id' => $id], 'after' => $diff['after'] + ['line_total' => (float) $saved['line_total'], 'deal_value' => $value]]);
    }
    $pdo->commit();
    return $id;
});
$did = $line === null ? 'Added ' . $f['name'] . ' to the deal' : (($diff['after'] ?? []) === [] ? 'Nothing to change' : 'Saved the line ' . ($f['name'] ?? ''));
crm_done($did, $newLine, crm_land(return_path('/deals/' . $dealId), $line === null ? 'product_added' : 'product_saved', 'deal-products'), 'dealChanged', ['deal_id' => $dealId]);
