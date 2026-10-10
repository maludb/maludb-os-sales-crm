<?php
declare(strict_types=1);
/** Action `deal_product_remove` (log deal.product_remove {name, line_total} — the edit rule on the deal): `product_line`. The deal's value follows when it follows the lines. */
require_once dirname(__DIR__, 3) . '/app/bootstrap.php';
require_once dirname(__DIR__, 3) . '/app/features/deals/write.php';
crm_handler_begin();
$pdo = db();
$line = request_integer('product_line');
$lineRow = $line === null ? null : find_deal_product($pdo, $line);
if ($lineRow === null) { refuse(404, 'Product line not found.'); }
$dealId = (int) $lineRow['deal_id'];
$row = require_record($pdo, 'deal', $dealId);
require_editable($pdo, 'deal', $row);
crm_guard($pdo, static function () use ($pdo, $line, $lineRow, $dealId): void {
    $pdo->beginTransaction();
    deal_product_remove($pdo, $line);
    log_activity($pdo, 'deal.product_remove', 'deal', $dealId, ['deal_id' => $dealId, 'before' => ['deal_product_id' => $line, 'name' => $lineRow['name'], 'quantity' => (float) $lineRow['quantity'], 'unit_price' => (float) $lineRow['unit_price'], 'line_total' => (float) $lineRow['line_total']],
        'after' => ['deal_value' => (float) one_value($pdo, 'SELECT value FROM deals WHERE id = :d', ['d' => $dealId])]]);
    $pdo->commit();
});
crm_done('Removed ' . $lineRow['name'], $line, crm_land(return_path('/deals/' . $dealId), 'product_removed', 'deal-products'), 'dealChanged', ['deal_id' => $dealId]);
