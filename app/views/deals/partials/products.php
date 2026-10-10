<?php /** The product lines (#deal-products): name, quantity, unit price, discount, how often, the line's total; the total; each line editable in place (a form per
 * row); Add line (by name — or from Inventory or the ledger when a super-admin connected them). When the value follows the lines it says so.
 * Data: d, products, total, may, connected */
$id = (int) $d['deal_id']; ?>
<div class="card mb-3" id="deal-products"><div class="card-header d-flex align-items-center flex-wrap gap-2"><h6 class="card-title mb-0">Products</h6>
    <span class="ms-auto fs-12"><span class="text-muted">Total</span> <span class="fw-semibold" id="deal-products-total"><?= e(money($total, $d['currency'])) ?></span>
        <?php if ($d['value_from_products']): ?><span class="badge bg-soft-info text-info ms-1" id="deal-products-drives-value">the deal's value</span><?php endif; ?></span></div>
    <div class="card-body">
    <?php if ($products === []): ?><div class="text-muted fs-12 mb-2" id="deal-products-empty">No products on the deal.</div><?php endif; ?>
    <?php foreach ($products as $p): $pid = (int) $p['deal_product_id']; ?>
        <form method="post" action="/deals/products/save.php" hx-post="/deals/products/save.php" hx-target="#flash" class="row g-1 align-items-end py-2 border-bottom deal-product-row" id="deal-product-<?= $pid ?>"><?= csrf_field() ?>
            <input type="hidden" name="product_line" value="<?= $pid ?>"><input type="hidden" name="return_to" value="/deals/<?= $id ?>#deal-products">
            <div class="col-12 col-md-4"><label class="form-label fs-11 mb-0" for="deal-product-<?= $pid ?>-name">Product</label><input type="text" name="name" maxlength="200" class="form-control form-control-sm" id="deal-product-<?= $pid ?>-name" value="<?= e($p['name']) ?>"<?= $may['edit'] ? '' : ' disabled' ?>>
                <?php if ($p['provider_app']): ?><div class="fs-11 text-muted">from <?= $p['provider_app'] === 'gl' ? 'the ledger' : 'Inventory' ?><?= $p['sku'] ? ' · ' . e($p['sku']) : '' ?></div><?php endif; ?></div>
            <div class="col-4 col-md-1"><label class="form-label fs-11 mb-0" for="deal-product-<?= $pid ?>-qty">Qty</label><input type="number" step="any" min="0" name="quantity" class="form-control form-control-sm" id="deal-product-<?= $pid ?>-qty" value="<?= e(rtrim(rtrim((string) $p['quantity'], '0'), '.')) ?>"<?= $may['edit'] ? '' : ' disabled' ?>></div>
            <div class="col-8 col-md-2"><label class="form-label fs-11 mb-0" for="deal-product-<?= $pid ?>-price">Unit price</label><input type="number" step="any" min="0" name="unit_price" class="form-control form-control-sm" id="deal-product-<?= $pid ?>-price" value="<?= e(rtrim(rtrim((string) $p['unit_price'], '0'), '.')) ?>"<?= $may['edit'] ? '' : ' disabled' ?>></div>
            <div class="col-4 col-md-1"><label class="form-label fs-11 mb-0" for="deal-product-<?= $pid ?>-disc">Disc. %</label><input type="number" step="any" min="0" max="100" name="discount_percent" class="form-control form-control-sm" id="deal-product-<?= $pid ?>-disc" value="<?= e(rtrim(rtrim((string) $p['discount_percent'], '0'), '.')) ?>"<?= $may['edit'] ? '' : ' disabled' ?>></div>
            <div class="col-8 col-md-2"><label class="form-label fs-11 mb-0" for="deal-product-<?= $pid ?>-rec">How often</label><select name="recurring" class="form-select form-select-sm" id="deal-product-<?= $pid ?>-rec"<?= $may['edit'] ? '' : ' disabled' ?>>
                <?php foreach (DEAL_RECURRING as $k => $label): ?><option value="<?= $k ?>"<?= $p['recurring'] === $k ? ' selected' : '' ?>><?= $label ?></option><?php endforeach; ?></select></div>
            <div class="col-12 col-md-2 d-flex align-items-center gap-1 justify-content-end"><span class="fw-semibold fs-12 me-auto me-md-0 text-nowrap" id="deal-product-<?= $pid ?>-total"><?= e(money($p['line_total'], $d['currency'])) ?></span>
                <?php if ($may['edit']): ?><button type="submit" class="btn btn-sm btn-light" id="deal-product-<?= $pid ?>-save">Save</button>
                <button type="submit" class="btn btn-sm btn-light text-danger" formaction="/deals/products/remove.php" hx-post="/deals/products/remove.php" hx-confirm="Remove <?= e($p['name']) ?>?" id="deal-product-<?= $pid ?>-remove" aria-label="Remove"><i class="feather-trash-2"></i></button><?php endif; ?></div>
        </form>
    <?php endforeach; ?>
    <?php if ($may['edit'] && $d['status'] === 'open'): ?>
    <details class="mt-2" id="deal-product-add"<?= $products === [] ? ' open' : '' ?>><summary class="fs-12 fw-semibold text-primary">Add a line</summary>
    <form method="post" action="/deals/products/add.php" hx-post="/deals/products/add.php" hx-target="#flash" class="row g-2 align-items-end mt-1" id="deal-product-add-form"><?= csrf_field() ?>
        <input type="hidden" name="deal" value="<?= $id ?>"><input type="hidden" name="return_to" value="/deals/<?= $id ?>#deal-products">
        <?php if ($connected): ?><div class="col-12"><label class="form-label fs-11 mb-0" id="deal-product-add-pick-label" for="deal-product-add-field-product">From the catalog</label>
            <?= picker_field(['id' => 'deal-product-add-field-product', 'name' => 'product', 'source' => 'product', 'value' => null, 'labelledby' => 'deal-product-add-pick-label', 'title' => 'A product', 'placeholder' => 'Or type a name below']) ?></div><?php endif; ?>
        <div class="col-12 col-md-5"><label class="form-label fs-11 mb-0" for="deal-product-add-field-name">Product</label><input type="text" name="name" maxlength="200" class="form-control btn-touch" id="deal-product-add-field-name" placeholder="Annual licence"></div>
        <div class="col-4 col-md-2"><label class="form-label fs-11 mb-0" for="deal-product-add-field-quantity">Qty</label><input type="number" step="any" min="0" name="quantity" value="1" class="form-control btn-touch" id="deal-product-add-field-quantity"></div>
        <div class="col-8 col-md-3"><label class="form-label fs-11 mb-0" for="deal-product-add-field-unit_price">Unit price</label><input type="number" step="any" min="0" name="unit_price" class="form-control btn-touch" id="deal-product-add-field-unit_price"></div>
        <div class="col-4 col-md-2"><label class="form-label fs-11 mb-0" for="deal-product-add-field-discount">Disc. %</label><input type="number" step="any" min="0" max="100" name="discount_percent" value="0" class="form-control btn-touch" id="deal-product-add-field-discount"></div>
        <div class="col-8 col-md-4"><label class="form-label fs-11 mb-0" for="deal-product-add-field-recurring">How often</label><select name="recurring" class="form-select btn-touch" id="deal-product-add-field-recurring"><?php foreach (DEAL_RECURRING as $k => $label): ?><option value="<?= $k ?>"><?= $label ?></option><?php endforeach; ?></select></div>
        <div class="col-12 col-md-4"><button type="submit" class="btn btn-light btn-touch w-100" id="deal-product-add-btn"><i class="feather-plus me-1"></i>Add the line</button></div>
    </form></details>
    <?php endif; ?>
    </div></div>
