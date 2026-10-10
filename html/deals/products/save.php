<?php
declare(strict_types=1);
/** Action `deal_product_update` (log deal.product_update): `product_line` + any field of deal_product_add, `position`. A field left out stays as it was. The one handler is add.php's. */
if (!isset($_POST['product_line']) || $_POST['product_line'] === '') {
    require_once dirname(__DIR__, 3) . '/app/bootstrap.php';
    crm_handler_begin();
    crm_refuse_fields(['product_line' => 'Say which line.']);
}
require __DIR__ . '/add.php';
